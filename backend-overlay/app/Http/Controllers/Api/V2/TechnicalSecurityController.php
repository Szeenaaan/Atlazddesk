<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\TechnicalSecurityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Per-installation Super Admin settings; client/workforce settings remain contractor-owned. */
final class TechnicalSecurityController extends Controller
{
    public function __construct(private readonly TechnicalSecurityService $security) {}

    public function index(Request $request)
    {
        $this->authorizeTechnical($request);
        return $this->respond('Account security settings loaded.', $this->security->publicConfiguration());
    }

    public function updateMfa(Request $request)
    {
        $this->authorizeTechnical($request);
        $data = $request->validate([
            'totp_enabled' => ['sometimes', 'required', 'boolean'],
            'email_enabled' => ['sometimes', 'required', 'boolean'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
        abort_if(! array_key_exists('totp_enabled', $data) && ! array_key_exists('email_enabled', $data), 422,
            'Select at least one setting.');
        return $this->respond('Sign-in security settings saved.', $this->security->updateMfa($request, $data));
    }

    public function updateMail(Request $request)
    {
        $this->authorizeTechnical($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'driver' => ['required', Rule::in(['smtp', 'resend', 'postmark'])],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'credentials' => ['sometimes', 'array:host,port,encryption,username,password,api_key'],
            'credentials.host' => ['sometimes', 'string', 'max:255'],
            'credentials.port' => ['sometimes', 'integer', 'between:1,65535'],
            'credentials.encryption' => ['sometimes', Rule::in(['tls', 'ssl'])],
            'credentials.username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'credentials.password' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'credentials.api_key' => ['sometimes', 'string', 'max:2048'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);
        return $this->respond('Email settings saved.', $this->security->updateMail($request, $data));
    }

    public function testMail(Request $request)
    {
        $this->authorizeTechnical($request);
        $request->validate(['current_password' => ['required', 'string', 'max:1024']]);
        $this->security->sendTest($request);
        return $this->respond('Check your email for the test message. It may take a moment to arrive.');
    }

    private function authorizeTechnical(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
    }

    private function respond(string $message, ?array $data = null)
    {
        $payload = ['success' => true, 'statuscode' => 200, 'message' => $message];
        if ($data !== null) $payload['data'] = $data;
        return response()->json($payload)->header('Cache-Control', 'no-store');
    }
}
