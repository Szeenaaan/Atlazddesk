<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{AmcMailProvider, Customer};
use App\Services\Mail\ContractorMailConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HTTP adapter only: authorization, input validation, service delegation and response. */
final class ContractorMailSettingsController extends Controller
{
    public function __construct(private readonly ContractorMailConfigurationService $service) {}

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return ApiResponse::from($this->service->index(), true);
    }

    public function createProvider(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->providerRules(true));
        return ApiResponse::from($this->service->createProvider($request, $data), true);
    }

    public function updateProvider(Request $request, AmcMailProvider $provider)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->providerRules(false));
        return ApiResponse::from($this->service->updateProvider($request, $provider, $data), true);
    }

    public function removeProvider(Request $request, AmcMailProvider $provider)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['current_password' => ['required', 'string']]);
        return ApiResponse::from($this->service->removeProvider($request, $provider, $data['current_password']), true);
    }

    public function setDefault(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'default_provider_id' => ['nullable', 'integer', 'exists:amc_mail_providers,id'],
            'notifications_enabled' => ['required', 'boolean'],
            'current_password' => ['required', 'string'],
        ]);
        return ApiResponse::from($this->service->setDefault($request, $data), true);
    }

    public function client(Request $request, Customer $customer)
    {
        $this->authorizeAdmin($request);
        return ApiResponse::from($this->service->client($customer), true);
    }

    public function saveClient(Request $request, Customer $customer)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:amc_mail_providers,id'],
            'notifications_enabled' => ['nullable', 'boolean'],
            'current_password' => ['required', 'string'],
        ]);
        return ApiResponse::from($this->service->saveClient($request, $customer, $data), true);
    }

    public function testProvider(Request $request, AmcMailProvider $provider)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['current_password' => ['required', 'string']]);
        return ApiResponse::from($this->service->testProvider($request, $provider, $data['current_password']), true);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }

    private function providerRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return [
            'current_password' => ['required', 'string'],
            'name' => [$required, 'string', 'max:120'],
            'driver' => [$required, Rule::in(['smtp', 'resend', 'postmark'])],
            'from_email' => [$required, 'email', 'max:255'],
            'from_name' => [$required, 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array:host,port,encryption,username,password,api_key'],
            'credentials.host' => ['sometimes', 'string', 'max:255'],
            'credentials.port' => ['sometimes', 'integer', 'between:1,65535'],
            'credentials.encryption' => ['sometimes', Rule::in(['tls', 'ssl'])],
            'credentials.username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'credentials.password' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'credentials.api_key' => ['sometimes', 'string', 'max:2048'],
        ];
    }
}
