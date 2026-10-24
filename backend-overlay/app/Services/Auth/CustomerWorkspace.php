<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/** Delegated customer permissions; the authenticated web guard always remains the real actor. */
final class CustomerWorkspace
{
    public const KEY = 'amc_customer_workspace';

    public function target(int $id): User
    {
        return User::query()->where('role', 'customer_admin')->where('status', 'active')
            ->whereNotNull('customer_id')->whereHas('customer', fn ($query) => $query->where('status', 'active'))
            ->findOrFail($id);
    }

    public function payload(Request $request, User $target): array
    {
        $actor = $request->attributes->get('amc_workspace_actor') ?? $request->user();
        return ['user' => app(MfaService::class)->publicUser($target), 'acting_as' => [
            'actor' => app(MfaService::class)->publicUser($actor),
            'target_name' => $target->name,
        ]];
    }
}
