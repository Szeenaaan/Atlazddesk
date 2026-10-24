<?php

namespace App\Services\Auth;

use App\Services\Mail\ContractorMailService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Cache, DB, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MfaService
{
    public function __construct(
        private readonly MfaSettings $settings,
        private readonly ContractorMailService $mail,
        private readonly \App\Services\System\TechnicalSecurityService $technical,
    ) {}
    private const PENDING_KEYS = [
        'amc_mfa_pending_user_id', 'amc_mfa_pending_until', 'amc_mfa_otp_hash',
        'amc_mfa_otp_until', 'amc_mfa_otp_attempts', 'amc_mfa_otp_purpose', 'amc_mfa_otp_sent_at',
        'amc_mfa_verified_factors',
        'amc_mfa_otp_delivery_key',
    ];

    public function required(User $user): bool
    {
        // Business Google enrollment is OPTIONAL; Email OTP is not.
        // Never interpret legacy require_mfa/required_roles as a forced Google enrollment.
        if ($user->role !== 'super_admin') {
            return $this->requiredMethods($user) !== [];
        }
        // Installation technical policy remains independent and mandatory.
        return $this->requiredMethods($user) !== []
            || $this->settings->requiredFor($user)
            || in_array($user->role, config('amc_mfa.required_roles', []), true);
    }

    public function methods(User $user): array
    {
        $methods = [];
        $enrolled = (bool) ($user->mfa_totp_confirmed_at && $user->mfa_totp_secret);
        if ($this->settings->totp($user) && $enrolled) {
            $methods[] = 'totp';
            if (count($user->mfa_recovery_codes ?? []) > 0) $methods[] = 'recovery';
        }
        if ($this->settings->email($user) && $user->mfa_email_enabled && $user->email_verified_at) {
            $methods[] = 'email';
        }
        // A recovery code replaces only an already-enrolled authenticator, NEVER Email OTP.
        return $methods;
    }

    public function begin(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $this->clearPending($request);
        $request->session()->forget(['amc_mfa_verified_user_id', 'amc_mfa_verified_methods']);
        $request->session()->put('amc_mfa_pending_user_id', $user->id);
        $request->session()->put('amc_mfa_pending_until', now()->addMinutes(
            (int) config('amc_mfa.challenge_minutes', 5)
        )->timestamp);
    }

    /** Every enabled method is mandatory; enabling both means BOTH must succeed. */
    public function requiredMethods(User $user): array
    {
        $flags = $this->settings->forUser($user);
        $required = [];
        // The Super Admin technical policy remains STRICT. Business users choose
        // whether to enroll Google, but enrollment makes it mandatory afterward.
        if (($flags['totp_enabled'] ?? false) && (
            $user->role === 'super_admin'
            || ($user->mfa_totp_confirmed_at && $user->mfa_totp_secret)
        )) {
            $required[] = 'totp';
        }
        // Business Email OTP is always mandatory when enabled by admin,
        // even before the first successful mailbox verification.
        if ($flags['email_enabled'] ?? false) {
            $required[] = 'email';
        }
        return $required;
    }

    /** Safe, unauthenticated progress response. Never expose account credentials or secrets. */
    public function challenge(Request $request, User $user): array
    {
        $required = $this->requiredMethods($user);
        $verified = array_values(array_intersect(
            $required,
            (array) $request->session()->get('amc_mfa_verified_factors', [])
        ));
        $remaining = array_values(array_diff($required, $verified));
        $setup = [];

        if (in_array('totp', $remaining, true)
            && (! $user->mfa_totp_confirmed_at || ! $user->mfa_totp_secret)) {
            $setup[] = 'totp';
        }
        if (in_array('email', $remaining, true)
            && (! $user->mfa_email_enabled || ! $user->email_verified_at)) {
            $setup[] = 'email';
        }

        return [
            'status' => $setup !== [] ? 'setup_required' : 'mfa_required',
            'required_methods' => $required,
            'verified_methods' => $verified,
            'remaining_methods' => $remaining,
            'setup_methods' => $setup,
            'methods' => $this->methods($user),
            'setup_method' => $setup[0] ?? null,
        ];
    }

    /** Record ONE passed method. Only finish after every enabled method has passed. */
    public function completeFactor(Request $request, User $user, string $method): array
    {
        $pending = $this->pending($request);
        abort_unless($pending->id === $user->id, 403, 'Invalid login challenge.');
        abort_unless(in_array($method, $this->requiredMethods($user), true), 403, 'MFA method is not required.');

        $verified = (array) $request->session()->get('amc_mfa_verified_factors', []);
        abort_if(in_array($method, $verified, true), 409, 'This MFA step was already completed.');
        $verified[] = $method;
        $request->session()->put('amc_mfa_verified_factors', array_values(array_unique($verified)));

        if ($this->allRequiredFactorsVerified($request, $user)) {
            return $this->finish($request, $user, true);
        }

        return $this->challenge($request, $user);
    }

    public function allRequiredFactorsVerified(Request $request, User $user): bool
    {
        $required = $this->requiredMethods($user);
        $verified = (array) $request->session()->get('amc_mfa_verified_factors', []);
        return $required !== [] && array_diff($required, $verified) === [];
    }

    public function pending(Request $request): User
    {
        $id = $request->session()->get('amc_mfa_pending_user_id');
        $expires = (int) $request->session()->get('amc_mfa_pending_until', 0);
        $user = $id && $expires >= time() ? User::find($id) : null;
        if (! $user || $user->status !== 'active') {
            $this->clearPending($request);
            throw new HttpException(401, 'Login challenge expired. Please sign in again.');
        }
        return $user;
    }

    public function finish(Request $request, User $user, bool $factorVerified = false): array
    {
        $required = $this->requiredMethods($user);
        if ($required !== []) {
            // Never create a web session after just one of two required methods.
            abort_unless($factorVerified
                && (int) $request->session()->get('amc_mfa_pending_user_id', 0) === (int) $user->id
                && $this->allRequiredFactorsVerified($request, $user),
                403, 'Complete every required MFA step before signing in.');
        }

        app(AccountSession::class)->claim($request, $user);
        $request->session()->forget(CustomerWorkspace::KEY);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->clearPending($request);
        $request->session()->put('amc_mfa_verified_user_id', $user->id);
        $request->session()->put('amc_mfa_verified_methods', $required);
        $request->session()->put('amc_mfa_verified_security_version', (int) $user->mfa_security_version);
        $user->forceFill(['last_login_at' => now()])->save();

        return ['status' => 'authenticated', 'user' => $this->publicUser($user)];
    }

    public function publicUser(User $user): array
    {
        return $user->only(['id','name','email','role','customer_id']);
    }

    public function clearPending(Request $request): void
    {
        if ($key = $request->session()->get('amc_mfa_otp_delivery_key')) Cache::forget($key);
        $request->session()->forget(self::PENDING_KEYS);
    }

    public function consumeTotp(User $user, string $code, TotpService $totp): bool
    {
        return DB::transaction(function () use ($user, $code, $totp): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $locked->mfa_totp_confirmed_at || ! $locked->mfa_totp_secret) return false;
            $step = $totp->matchStep($locked->mfa_totp_secret, $code, $locked->mfa_last_totp_step);
            if ($step === null) return false;
            $locked->mfa_last_totp_step = $step;
            $locked->save();
            return true;
        });
    }

    public function consumeRecovery(User $user, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
        if (! preg_match('/^[A-F0-9]{20}$/D', $normalized)) return false;
        return DB::transaction(function () use ($user, $normalized): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $codes = $locked->mfa_recovery_codes ?? [];
            foreach ($codes as $index => $hash) {
                if (Hash::check($normalized, $hash)) {
                    unset($codes[$index]);
                    $locked->mfa_recovery_codes = array_values($codes);
                    $locked->save();
                    return true;
                }
            }
            return false;
        });
    }

    public function recoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < 8; $i++) $plain[] = strtoupper(bin2hex(random_bytes(10)));
        $user->mfa_recovery_codes = array_map(fn ($code) => Hash::make($code), $plain);
        $user->save();
        return $plain;
    }

    public function sendEmail(Request $request, User $user, string $purpose): void
    {
        abort_unless($this->settings->email($user), 403, 'Email OTP is disabled by your administrator.');
        if (! in_array($purpose, ['enroll_email', 'login_enroll'], true)) {
            abort_unless((bool) $user->email_verified_at, 422, 'Your email must be verified first.');
        }
        // Never send OTP codes through debug log/array mailers.
        abort_unless($user->role === 'super_admin' ? $this->technical->mailReady() : $this->mail->hasProvider($user->customer_id),
            503, 'Email MFA delivery is not configured.');
        $key = 'amc:mfa:email:'.$user->id.':'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429,
            'Verification email limit reached. Request a new code in '.max(1, RateLimiter::availableIn($key)).' seconds.',
            ['Retry-After' => max(1, RateLimiter::availableIn($key))]);
        $lastSent = (int) $request->session()->get('amc_mfa_otp_sent_at', 0);
        abort_if($lastSent && time() - $lastSent < (int) config('amc_mfa.email_cooldown_seconds', 60),
            429, 'Please wait before requesting another code.');

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(5)->timestamp;
        $deliveryKey = 'amc:mfa:delivery:'.bin2hex(random_bytes(32));
        abort_unless(Cache::put($deliveryKey, true, 300), 503, 'We could not send your verification code. Please try again.');
        try {
            \App\Jobs\SendAccountEmail::dispatch($user->id, $user->email, $user->role,
                (int) $user->mfa_security_version, 'otp', null, $code, $expiresAt, $deliveryKey);
        } catch (\Throwable) {
            Cache::forget($deliveryKey);
            abort(503, 'We could not send your verification code. Please try again.');
        }
        if ($previous = $request->session()->get('amc_mfa_otp_delivery_key')) Cache::forget($previous);
        RateLimiter::hit($key, 900);
        $request->session()->put([
            'amc_mfa_otp_hash' => Hash::make($code),
            'amc_mfa_otp_until' => $expiresAt,
            'amc_mfa_otp_attempts' => 0,
            'amc_mfa_otp_purpose' => $purpose,
            'amc_mfa_otp_sent_at' => time(),
            'amc_mfa_otp_delivery_key' => $deliveryKey,
        ]);
    }

    public function consumeEmail(Request $request, string $code, string $purpose): bool
    {
        if ($request->session()->get('amc_mfa_otp_purpose') !== $purpose
            || (int) $request->session()->get('amc_mfa_otp_until', 0) < time()
            || (int) $request->session()->get('amc_mfa_otp_attempts', 0) >= 5) return false;

        $attempts = (int) $request->session()->get('amc_mfa_otp_attempts', 0) + 1;
        $request->session()->put('amc_mfa_otp_attempts', $attempts);
        $hash = $request->session()->get('amc_mfa_otp_hash', '');
        if (! Hash::check($code, (string) $hash)) return false;

        if ($key = $request->session()->get('amc_mfa_otp_delivery_key')) Cache::forget($key);

        $request->session()->forget([
            'amc_mfa_otp_hash','amc_mfa_otp_until','amc_mfa_otp_attempts',
            'amc_mfa_otp_purpose','amc_mfa_otp_sent_at',
            'amc_mfa_otp_delivery_key',
        ]);
        return true;
    }

    public function rejectFactor(): never
    {
        throw ValidationException::withMessages(['code' => ['Invalid or expired verification code.']]);
    }
}
