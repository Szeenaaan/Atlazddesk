<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UsersController extends Controller
{
    /** Admin manages workforce/client accounts; Customer Admin sees only company users. */
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $filters = $request->validate([
            'search' => ['sometimes','string','max:100'],
            'customer_id' => ['sometimes','integer','exists:customers,id'],
            'workforce_only' => ['sometimes','boolean'],
            'role' => ['sometimes',Rule::in(['admin','technician','customer_admin','customer_user'])],
            'status' => ['sometimes',Rule::in(['active','inactive'])],
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'page' => ['sometimes','integer','min:1'],
        ]);
        $query = User::query()->select(['id','name','email','role','customer_id','status','created_at']);
        if (AmcAccess::isAdmin($actor)) $query->where('role', '!=', 'super_admin');
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        if ($actor->role === 'admin') {
            if (! empty($filters['workforce_only'])) $query->whereNull('customer_id');
            elseif (isset($filters['customer_id'])) $query->where('customer_id', $filters['customer_id']);
            if (isset($filters['role'])) $query->where('role', $filters['role']);
        }
        if (isset($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['search'])) {
            $term = addcslashes($filters['search'], '%_\\');
            $query->where(fn ($q) => $q->where('name','like',"%{$term}%")
                ->orWhere('email','like',"%{$term}%"));
        }
        return $query->orderByDesc('id')->paginate($request->integer('per_page',25));
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $allowed = match ($actor->role) {
            'admin' => ['technician','customer_admin','customer_user'],
            'customer_admin' => ['customer_user'],
            default => [],
        };
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users', 'email')],
            'password' => ['required','confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in($allowed)],
            'customer_id' => $actor->role === 'customer_admin'
                ? ['prohibited'] : ['nullable','integer','exists:customers,id'],
            'status' => ['sometimes',Rule::in(['active','inactive'])],
            'asset_ids' => ['sometimes','array','max:1000'],
            'asset_ids.*' => ['required','integer','distinct'],
        ]);
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $data['customer_id'] = $actor->customer_id;
        }
        if (in_array($data['role'], ['customer_admin', 'customer_user'], true)) {
            abort_unless(! empty($data['customer_id']), 422, 'Customer users require a customer_id.');
        } else {
            abort_if(! empty($data['customer_id']), 422, 'Staff must not have a customer_id.');
            $data['customer_id'] = null;
        }
        // The User model has Laravel's hashed password cast.
        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $ids = $data['asset_ids'] ?? null;
            unset($data['asset_ids']);
            $user = User::create($data);
            if ($ids !== null) app(\App\Services\UserAssetAssignments::class)->sync($user, $ids);
            return $user;
        });
        AmcAudit::record($request, 'user.created', $user, ['role' => $user->role, 'customer_id'=>$user->customer_id]);
        return response()->json(['data' => $user->only(['id','name','email','role','status','customer_id'])], 201);
    }

    public function changeStatus(Request $request, int $id)
    {
        $actor = $request->user();
        abort_unless(AmcAccess::mayManageUsers($actor), 403);
        $data = $request->validate(['status'=>['required', Rule::in(['active','inactive'])]]);
        $query = User::query();
        if ($actor->role === 'admin') $query->where('role','!=','super_admin');
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id)->where('role', 'customer_user');
        }
        $user = $query->findOrFail($id);
        abort_if($user->id === $actor->id && $data['status'] === 'inactive', 422, 'You cannot deactivate yourself.');
        if ($user->role === 'super_admin') abort(403, 'Super Admin accounts require privileged recovery procedures.');
        $user->update($data);
        $user->tokens()->delete();
        AmcAudit::record($request, 'user.status_changed', $user, ['status'=>$user->status]);
        return response()->json(['data'=>$user->only(['id','status'])]);
    }
}
