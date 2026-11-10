<?php

namespace App\Support;

use App\Models\Ticket;
use Illuminate\Support\Str;

final class TicketNoticeText
{
    public const REJECTED_ESCALATION_MESSAGE = 'Contact your administrator about this escalation request.';
    public static function heading(string $event, array $changes = []): string
    {
        return match ($event) {
            'created' => 'New ticket created',
            'assigned' => 'Technician assigned',
            'unassigned' => 'Technician unassigned',
            'status_changed' => ($changes['status'][1] ?? null) === 'cancelled' ? 'Ticket cancelled' : 'Ticket status changed',
            'updated' => 'Ticket status and assignment',
            'commented' => 'New ticket comment',
            'internal_note' => 'New internal note',
            'attachment_uploaded' => 'Attachment added',
            'work_logged' => 'Work log added',
            'work_report' => 'Work report submitted',
            'sla_warning' => 'Ticket SLA deadline approaching',
            'sla_breach' => 'Ticket SLA deadline missed',
            'contract_expiry' => 'Maintenance contract expiring',
            'escalation_pending' => 'Ticket escalation requested',
            'escalation_approved' => 'Ticket escalation approved',
            'escalation_rejected' => 'Ticket escalation rejected',
            default => 'Ticket activity recorded',
        };
    }

    public static function message(string $event, Ticket $ticket, ?string $excerpt = null, array $changes = []): string
    {
        $number = $ticket->displayNumber();
        $status = $ticket->status?->name ?? 'Unavailable';
        $assignee = $ticket->assignee?->name ?? 'Unassigned';
        return match ($event) {
            'created' => "{$number} was created.",
            'assigned' => "{$number} was assigned to {$assignee}.",
            'unassigned' => "{$number} no longer has an assigned technician.",
            'status_changed' => ($changes['status'][1] ?? null) === 'cancelled' ? "{$number} was cancelled." : (isset($changes['status'])
                ? $number.' moved from '.Str::headline($changes['status'][0]).' to '.Str::headline($changes['status'][1]).'.'
                : "{$number} changed status. Current status: {$status}."),
            'updated' => "{$number} — current status: {$status}. Assigned technician: {$assignee}.",
            'commented' => "{$number} has a new comment.".($excerpt ? ' '.$excerpt : ''),
            'internal_note' => "{$number} has a new internal note.".($excerpt ? ' '.$excerpt : ''),
            'attachment_uploaded' => "An attachment was added to {$number}.",
            'work_logged' => "Technician work time was recorded for {$number}.",
            'sla_warning' => "{$number} is approaching its SLA deadline. Review the ticket deadlines.",
            'sla_breach' => "{$number} has missed an SLA deadline. Review the ticket deadlines.",
            'contract_expiry' => "The maintenance contract for {$number} is approaching expiry.",
            default => self::heading($event).' for '.$number.'.',
        };
    }
}
