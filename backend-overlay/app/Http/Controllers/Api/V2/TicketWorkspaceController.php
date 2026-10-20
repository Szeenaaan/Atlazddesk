<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmcTicketResource;
use App\Jobs\DispatchTicketNotice;
use App\Models\{Ticket, TicketComment};
use App\Services\TicketService;
use App\Support\{AmcAccess, AmcAudit, TicketQueries};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketWorkspaceController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    public function index(Request $request)
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:40'],
            'priority' => ['sometimes', 'string', 'max:30']]);
        $query = TicketQueries::visible($request->user());
        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('search'));
            $query->where(fn ($q) => $q->where('subject', 'like', "%{$search}%")
                ->orWhere('ticket_number', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->whereHas('status', fn ($status) => $status->where('name', $request->input('status')));
        }
        if ($request->filled('priority')) {
            $query->whereHas('priority', fn ($priority) => $priority->where('name', $request->input('priority')));
        }
        return AmcTicketResource::collection($query->latest()->paginate($request->integer('per_page', 25)));
    }

    /** A dedicated inbox endpoint makes assignment-only technician access explicit. */
    public function inbox(Request $request)
    {
        abort_unless($request->user()->role === 'technician', 403);
        return $this->index($request);
    }

    public function show(Request $request, int $ticket)
    {
        return new AmcTicketResource(TicketQueries::withDetails($request->user())->findOrFail($ticket));
    }

    public function store(Request $request)
    {
        abort_unless(AmcAccess::isStaff($request->user()) || AmcAccess::isCustomer($request->user()), 403);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'customer_id' => AmcAccess::isCustomer($request->user())
                ? ['prohibited'] : ['required', 'integer', 'exists:customers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'category_id' => ['required', 'integer', 'exists:ticket_categories,id'],
            'priority_id' => ['required', 'integer', 'exists:ticket_priorities,id'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);
        $ticket = $this->service->create($request, $data);
        return (new AmcTicketResource(TicketQueries::visible($request->user())->findOrFail($ticket->id)))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, int $ticket)
    {
        $model = TicketQueries::withDetails($request->user())->findOrFail($ticket);
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'max:40'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);
        abort_unless($data !== [], 422, 'No changes supplied.');
        $this->service->update($request, $model, $data);
        return new AmcTicketResource(TicketQueries::withDetails($request->user())->findOrFail($ticket));
    }

    public function comment(Request $request, int $ticket)
    {
        $model = TicketQueries::withDetails($request->user())->findOrFail($ticket);
        $data = $request->validate(['comment' => ['required','string','max:10000'],
            'is_internal' => ['sometimes','boolean']]);
        $internal = (bool) ($data['is_internal'] ?? false);
        abort_if($internal && AmcAccess::isCustomer($request->user()), 403);
        $comment = DB::transaction(function () use ($request, $model, $data, $internal) {
            $comment = TicketComment::create([
                'ticket_id' => $model->id, 'user_id' => $request->user()->id,
                'comment' => $data['comment'], 'is_internal' => $internal,
            ]);
            if (! AmcAccess::isCustomer($request->user()) && ! $model->first_response_at && ! $internal) {
                $model->update(['first_response_at' => now()]);
            }
            AmcAudit::record($request, 'ticket.commented', $model, ['is_internal' => $internal]);
            if (! $internal) DispatchTicketNotice::dispatch($model->id, 'commented')->afterCommit();
            return $comment;
        });
        return response()->json(['data' => ['id' => $comment->id]], 201);
    }
}
