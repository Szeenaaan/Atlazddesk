<?php

require __DIR__.'/api_v2.php';

// Public, nonsecret availability check; all business routes remain blocked while down.
\Illuminate\Support\Facades\Route::get('/maintenance-status', function () {
    return response()->json(['maintenance' => app()->isDownForMaintenance()])
        ->header('Cache-Control', 'no-store');
});
