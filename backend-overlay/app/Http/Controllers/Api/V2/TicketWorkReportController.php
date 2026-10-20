<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Tickets\TicketWorkReportService;
use App\Support\ApiResponse;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/** Validates work report input; assignment and persistence are service responsibilities. */
final class TicketWorkReportController extends Controller
{
    public function __construct(private readonly TicketWorkReportService $service) {}

    public function index(Request $request, int $ticket): JsonResponse
    {
        return ApiResponse::from($this->service->list($request, $ticket), private: true);
    }

    public function store(Request $request, int $ticket): JsonResponse
    {
        abort_unless($request->user()?->role === 'technician', 403);
        $data = $request->validate([
            'progress' => ['required', Rule::in(['in_progress', 'completed', 'follow_up'])],
            'outcome' => ['required', Rule::in(['success', 'failed', 'repairable', 'unrepairable', 'warning', 'waiting_parts', 'other'])],
            'diagnosis' => ['required', 'string', 'max:10000'],
            'work_performed' => ['nullable', 'string', 'max:10000'],
            'recommendation' => ['nullable', 'string', 'max:10000'],
            'parts_required' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::from($this->service->submit($request, $ticket, $data), private: true);
    }
}
