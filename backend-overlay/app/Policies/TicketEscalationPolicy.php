<?php

namespace App\Policies;

use App\Models\{Ticket, User};

class TicketEscalationPolicy
{
    public function review(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active' && $user->customer_id === null;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->review($user) || $this->request($user, $ticket);
    }

    public function request(User $user, Ticket $ticket): bool
    {
        return $user->role === 'technician' && $user->status === 'active'
            && $user->customer_id === null && (int) $ticket->assigned_to === (int) $user->id;
    }
}
