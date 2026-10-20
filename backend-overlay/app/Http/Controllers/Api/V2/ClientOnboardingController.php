<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreClientOnboardingRequest;
use App\Services\ClientOnboardingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** The request validates onboarding; the service owns the atomic use case. */
final class ClientOnboardingController extends Controller
{
    public function __construct(private readonly ClientOnboardingService $service) {}

    public function store(StoreClientOnboardingRequest $request): JsonResponse
    {
        return ApiResponse::from($this->service->onboard($request, $request->validated()), private: true);
    }
}
