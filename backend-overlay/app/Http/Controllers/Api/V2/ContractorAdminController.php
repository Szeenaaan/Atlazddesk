<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\ContractorAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Validates HTTP input and delegates the use case to the service. */
final class ContractorAdminController extends Controller
{
    public function __construct(private readonly ContractorAdminService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::from($this->service->list($request), private: true);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
        ]);

        return ApiResponse::from($this->service->create($request, $data), private: true);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return ApiResponse::from($this->service->changeStatus($request, $id, $data['status']), private: true);
    }
}
