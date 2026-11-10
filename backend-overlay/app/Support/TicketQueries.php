<?php

namespace App\Support;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class TicketQueries
{
    public static function visible(User $user): Builder
    {
        return AmcAccess::scopeTicket(Ticket::query(), $user)
            ->with(['customer', 'category', 'priority', 'status', 'assignee', 'asset']);
    }

    public static function withPendingFlag(Builder $query, User $user): Builder
    {
        return in_array($user->role, ['customer_admin', 'customer_user', 'technician'], true)
            ? $query->withExists(['escalations as escalation_pending' => fn ($q) => $q->where('state', 'pending')])
            : $query;
    }

    public static function withDetails(User $user): Builder
    {
        return self::withPendingFlag(self::visible($user), $user)->with('comments.user');
    }

    /** Caller owns the transaction. Ticket mutations and escalation requests lock the ticket first. */
    public static function locked(User $user, int $id): Ticket
    {
        $ticket = AmcAccess::scopeTicket(Ticket::query(), $user)->lockForUpdate()->findOrFail($id);
        abort_if(in_array($user->role, ['customer_admin', 'customer_user', 'technician'], true)
            && $ticket->isFinalized(), 403, 'This ticket is complete and cannot be changed. Contact Admin for assistance.');
        // A current locking read also protects requests that started before the escalation committed.
        if (in_array($user->role, ['customer_admin', 'customer_user', 'technician'], true)) {
            abort_if($ticket->escalations()->where('state', 'pending')
                ->lockForUpdate()->first(['id']) !== null, 403,
                'This ticket is read-only. Contact Admin for assistance.');
        }
        return $ticket;
    }
}
