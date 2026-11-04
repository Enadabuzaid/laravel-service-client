<?php

use Enadstack\ServiceClient\ServiceClient;
use Enadstack\ServiceClient\ServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class DemoClient extends ServiceClient
{
    protected function serviceName(): string
    {
        return 'demo';
    }

    public function earn(array $data): array
    {
        return $this->post('/api/v1/earn', $data)->json('data');
    }

    public function show(): array
    {
        return $this->get('/api/v1/thing')->json('data');
    }
}

class CustomFakeClient extends DemoClient
{
    public static function fakeInstance(): ?object
    {
        return new class {};
    }
}

beforeEach(function () {
    Sleep::fake();
    config([
        'service-client.application' => 'tafadel',
        'services.demo' => ['url' => 'http://demo.test', 'api_key' => 'ia_live_x'],
    ]);
});

it('sends standard headers and idempotency key on writes', function () {
    Http::fake(['demo.test/*' => Http::response(['data' => ['ok' => true]])]);

    (new DemoClient)->forTenant('tafadel:tenant:12')->withIdempotencyKey('k-1')->earn(['points' => 5]);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://demo.test/api/v1/earn'
        && $r->hasHeader('Authorization', 'Bearer ia_live_x')
        && $r->hasHeader('X-Application', 'tafadel')
        && $r->hasHeader('X-Tenant-Reference', 'tafadel:tenant:12')
        && $r->hasHeader('Idempotency-Key', 'k-1')
        && $r->hasHeader('X-Request-Id')
        && $r['points'] === 5);
});

it('throws when a write has no explicit idempotency key', function () {
    Http::fake();

    try {
        (new DemoClient)->earn([]);
        $this->fail('expected exception');
    } catch (ServiceException $e) {
        expect($e->code())->toBe('IDEMPOTENCY_KEY_REQUIRED');
    }

    Http::assertNothingSent();
});

it('generates and warns when allowed by config', function () {
    config(['service-client.allow_generated_idempotency_keys' => true]);
    Log::spy();
    Http::fake(['*' => Http::response(['data' => []])]);

    (new DemoClient)->earn([]);

    Http::assertSent(fn (Request $r) => Str::isUuid($r->header('Idempotency-Key')[0]));
    Log::shouldHaveReceived('warning')->once();
});

it('mirrors the key into the body when asked', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    (new DemoClient)->withIdempotencyKey('k-9', inBody: true)->earn(['a' => 1]);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'k-9') && $r['idempotency_key'] === 'k-9' && $r['a'] === 1);
});

it('does not send an idempotency key on reads', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    (new DemoClient)->show();

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Idempotency-Key'));
});

it('maps the error envelope to ServiceException', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'INSUFFICIENT_POINTS', 'message' => 'No.', 'details' => ['need' => 10]]], 422)]);

    try {
        (new DemoClient)->withIdempotencyKey('k')->earn([]);
        $this->fail('expected exception');
    } catch (ServiceException $e) {
        expect($e->code())->toBe('INSUFFICIENT_POINTS')
            ->and($e->status())->toBe(422)
            ->and($e->details())->toBe(['need' => 10]);
    }
});

it('falls back to SERVICE_ERROR for non-envelope failures', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => (new DemoClient)->show())->toThrow(fn (ServiceException $e) => $e->code() === 'SERVICE_ERROR');
});

it('maps connection failures to SERVICE_UNREACHABLE after retrying', function () {
    config(['service-client.retry_delay_ms' => 0, 'service-client.retries' => 2]);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('refused');
    });

    try {
        (new DemoClient)->show();
        $this->fail('expected exception');
    } catch (ServiceException $e) {
        expect($e->code())->toBe('SERVICE_UNREACHABLE')->and($calls)->toBe(3);
    }
});

it('retries GET on 503 and then succeeds', function () {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push([], 503)->push(['data' => ['ok' => 1]])]);

    expect((new DemoClient)->show())->toBe(['ok' => 1]);
    Http::assertSentCount(2);
});

it('honours Retry-After and bounds the attempts', function () {
    Sleep::fake();
    config(['service-client.retries' => 2]);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '2'])]);

    expect(fn () => (new DemoClient)->show())->toThrow(fn (ServiceException $e) => $e->code() === 'RATE_LIMITED');
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

it('caps Retry-After at max_retry_after_seconds', function () {
    Sleep::fake();
    config(['service-client.retries' => 1, 'service-client.max_retry_after_seconds' => 5]);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '3600'])]);

    try {
        (new DemoClient)->show();
    } catch (ServiceException) {
    }

    Sleep::assertSequence([Sleep::for(5000)->milliseconds()]);
});

it('retries writes with an explicit key and reuses the key', function () {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()->push([], 502)->push(['data' => []])]);

    (new DemoClient)->withIdempotencyKey('k-7')->earn([]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'k-7'));
    $keys = Http::recorded()->map(fn ($p) => $p[0]->header('Idempotency-Key')[0])->unique();
    expect($keys->all())->toBe(['k-7']);
});

it('does not retry statuses for writes with a generated key', function () {
    Sleep::fake();
    config(['service-client.allow_generated_idempotency_keys' => true]);
    Http::fake(['*' => Http::response([], 503)]);

    try {
        (new DemoClient)->earn([]);
    } catch (ServiceException) {
    }

    Http::assertSentCount(1);
});

it('does not retry non-retryable statuses', function () {
    Http::fake(['*' => Http::response([], 500)]);

    try {
        (new DemoClient)->show();
    } catch (ServiceException) {
    }

    Http::assertSentCount(1);
});

it('retries connection errors on writes with the same key', function () {
    Sleep::fake();
    $keys = [];
    Http::fake(function (Request $r) use (&$keys) {
        $keys[] = $r->header('Idempotency-Key')[0];
        throw new ConnectionException('refused');
    });

    expect(fn () => (new DemoClient)->withIdempotencyKey('k-8')->earn([]))->toThrow(ServiceException::class);
    expect($keys)->toBe(['k-8', 'k-8', 'k-8']);
});

it('lets a service override the retry count', function () {
    Sleep::fake();
    config(['services.demo.retries' => 0]);
    Http::fake(['*' => Http::response([], 503)]);

    try {
        (new DemoClient)->show();
    } catch (ServiceException) {
    }

    Http::assertSentCount(1);
});

it('is not configured without a url', function () {
    config(['services.demo.url' => null]);

    expect((new DemoClient)->isConfigured())->toBeFalse()
        ->and(fn () => (new DemoClient)->show())->toThrow(ServiceException::class);
});

it('binds a custom fake object in the container', function () {
    $fake = CustomFakeClient::fake();

    expect(app(CustomFakeClient::class))->toBe($fake);

    CustomFakeClient::resetFake();
    expect(app(CustomFakeClient::class))->not->toBe($fake);
});

it('binds an explicitly passed fake object', function () {
    $mine = new stdClass;

    expect(DemoClient::fake($mine))->toBe($mine)->and(app(DemoClient::class))->toBe($mine);
    DemoClient::resetFake();
});

it('fakes out of the box and records requests', function () {
    $fake = DemoClient::fake(['demo.test/*' => Http::response(['data' => ['ok' => true]])]);

    expect((new DemoClient)->withIdempotencyKey('k')->earn(['points' => 3]))->toBe(['ok' => true]);

    $fake->assertSentCount(1)
        ->assertSent(fn (Request $r) => $r->method() === 'POST' && $r['points'] === 3)
        ->assertNotSent(fn (Request $r) => $r->method() === 'GET');
});

it('scopes recorded requests to the faked service', function () {
    config(['services.other' => ['url' => 'http://other.test']]);
    $fake = DemoClient::fake(['demo.test/*' => Http::response(['data' => []])]);
    Http::fake(['other.test/*' => Http::response([])]);

    (new DemoClient)->show();
    Http::get('http://other.test/x');

    $fake->assertSentCount(1);
});

it('resets the container binding and http state', function () {
    $fake = DemoClient::fake(['*' => Http::response(['data' => []])]);
    (new DemoClient)->show();
    $fake->assertSentCount(1);

    $fake->reset();

    expect(Http::recorded())->toHaveCount(0);
});

it('lets one app call two services under different application codes', function () {
    config(['services.demo.application' => 'salonakhub-b2b']);
    Http::fake(['*' => Http::response(['data' => []])]);

    (new DemoClient)->show();
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Application', 'salonakhub-b2b'));

    config(['services.demo.application' => null]);
    (new DemoClient)->show();
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Application', 'tafadel'));
});

it('maps non-envelope bodies by status and preserves the message', function (int $status, string $code) {
    Http::fake(['*' => Http::response(['message' => 'Unauthorized'], $status, ['Retry-After' => '7'])]);

    try {
        (new DemoClient)->show();
        $this->fail('expected exception');
    } catch (ServiceException $e) {
        expect($e->code())->toBe($code)
            ->and($e->status())->toBe($status)
            ->and($e->getMessage())->toBe('Unauthorized')
            ->and($e->retryAfter())->toBe(7);
    }
})->with([
    [401, 'UNAUTHORIZED'], [403, 'FORBIDDEN'], [404, 'NOT_FOUND'], [409, 'CONFLICT'],
    [422, 'VALIDATION_FAILED'], [429, 'RATE_LIMITED'], [500, 'SERVICE_ERROR'], [503, 'SERVICE_ERROR'],
]);

it('exposes validation errors as details for 422 bodies', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid.', 'errors' => ['name' => ['required']]], 422)]);

    expect(fn () => (new DemoClient)->show())->toThrow(fn (ServiceException $e) => $e->details() === ['name' => ['required']]);
});

it('reports retryable statuses', function (int $status, bool $retryable) {
    expect((new ServiceException('X', 'm', $status))->isRetryable())->toBe($retryable)
        ->and(ServiceException::unreachable('demo')->isRetryable())->toBeTrue();
})->with([[429, true], [502, true], [503, true], [504, true], [500, false], [404, false], [422, false]]);

it('parses an HTTP-date Retry-After', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 30).' GMT'])]);

    expect(fn () => (new DemoClient)->show())->toThrow(fn (ServiceException $e) => $e->retryAfter() >= 28 && $e->retryAfter() <= 30);
});

function pendingOptions(): array
{
    return (function () {
        $pending = $this->pending('GET');

        return (new ReflectionProperty($pending, 'options'))->getValue($pending);
    })->call(new DemoClient);
}

it('falls back to default timeouts when configured with null or 0', function (mixed $value) {
    config(['services.demo.timeout' => $value, 'services.demo.connect_timeout' => $value, 'service-client.timeout' => $value, 'service-client.connect_timeout' => $value]);

    expect(pendingOptions())->toMatchArray(['timeout' => 10, 'connect_timeout' => 5]);
})->with([null, 0, '0', -1]);

it('uses the service timeout when valid, else the package value', function () {
    config(['services.demo.timeout' => 30, 'services.demo.connect_timeout' => null, 'service-client.connect_timeout' => 2]);

    expect(pendingOptions())->toMatchArray(['timeout' => 30, 'connect_timeout' => 2]);
});
