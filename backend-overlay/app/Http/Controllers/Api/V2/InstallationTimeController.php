<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\InstallationTimezoneService;
use Illuminate\Http\Request;

/** Authenticated read-only installation clock preferences for all roles. */
final class InstallationTimeController extends Controller
{
    public function __construct(private readonly InstallationTimezoneService $time) {}

    public function show(Request $request)
    {
        return response()->json([
            'success' => true,
            'statuscode' => 200,
            'message' => 'Installation display timezone loaded.',
            'data' => $this->time->settings() + ['now_utc' => now('UTC')->toIso8601String()],
        ])->header('Cache-Control', 'no-store');
    }

    public function zones(Request $request)
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
        $validated = $request->validate(['country' => ['required', 'string', 'regex:/^[A-Z]{2}$/D']]);
        $zones = $this->time->zonesForCountry($validated['country']);
        abort_if($zones === [], 422, 'Country has no supported IANA timezone.');
        return response()->json([
            'success' => true,
            'statuscode' => 200,
            'message' => 'Available timezones loaded.',
            'data' => $zones,
        ])->header('Cache-Control', 'no-store');
    }
}
