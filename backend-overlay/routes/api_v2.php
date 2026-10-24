<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V2\{
    AuthController, MfaController, SecuritySettingsController, WorkspaceController, TicketWorkspaceController,
    DirectoryController, DirectoryWriteController, OperationsController,
    HealthController, LiveHealthController, UsersController, AdminOperationsController, ReportsController, IncidentsController, ClientOnboardingController, ClientMfaPolicyController, NotificationController, TicketWorkReportController, ContractorAdminController, SystemSupportController, ContractorMailSettingsController
};

Route::get('v2/health/live', [LiveHealthController::class, 'live'])->middleware('throttle:amc-health');
Route::get('v2/health/ready', [LiveHealthController::class, 'ready'])->middleware('throttle:amc-health');

Route::prefix('v2')->middleware(['auth:sanctum','amc.active','amc.role','amc.boundary','amc.mfa','throttle:amc-api', \App\Http\Middleware\EnsureCustomerWorkspace::class])->group(function () {
    Route::post('/users/{id}/dashboard', [\App\Http\Controllers\Api\V2\CustomerWorkspaceController::class, 'start'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::delete('/customer-workspace', [\App\Http\Controllers\Api\V2\CustomerWorkspaceController::class, 'stop'])->middleware('throttle:amc-write');
    Route::get('/auth/me', [AuthController::class,'me']);
    Route::post('/auth/activity', [AuthController::class,'activity'])->middleware('throttle:amc-write');
    Route::get('/installation/timezone', [\App\Http\Controllers\Api\V2\InstallationTimeController::class, 'show']);
    Route::patch('/auth/profile', [AuthController::class,'updateProfile'])->middleware('throttle:amc-write');
    Route::get('/auth/mfa/status', [MfaController::class,'status']);
    // Replace an enrolled authenticator without disabling the mandatory MFA policy.
    Route::post('/auth/mfa/totp/replace/start', [\App\Http\Controllers\Api\V2\AuthenticatorReplacementController::class, 'start'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/totp/replace/confirm', [\App\Http\Controllers\Api\V2\AuthenticatorReplacementController::class, 'confirm'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/totp/replace/cancel', [\App\Http\Controllers\Api\V2\AuthenticatorReplacementController::class, 'cancel'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/recovery/regenerate', [\App\Http\Controllers\Api\V2\RecoveryController::class,'regenerate'])->middleware('throttle:amc-mfa');
    Route::post('/users/{id}/mfa/authenticator/reset', [\App\Http\Controllers\Api\V2\RecoveryController::class,'resetAuthenticator'])->middleware('throttle:amc-mfa')->whereNumber('id');
    Route::get('/admin/security/mfa', [SecuritySettingsController::class, 'index']);
    Route::patch('/admin/security/mfa', [SecuritySettingsController::class, 'update'])->middleware('throttle:amc-write');
    Route::post('/auth/mfa/email/enroll/send', [MfaController::class,'sendEmailEnrollment'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/email/enroll/confirm', [MfaController::class,'confirmEmailEnrollment'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/email/disable/send', [MfaController::class,'sendEmailDisable'])->middleware('throttle:amc-mfa');
    Route::post('/auth/mfa/disable', [MfaController::class,'disable'])->middleware('throttle:amc-mfa');
    Route::get('/bootstrap', [WorkspaceController::class,'index']);
    Route::get('/categories', [\App\Http\Controllers\Api\V2\CategoriesController::class, 'index']);
    Route::post('/categories', [\App\Http\Controllers\Api\V2\CategoriesController::class, 'store'])->middleware('throttle:amc-write');
    Route::patch('/categories/{id}', [\App\Http\Controllers\Api\V2\CategoriesController::class, 'update'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::delete('/categories/{id}', [\App\Http\Controllers\Api\V2\CategoriesController::class, 'destroy'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/contract-options', [\App\Http\Controllers\Api\V2\ContractCatalogController::class, 'index']);
    Route::post('/sla-policies', [\App\Http\Controllers\Api\V2\ContractCatalogController::class, 'storePolicy'])->middleware('throttle:amc-write');
    Route::patch('/sla-policies/{id}', [\App\Http\Controllers\Api\V2\ContractCatalogController::class, 'updatePolicy'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::post('/contract-services', [\App\Http\Controllers\Api\V2\ContractCatalogController::class, 'storeService'])->middleware('throttle:amc-write');
    Route::patch('/contract-services/{id}', [\App\Http\Controllers\Api\V2\ContractCatalogController::class, 'updateService'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/lookups', [DirectoryController::class,'lookups']);
    Route::get('/contracts/{id}/documents', [\App\Http\Controllers\Api\V2\ContractDocumentsController::class, 'index'])->whereNumber('id');
    Route::post('/contracts/{id}/documents', [\App\Http\Controllers\Api\V2\ContractDocumentsController::class, 'store'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/contracts/{id}/documents/{document}', [\App\Http\Controllers\Api\V2\ContractDocumentsController::class, 'show'])->whereNumber(['id', 'document']);
    Route::get('/directory/{resource}', [DirectoryController::class,'index']);
    Route::get('/directory/{resource}/{id}', [DirectoryController::class,'show'])->whereNumber('id');
    Route::post('/directory/{resource}', [DirectoryWriteController::class,'store'])->middleware('throttle:amc-write');
    Route::patch('/directory/{resource}/{id}', [DirectoryWriteController::class,'update'])
        ->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/tickets', [TicketWorkspaceController::class,'index']);
    Route::get('/tickets/inbox', [TicketWorkspaceController::class,'inbox']);
    Route::get('/tickets/{ticket}', [TicketWorkspaceController::class,'show'])->whereNumber('ticket');
    Route::post('/tickets', [TicketWorkspaceController::class,'store'])->middleware('throttle:amc-write');
    Route::patch('/tickets/{ticket}', [TicketWorkspaceController::class,'update'])
        ->middleware('throttle:amc-write')->whereNumber('ticket');
    Route::post('/tickets/{ticket}/comments', [TicketWorkspaceController::class,'comment'])
        ->middleware('throttle:amc-write')->whereNumber('ticket');
    Route::get('/tickets/{ticket}/work-reports', [TicketWorkReportController::class,'index'])->whereNumber('ticket');
    Route::post('/tickets/{ticket}/work-reports', [TicketWorkReportController::class,'store'])
        ->middleware('throttle:amc-write')->whereNumber('ticket');
    Route::get('/tickets/{id}/work-logs', [OperationsController::class,'workLogs'])->whereNumber('id');
    Route::post('/tickets/{id}/work-logs', [OperationsController::class,'addWorkLog'])
        ->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/tickets/{id}/attachments', [OperationsController::class,'attachments'])->whereNumber('id');
    Route::post('/tickets/{id}/attachments', [OperationsController::class,'upload'])
        ->middleware(['throttle:amc-write', 'throttle:amc-upload'])->whereNumber('id');
    Route::get('/tickets/{id}/attachments/{attachment}/download', [OperationsController::class,'download'])
        ->whereNumber(['id','attachment']);
    Route::get('/reports/summary', [ReportsController::class,'summary']);
    Route::get('/reports/tickets.csv', [ReportsController::class,'ticketCsv']);
    Route::get('/health', [HealthController::class,'index']);
    Route::get('/admin/metrics', [AdminOperationsController::class,'metrics']);
    Route::get('/admin/audit', [AdminOperationsController::class,'audit']);
    Route::get('/admin/incidents', [IncidentsController::class,'index']);
    Route::patch('/admin/incidents/{incident}/resolve', [IncidentsController::class,'resolve'])->middleware('throttle:amc-write');
    // Contractor Admin owns all MFA enablement and email transport configuration.
    Route::get('/admin/mail/providers', [ContractorMailSettingsController::class, 'index']);
    Route::post('/admin/mail/providers', [ContractorMailSettingsController::class, 'createProvider'])->middleware('throttle:amc-write');
    Route::patch('/admin/mail/providers/{provider}', [ContractorMailSettingsController::class, 'updateProvider'])->middleware('throttle:amc-write');
    Route::delete('/admin/mail/providers/{provider}', [ContractorMailSettingsController::class, 'removeProvider'])->middleware('throttle:amc-write');
    Route::put('/admin/mail/default', [ContractorMailSettingsController::class, 'setDefault'])->middleware('throttle:amc-write');
    Route::get('/admin/mail/clients/{customer}', [ContractorMailSettingsController::class, 'client']);
    Route::put('/admin/mail/clients/{customer}', [ContractorMailSettingsController::class, 'saveClient'])->middleware('throttle:amc-write');
    Route::post('/admin/mail/providers/{provider}/test', [ContractorMailSettingsController::class, 'testProvider'])->middleware('throttle:amc-support-mail');
    Route::post('/admin/customers/onboard', [ClientOnboardingController::class,'store'])
        ->middleware('throttle:amc-write');
    // Client MFA is a company Admin responsibility, not a platform owner function.
    Route::get('/admin/client-mfa/customers/{customer}', [ClientMfaPolicyController::class,'customer']);
    Route::put('/admin/client-mfa/customers/{customer}', [ClientMfaPolicyController::class,'saveCustomer'])->middleware('throttle:amc-write');
    Route::get('/admin/client-mfa/users/{user}', [ClientMfaPolicyController::class,'user']);
    Route::put('/admin/client-mfa/users/{user}', [ClientMfaPolicyController::class,'saveUser'])->middleware('throttle:amc-write');
    Route::delete('/admin/client-mfa/users/{user}', [ClientMfaPolicyController::class,'clearUser'])->middleware('throttle:amc-write');
    Route::get('/notifications', [NotificationController::class,'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class,'markRead'])->middleware('throttle:amc-write');
    // Technical support is scoped to this single VPS installation, not a central vendor panel.
    Route::get('/system/support/telemetry', [\App\Http\Controllers\Api\V2\SystemTelemetryController::class, 'overview']);
    Route::get('/system/support/telemetry/traces/{traceId}', [\App\Http\Controllers\Api\V2\SystemTelemetryController::class, 'trace']);
    Route::put('/system/support/telemetry/settings', [\App\Http\Controllers\Api\V2\SystemTelemetryController::class, 'updateSettings'])->middleware('throttle:amc-write');
    Route::get('/system/support/timezones', [\App\Http\Controllers\Api\V2\InstallationTimeController::class, 'zones']);
    Route::get('/system/security', [\App\Http\Controllers\Api\V2\TechnicalSecurityController::class, 'index']);
    Route::put('/system/security/mfa', [\App\Http\Controllers\Api\V2\TechnicalSecurityController::class, 'updateMfa'])->middleware('throttle:amc-write');
    Route::put('/system/security/mail', [\App\Http\Controllers\Api\V2\TechnicalSecurityController::class, 'updateMail'])->middleware('throttle:amc-write');
    Route::post('/system/security/mail/test', [\App\Http\Controllers\Api\V2\TechnicalSecurityController::class, 'testMail'])->middleware('throttle:amc-support-mail');
    Route::get('/system/support/logs', [\App\Http\Controllers\Api\V2\SystemLogsController::class, 'index']);
    // AMC-RETENTION-V10.11: explicit role checks in RetentionSettingsController.
    Route::get('/system/support/retention', [\App\Http\Controllers\Api\V2\RetentionSettingsController::class, 'technicalIndex']);
    Route::put('/system/support/retention', [\App\Http\Controllers\Api\V2\RetentionSettingsController::class, 'technicalUpdate'])->middleware('throttle:amc-write');
    Route::get('/admin/retention', [\App\Http\Controllers\Api\V2\RetentionSettingsController::class, 'businessIndex']);
    Route::put('/admin/retention', [\App\Http\Controllers\Api\V2\RetentionSettingsController::class, 'businessUpdate'])->middleware('throttle:amc-write');
    Route::get('/system/support/overview', [SystemSupportController::class, 'overview']);
    Route::get('/system/support/report', [SystemSupportController::class, 'report']);
    Route::get('/system/support/failed-jobs', [SystemSupportController::class, 'failedJobs']);
    Route::get('/system/support/audit', [SystemSupportController::class, 'audit']);
    Route::get('/system/support/settings', [SystemSupportController::class, 'settings']);
    Route::put('/system/support/settings', [SystemSupportController::class, 'updateSettings'])->middleware('throttle:amc-write');
    Route::post('/system/support/test-email', [SystemSupportController::class, 'testEmail'])->middleware('throttle:amc-support-mail');
    Route::get('/system/admins', [ContractorAdminController::class,'index']);
    Route::post('/system/admins', [ContractorAdminController::class,'store'])->middleware('throttle:amc-write');
    Route::patch('/system/admins/{id}/status', [ContractorAdminController::class,'changeStatus'])
        ->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/users/{id}/assets', [\App\Http\Controllers\Api\V2\UserAssetsController::class,'show'])->whereNumber('id');
    Route::put('/users/{id}/assets', [\App\Http\Controllers\Api\V2\UserAssetsController::class,'update'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::get('/users', [UsersController::class,'index']);
    Route::get('/users/{id}', [UsersController::class,'show'])->whereNumber('id');
    Route::delete('/users/{id}', [UsersController::class,'destroy'])->middleware('throttle:amc-write')->whereNumber('id');
    Route::post('/users', [UsersController::class,'store'])->middleware('throttle:amc-write');
    Route::patch('/users/{id}/status', [UsersController::class,'changeStatus'])
        ->middleware('throttle:amc-write')->whereNumber('id');
});
