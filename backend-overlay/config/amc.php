<?php

return [
    'api_timeout_seconds' => (int) env('AMC_API_TIMEOUT', 25),
    'slow_query_ms' => (int) env('AMC_SLOW_QUERY_MS', 500),
    'slow_request_ms' => (int) env('AMC_SLOW_REQUEST_MS', 1000),
    'sla_warning_minutes' => (int) env('AMC_SLA_WARNING_MINUTES', 30),
    'contract_warning_days' => (int) env('AMC_CONTRACT_WARNING_DAYS', 30),
    'attachments_enabled' => (bool) env('AMC_ATTACHMENTS_ENABLED', false),
    'max_upload_kb' => (int) env('AMC_MAX_UPLOAD_KB', 10240),
    'sms_enabled' => (bool) env('AMC_SMS_ENABLED', false),
    'sms_provider' => env('AMC_SMS_PROVIDER', ''),
    'sms_api_key' => env('AMC_SMS_API_KEY', ''),
    'sms_template_id' => env('AMC_SMS_TEMPLATE_ID', ''),
];
