<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Support\AmcAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache, Redis};

class HealthController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(AmcAccess::isSuperAdmin($request->user()), 403);
        $checks = ['application'=>['status'=>'healthy']];
        try { DB::select('SELECT 1'); $checks['database']=['status'=>'healthy']; }
        catch (\Throwable) { $checks['database']=['status'=>'unhealthy']; }
        try { Cache::get('amc:health'); $checks['cache']=['status'=>'healthy']; }
        catch (\Throwable) { $checks['cache']=['status'=>'unhealthy']; }
        if (config('queue.default') === 'redis') {
            try { Redis::ping(); $checks['redis']=['status'=>'healthy']; }
            catch (\Throwable) { $checks['redis']=['status'=>'unhealthy']; }
        } else { $checks['queue']=['status'=>'unconfigured']; }
        $ok = collect($checks)->every(fn ($c) => $c['status']==='healthy' || $c['status']==='unconfigured');
        return response()->json(['status'=>$ok?'healthy':'degraded','checks'=>$checks], $ok?200:503);
    }
}
