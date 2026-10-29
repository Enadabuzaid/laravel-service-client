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

- Headers: `Authorization`, `X-Application`, `X-Tenant-Reference` (`->forTenant()`), `X-Request-Id`, `Idempotency-Key` on writes (`->withIdempotencyKey()`, uuid if omitted).
- Retries on connection errors only. Failure → `ServiceException` (`code()`, `status()`, `details()`); unreachable → `SERVICE_UNREACHABLE`.
- `isConfigured()` is false when the URL is unset.
- `Client::fake()` binds a fake (override `fakeInstance()` in your client).
- Webhooks: `->middleware('service-webhook:loyalty')` (HMAC signature, 5-min window, dedupe on `X-Webhook-Id`). `WebhookSignature` is an inline copy until the contracts verifier lands.

Publish config: `php artisan vendor:publish --tag=service-client-config`. Tests: `vendor/bin/pest`.
