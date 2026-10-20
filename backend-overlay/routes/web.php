<?php

use App\Http\Controllers\Api\V2\{AuthController, MfaController};
use Illuminate\Support\Facades\Route;

// Session guard and CSRF middleware come from the web middleware group.
// This endpoint is intentionally NOT registered in routes/api.php.
Route::prefix('api/v2/auth')->group(function () {
    Route::post('/login', [AuthController::class,'login'])->middleware('throttle:amc-login');
    Route::post('/logout', [AuthController::class,'logout'])->middleware(['auth','amc.active','throttle:amc-write']);
});


// These endpoints must use the `web` middleware group for session + CSRF.
// No authenticated Laravel session is created before MFA succeeds.
Route::prefix('api/v2/auth/mfa')->middleware('throttle:amc-mfa')->group(function () {
    Route::get('/pending', [MfaController::class, 'pendingStatus']);
    Route::post('/cancel', [MfaController::class, 'cancel']);
    Route::post('/totp/setup', [MfaController::class, 'setupTotp']);
    Route::post('/totp/confirm', [MfaController::class, 'confirmTotp']);
    Route::post('/verify', [MfaController::class, 'verifyLogin']);
    Route::post('/email/send', [MfaController::class, 'sendEmailLogin']);
});
