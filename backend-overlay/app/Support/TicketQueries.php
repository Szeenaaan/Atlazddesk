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

    public static function withDetails(User $user): Builder
    {
        return self::visible($user)->with('comments.user');
    }
}
