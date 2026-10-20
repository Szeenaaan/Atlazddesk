<?php
namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAssetAssignments;
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserAssetsController extends Controller
{
    private function target(Request $request, int $id): User {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $query = User::where('role', 'customer_user');
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id);
        }
        return $query->findOrFail($id);
    }
    public function show(Request $request, int $id) {
        $user = $this->target($request, $id);
        return response()->json(['asset_ids' => $user->assignedAssets()->pluck('assets.id')]);
    }
    public function update(Request $request, int $id, UserAssetAssignments $assignments) {
        $user = $this->target($request, $id);
        $data = $request->validate(['asset_ids' => ['present','array','max:1000'], 'asset_ids.*' => ['required','integer','distinct']]);
        DB::transaction(function () use ($user, $data, $request, $assignments) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $assignments->sync($user, $data['asset_ids']);
            AmcAudit::record($request, 'user.assets_updated', $user, ['asset_ids' => $data['asset_ids']]);
        });
        return $this->show($request, $id);
    }
}
