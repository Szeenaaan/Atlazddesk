<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketWorkReport extends Model
{
    protected $fillable = [
        'ticket_id', 'technician_id', 'progress', 'outcome', 'diagnosis',
        'work_performed', 'recommendation', 'parts_required',
    ];

    public function technician(): BelongsTo { return $this->belongsTo(User::class, 'technician_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
}
