<?php

namespace App\Services\System;

use App\Models\User;
use App\Support\{AmcAudit, ServiceResult};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Contractor Admin provisioning; never performs client business operations. */
final class ContractorAdminService
{
    public function list(Request $request): ServiceResult
    {
        $this->authorize($request);

        $query = User::query()->where('role', 'admin')
            ->select('id', 'name', 'email', 'role', 'status', 'created_at');
        $admins = \App\Support\GridSort::apply($query, $request, ['name', 'email', 'status'])->paginate(25);

        return ServiceResult::ok($admins, 'Contractor Admin accounts retrieved.');
    }

    public function create(Request $request, array $data): ServiceResult
    {
        $this->authorize($request);

        $admin = DB::transaction(function () use ($request, $data): User {
            $admin = User::create([
                ...$data,
                'role' => 'admin',
                'status' => 'active',
                'customer_id' => null,
            ]);
            AmcAudit::record($request, 'contractor_admin.created', $admin, ['role' => 'admin']);

            return $admin;
        });

        return ServiceResult::ok(
            $admin->only(['id', 'name', 'email', 'role', 'status']),
            'Contractor Admin created.',
            201,
        );
    }

    public function changeStatus(Request $request, int $id, string $status): ServiceResult
    {
        $this->authorize($request);

        $admin = DB::transaction(function () use ($request, $id, $status): User {
            $admin = User::query()->where('role', 'admin')->lockForUpdate()->findOrFail($id);
            $admin->update(['status' => $status]);
            $admin->tokens()->delete();
            AmcAudit::record($request, 'contractor_admin.status_changed', $admin, ['status' => $status]);

            return $admin;
        });

        return ServiceResult::ok($admin->only(['id', 'status']), 'Contractor Admin status updated.');
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
    }
}
