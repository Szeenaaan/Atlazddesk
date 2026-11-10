<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\{DesignationRequest, TechnicianWorkforceRequest};
use App\Models\TechnicianDesignation;
use App\Services\TechnicianDesignationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TechnicianDesignationController extends Controller
{
    public function __construct(private readonly TechnicianDesignationService $service) {}
    public function index(Request $request)
    {
        Gate::forUser($request->user())->authorize('manage', TechnicianDesignation::class);
        return response()->json(['data' => TechnicianDesignation::orderBy('level')->orderBy('name')
            ->get(['id', 'name', 'level', 'is_active'])])->header('Cache-Control', 'private, no-store');
    }
    public function store(DesignationRequest $request)
    {
        return response()->json(['data' => $this->service->save($request, $request->validated())
            ->only(['id', 'name', 'level', 'is_active'])], 201)->header('Cache-Control', 'private, no-store');
    }
    public function update(DesignationRequest $request, int $id)
    {
        return response()->json(['data' => $this->service->save($request, $request->validated(), $id)
            ->only(['id', 'name', 'level', 'is_active'])])->header('Cache-Control', 'private, no-store');
    }
    public function destroy(Request $request, int $id)
    {
        $this->service->delete($request, $id);
        return response()->json(['message' => 'Designation deleted.'])->header('Cache-Control', 'private, no-store');
    }
    public function workforce(TechnicianWorkforceRequest $request, int $id)
    {
        $user = $this->service->workforce($request, $id, $request->validated());
        return response()->json(['data' => [
            'id' => $user->id,
            'designation_id' => $user->designation_id,
            'workforce_type' => $user->workforce_type,
            'designation' => $user->designation?->only(['id', 'name', 'level', 'is_active']),
        ]])->header('Cache-Control', 'private, no-store');
    }
}
