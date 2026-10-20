<?php

namespace App\Http\Resources;

use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmcTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->customer;
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->description,
            'customer_id' => $this->customer_id,
            'customer' => $customer?->company_name ?? '—',
            'category_id' => $this->category_id,
            'category' => $this->category?->name ?? '—',
            'priority_id' => $this->priority_id,
            'priority' => $this->priority?->name ?? 'Medium',
            'status_id' => $this->status_id,
            'status' => $this->status?->name ?? 'New',
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->assignee?->name ?? 'Unassigned',
            'asset_id' => $this->asset_id,
            'asset' => $this->asset?->name ?? '—',
            'created_at' => $this->created_at?->toIso8601String(),
            'sla_resolution_due_at' => $this->sla_resolution_due_at?->toIso8601String(),
            'comments' => $this->whenLoaded('comments', function () use ($request) {
                $isCustomer = AmcAccess::isCustomer($request->user());
                return $this->comments->filter(fn ($comment) => ! $isCustomer || ! $comment->is_internal)
                    ->map(fn ($comment) => [
                        'id' => $comment->id,
                        'author' => $comment->user?->name ?? 'Support',
                        'text' => $comment->comment,
                        'internal' => (bool) $comment->is_internal,
                        'time' => $comment->created_at?->toIso8601String(),
                    ])->values();
            }, []),
        ];
    }
}
