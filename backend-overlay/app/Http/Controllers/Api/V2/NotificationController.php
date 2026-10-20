<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\{JsonResponse, Request};

final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['unread_only' => ['sometimes', 'boolean']]);

        return ApiResponse::from($this->service->list($request, (bool) ($filter['unread_only'] ?? false)), private: true);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        return ApiResponse::from($this->service->markRead($request, $notification), private: true);
    }
}
