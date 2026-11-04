<?php

return [
    // Sent as X-Application on every call. IDENTITY_APPLICATION_CODE is the legacy (Sofrito) name.
    'application' => env('SERVICE_APPLICATION_CODE', env('IDENTITY_APPLICATION_CODE')),

    // Writes must carry an explicit idempotency key (withIdempotencyKey()).
    // Set true to fall back to a random per-call UUID (logged as a warning).
    'allow_generated_idempotency_keys' => false,

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
