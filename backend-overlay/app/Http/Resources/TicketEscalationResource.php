<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketEscalationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Defense in depth: never serialize internal records for customer or technical roles.
        abort_unless(in_array($request->user()?->role, ['admin', 'technician'], true), 403);
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('view', [\App\Models\TicketEscalation::class, $this->ticket]);
        abort_if($request->user()->role === 'technician'
            && (int) $this->requester_id !== (int) $request->user()->id, 403);
        return [
            ...$this->resource->only(['id', 'ticket_id', 'state', 'reason',
                'previous_designation_name', 'previous_level', 'selected_designation_name']),
            'reference' => $this->displayNumber(),
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'requester_name' => $this->requester?->name,
            'reviewer_name' => $this->reviewer?->name,
            ...($request->user()->role === 'admin' ? [
                'admin_note' => $this->admin_note,
                'current_designation' => $this->requester?->designation?->only(['name', 'level']),
                'selected_employee_name' => $this->selectedEmployee?->name,
                'ticket_number' => $this->ticket?->displayNumber(),
                'subject' => $this->ticket?->subject,
            ] : []),
        ];
    }
}
