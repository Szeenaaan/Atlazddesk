<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TechnicianAssignmentLimits;
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianAssignmentLimitController extends Controller
{
    public function __construct(private readonly TechnicianAssignmentLimits $limits) {}

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin' && ! $request->attributes->has('amc_workspace_actor'), 403);
    }

    private function technician(int $id): User
    {
        return User::whereKey($id)->where('role', 'technician')->whereNull('customer_id')->firstOrFail();
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => ['default_limit' => $this->limits->defaultLimit()]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateDefault(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['limit' => ['present', 'nullable', 'integer', 'min:1', 'max:10000']]);
        DB::transaction(function () use ($request, $data) {
            $this->limits->saveDefault($data['limit'], $request->user()->id);
            AmcAudit::record($request, 'technician.assignment_default_limit_updated', $request->user(), ['limit' => $data['limit']]);
        });
        return $this->index($request);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => $this->limits->capacity($this->technician($id))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['limit' => ['present', 'nullable', 'integer', 'min:1', 'max:10000']]);
        DB::transaction(function () use ($request, $data, $id) {
            $technician = User::whereKey($id)->where('role', 'technician')->whereNull('customer_id')
                ->lockForUpdate()->firstOrFail();
            $this->limits->saveOverride($technician, $data['limit'], $request->user()->id);
            AmcAudit::record($request, 'technician.assignment_limit_updated', $technician, ['limit' => $data['limit']]);
        });
        return $this->show($request, $id);
    }
}
