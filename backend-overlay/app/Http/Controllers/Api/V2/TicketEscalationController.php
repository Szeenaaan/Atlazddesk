<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\{EscalationRequest, EscalationDecisionRequest};
use App\Http\Resources\TicketEscalationResource;
use App\Models\TicketEscalation;
use App\Services\TicketEscalationService;
use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketEscalationController extends Controller
{
    private const RELATIONS = ['ticket', 'requester.designation', 'selectedEmployee', 'reviewer'];
    public function __construct(private readonly TicketEscalationService $service) {}
    public function all(Request $request)
    {
        Gate::forUser($request->user())->authorize('review', TicketEscalation::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        return TicketEscalationResource::collection(TicketEscalation::query()
            ->whereHas('ticket', fn ($q) => AmcAccess::scopeTicket($q, $request->user()))
            ->with(self::RELATIONS)->latest('id')->paginate(25))->response()->header('Cache-Control', 'private, no-store');
    }
    public function index(Request $request, int $ticket)
    {
        $this->service->visible($request, $ticket);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $query = TicketEscalation::where('ticket_id', $ticket);
        if ($request->user()->role === 'technician') $query->where('requester_id', $request->user()->id);
        return TicketEscalationResource::collection($query->with(self::RELATIONS)->latest('id')->paginate(25))
            ->response()->header('Cache-Control', 'private, no-store');
    }
    public function eligible(Request $request, int $ticket)
    {
        return response()->json(['data' => $this->service->eligible($request, $ticket)])->header('Cache-Control', 'private, no-store');
    }
    public function store(EscalationRequest $request, int $ticket)
    {
        return (new TicketEscalationResource($this->service->request($request, $ticket, $request->validated())->load(self::RELATIONS)))
            ->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }
    public function decide(EscalationDecisionRequest $request, int $ticket, int $escalation)
    {
        return (new TicketEscalationResource($this->service->decide($request, $ticket, $escalation, $request->validated())->load(self::RELATIONS)))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
