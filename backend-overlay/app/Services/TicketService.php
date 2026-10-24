<?php

namespace App\Services;

use App\Jobs\DispatchTicketNotice;
use App\Models\{AmcContract, SlaPolicy, Ticket, TicketAssignment, TicketStatus, User};
use App\Support\{AmcAudit, AmcAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketService
{
    private const TRANSITIONS = [
        'new' => ['assigned', 'in_progress', 'cancelled'],
        'assigned' => ['in_progress', 'waiting_customer', 'waiting_vendor', 'waiting_parts', 'cancelled'],
        'in_progress' => ['waiting_customer', 'waiting_vendor', 'waiting_parts', 'resolved'],
        'waiting_customer' => ['in_progress', 'resolved'],
        'waiting_vendor' => ['in_progress', 'resolved'],
        'waiting_parts' => ['in_progress', 'resolved'],
        'resolved' => ['customer_confirmation', 'in_progress', 'closed'],
        'customer_confirmation' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function create(Request $request, array $data): Ticket
    {
        $user = $request->user();
        abort_unless(AmcAccess::mayCreateTicket($user, $request->attributes->get('amc_workspace_actor')),
            403, 'Only customer accounts can create tickets.');
        $customerId = AmcAccess::customerId($user, $data['customer_id'] ?? null);
        Validator::make($data, ['asset_id' => ['required', 'integer', 'exists:assets,id']])->validate();
        if (! empty($data['asset_id'])) {
            abort_unless(\App\Models\Asset::whereKey($data['asset_id'])
                ->where('customer_id', $customerId)->exists(), 422, 'Asset must belong to customer.');
        }
        if (! empty($data['asset_id'])) {
            $asset = \App\Models\Asset::findOrFail($data['asset_id']);
            if ($user->role === 'customer_user') {
                abort_unless($user->assignedAssets()->where('assets.id', $asset->id)->exists(), 422, 'Asset must be assigned to you.');
            }
            $compatible = (int) $asset->category_id === (int) $data['category_id'];
            if (! $compatible) throw ValidationException::withMessages([
                'asset_id' => 'Asset must match the selected category.',
            ]);
        }
        if (! empty($data['location_id'])) {
            abort_unless(\App\Models\Location::whereKey($data['location_id'])
                ->where('customer_id', $customerId)->exists(), 422, 'Location must belong to customer.');
        }
        $policy = AmcContract::query()->where('customer_id', $customerId)
            ->where('status', 'active')->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())->with('slaPolicy')->first()?->slaPolicy
            ?? SlaPolicy::where('is_active', true)->first();
        $priority = \App\Models\TicketPriority::findOrFail($data['priority_id']);
        $level = strtolower($priority->name);
        $newStatus = TicketStatus::where('slug', 'new')->firstOrFail();
        $responseMinutes = $policy?->{$level.'_response_minutes'};
        $resolutionMinutes = $policy?->{$level.'_resolution_minutes'};
        return DB::transaction(function () use ($request, $data, $user, $customerId, $newStatus, $policy, $responseMinutes, $resolutionMinutes) {
            $ticket = Ticket::create(array_merge($data, [
                'customer_id' => $customerId,
                'ticket_number' => 'INC-'.now()->format('Y').'-'.strtoupper(Str::random(16)),
                'created_by' => \App\Support\AmcAudit::actorId($request),
                'status_id' => $newStatus->id,
                'sla_policy_id' => $policy?->id,
                // Wall-clock deadlines; business hours/holidays require a dedicated calendar service.
                'sla_response_due_at' => $responseMinutes ? now()->addMinutes($responseMinutes) : null,
                'sla_resolution_due_at' => $resolutionMinutes ? now()->addMinutes($resolutionMinutes) : null,
            ]));
            AmcAudit::record($request, 'ticket.created', $ticket);
            DispatchTicketNotice::dispatch($ticket->id, 'created')->afterCommit();
            return $ticket;
        });
    }

    public function update(Request $request, Ticket $ticket, array $data): Ticket
    {
        $user = $request->user();
        $isCustomer = AmcAccess::isCustomer($user);
        return DB::transaction(function () use ($request, $ticket, $data, $user, $isCustomer) {
            $ticket = AmcAccess::scopeTicket(Ticket::query(), $user)->lockForUpdate()->findOrFail($ticket->id);
            $changes = [];
            if (array_key_exists('assigned_to', $data)) {
                abort_unless(AmcAccess::mayAssign($user), 403);
                $assigneeId = $data['assigned_to'];
                if ($assigneeId !== null) {
                    abort_unless(User::whereKey($assigneeId)->where('role','technician')
                        ->where('status', 'active')->exists(), 422, 'Assignee must be an active technician.');
                }
                if ($ticket->assigned_to !== $assigneeId) {
                    TicketAssignment::where('ticket_id', $ticket->id)->whereNull('unassigned_at')
                        ->update(['unassigned_at' => now()]);
                    if ($assigneeId !== null) {
                        TicketAssignment::create([
                            'ticket_id' => $ticket->id, 'technician_id' => $assigneeId,
                            'assigned_by' => \App\Support\AmcAudit::actorId($request), 'assigned_at' => now(),
                        ]);
                    }
                    $changes['assigned_to'] = [$ticket->assigned_to, $assigneeId];
                    $ticket->assigned_to = $assigneeId;
                    if ($ticket->status?->slug === 'new' && $assigneeId !== null && ! isset($data['status'])) {
                        $data['status'] = 'Assigned';
                    }
                }
            }
            if (isset($data['status'])) {
                $target = Str::of($data['status'])->lower()->replace(' ', '_')->toString();
                $current = $ticket->status?->slug;
                if ($target !== $current) {
                    abort_unless(in_array($target, self::TRANSITIONS[$current] ?? [], true), 422, 'Invalid ticket status transition.');
                    if ($isCustomer) {
                        abort_unless($current === 'resolved' && in_array($target, ['customer_confirmation','in_progress'], true)
                            || $current === 'customer_confirmation' && $target === 'closed', 403);
                    } elseif ($user->role === 'technician') {
                        abort_unless($ticket->assigned_to === $user->id && ! in_array($target, ['cancelled','closed'], true), 403);
                    }
                    $status = TicketStatus::where('slug', $target)->firstOrFail();
                    $changes['status'] = [$current, $target];
                    $ticket->status_id = $status->id;
                    if ($target === 'resolved') $ticket->resolved_at = now();
                    if ($target === 'closed') $ticket->closed_at = now();
                    if ($target === 'in_progress' && $current === 'resolved') $ticket->resolved_at = null;
                }
            }
            if ($changes) {
                $ticket->save();
                AmcAudit::record($request, 'ticket.updated', $ticket, $changes);
                if (array_key_exists('assigned_to', $changes) && $ticket->assigned_to) {
                    DispatchTicketNotice::dispatch($ticket->id, 'assigned', (int) $ticket->assigned_to, null, AmcAudit::actorId($request), (string) \Illuminate\Support\Str::uuid())->afterCommit();
                }
                else DispatchTicketNotice::dispatch($ticket->id, 'updated', null, null, AmcAudit::actorId($request), (string) \Illuminate\Support\Str::uuid())->afterCommit();
            }
            return $ticket;
        });
    }
}
