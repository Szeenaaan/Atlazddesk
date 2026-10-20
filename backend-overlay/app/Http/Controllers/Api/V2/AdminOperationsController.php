<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\{ActivityLog, AmcContract, Ticket, User};
use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOperationsController extends Controller
{
    public function audit(Request $request)
    {
        abort_unless(AmcAccess::isSuperAdmin($request->user()), 403);
        $data = $request->validate(['event'=>['sometimes','string','max:100']]);
        $q = ActivityLog::query()->orderByDesc('id');
        if (isset($data['event'])) $q->where('event',$data['event']);
        return response()->json($q->select(['id','event','created_at'])->paginate(25));
    }

    public function metrics(Request $request)
    {
        abort_unless(AmcAccess::isSuperAdmin($request->user()), 403);
        $now = now();
        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'incidents_open' => \App\Models\AmcIncident::whereNull('resolved_at')->count(),
            'incidents_last_24h' => \App\Models\AmcIncident::where('last_seen_at', '>=', now()->subDay())->count(),
            'queue_connection' => config('queue.default'),
            'cache_store' => config('cache.default'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]);
    }
}
