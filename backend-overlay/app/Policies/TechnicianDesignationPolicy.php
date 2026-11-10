<?php

namespace App\Policies;

use App\Models\User;

class TechnicianDesignationPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active' && $user->customer_id === null;
    }
}
