<?php

return [
    // Sent as X-Application on every call. IDENTITY_APPLICATION_CODE is the legacy (Sofrito) name.
    'application' => env('SERVICE_APPLICATION_CODE', env('IDENTITY_APPLICATION_CODE')),

    'timeout' => 10,
    'connect_timeout' => 5,

    // Retries on connection errors only (never on HTTP responses).
    'retries' => 2,
    'retry_delay_ms' => 200,

    'webhook' => [
        'tolerance_seconds' => 300,
        'dedupe_ttl_seconds' => 86400,
    ],
];
