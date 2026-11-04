# Changelog

## 0.1.0

First tagged release, with first-consumer fixes over the initial base package.

### Behaviour changes
- Writes now throw `IDEMPOTENCY_KEY_REQUIRED` unless `withIdempotencyKey()` was used or `service-client.allow_generated_idempotency_keys` is true (default false). `withIdempotencyKey($key, inBody: true)` mirrors the key into the JSON body.
- Non-envelope error bodies map by HTTP status (`UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, `VALIDATION_FAILED`, `RATE_LIMITED`) and keep the body message. Previously all were `SERVICE_ERROR`. `ServiceException` gains `retryAfter()` and `isRetryable()`.
- HTTP 429/502/503/504 are now retried for GET and keyed writes (previously only connection errors), honouring `Retry-After`. New config: `retry_statuses`, `max_retry_after_seconds`, per-service `retries`.
- Timeout null/0/negative falls back to the default instead of 0 (infinite).
- Webhook middleware marks events processed only after a non-5xx handler response, uses atomic locks (409 while in flight) and also de-dupes on `sha256(timestamp.body)`. New config `webhook.lock_seconds`.
- `fake()` fakes the HTTP layer and returns a `ServiceFake` recorder by default; `fakeInstance()` now returns `?object` (null = default recorder) and `fake($object)` still binds a custom fake. Added `resetFake()`.
- `services.<name>.application` overrides `X-Application` per service.
