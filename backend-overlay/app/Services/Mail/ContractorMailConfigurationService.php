<?php

namespace App\Services\Mail;

use App\Models\{AmcMailProvider, Customer};
use App\Support\{AmcAudit, ServiceResult};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};

/** Contractor-owned email provider provisioning, routing and audit use cases. */
final class ContractorMailConfigurationService
{
    public function __construct(private readonly ContractorMailService $transport) {}

    public function index(): ServiceResult
    {
        $settings = DB::table('amc_mail_settings')->where('id', 1)->first();
        return ServiceResult::ok([
            'providers' => AmcMailProvider::orderBy('name')->get()
                ->map(fn (AmcMailProvider $provider) => $provider->publicData()),
            'default_provider_id' => $settings?->default_provider_id,
            'notifications_enabled' => (bool) ($settings?->notifications_enabled ?? false),
        ], 'Email delivery settings loaded.');
    }

    public function createProvider(Request $request, array $data): ServiceResult
    {
        $this->verifyPassword($request, $data['current_password']);
        abort_if(AmcMailProvider::count() >= 25, 422, 'Maximum number of mail providers reached.');
        $this->assertCredentialShape($data['driver'], $data['credentials']);
        $provider = AmcMailProvider::create([
            'name' => $data['name'], 'driver' => $data['driver'],
            'from_email' => $data['from_email'], 'from_name' => $data['from_name'],
            'is_active' => $data['is_active'] ?? true,
            'credentials' => $data['credentials'],
            'created_by' => $request->user()->id,
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
            $merged = array_merge($provider->credentials ?? [], $data['credentials']);
            $this->assertCredentialShape($provider->driver, $merged);
            $changes['credentials'] = $merged;
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
        $this->verifyPassword($request, $data['current_password']);
        $providerId = $data['default_provider_id'] ?? null;
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
            'updated_by' => $request->user()->id, 'updated_at' => now(),
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
            'updated_by' => $request->user()->id, 'updated_at' => now(),
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
        $this->transport->sendTest($provider, $request->user()->email);
        AmcAudit::record($request, 'mail.provider.test', $provider, ['recipient' => 'own_account']);
        return ServiceResult::ok(message: 'Email provider accepted the test request. Confirm delivery separately.');
    }

    private function verifyPassword(Request $request, string $password): void
    {
        abort_unless(Hash::check($password, $request->user()->password),
            422, 'Current password is incorrect.');
    }

    private function requireActiveProvider(?int $id): void
    {
        if ($id !== null) {
            abort_unless(AmcMailProvider::whereKey($id)->where('is_active', true)->exists(),
                422, 'Selected provider is disabled.');
        }
    }

    private function assertNotInUse(AmcMailProvider $provider): void
    {
        abort_if(DB::table('amc_mail_settings')->where('default_provider_id', $provider->id)->exists()
            || DB::table('amc_client_mail_settings')->where('provider_id', $provider->id)->exists(),
            422, 'This provider is assigned. Reassign default and client delivery first.');
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
