<?php

namespace App\Services\Mail;

use App\Models\{AmcMailProvider, Customer};
use App\Support\{AmcAudit, ServiceResult};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Schema};

/** Contractor-owned email provider provisioning, routing and audit use cases. */
final class ContractorMailConfigurationService
{
    public function __construct(private readonly ContractorMailService $transport) {}

    public function index(): ServiceResult
    {
        if ($error = $this->mailSchemaError()) return $error;
        $settings = DB::table('amc_mail_settings')->where('id', 1)->first();
        return ServiceResult::ok([
            'providers' => AmcMailProvider::orderBy('name')->get()
                ->map(fn (AmcMailProvider $provider) => $provider->publicData()),
            'default_provider_id' => $settings?->default_provider_id,
            'notifications_enabled' => (bool) ($settings?->notifications_enabled ?? false),
            'notification_email' => $settings?->notification_email,
            'last_ticket_delivery' => DB::table('amc_ticket_mail_deliveries')->latest('id')
                ->first(['status', 'attempts', 'sent_at', 'failure_category', 'updated_at']),
        ], 'Email delivery settings loaded.');
    }

    public function createProvider(Request $request, array $data): ServiceResult
    {
        $this->verifyPassword($request, $data['current_password']);
        abort_if(AmcMailProvider::count() >= 25, 422, 'Maximum number of mail providers reached.');
        $this->assertCredentialShape($data['driver'], $data['credentials']);
        if ($data['driver'] === 'smtp') app(SmtpCredentialVerifier::class)->verify($data['credentials']);
        $provider = AmcMailProvider::create([
            'name' => $data['name'], 'driver' => $data['driver'],
            'from_email' => $data['from_email'], 'from_name' => $data['from_name'],
            'is_active' => $data['is_active'] ?? true,
            'credentials' => $data['credentials'],
            'created_by' => \App\Support\AmcAudit::actorId($request),
        ]);
        AmcAudit::record($request, 'mail.provider.created', $provider, ['driver' => $provider->driver]);
        return ServiceResult::ok($provider->publicData(), 'Email provider saved.', 201);
    }

    public function updateProvider(Request $request, AmcMailProvider $provider, array $data): ServiceResult
    {
        $this->verifyPassword($request, $data['current_password']);
        abort_if(isset($data['driver']) && $data['driver'] !== $provider->driver, 422,
            'Create a new provider to change its delivery type.');
        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $this->assertNotInUse($provider);
        }
        $changes = array_intersect_key($data, array_flip(['name', 'from_email', 'from_name', 'is_active']));
        if (! empty($data['credentials'])) {
            // Blank secret means preserve the encrypted saved secret, including null from middleware.
            foreach (['password', 'api_key'] as $secret) {
                if (array_key_exists($secret, $data['credentials']) && ($data['credentials'][$secret] === null || $data['credentials'][$secret] === '')) {
                    unset($data['credentials'][$secret]);
                }
            }
            $merged = array_merge($provider->credentials ?? [], $data['credentials']);
            $this->assertCredentialShape($provider->driver, $merged);
            $changes['credentials'] = $merged;
        }
        if ($provider->driver === 'smtp' && (isset($changes['credentials']) || ($data['is_active'] ?? false))) {
            app(SmtpCredentialVerifier::class)->verify($changes['credentials'] ?? $provider->credentials ?? []);
        }
        $provider->update($changes);
        AmcAudit::record($request, 'mail.provider.updated', $provider, ['driver' => $provider->driver]);
        return ServiceResult::ok($provider->publicData(), 'Email provider updated.');
    }

    public function removeProvider(Request $request, AmcMailProvider $provider, string $password): ServiceResult
    {
        $this->verifyPassword($request, $password);
        $this->assertNotInUse($provider);
        AmcAudit::record($request, 'mail.provider.deleted', $provider, ['driver' => $provider->driver]);
        $provider->delete();
        return ServiceResult::ok(message: 'Email provider deleted.');
    }

    public function setDefault(Request $request, array $data): ServiceResult
    {
        if ($error = $this->mailSchemaError()) return $error;
        $this->verifyPassword($request, $data['current_password']);
        $providerId = $data['default_provider_id'] ?? null;
        $inbox = array_key_exists('notification_email', $data) ? $data['notification_email']
            : DB::table('amc_mail_settings')->where('id', 1)->value('notification_email');
        abort_if($data['notifications_enabled'] && ! filter_var($inbox ?? '', FILTER_VALIDATE_EMAIL),
            422, 'Enter the shared notification inbox before enabling ticket emails.');
        if ($data['notifications_enabled'] && ! $providerId) {
            abort(422, 'Select a default provider before enabling email notifications.');
        }
        $this->requireActiveProvider($providerId);
        if (! $providerId) {
            // Do not silently strand accounts with Email OTP enabled or enabled client routing.
            $emailMfa = DB::table('amc_security_settings')
                ->where('key', 'mfa_email_enabled')->where('enabled', true)->exists();
            $clientEmailMfaWithoutOverride = DB::table('amc_client_mfa_policies as m')
                ->leftJoin('amc_client_mail_settings as r', 'r.customer_id', '=', 'm.customer_id')
                ->where('m.email_enabled', true)->whereNull('r.provider_id')->exists();
            $userEmailMfaWithoutOverride = DB::table('amc_user_mfa_policies as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->leftJoin('amc_client_mail_settings as r', 'r.customer_id', '=', 'u.customer_id')
                ->where('p.email_enabled', true)->whereNull('r.provider_id')->exists();
            $clientEmailNotificationsWithoutOverride = DB::table('amc_client_mail_settings')
                ->where('notifications_enabled', true)->whereNull('provider_id')->exists();
            abort_if($emailMfa || $clientEmailMfaWithoutOverride || $userEmailMfaWithoutOverride
                || $clientEmailNotificationsWithoutOverride, 422,
                'Assign replacement providers or disable dependent email policies before removing the default.');
        }
        DB::table('amc_mail_settings')->updateOrInsert(['id' => 1], [
            'default_provider_id' => $providerId,
            'notifications_enabled' => $data['notifications_enabled'],
            'notification_email' => $inbox,
            'updated_by' => \App\Support\AmcAudit::actorId($request), 'updated_at' => now(),
        ]);
        AmcAudit::record($request, 'mail.default.updated', $request->user(), [
            'provider_id' => $providerId,
            'notifications_enabled' => $data['notifications_enabled'],
        ]);
        return $this->index();
    }

    public function client(Customer $customer): ServiceResult
    {
        $setting = DB::table('amc_client_mail_settings')->where('customer_id', $customer->id)->first();
        return ServiceResult::ok([
            'customer_id' => $customer->id,
            'provider_id' => $setting?->provider_id,
            'notifications_enabled' => $setting?->notifications_enabled === null
                ? null : (bool) $setting->notifications_enabled,
            'effective_provider_id' => $this->transport->providerFor($customer->id)?->id,
            'effective_notifications_enabled' => $this->transport->notificationsEnabled($customer->id),
        ], 'Client email settings loaded.');
    }

    public function saveClient(Request $request, Customer $customer, array $data): ServiceResult
    {
        $this->verifyPassword($request, $data['current_password']);
        $providerId = $data['provider_id'] ?? null;
        $this->requireActiveProvider($providerId);
        $effectiveId = $providerId ?? DB::table('amc_mail_settings')->where('id', 1)->value('default_provider_id');
        if (($data['notifications_enabled'] ?? null) === true) {
            abort_unless($effectiveId && AmcMailProvider::whereKey($effectiveId)->where('is_active', true)->exists(),
                422, 'Select a delivery provider before enabling client email notifications.');
        }
        if (! $effectiveId) {
            $emailMfa = DB::table('amc_client_mfa_policies')
                ->where('customer_id', $customer->id)->where('email_enabled', true)->exists();
            $emailOverride = DB::table('amc_user_mfa_policies as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('u.customer_id', $customer->id)->where('p.email_enabled', true)->exists();
            abort_if($emailMfa || $emailOverride, 422,
                'A delivery provider is required while client Email OTP is enabled.');
        }
        DB::table('amc_client_mail_settings')->updateOrInsert(['customer_id' => $customer->id], [
            'provider_id' => $providerId,
            'notifications_enabled' => $data['notifications_enabled'] ?? null,
            'updated_by' => \App\Support\AmcAudit::actorId($request), 'updated_at' => now(),
        ]);
        AmcAudit::record($request, 'mail.client_route.updated', $customer, [
            'provider_id' => $providerId,
            'notifications_enabled' => $data['notifications_enabled'] ?? null,
        ]);
        return $this->client($customer);
    }

    public function testProvider(Request $request, AmcMailProvider $provider, string $password): ServiceResult
    {
        $this->verifyPassword($request, $password);
        abort_unless($provider->is_active, 422, 'Enable this provider before testing it.');
        // Only the Admin's verified account email receives test messages.
        $user = $request->user();
        if ($provider->driver === 'smtp') app(SmtpCredentialVerifier::class)->verify($provider->credentials ?? []);
        \App\Jobs\SendAccountEmail::dispatch($user->id, $user->email, $user->role,
            (int) $user->mfa_security_version, 'business_test', $provider->id)->afterCommit();
        AmcAudit::record($request, 'mail.provider.test', $provider, ['recipient' => 'own_account']);
        return ServiceResult::ok(message: 'Check your email for the test message. It may take a moment to arrive.');
    }

    private function verifyPassword(Request $request, string $password): void
    {
        abort_unless(Hash::check($password, $request->user()->password),
            422, 'Current password is incorrect.');
    }

    private function mailSchemaError(): ?ServiceResult
    {
        if (Schema::hasColumn('amc_mail_settings', 'notification_email')
            && Schema::hasTable('amc_ticket_mail_deliveries')) return null;
        return new ServiceResult(false, 503,
            'Email settings are unavailable because the server update is incomplete. Contact your server administrator to finish the update.');
    }

    private function requireActiveProvider(?int $id): void
    {
        if ($id !== null) {
            abort_unless(AmcMailProvider::whereKey($id)->where('is_active', true)->exists(),
                422, 'Selected provider is disabled.');
            $provider = AmcMailProvider::findOrFail($id);
            if ($provider->driver === 'smtp') app(SmtpCredentialVerifier::class)->verify($provider->credentials ?? []);
        }
    }

    private function assertNotInUse(AmcMailProvider $provider): void
    {
        abort_if(DB::table('amc_mail_settings')->where('default_provider_id', $provider->id)->exists()
            || DB::table('amc_client_mail_settings')->where('provider_id', $provider->id)->exists(),
            422, 'This provider is used for email delivery. Select a replacement in the default and customer email settings before disabling or deleting it.');
    }

    private function assertCredentialShape(string $driver, array $credentials): void
    {
        if ($driver === 'smtp') {
            abort_unless(! empty($credentials['host']) && ! empty($credentials['port']),
                422, 'SMTP host and port are required.');
        } else {
            abort_unless(! empty($credentials['api_key']), 422, 'A provider API key is required.');
        }
    }
}
