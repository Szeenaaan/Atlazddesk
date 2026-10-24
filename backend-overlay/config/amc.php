<?php

return [
    'account_mail_queue_connection' => env('ACCOUNT_MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database') === 'sync'
        ? 'database' : env('QUEUE_CONNECTION', 'database')),
    // Ticket mail must use a worker even when local API/in-app jobs use the sync driver.
    'ticket_mail_queue_connection' => env('TICKET_MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database') === 'sync'
        ? 'database' : env('QUEUE_CONNECTION', 'database')),
    'frontend_url' => env('FRONTEND_URL', ''),
    'maintenance_enabled' => (bool) env('AMC_MAINTENANCE_ENABLED', false),
    'api_timeout_seconds' => (int) env('AMC_API_TIMEOUT', 25),
    'slow_query_ms' => (int) env('AMC_SLOW_QUERY_MS', 500),
    'slow_request_ms' => (int) env('AMC_SLOW_REQUEST_MS', 1000),
    'sla_warning_minutes' => (int) env('AMC_SLA_WARNING_MINUTES', 30),
    'contract_warning_days' => (int) env('AMC_CONTRACT_WARNING_DAYS', 30),
    'attachments_enabled' => (bool) env('AMC_ATTACHMENTS_ENABLED', false),
    'scanner_host' => env('AMC_SCANNER_HOST', 'clamav'),
    'scanner_port' => (int) env('AMC_SCANNER_PORT', 3310),
    'max_upload_kb' => (int) env('AMC_MAX_UPLOAD_KB', 10240),
    'sms_enabled' => (bool) env('AMC_SMS_ENABLED', false),
    'sms_provider' => env('AMC_SMS_PROVIDER', ''),
    'sms_api_key' => env('AMC_SMS_API_KEY', ''),
    'sms_template_id' => env('AMC_SMS_TEMPLATE_ID', ''),
];
