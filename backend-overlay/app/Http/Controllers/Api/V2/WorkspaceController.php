<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\AmcTicketResource;
use App\Models\{Ticket, Customer, Asset, AmcContract, PreventiveMaintenance, User};
use App\Support\{AmcAccess, TicketQueries};
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(AmcAccess::isSuperAdmin($user), 403, 'Use technical monitoring, not client operations.');
        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'customer_id']),
            'tickets' => AmcTicketResource::collection(TicketQueries::visible($user)->latest()->paginate(50))->resolve(),
            'customers' => AmcAccess::scopeCustomers(Customer::query(), $user)->limit(100)->get(),
            'assets' => ($user->role === 'technician'
                ? Asset::with('type')->whereIn('id', Ticket::where('assigned_to',$user->id)->whereNotNull('asset_id')->select('asset_id'))
                : AmcAccess::scopeAssets(Asset::with('type'), $user))->limit(100)->get()
                ->map(fn ($a) => [...$a->toArray(), 'type' => $a->type?->name]),
            'contracts' => AmcAccess::isAdmin($user)
                ? AmcContract::query()->limit(100)->get() : [],
            'maintenance' => ($user->role === 'technician'
                ? PreventiveMaintenance::where('assigned_to', $user->id)
                : AmcAccess::scopeCustomer(PreventiveMaintenance::query(), $user))->limit(100)->get(),
            'technicians' => AmcAccess::isStaff($user)
                ? User::where('role', 'technician')->where('status', 'active')->get(['id','name','email','role','status']) : [],
        ]);
    }
}
