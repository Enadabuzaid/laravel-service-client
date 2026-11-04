<?php

return [
    // Sent as X-Application on every call. IDENTITY_APPLICATION_CODE is the legacy (Sofrito) name.
    'application' => env('SERVICE_APPLICATION_CODE', env('IDENTITY_APPLICATION_CODE')),

    // Writes must carry an explicit idempotency key (withIdempotencyKey()).
    // Set true to fall back to a random per-call UUID (logged as a warning).
    'allow_generated_idempotency_keys' => false,

    'timeout' => 10,
    'connect_timeout' => 5,

    // Extra attempts after the first (override per service: services.<name>.retries).
    // Connection errors are always retried; the HTTP statuses below are retried
    // only for GET and for writes with an explicit idempotency key.
    'retries' => 2,
    'retry_delay_ms' => 200,
    'retry_statuses' => [429, 502, 503, 504],
    // Upper bound on how long a Retry-After header can make us sleep.
    'max_retry_after_seconds' => 30,

    'webhook' => [
        'tolerance_seconds' => 300,
        'dedupe_ttl_seconds' => 86400,
    ],
];
