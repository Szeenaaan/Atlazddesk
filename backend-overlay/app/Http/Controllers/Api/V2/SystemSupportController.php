<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\System\SupportDiagnostics;
use App\Services\Mail\ContractorMailService;
use App\Support\{AmcAudit, InstallationSettings, SafeDiagnosticSearch};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Log};
use Illuminate\Validation\ValidationException;
use Throwable;

/** Per-installation technical support. Never includes customer/ticket workspace privileges. */
final class SystemSupportController extends Controller
{
    public function __construct(
        private readonly SupportDiagnostics $diagnostics,
        private readonly InstallationSettings $settings,
        private readonly \App\Services\System\InstallationTimezoneService $timezone,
        private readonly \App\Services\System\TechnicalSecurityService $technical,
    ) {}

    public function overview(Request $request)
    {
        $this->authorizeTechnical($request);
        return $this->privateJson(['data' => $this->diagnostics->collect()]);
    }

    public function report(Request $request)
    {
        $this->authorizeTechnical($request);
        AmcAudit::record($request, 'system.support_report.exported', $request->user());
        return response()->json(['data' => $this->diagnostics->collect()])
            ->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="amc-support-diagnostics.json"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function failedJobs(Request $request)
    {
        $this->authorizeTechnical($request);
        // AMC-DIAGNOSTIC-SEARCH-V10.9: searchable safe metadata only.
        if (is_string($request->query('q'))) {
            $request->merge(['q' => trim($request->query('q'))]);
        }
        $filters = $request->validate(['q' => ['sometimes', 'string', 'min:2', 'max:100']]);
        $query = DB::table('failed_jobs');
        SafeDiagnosticSearch::apply($query, ['uuid', 'connection', 'queue'], (string) ($filters['q'] ?? ''));
        $rows = $query->orderByDesc('failed_at')->limit(50)
            ->get(['uuid', 'connection', 'queue', 'failed_at']);
        // Never query or return serialized payload, exception, job parameters, or customer data.
        return $this->privateJson(['data' => $rows]);
    }

    public function audit(Request $request)
    {
        $this->authorizeTechnical($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'event' => ['sometimes', 'string', 'max:100'],
            'request_id' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = DB::table('activity_logs')->orderByDesc('id');
        if (!empty($filters['event'])) $query->where('event', $filters['event']);
        if (!empty($filters['request_id'])) $query->where('request_id', $filters['request_id']);
        return response()->json($query->select('id','created_at','user_id','event','request_id')->paginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request)
    {
        $this->authorizeTechnical($request);
        return $this->privateJson(['data' => $this->settings->all()]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizeTechnical($request);
        $data = $request->validate([
            'installation_label' => ['required','string','min:2','max:90'],
            'support_email' => ['nullable','email','max:255'],
            'queue_warning_depth' => ['required','integer','min:1','max:100000'],
            'disk_warning_percent' => ['required','integer','min:1','max:70'],
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'timezone' => ['sometimes', 'string', 'max:80'],
            'current_password' => ['required','string', 'max:1024'],
        ]);
        $this->confirmPassword($request, $data['current_password']);
        $currentTimezone = $this->timezone->settings();
        $country = (array_key_exists('country_code', $data) ? $data['country_code'] : $currentTimezone['country_code']) ?: null;
        $zone = $data['timezone'] ?? $currentTimezone['timezone'];
        abort_unless($this->timezone->validCombination($country, $zone), 422,
            'Select a timezone that belongs to the selected country.');
        DB::transaction(function () use ($request, $data, $country, $zone): void {
            foreach (['installation_label','support_email','queue_warning_depth','disk_warning_percent'] as $key) {
                DB::table('amc_installation_settings')->updateOrInsert(['key' => $key], [
                    'value' => (string) ($data[$key] ?? ''),
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
            foreach (['display_country' => $country ?? '', 'display_timezone' => $zone] as $key => $value) {
                DB::table('amc_installation_settings')->updateOrInsert(['key' => $key], [
                    'value' => $value, 'updated_by' => $request->user()->id, 'updated_at' => now(),
                ]);
            }
            // Never audit passwords or transport secrets.
            AmcAudit::record($request, 'system.support_settings.updated', $request->user(), [
                'changed_keys' => collect($data)->except('current_password')->keys()->all(),
            ]);
        });
        return $this->privateJson(['data' => $this->settings->all()]);
    }

    public function testEmail(Request $request)
    {
        $this->authorizeTechnical($request);
        $data = $request->validate(['current_password' => ['required','string', 'max:1024']]);
        $this->confirmPassword($request, $data['current_password']);
        abort_unless($this->technical->mailReady(), 422, 'Configure technical email delivery first.');
        try {
            $this->technical->sendTest($request);
        } catch (Throwable $e) {
            AmcAudit::record($request, 'system.mail_test.failed', $request->user());
            Log::channel('amc_mail')->warning('amc.support.mail_test_failed', ['exception_class' => $e::class]);
            return $this->privateJson(['message' => 'The test email could not be sent. Try again. If the problem continues, contact your server administrator.'], 503);
        }
        AmcAudit::record($request, 'system.mail_test.queued', $request->user());
        return $this->privateJson(['message' => 'Check your email for the test message. It may take a moment to arrive.']);
    }

    private function confirmPassword(Request $request, string $password): void
    {
        if (! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }
    }

    private function authorizeTechnical(Request $request): void
    {
        abort_unless($request->user()?->role === 'super_admin', 403);
    }

    private function privateJson(array $payload, int $status = 200)
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}
