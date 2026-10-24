<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\{MfaService, MfaSettings, TotpService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash};
use Illuminate\Validation\ValidationException;

final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly TotpService $totp,
        private readonly MfaSettings $settings,
    ) {}

    public function pendingStatus(Request $request)
    {
        $user = $this->mfa->pending($request);

        return response()->json($this->mfa->challenge($request, $user))
            ->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request)
    {
        $this->mfa->clearPending($request);
        $request->session()->regenerate();
        return response()->json(['message' => 'Sign-in cancelled.']);
    }

    /** In the challenge flow, only the account that passed password verification can enroll. */
    /** Allow only the password-verified pending user to enroll during MFA login. */
    public function setupTotp(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            $user = $this->mfa->pending($request);
            $remaining = $this->mfa->challenge($request, $user)['remaining_methods'];
            abort_unless(in_array('totp', $remaining, true), 403, 'Authenticator setup is not required.');
        } else {
            $this->requirePassword($request, $user);
        }

        abort_unless($this->settings->totp($user), 403, 'Authenticator is disabled by your administrator.');
        abort_if($user->mfa_totp_confirmed_at, 409, 'Google Authenticator is already enabled.');

        $secret = $this->totp->generateSecret();
        $user->forceFill(['mfa_totp_pending_secret' => $secret])->save();

        return response()->json([
            'secret' => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $user->email),
        ])->header('Cache-Control', 'no-store');
    }

    public function confirmTotp(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $pending = ! Auth::guard('web')->check();
        $user = $pending ? $this->mfa->pending($request) : $request->user();

        abort_unless($this->settings->totp($user), 403, 'Authenticator is disabled by your administrator.');
        abort_unless($user->mfa_totp_pending_secret && ! $user->mfa_totp_confirmed_at,
            409, 'Start authenticator enrollment first.');

        $matched = $this->totp->matchStep($user->mfa_totp_pending_secret, $data['code']);
        if ($matched === null) {
            $this->mfa->rejectFactor();
        }

        DB::transaction(function () use ($user, $matched): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->mfa_totp_pending_secret && ! $locked->mfa_totp_confirmed_at,
                409, 'Authenticator enrollment already completed.');
            $locked->mfa_totp_secret = $locked->mfa_totp_pending_secret;
            $locked->mfa_totp_pending_secret = null;
            $locked->mfa_totp_confirmed_at = now();
            $locked->mfa_last_totp_step = $matched;
            $locked->save();
        });

        $user->refresh();
        $recoveryCodes = $this->mfa->recoveryCodes($user);
        if ($pending) {
            $result = $this->mfa->completeFactor($request, $user, 'totp');
        } else {
            // This session already passed password + all previously mandatory factors.
            // Successful enrollment here proves the new authenticator, so mark it
            // verified in THIS session instead of needlessly signing in again.
            if ($request->hasSession()
                && (int) $request->session()->get('amc_mfa_verified_user_id', 0) === (int) $user->id) {
                $current = (array) $request->session()->get('amc_mfa_verified_methods', []);
                $request->session()->put('amc_mfa_verified_methods', array_values(array_unique([
                    ...$current, 'totp',
                ])));
            }
            $result = ['status' => 'enabled'];
        }

        return response()->json($result + ['recovery_codes' => $recoveryCodes])
            ->header('Cache-Control', 'no-store');
    }

    public function sendEmailLogin(Request $request)
    {
        $user = $this->mfa->pending($request);
        $remaining = $this->mfa->challenge($request, $user)['remaining_methods'];
        abort_unless(in_array('email', $remaining, true), 403, 'Email verification is not required.');

        $enrolled = (bool) ($user->mfa_email_enabled && $user->email_verified_at);
        $this->mfa->sendEmail($request, $user, $enrolled ? 'login' : 'login_enroll');

        return response()->json(['message' => 'Check your email for your verification code. It may take a moment to arrive.'])
            ->header('Cache-Control', 'no-store');
    }

    public function verifyLogin(Request $request)
    {
        $data = $request->validate([
            'method' => ['required', 'in:totp,email,recovery'],
            'code' => ['required', 'string', 'max:30'],
        ]);

        $user = $this->mfa->pending($request);
        $failureKey = 'amc:mfa:fail:'.$user->id.':'.$request->ip();
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($failureKey, 5),
            429, 'Too many attempts. Sign in again later.');

        $remaining = $this->mfa->challenge($request, $user)['remaining_methods'];
        $method = $data['method'];
        // Recovery replaces an authenticator code; when BOTH are required,
        // it never replaces the independent email step.
        // Backup recovery is ONLY a substitute for Google Authenticator.
        // Mandatory email verification cannot be replaced with a recovery code.
        $satisfied = $method === 'recovery'
            ? (in_array('totp', $remaining, true) ? 'totp' : null)
            : $method;
        abort_unless($satisfied && in_array($satisfied, $remaining, true), 403,
            'This verification step is not available.');
        if ($method === 'totp') {
            abort_unless($user->mfa_totp_confirmed_at && $user->mfa_totp_secret, 403,
                'Set up Google Authenticator first.');
        }

        $valid = match ($method) {
            'totp' => $this->mfa->consumeTotp($user, $data['code'], $this->totp),
            'email' => $this->mfa->consumeEmail($request, $data['code'],
                $user->mfa_email_enabled && $user->email_verified_at ? 'login' : 'login_enroll'),
            'recovery' => $this->mfa->consumeRecovery($user, $data['code']),
        };
        if (! $valid) {
            \Illuminate\Support\Facades\RateLimiter::hit($failureKey, 300);
            $this->mfa->rejectFactor();
        }
        \Illuminate\Support\Facades\RateLimiter::clear($failureKey);

        if ($method === 'email' && (! $user->mfa_email_enabled || ! $user->email_verified_at)) {
            // Successful email challenge proves possession of the registered mailbox.
            $user->forceFill(['mfa_email_enabled' => true, 'email_verified_at' => now()])->save();
        }

        return response()->json($this->mfa->completeFactor($request, $user, $satisfied))
            ->header('Cache-Control', 'no-store');
    }

    public function status(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'totp_enabled' => (bool) $user->mfa_totp_confirmed_at,
            'email_enabled' => (bool) $user->mfa_email_enabled,
            'email_verified' => (bool) $user->email_verified_at,
            'required' => $this->mfa->required($user),
            'available' => $this->settings->forUser($user),
            'recovery_remaining' => count($user->mfa_recovery_codes ?? []),
        ])->header('Cache-Control', 'no-store');
    }

    public function sendEmailEnrollment(Request $request)
    {
        abort_unless($this->settings->email($request->user()), 403, 'Email OTP is disabled by your administrator.');
        $this->requirePassword($request, $request->user());
        abort_if($request->user()->mfa_email_enabled, 409, 'Email MFA is already enabled.');
        $this->mfa->sendEmail($request, $request->user(), 'enroll_email');
        return response()->json(['message' => 'Check your email for your verification code. It may take a moment to arrive.']);
    }

    public function confirmEmailEnrollment(Request $request)
    {
        abort_unless($this->settings->email($request->user()), 403, 'Email OTP is disabled by your administrator.');
        $data = $request->validate(['code' => ['required','string', 'regex:/^\d{6}$/D']]);
        if (! $this->mfa->consumeEmail($request, $data['code'], 'enroll_email')) $this->mfa->rejectFactor();
        $request->user()->forceFill([
            'mfa_email_enabled' => true,
            'email_verified_at' => $request->user()->email_verified_at ?? now(),
        ])->save();
        $request->session()->put('amc_mfa_verified_user_id', $request->user()->id);
        // Recovery codes replace Google Authenticator only; they cannot replace Email OTP.
        $user = $request->user();
        $recovery = $user->mfa_totp_confirmed_at && $user->mfa_totp_secret && empty($user->mfa_recovery_codes)
            ? $this->mfa->recoveryCodes($user) : [];
        return response()->json(['message' => 'Email OTP is enabled.', 'recovery_codes' => $recovery])
            ->header('Cache-Control', 'no-store');
    }

    public function disable(Request $request)
    {
        $data = $request->validate([
            'method' => ['required','in:totp,email'],
            'password' => ['required','string', 'max:1024'],
            'code' => ['required','string','max:30'],
        ]);
        $user = $request->user();
        abort_unless(Hash::check($data['password'], $user->password), 422, 'Password is incorrect.');
        $methods = $this->mfa->methods($user);
        abort_unless($data['method'] === 'totp' ? (bool) $user->mfa_totp_confirmed_at : (bool) $user->mfa_email_enabled,
            422, 'MFA method is not enabled for this account.');
        // Google is optional for business users and may be removed with proof.
        // Email is required by admin policy; users cannot opt out themselves.
        // Super Admin must not remove ANY factor required by technical policy.
        abort_if($data['method'] === 'email' && $this->settings->email($user), 422,
            'Email OTP is required by your administrator and cannot be disabled here.');
        abort_if($user->role === 'super_admin'
            && in_array($data['method'], $this->mfa->requiredMethods($user), true),
            422, 'Change the technical authentication policy before removing this method.');

        // Removing an authenticator requires its code or a one-time recovery code.
        // Removing email requires the authenticator if available, otherwise a valid email OTP.
        if ($data['method'] === 'totp') {
            $valid = $this->mfa->consumeTotp($user, $data['code'], $this->totp)
                || $this->mfa->consumeRecovery($user, $data['code']);
        } else {
            $valid = $user->mfa_totp_confirmed_at
                ? $this->mfa->consumeTotp($user, $data['code'], $this->totp)
                : ($this->mfa->consumeEmail($request, $data['code'], 'disable_email')
                    || $this->mfa->consumeRecovery($user, $data['code']));
        }
        if (! $valid) $this->mfa->rejectFactor();
        if ($data['method'] === 'totp') {
            $user->forceFill([
                'mfa_totp_secret' => null,
                'mfa_totp_pending_secret' => null,
                'mfa_totp_confirmed_at' => null,
                'mfa_last_totp_step' => null,
                'mfa_recovery_codes' => null,
            ])->save();
        } else {
            $user->forceFill(['mfa_email_enabled' => false])->save();
        }
        return response()->json(['message' => 'Sign-in verification method removed.']);
    }

    public function sendEmailDisable(Request $request)
    {
        $this->requirePassword($request, $request->user());
        abort_unless($request->user()->mfa_email_enabled, 409, 'Email MFA is not enabled.');
        abort_if($this->settings->email($request->user()), 422,
            'Email OTP is required by your administrator and cannot be disabled here.');
        $this->mfa->sendEmail($request, $request->user(), 'disable_email');
        return response()->json(['message' => 'Check your email for your verification code. It may take a moment to arrive.']);
    }

    private function requirePassword(Request $request, User $user): void
    {
        $data = $request->validate(['password' => ['required','string','max:1024']]);
        abort_unless(Hash::check($data['password'], $user->password), 422, 'Current password is incorrect.');
    }
}
