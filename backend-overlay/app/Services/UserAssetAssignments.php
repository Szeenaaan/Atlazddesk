<?php
namespace App\Services;

use App\Models\{Asset, User};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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
        foreach ($assets as $asset) {
            if ($asset->assignment_mode === 'common') throw ValidationException::withMessages(['asset_ids' => 'Common assets are available to all client users and cannot be assigned.']);
            if ($asset->assignment_mode === 'individual' && DB::table('asset_user')->where('asset_id', $asset->id)->where('user_id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['asset_ids' => 'An individual asset is already assigned to another user.']);
            }
        }
        $user->assignedAssets()->sync($ids);
    }
}
