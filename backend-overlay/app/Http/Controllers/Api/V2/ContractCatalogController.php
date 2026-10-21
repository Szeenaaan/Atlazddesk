<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{AmcService, SlaPolicy};
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ContractCatalogController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless(AmcAccess::isAdmin($request->user()), 403);
    }

    private function policyFields(): array
    {
        $fields = ['id', 'name', 'is_active'];
        foreach (['critical', 'high', 'medium', 'low'] as $priority) {
            $fields[] = $priority.'_response_minutes';
            $fields[] = $priority.'_resolution_minutes';
        }
        return $fields;
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json([
            'policies' => SlaPolicy::orderBy('name')->get($this->policyFields()),
            'services' => AmcService::orderBy('name')->get(['id', 'name', 'description', 'is_active']),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function savePolicy(Request $request, ?int $id = null)
    {
        $this->authorizeAdmin($request);
        $policy = $id === null ? new SlaPolicy : SlaPolicy::findOrFail($id);
        $rules = ['name' => ['required', 'string', 'max:255', Rule::unique('sla_policies')->ignore($id)],
            'is_active' => ['required', 'boolean']];
        foreach (['critical', 'high', 'medium', 'low'] as $priority) {
            $rules[$priority.'_response_minutes'] = ['required', 'integer', 'min:1', 'max:525600'];
            $rules[$priority.'_resolution_minutes'] = ['required', 'integer', 'min:1', 'max:525600', 'gte:'.$priority.'_response_minutes'];
        }
        $policy->fill($request->validate($rules))->save();
        AmcAudit::record($request, $id === null ? 'sla_policy.created' : 'sla_policy.updated', $policy);
        return response()->json(['data' => $policy->only($this->policyFields())], $id === null ? 201 : 200);
    }

    public function storePolicy(Request $request) { return $this->savePolicy($request); }
    public function updatePolicy(Request $request, int $id) { return $this->savePolicy($request, $id); }

    private function saveService(Request $request, ?int $id = null)
    {
        $this->authorizeAdmin($request);
        $service = $id === null ? new AmcService : AmcService::findOrFail($id);
        $service->fill($request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('amc_services')->ignore($id)],
            'description' => ['nullable', 'string', 'max:5000'], 'is_active' => ['required', 'boolean'],
        ]))->save();
        AmcAudit::record($request, $id === null ? 'contract_service.created' : 'contract_service.updated', $service);
        return response()->json(['data' => $service->only(['id', 'name', 'description', 'is_active'])], $id === null ? 201 : 200);
    }

    public function storeService(Request $request) { return $this->saveService($request); }
    public function updateService(Request $request, int $id) { return $this->saveService($request, $id); }
}
