<?php
namespace App\Support;

use App\Models\AmcIncident;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IncidentRecorder
{
    public static function record(Throwable $error, string $kind = 'exception', ?string $requestId = null, ?string $route = null, ?int $actorId = null): void
    {
        // Never persist request bodies, credentials, API keys, headers, or stack traces in DB.
        // Laravel logs keep the original stack trace under their independently configured retention.
        try {
            $exceptionClass = get_class($error);
            // AMC-SUPPORT-LOGS-V10.6: log only category/class, never exception message or payload.
            try {
                Log::channel('amc_application')->error('application.incident', [
                    'kind' => $kind, 'exception_class' => $exceptionClass,
                ]);
            } catch (\Throwable) { /* Logging must not break the request. */ }
            $fingerprint = hash('sha256', $kind.'|'.$exceptionClass.'|'.$error->getFile().'|'.$error->getLine());
            // Error messages may contain SQL bindings or secrets; store only generic descriptors.
            $safe = substr($exceptionClass.' at '.basename($error->getFile()).':'.$error->getLine(), 0, 1000);
            $existing = AmcIncident::query()->where('fingerprint', $fingerprint)->whereNull('resolved_at')->first();
            if ($existing) {
                $existing->increment('occurrences');
                $existing->update(['last_seen_at'=>now(), 'request_id'=>$requestId, 'route'=>$route, 'actor_id'=>$actorId]);
            } else {
                AmcIncident::create([
                    'fingerprint'=>$fingerprint, 'kind'=>$kind, 'severity'=>'error',
                    'exception_class'=>$exceptionClass, 'message'=>$safe,
                    'request_id'=>$requestId, 'route'=>$route, 'actor_id'=>$actorId,
                    'first_seen_at'=>now(), 'last_seen_at'=>now(),
                ]);
            }
        } catch (Throwable $recordingError) {
            // DB or telemetry outages must never cascade into application failure.
            Log::warning('amc.incident_recorder_unavailable', ['type'=>get_class($recordingError)]);
        }
    }
}
