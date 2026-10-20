<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings, TotpService};
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

/** Replace an already-enrolled authenticator without ever temporarily disabling MFA. */
final class AuthenticatorReplacementController extends Controller
{
    private const SESSION_KEYS = [
        'amc_totp_replace_user', 'amc_totp_replace_digest',
        'amc_totp_replace_until', 'amc_totp_replace_version',
    ];

    public function __construct(
        private readonly MfaService $mfa,
        private readonly MfaSettings $settings,
        private readonly TotpService $totp,
    ) {}

    private function account(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $request->hasSession()
            && (int) $request->session()->get('amc_mfa_verified_user_id', 0) === (int) $user->id
            && (int) $request->session()->get('amc_mfa_verified_security_version', 0) === (int) $user->mfa_security_version,
            403, 'A fully verified browser session is required.');
        abort_unless($this->settings->totp($user), 403, 'Authenticator is disabled by your administrator.');
        abort_unless($user->mfa_totp_confirmed_at && $user->mfa_totp_secret,
            409, 'Enroll an authenticator before trying to replace it.');
        return $user;
    }

    /** Password AND possession of the old authenticator/recovery code are required. */
    public function start(Request $request)
    {
        $user = $this->account($request);
        $data = $request->validate([
            'password' => ['required', 'string', 'max:1024'],
            'current_code' => ['required', 'string', 'max:30'],
        ]);
        $key = 'amc:totp:replace:start:'.$user->id.':'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again later.');
        $passwordValid = Hash::check($data['password'], $user->password);
        $factorValid = false;
        if ($passwordValid) {
            $factorValid = $this->mfa->consumeTotp($user, $data['current_code'], $this->totp)
                || $this->mfa->consumeRecovery($user, $data['current_code']);
        }
        if (! $passwordValid || ! $factorValid) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages([
                'current_code' => ['Current password or authenticator/recovery code is incorrect.'],
            ]);
        }
        RateLimiter::clear($key);

        // Do not change the active authenticator. Pending secrets are encrypted at rest.
        $secret = $this->totp->generateSecret();
        $user->forceFill(['mfa_totp_pending_secret' => $secret])->save();
        $request->session()->put([
            'amc_totp_replace_user' => $user->id,
            'amc_totp_replace_digest' => hash('sha256', $secret),
            'amc_totp_replace_until' => now()->addMinutes(10)->timestamp,
            'amc_totp_replace_version' => (int) $user->mfa_security_version,
        ]);
        AmcAudit::record($request, 'security.authenticator_replacement_started', $user);

        return response()->json([
            'provisioning_uri' => $this->totp->provisioningUri($secret, $user->email),
            'secret' => $secret,
            'expires_in_seconds' => 600,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** A valid code from the NEW device is required before the old key is retired. */
    public function confirm(Request $request)
    {
        $user = $this->account($request);
        $input = $request->validate(['code' => ['required', 'regex:/^\d{6}$/D']]);
        $session = $request->session();
        abort_unless((int) $session->get('amc_totp_replace_user', 0) === (int) $user->id
            && (int) $session->get('amc_totp_replace_until', 0) >= time()
            && (int) $session->get('amc_totp_replace_version', -1) === (int) $user->mfa_security_version,
            409, 'Replacement expired. Start again using your existing authenticator.');

        $key = 'amc:totp:replace:confirm:'.$user->id.':'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again later.');
        $digest = (string) $session->get('amc_totp_replace_digest', '');
        $codes = DB::transaction(function () use ($request, $user, $input, $digest): ?array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $secret = $locked->mfa_totp_pending_secret;
            abort_unless($secret && $digest !== '' && hash_equals(hash('sha256', $secret), $digest)
                && (int) $locked->mfa_security_version === (int) $user->mfa_security_version
                && $locked->mfa_totp_confirmed_at && $locked->mfa_totp_secret,
                409, 'Authenticator replacement no longer matches this session. Start again.');
            $step = $this->totp->matchStep($secret, $input['code']);
            if ($step === null) return null;

            $locked->forceFill([
                'mfa_totp_secret' => $secret,
                'mfa_totp_pending_secret' => null,
                'mfa_totp_confirmed_at' => now(),
                'mfa_last_totp_step' => $step,
                'mfa_security_version' => (int) $locked->mfa_security_version + 1,
            ])->save();
            $issued = $this->mfa->recoveryCodes($locked); // Invalidates all older codes.
            AmcAudit::record($request, 'security.authenticator_replaced', $locked, [
                'previous_sessions_revoked' => true, 'recovery_codes_rotated' => true,
            ]);
            return $issued;
        });

        if ($codes === null) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['code' => ['Invalid new authenticator code.']]);
        }
        RateLimiter::clear($key);
        $user->refresh();
        $session->forget(self::SESSION_KEYS);
        // The new key was verified in THIS session; all older sessions stay revoked.
        $session->put('amc_mfa_verified_security_version', (int) $user->mfa_security_version);
        $session->put('amc_mfa_verified_methods', array_values(array_unique([
            ...(array) $session->get('amc_mfa_verified_methods', []), 'totp',
        ])));

        return response()->json([
            'message' => 'Authenticator replaced. Previous device and recovery codes are invalid.',
            'recovery_codes' => $codes,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Cancelling never touches the old device or its existing recovery codes. */
    public function cancel(Request $request)
    {
        $user = $this->account($request);
        $digest = (string) $request->session()->get('amc_totp_replace_digest', '');
        if ((int) $request->session()->get('amc_totp_replace_user', 0) === (int) $user->id && $digest !== '') {
            DB::transaction(function () use ($user, $digest): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                if ($locked->mfa_totp_pending_secret
                    && hash_equals(hash('sha256', $locked->mfa_totp_pending_secret), $digest)) {
                    $locked->forceFill(['mfa_totp_pending_secret' => null])->save();
                }
            });
        }
        $request->session()->forget(self::SESSION_KEYS);
        return response()->json(['message' => 'Replacement cancelled. Your existing authenticator is unchanged.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
