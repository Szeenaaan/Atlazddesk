<?php

namespace App\Services\System;

use App\Models\{TechnicalMailSetting, User};
use App\Services\Mail\ContractorMailService;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\ValidationException;

/** Isolated per-installation technical account settings; does not govern contractor/client MFA. */
final class TechnicalSecurityService
{
    public function __construct(private readonly ContractorMailService $delivery) {}

    public function flags(): array
    {
        $values = DB::table('amc_installation_settings')
            ->whereIn('key', ['technical_totp_available', 'technical_email_available'])
            ->pluck('value', 'key');

        // Preserve active Super Admin MFA during upgrades rather than silently bypassing it.
        // The explicit technical switches replace these legacy-derived values after first save.
        $legacy = DB::table('amc_security_settings')
            ->whereIn('key', ['mfa_totp_enabled', 'mfa_email_enabled'])
            ->pluck('enabled', 'key');
        $totpLegacy = (bool) ($legacy['mfa_totp_enabled'] ?? false)
            && User::where('role', 'super_admin')->whereNotNull('mfa_totp_confirmed_at')->exists();
        $emailLegacy = (bool) ($legacy['mfa_email_enabled'] ?? false)
            && User::where('role', 'super_admin')->where('mfa_email_enabled', true)->exists();

        return [
            'totp_enabled' => array_key_exists('technical_totp_available', $values->all())
                ? $values['technical_totp_available'] === '1' : $totpLegacy,
            'email_enabled' => array_key_exists('technical_email_available', $values->all())
                ? $values['technical_email_available'] === '1' : $emailLegacy,
            'require_mfa' => false,
        ];
    }

    public function publicConfiguration(): array
    {
        $mail = TechnicalMailSetting::find(1);
        return [
            'mfa' => $this->flags(),
            'mail' => [
                'enabled' => (bool) ($mail?->enabled ?? false),
                'driver' => $mail?->driver,
                'from_email' => $mail?->from_email,
                'from_name' => $mail?->from_name,
                'credential_configured' => ! empty($mail?->credentials),
            ],
        ];
    }

    public function mailReady(): bool
    {
        $setting = TechnicalMailSetting::find(1);
        return (bool) ($setting?->enabled && $setting->driver && ! empty($setting->credentials));
    }

    public function updateMfa(Request $request, array $data): array
    {
        $user = $request->user();
        $this->confirmPassword($user, $data['current_password']);
        $old = $this->flags();
        $nextTotp = $data['totp_enabled'] ?? $old['totp_enabled'];
        $nextEmail = $data['email_enabled'] ?? $old['email_enabled'];

        if ($nextEmail && ! $this->mailReady()) {
            throw ValidationException::withMessages(['email_enabled' => ['Configure and enable a technical email provider before allowing Email OTP.']]);
        }
        // Technical policy and personal authenticator enrollment are different settings.
        // Do not require removing the authenticator first: the account-level removal
        // endpoint correctly prevents removal while policy still mandates the method.
        // A policy downgrade affecting an enrolled Super Admin requires this administrator
        // to have an existing, fully MFA-verified session AND to re-enter their password.
        $turningOffEnrolledTotp = $old['totp_enabled'] && ! $nextTotp
            && User::where('role', 'super_admin')->whereNotNull('mfa_totp_confirmed_at')->exists();
        $turningOffEnrolledEmail = $old['email_enabled'] && ! $nextEmail
            && User::where('role', 'super_admin')->where('mfa_email_enabled', true)->exists();
        if ($turningOffEnrolledTotp || $turningOffEnrolledEmail) {
            $verifiedMethods = $request->hasSession()
                ? (array) $request->session()->get('amc_mfa_verified_methods', []) : [];
            $verifiedSession = $request->hasSession()
                && (int) $request->session()->get('amc_mfa_verified_user_id', 0) === (int) $user->id
                && (int) $request->session()->get('amc_mfa_verified_security_version', -1)
                    === (int) $user->mfa_security_version
                && (! $turningOffEnrolledTotp || in_array('totp', $verifiedMethods, true))
                && (! $turningOffEnrolledEmail || in_array('email', $verifiedMethods, true));
            abort_unless($verifiedSession, 403,
                'Sign in again and complete the currently required MFA steps before changing the technical policy.');
        }
        if (in_array('super_admin', config('amc_mfa.required_roles', []), true) && ! $nextTotp) {
            throw ValidationException::withMessages(['totp_enabled' => ['This installation requires Google Authenticator availability for Super Admin enrollment.']]);
        }

        DB::transaction(function () use ($request, $data, $old): void {
            foreach (['totp_enabled' => 'technical_totp_available', 'email_enabled' => 'technical_email_available'] as $field => $key) {
                if (! array_key_exists($field, $data)) continue;
                DB::table('amc_installation_settings')->updateOrInsert(['key' => $key], [
                    'value' => $data[$field] ? '1' : '0',
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
            AmcAudit::record($request, 'system.technical_mfa.updated', $request->user(), [
                'previous' => $old,
                'changed_keys' => array_values(array_intersect(['totp_enabled', 'email_enabled'], array_keys($data))),
            ]);
        });

        return $this->flags();
    }

    public function updateMail(Request $request, array $data): array
    {
        $this->confirmPassword($request->user(), $data['current_password']);
        $current = TechnicalMailSetting::find(1);
        $enabled = (bool) $data['enabled'];
        $driver = $data['driver'] ?? $current?->driver;
        $config = $data['credentials'] ?? null;
        if ($config === null && $driver === $current?->driver) {
            $config = $current?->credentials;
        }
        if ($enabled && (! $driver || ! $config || ! $data['from_email'] || ! $data['from_name'])) {
            throw ValidationException::withMessages(['enabled' => ['Enter a valid provider, sender and credentials before enabling delivery.']]);
        }
        if ($enabled && $driver === 'smtp' && (empty($config['host']) || empty($config['port']))) {
            throw ValidationException::withMessages(['credentials' => ['SMTP host and port are required.']]);
        }
        if ($enabled && in_array($driver, ['resend', 'postmark'], true) && empty($config['api_key'])) {
            throw ValidationException::withMessages(['credentials' => ['Provider API key is required.']]);
        }
        if (! $enabled && (bool) $this->flags()['email_enabled']) {
            throw ValidationException::withMessages(['enabled' => ['Disable technical Email OTP before disabling the email provider.']]);
        }

        DB::transaction(function () use ($request, $data, $driver, $config, $enabled): void {
            TechnicalMailSetting::updateOrCreate(['id' => 1], [
                'driver' => $driver,
                'enabled' => $enabled,
                'from_email' => $data['from_email'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'credentials' => $config,
                'updated_by' => $request->user()->id,
            ]);
            AmcAudit::record($request, 'system.technical_mail.updated', $request->user(), [
                'driver' => $driver,
                'enabled' => $enabled,
            ]);
        });

        return $this->publicConfiguration()['mail'];
    }

    public function sendSecurityCode(User $user, string $code): void
    {
        $this->deliver($user->email, 'AtlasDesk verification code',
            'Your AtlasDesk verification code is: '.$code.'. It expires in five minutes. Do not share it.');
    }

    public function sendTest(Request $request): void
    {
        $this->confirmPassword($request->user(), $request->input('current_password', ''));
        $this->deliver($request->user()->email, 'AtlasDesk technical delivery test',
            'Your technical mail provider accepted a delivery test. No customer data is included.');
        AmcAudit::record($request, 'system.technical_mail.test_accepted', $request->user());
    }

    private function deliver(string $recipient, string $subject, string $text): void
    {
        $setting = TechnicalMailSetting::find(1);
        abort_unless($setting?->enabled && $setting->credentials, 503,
            'Technical email provider is not configured.');

        // Reuse the validated SMTP/Resend/Postmark transport, not contractor/client routing.
        $provider = new \App\Models\AmcMailProvider();
        $provider->forceFill([
            'driver' => $setting->driver,
            'from_email' => $setting->from_email,
            'from_name' => $setting->from_name,
            'credentials' => $setting->credentials,
            'is_active' => true,
        ]);
        $this->delivery->sendWithProvider($provider, $recipient, $subject, $text);
    }

    private function confirmPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }
    }
}
