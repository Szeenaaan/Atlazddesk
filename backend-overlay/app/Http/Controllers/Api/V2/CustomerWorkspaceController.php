<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Auth\{CustomerWorkspace, MfaService};
use App\Support\AmcAudit;
use Illuminate\Http\Request;

final class CustomerWorkspaceController extends Controller
{
    public function start(Request $request, int $id, CustomerWorkspace $workspace)
    {
        abort_unless($request->user()->role === 'admin' && $request->hasSession(), 403);
        abort_unless((int) $request->session()->get(\Illuminate\Support\Facades\Auth::guard('web')->getName()) === (int) $request->user()->id, 403);
        app(\App\Services\Auth\AccountSession::class)->validateAndTouch($request, $request->user(), false);
        abort_if($request->session()->has(CustomerWorkspace::KEY), 409, 'Return to your own workspace first.');
        $target = $workspace->target($id);
        AmcAudit::record($request, 'customer.dashboard.entered', $target);
        $request->session()->regenerate();
        $request->session()->put(CustomerWorkspace::KEY, $target->id);
        $request->session()->put(CustomerWorkspace::KEY.'_actor', $request->user()->id);
        return response()->json($workspace->payload($request, $target))->header('Cache-Control', 'no-store');
    }

    public function stop(Request $request, MfaService $mfa)
    {
        abort_unless($request->user()->role === 'admin' && $request->hasSession(), 403);
        if ($id = $request->session()->get(CustomerWorkspace::KEY)) {
            AmcAudit::record($request, 'customer.dashboard.exited', $request->user(), ['customer_admin_id' => (int) $id]);
        }
        $request->session()->forget(CustomerWorkspace::KEY);
        $request->session()->forget(CustomerWorkspace::KEY.'_actor');
        $request->session()->regenerate();
        return response()->json(['user' => $mfa->publicUser($request->user()), 'acting_as' => null])->header('Cache-Control', 'no-store');
    }
}
