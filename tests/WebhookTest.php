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
