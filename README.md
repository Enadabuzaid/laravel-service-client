# enadstack/laravel-service-client

Base for every `enadstack/<name>-client`. See `laravel-api-contracts/docs/CONNECTING.md`.

## Write a client
```php
class LoyaltyClient extends \Enadstack\ServiceClient\ServiceClient
{
    protected function serviceName(): string { return 'loyalty'; }   // services.loyalty.*

    public function balance(string $id): array { return $this->get("/api/v1/accounts/{$id}")->json('data'); }
}
```
`config/services.php`: `'loyalty' => ['url' => env('LOYALTY_SERVICE_URL'), 'api_key' => env('LOYALTY_SERVICE_API_KEY'), 'webhook_secret' => env('LOYALTY_WEBHOOK_SECRET')]`
(optional `timeout`, `connect_timeout`). App code: `SERVICE_APPLICATION_CODE`.

- Headers: `Authorization`, `X-Application` (`services.<name>.application` overrides `service-client.application`, so one app can call two services under different codes), `X-Tenant-Reference` (`->forTenant()`), `X-Request-Id`, `Idempotency-Key` on writes.
- Timeouts: `services.<name>.timeout|connect_timeout`, then `service-client.*`, then 10s/5s. null/absent/0 never means "no timeout".

## Idempotency
Writes (POST/PUT/PATCH/DELETE) require an explicit key: `->withIdempotencyKey($key)`. Without one a `ServiceException` with code `IDEMPOTENCY_KEY_REQUIRED` is thrown. For services that read the body field instead of the header (e.g. notification-service) use `->withIdempotencyKey($key, inBody: true)`; the key is also sent as `idempotency_key` in the JSON body. Set `service-client.allow_generated_idempotency_keys` to `true` to fall back to a random per-call UUID (logs a warning; such a key does not deduplicate caller retries).

## Errors
Failures throw `ServiceException`: `code()`, `getMessage()`, `status()`, `details()`, `retryAfter()` (seconds, from `Retry-After`), `isRetryable()` (unreachable, 429, 502, 503, 504). The `{"error":{code,message,details}}` envelope is used when present; otherwise the code comes from the status (401 `UNAUTHORIZED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 409 `CONFLICT`, 422 `VALIDATION_FAILED` with `errors` as details, 429 `RATE_LIMITED`, else `SERVICE_ERROR`) and the body `message` is kept. Unreachable is `SERVICE_UNREACHABLE`.

## Retries
`retries` extra attempts (global, or `services.<name>.retries`), `retry_delay_ms` between them. Connection errors are always retried. `retry_statuses` (default 429/502/503/504) are retried only for GET and for writes with an explicit idempotency key, sleeping for `Retry-After` (capped by `max_retry_after_seconds`). The same idempotency key and request id are reused on every attempt.

## Testing
`DemoClient::fake(['demo.test/*' => Http::response([...])])` fakes the HTTP layer (typed methods run end to end) and returns a recorder: `assertSent(fn (Request $r) => ...)`, `assertNotSent()`, `assertSentCount($n)`, `requests()`, scoped to that service's URL. Plain `Http::fake()` works too. `DemoClient::fake($object)` (or overriding `fakeInstance()`) binds your own fake in the container instead. `DemoClient::resetFake()` / `$fake->reset()` clears the binding and Http state.

## Webhooks
`->middleware('service-webhook:loyalty')`: HMAC signature over `timestamp.body` (5-min window). The `X-Webhook-Id` header is NOT covered by the signature, so events are de-duplicated on the id and on `sha256(timestamp.body)`. A per-event atomic lock (needs a lock-capable cache store) answers 409 `WEBHOOK_IN_PROGRESS` to concurrent redeliveries; the event is marked processed only after the handler returns a non-5xx response, so a failing handler (5xx or exception) can be redelivered. Note a 4xx from your handler is final. `WebhookSignature` is an inline copy until the contracts verifier lands.

- `isConfigured()` is false when the URL is unset.

Publish config: `php artisan vendor:publish --tag=service-client-config`. Tests: `vendor/bin/pest`.
