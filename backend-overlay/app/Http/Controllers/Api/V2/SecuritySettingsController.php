<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Auth\MfaSettings;
use App\Services\Mail\ContractorMailService;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

final class SecuritySettingsController extends Controller
{
    public function __construct(
        private readonly MfaSettings $settings,
        private readonly ContractorMailService $mail,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => $this->settings->all()])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'totp_enabled' => ['sometimes', 'required', 'boolean'],
            'email_enabled' => ['sometimes', 'required', 'boolean'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
        abort_if(! array_key_exists('totp_enabled', $data) && ! array_key_exists('email_enabled', $data),
            422, 'Choose at least one setting.');
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422,
            'Current password is incorrect.');

        // Do not let an administrator enable email OTP when it would print codes to application logs.
        if (($data['email_enabled'] ?? false) && ! $this->mail->hasProvider(null)) {
            abort(422, 'Configure an active default email provider before enabling Email OTP.');
        }
        // Prevent an installation-wide required-role policy from becoming impossible to enroll.
        $nextTotp = $data['totp_enabled'] ?? $this->settings->totp();
        abort_if(config('amc_mfa.required_roles', []) !== [] && ! $nextTotp, 422,
            'Google Authenticator must remain enabled while any staff role requires MFA.');
        $before = $this->settings->all();
        DB::transaction(function () use ($request, $data, $before): void {
            foreach (['totp_enabled' => 'mfa_totp_enabled', 'email_enabled' => 'mfa_email_enabled'] as $field => $key) {
                if (! array_key_exists($field, $data)) continue;
                DB::table('amc_security_settings')->updateOrInsert(['key' => $key], [
                    'enabled' => (bool) $data[$field], 'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
            // No code, secret, SMTP password or TOTP provisioning URI appears in the audit record.
            $this->settings->invalidate();
            AmcAudit::record($request, 'security.mfa_settings.updated', $request->user(), [
                'previous' => $before, 'current' => $this->settings->all(),
            ]);
        });
        return response()->json(['data' => $this->settings->all()])->header('Cache-Control', 'no-store');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
