<?php

use Enadstack\ServiceClient\ServiceClient;
use Enadstack\ServiceClient\ServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

    public static function fakeInstance(): object
    {
        return new class {};
    }
}

beforeEach(function () {
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

it('does not send an idempotency key on reads', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    (new DemoClient)->show();

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Idempotency-Key'));
});

it('maps the error envelope to ServiceException', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'INSUFFICIENT_POINTS', 'message' => 'No.', 'details' => ['need' => 10]]], 422)]);

    try {
        (new DemoClient)->earn([]);
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

it('does not retry on HTTP error responses', function () {
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

it('binds a fake in the container', function () {
    $fake = DemoClient::fake();

    expect(app(DemoClient::class))->toBe($fake);
});
