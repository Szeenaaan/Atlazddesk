<?php
namespace App\Services;

use App\Models\{Asset, User};
use Illuminate\Validation\ValidationException;

final class UserAssetAssignments
{
    // Call inside a transaction. Lock selected assets against concurrent ownership transfers.
    public function sync(User $user, array $ids): void {
        if ($user->role !== 'customer_user' || ! $user->customer_id) {
            throw ValidationException::withMessages(['asset_ids' => 'Only customer users can receive asset assignments.']);
        }
        $assets = Asset::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($assets->count() !== count($ids) || $assets->contains(fn ($asset) => (int) $asset->customer_id !== (int) $user->customer_id)) {
            throw ValidationException::withMessages(['asset_ids' => 'Every assigned asset must belong to the user’s customer organization.']);
        }
        $user->assignedAssets()->sync($ids);
    }
}
