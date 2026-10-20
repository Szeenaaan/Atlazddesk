<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{Customer, Asset, AmcContract, PreventiveMaintenance, User, Location};
use App\Support\{AmcAccess, AmcAudit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DirectoryWriteController extends Controller
{
    private const MODELS = [
        'customers' => Customer::class,
        'assets' => Asset::class,
        'contracts' => AmcContract::class,
        'maintenance' => PreventiveMaintenance::class,
    ];

    private function model(string $resource): string
    {
        abort_unless(isset(self::MODELS[$resource]), 404);
        return self::MODELS[$resource];
    }

    private function rules(string $resource, ?int $id = null): array
    {
        return match ($resource) {
            'customers' => [
                'company_name' => ['required','string','max:255'],
                'customer_code' => ['required','string','max:50',Rule::unique('customers','customer_code')->ignore($id)],
                'email' => ['nullable','email','max:255'], 'phone' => ['nullable','string','max:50'],
                'city' => ['nullable','string','max:100'], 'country' => ['nullable','string','max:100'],
                'status' => ['sometimes',Rule::in(['active','inactive'])],
            ],
            'assets' => [
                'customer_id' => ['required','integer','exists:customers,id'],
                'name' => ['required','string','max:255'],
                'asset_tag' => ['required','string','max:80',Rule::unique('assets','asset_tag')->ignore($id)],
                'category_id' => ['required','integer','exists:ticket_categories,id'],
                'asset_type_id' => ['sometimes','nullable','integer','exists:asset_types,id'],
                'location_id' => ['nullable','integer','exists:locations,id'],
                'manufacturer' => ['nullable','string','max:255'], 'model' => ['nullable','string','max:255'],
                'serial_number' => ['nullable','string','max:255'],
                'status' => ['sometimes',Rule::in(['active','inactive','maintenance','retired'])],
            ],
            'contracts' => [
                'customer_id' => ['required','integer','exists:customers,id'],
                'contract_number' => ['required','string','max:80',Rule::unique('amc_contracts','contract_number')->ignore($id)],
                'sla_policy_id' => ['nullable','integer','exists:sla_policies,id'],
                'start_date' => ['required','date'], 'end_date' => ['required','date','after_or_equal:start_date'],
                'contract_type' => ['required','string','max:80'],
                'contract_value' => ['nullable','numeric','min:0','max:999999999999.99'],
                'currency' => ['sometimes','string','size:3'],
                'status' => ['sometimes',Rule::in(['active','inactive','expired'])],
            ],
            'maintenance' => [
                'customer_id' => ['required','integer','exists:customers,id'],
                'location_id' => ['nullable','integer','exists:locations,id'],
                'asset_id' => ['nullable','integer','exists:assets,id'],
                'assigned_to' => ['nullable','integer','exists:users,id'],
                'title' => ['required','string','max:255'], 'description' => ['nullable','string','max:5000'],
                'frequency' => ['required',Rule::in(['daily','weekly','monthly','quarterly','yearly'])],
                'next_due_date' => ['required','date'],
                'status' => ['sometimes',Rule::in(['active','paused','completed'])],
            ],
        };
    }

    private function verifyRelations(string $resource, array $data): void
    {
        if ($resource === 'customers') return;
        $customerId = $data['customer_id'];
        foreach (['asset_id' => Asset::class, 'location_id' => Location::class] as $field => $model) {
            if (! empty($data[$field])) {
                abort_unless($model::whereKey($data[$field])->where('customer_id',$customerId)->exists(),
                    422, "{$field} must belong to the selected customer.");
            }
        }
        if ($resource === 'maintenance' && ! empty($data['assigned_to'])) {
            abort_unless(User::whereKey($data['assigned_to'])->where('role','technician')
                ->where('status','active')->exists(), 422, 'Assignee must be an active technician.');
        }
    }

    public function store(Request $request, string $resource)
    {
        $actor = $request->user();
        abort_unless($resource === 'assets'
            ? AmcAccess::mayManageAssets($actor) : AmcAccess::mayManage($actor), 403);
        abort_if($resource === 'customers', 409, 'Register clients and their Customer Admin together via /admin/customers/onboard.');
        $class = $this->model($resource);
        $rules = $this->rules($resource);
        if ($actor->role === 'customer_admin') $rules['customer_id'] = ['prohibited'];
        $data = $request->validate($rules);
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $data['customer_id'] = $actor->customer_id;
        }
        $this->verifyRelations($resource, $data);
        $record = DB::transaction(function () use ($request, $class, $data, $resource) {
            $record = $class::create($data);
            AmcAudit::record($request, $resource.'.created', $record);
            return $record;
        });
        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, string $resource, int $id)
    {
        $actor = $request->user();
        abort_unless($resource === 'assets'
            ? AmcAccess::mayManageAssets($actor) : AmcAccess::mayManage($actor), 403);
        $class = $this->model($resource);
        $query = $class::query();
        if ($actor->role === 'customer_admin') {
            abort_unless($actor->customer_id, 403);
            $query->where('customer_id', $actor->customer_id);
        }
        $record = $query->findOrFail($id);
        $rules = $this->rules($resource, $record->id);
        foreach ($rules as &$rule) {
            $rule = array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rule);
        }
        unset($rule);
        if ($actor->role === 'customer_admin') $rules['customer_id'] = ['prohibited'];
        $data = $request->validate($rules);
        if ($resource === 'assets' && isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $record->customer_id) {
            abort_if(DB::table('asset_user')->where('asset_id', $record->id)->exists(), 422, 'Remove user assignments before transferring this asset to another customer.');
        }
        $this->verifyRelations($resource, array_merge($record->toArray(), $data));
        DB::transaction(function () use ($request, $resource, $record, $data) {
            if ($resource === 'assets') {
                $record = Asset::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if (isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $record->customer_id) {
                    abort_if(DB::table('asset_user')->where('asset_id', $record->id)->exists(), 422, 'Remove user assignments before transferring this asset.');
                }
            }
            $record->update($data);
            AmcAudit::record($request, $resource.'.updated', $record, ['fields'=>array_keys($data)]);
        });
        return response()->json(['data' => $record->fresh()]);
    }
}
