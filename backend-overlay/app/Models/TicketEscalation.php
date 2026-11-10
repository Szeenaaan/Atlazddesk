<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEscalation extends Model
{
    protected $guarded = ['id', 'pending_ticket_id'];
    protected $hidden = ['pending_ticket_id'];
    protected $casts = ['decided_at' => 'datetime', 'previous_level' => 'integer', 'selected_level' => 'integer', 'assignment_id' => 'integer'];

    public function displayNumber(): string
    {
        return '#ES'.($this->created_at?->format('Y') ?? now()->format('Y')).str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requester_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function selectedEmployee(): BelongsTo { return $this->belongsTo(User::class, 'selected_employee_id'); }
}
