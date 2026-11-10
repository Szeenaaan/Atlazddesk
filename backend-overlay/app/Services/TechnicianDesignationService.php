<?php

namespace App\Services;

use App\Models\{TechnicianDesignation, TicketEscalation, User};
use App\Support\AmcAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

class TechnicianDesignationService
{
    public function active(int $id): TechnicianDesignation
    {
        $designation = TechnicianDesignation::query()->lockForUpdate()->find($id);
        if (! $designation?->is_active) throw ValidationException::withMessages([
            'designation_id' => 'Choose an active designation.',
        ]);
        return $designation;
    }

    public function save(Request $request, array $data, ?int $id = null): TechnicianDesignation
    {
        Gate::forUser($request->user())->authorize('manage', TechnicianDesignation::class);
        return DB::transaction(function () use ($request, $data, $id) {
            $designation = $id ? TechnicianDesignation::lockForUpdate()->findOrFail($id) : new TechnicianDesignation;
            $designation->fill($data)->save();
            AmcAudit::record($request, $id ? 'designation.updated' : 'designation.created', $designation, $data);
            return $designation;
        });
    }

    public function delete(Request $request, int $id): void
    {
        Gate::forUser($request->user())->authorize('manage', TechnicianDesignation::class);
        DB::transaction(function () use ($request, $id) {
            $designation = TechnicianDesignation::lockForUpdate()->findOrFail($id);
            abort_if(User::where('designation_id', $id)->exists()
                || TicketEscalation::where(fn ($q) => $q->where('previous_designation_id', $id)->orWhere('selected_designation_id', $id))->exists(),
                422, 'This designation is used by a technician or escalation history. Deactivate it instead.');
            AmcAudit::record($request, 'designation.deleted', $designation, $designation->only(['name', 'level']));
            $designation->delete();
        });
    }

    public function workforce(Request $request, int $id, array $data): User
    {
        Gate::forUser($request->user())->authorize('manage', TechnicianDesignation::class);
        return DB::transaction(function () use ($request, $id, $data) {
            $user = User::where('role', 'technician')->whereNull('customer_id')->lockForUpdate()->findOrFail($id);
            $this->active((int) $data['designation_id']);
            $before = $user->only(['designation_id', 'workforce_type']);
            $user->update($data);
            AmcAudit::record($request, 'technician.workforce_updated', $user, ['before' => $before, 'after' => $data]);
            return $user->load('designation');
        });
    }
}
