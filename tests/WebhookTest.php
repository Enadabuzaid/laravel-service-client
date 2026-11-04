<?php

use Enadstack\ServiceClient\Http\WebhookSignature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['services.demo.webhook_secret' => 'whsec_test']);
    Cache::flush();
    Route::post('hooks/demo', fn () => response()->json(['handled' => true]))->middleware('service-webhook:demo');
});

function deliver($test, string $id = 'evt-1', ?int $ts = null, ?string $sig = null, string $body = '{"type":"x"}')
{
    $ts = (string) ($ts ?? time());

    return $test->call('POST', 'hooks/demo', [], [], [], [
        'HTTP_X_WEBHOOK_ID' => $id,
        'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
        'HTTP_X_WEBHOOK_SIGNATURE' => $sig ?? WebhookSignature::sign('whsec_test', $ts, $body),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('accepts a valid signed webhook', function () {
    deliver($this)->assertOk()->assertJson(['handled' => true]);
});

it('rejects a bad signature', function () {
    deliver($this, sig: 'sha256=bad')->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');
});

it('rejects a tampered body', function () {
    $ts = time();
    deliver($this, ts: $ts, sig: WebhookSignature::sign('whsec_test', (string) $ts, 'other'))->assertUnauthorized();
});

it('rejects timestamps older than 5 minutes', function () {
    deliver($this, ts: time() - 301)->assertUnauthorized();
});

it('acknowledges but does not reprocess duplicate webhook ids', function () {
    deliver($this)->assertJson(['handled' => true]);
    deliver($this)->assertOk()->assertJson(['data' => ['duplicate' => true]]);
});

it('rejects when no secret is configured', function () {
    config(['services.demo.webhook_secret' => null]);
    deliver($this)->assertUnauthorized();
});

it('does not mark an event processed when the handler returns 5xx, so it is redeliverable', function () {
    $calls = 0;
    Route::post('hooks/flaky', function () use (&$calls) {
        return ++$calls === 1 ? response()->json(['error' => 'down'], 503) : response()->json(['handled' => true]);
    })->middleware('service-webhook:demo');

    $send = fn () => $this->call('POST', 'hooks/flaky', [], [], [], [
        'HTTP_X_WEBHOOK_ID' => 'evt-9',
        'HTTP_X_WEBHOOK_TIMESTAMP' => $ts = (string) time(),
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign('whsec_test', $ts, '{"a":1}'),
        'CONTENT_TYPE' => 'application/json',
    ], '{"a":1}');

    $send()->assertStatus(503);
    $send()->assertOk()->assertJson(['handled' => true]);
    $send()->assertOk()->assertJson(['data' => ['duplicate' => true]]);
    expect($calls)->toBe(2);
});

it('does not mark an event processed when the handler throws, and releases the lock', function () {
    $calls = 0;
    Route::post('hooks/boom', function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('boom');
        }

        return response()->json(['handled' => true]);
    })->middleware('service-webhook:demo');

    $ts = (string) time();
    $headers = [
        'HTTP_X_WEBHOOK_ID' => 'evt-10',
        'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign('whsec_test', $ts, '{}'),
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->withoutExceptionHandling();
    expect(fn () => $this->call('POST', 'hooks/boom', [], [], [], $headers, '{}'))->toThrow(RuntimeException::class);
    $this->call('POST', 'hooks/boom', [], [], [], $headers, '{}')->assertOk()->assertJson(['handled' => true]);
});

it('returns 409 while the same event is being processed', function () {
    $ts = (string) time();
    $body = '{"type":"x"}';
    $hash = hash('sha256', $ts.'.'.$body);
    $lock = Cache::lock("service-webhook:demo:hash:{$hash}:lock", 60);
    expect($lock->get())->toBeTrue();

    deliver($this, 'evt-11', (int) $ts)->assertStatus(409)->assertJsonPath('error.code', 'WEBHOOK_IN_PROGRESS');
    $lock->release();
    deliver($this, 'evt-11', (int) $ts)->assertOk()->assertJson(['handled' => true]);
});

it('de-dupes a replay with a different X-Webhook-Id but identical signed payload', function () {
    $ts = time();
    deliver($this, 'evt-a', $ts)->assertJson(['handled' => true]);
    deliver($this, 'evt-b', $ts)->assertOk()->assertJson(['data' => ['duplicate' => true]]);
});
