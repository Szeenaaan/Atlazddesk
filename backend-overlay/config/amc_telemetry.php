<?php

return [
    // The Super Admin can enable collection after database migrations are applied.
    // No request bodies, SQL text/bindings, URLs with IDs or credentials are stored.
    'enabled' => (bool) env('AMC_TELEMETRY_ENABLED', false),
    'sample_percent' => (int) env('AMC_TELEMETRY_SAMPLE_PERCENT', 10),
    'retention_days' => (int) env('AMC_TELEMETRY_RETENTION_DAYS', 7),
    'slow_request_ms' => (int) env('AMC_TELEMETRY_SLOW_MS', 1000),
];
