<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{Customer, Asset, AssetType, Location, AmcContract, SlaPolicy, PreventiveMaintenance, Ticket, User, TicketCategory, TicketPriority, TicketStatus};
use App\Support\AmcAccess;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    private const RESOURCES = [
        'customers' => Customer::class,
        'assets' => Asset::class,
        'contracts' => AmcContract::class,
        'maintenance' => PreventiveMaintenance::class,
    ];

    private function query(Request $request, string $resource)
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        $user = $request->user();
        // Commercial AMC contracts are managed by the contractor, not by clients.
        abort_if($resource === 'contracts' && ! AmcAccess::isAdmin($user), 403);
        if ($user->role === 'technician') {
            if ($resource === 'maintenance') return PreventiveMaintenance::where('assigned_to', $user->id);
            if ($resource === 'assets') return Asset::whereIn('id',
                Ticket::query()->where('assigned_to', $user->id)->whereNotNull('asset_id')->select('asset_id'));
        }
        if ($resource === 'assets') return AmcAccess::scopeAssets(Asset::query(), $user);
        $query = self::RESOURCES[$resource]::query();
        return $resource === 'customers'
            ? AmcAccess::scopeCustomers($query, $request->user())
            : AmcAccess::scopeCustomer($query, $request->user());
    }

    public function index(Request $request, string $resource)
    {
        $data = $request->validate([
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'search' => ['sometimes','string','max:100'],
            'city' => ['sometimes','string','max:255'],
            'status' => ['sometimes','string','max:40'],
            'customer_id' => ['sometimes','integer'],
            'asset_type_id' => ['sometimes','integer'],
            'category_id' => ['sometimes','integer','exists:ticket_categories,id'],
        ]);
        $q = $this->query($request, $resource);
        if ($resource === 'assets') {
            $q->with(['category:id,name', 'customer:id,company_name']);
            foreach (['customer_id', 'asset_type_id'] as $field) {
                if (isset($data[$field])) $q->where($field, $data[$field]);
            }
            if (isset($data['category_id'])) $q->where('category_id', $data['category_id']);
        }
        if ($resource === 'customers' && isset($data['city'])) $q->where('city', $data['city']);
        if (in_array($resource, ['customers', 'assets'], true) && isset($data['status'])) $q->where('status', $data['status']);
        if (! empty($data['search'])) {
            $column = match ($resource) {
                'customers' => 'company_name', 'assets' => 'name',
                'contracts' => 'contract_number', default => 'title',
            };
            $search = str_replace(['%','_'], ['\\%','\\_'], $data['search']);
            $secondary = match ($resource) { 'customers' => 'customer_code', 'assets' => 'asset_tag', default => $column };
            $q->where(fn ($query) => $query->where($column, 'like', "%{$search}%")->orWhere($secondary, 'like', "%{$search}%"));
        }
        return response()->json($q->orderByDesc('id')->paginate($request->integer('per_page',25)));
    }

    public function show(Request $request, string $resource, int $id)
    {
        return response()->json($this->query($request, $resource)->findOrFail($id));
    }

    public function lookups(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'customers' => AmcAccess::scopeCustomers(Customer::query(), $user)
                ->orderBy('company_name')->get(['id','company_name']),
            'categories' => TicketCategory::where('is_active', true)->orderBy('name')->get(['id','name']),
            'customer_locations' => AmcAccess::scopeCustomers(Customer::query(), $user)->whereNotNull('city')->distinct()->orderBy('city')->pluck('city'),
            'priorities' => TicketPriority::where('is_active', true)->orderByDesc('level')->get(['id','name']),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->get(['id','name','slug']),
            'asset_types' => AssetType::where('is_active', true)->orderBy('name')->get(['id','name']),
            'sla_policies' => AmcAccess::isStaff($user) ? SlaPolicy::where('is_active',true)->get(['id','name']) : [],
            'technicians' => AmcAccess::isStaff($user)
                ? User::where('role','technician')->where('status','active')->get(['id','name']) : [],
        ]);
    }
}
