<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\RequestTelemetryService;
use App\Support\{ApiResponse, ServiceResult};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Super Admin only: safe, per-installation request telemetry and bounded profiling. */
final class SystemTelemetryController extends Controller
{
    public function __construct(private readonly RequestTelemetryService $telemetry) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeTechnical($request);
        $input = $request->validate(['hours' => ['sometimes', 'integer', 'min:1', 'max:168']]);

        return ApiResponse::from(ServiceResult::ok(
            $this->telemetry->performance((int) ($input['hours'] ?? 24)),
            'Request metrics and traces retrieved.',
        ), true);
    }

    public function trace(Request $request, string $traceId): JsonResponse
    {
        $this->authorizeTechnical($request);
        abort_unless((bool) preg_match('/^[a-f0-9]{32}$/', $traceId), 404);

        return ApiResponse::from(ServiceResult::ok(
            $this->telemetry->trace($traceId),
            'Trace observations retrieved.',
        ), true);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeTechnical($request);
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sample_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:30'],
            'slow_request_ms' => ['required', 'integer', 'min:100', 'max:60000'],
            'current_password' => ['required', 'string', 'max:1024'],
        ]);

        if (! Hash::check($input['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        $settings = $this->telemetry->updateSettings($request, $input);

        return ApiResponse::from(ServiceResult::ok($settings, 'Technical telemetry settings saved.'), true);
    }

    private function authorizeTechnical(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
    }
}
