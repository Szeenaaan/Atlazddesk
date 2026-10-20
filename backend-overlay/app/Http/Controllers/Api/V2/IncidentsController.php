<?php
namespace App\Http\Controllers\Api\V2;
use App\Http\Controllers\Controller;
use App\Models\AmcIncident;
use App\Support\AmcAccess;
use Illuminate\Http\Request;

class IncidentsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(AmcAccess::isSuperAdmin($request->user()), 403);
        $filter=$request->validate(['state'=>['sometimes','in:open,resolved'],'kind'=>['sometimes','string','max:32']]);
        return AmcIncident::query()->when(isset($filter['state']), fn($q)=>$filter['state']==='open'?$q->whereNull('resolved_at'):$q->whereNotNull('resolved_at'))
            ->when(isset($filter['kind']), fn($q)=>$q->where('kind',$filter['kind']))
            ->orderByDesc('last_seen_at')->paginate(25);
    }
    public function resolve(Request $request, AmcIncident $incident)
    {
        abort_unless(AmcAccess::isSuperAdmin($request->user()), 403);
        $incident->update(['resolved_at'=>now()]);
        return response()->json(['message'=>'Incident acknowledged and marked resolved.']);
    }
}
