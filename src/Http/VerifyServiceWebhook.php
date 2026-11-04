<?php

namespace Enadstack\ServiceClient\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware `service-webhook:{name}`; secret from services.{name}.webhook_secret. */
class VerifyServiceWebhook
{
    public function handle(Request $request, Closure $next, string $name): Response
    {
        $secret = (string) config("services.{$name}.webhook_secret");
        $id = (string) $request->header('X-Webhook-Id');

        if ($secret === '' || $id === ''
            || ! WebhookSignature::verify(
                $secret,
                (string) $request->header('X-Webhook-Timestamp'),
                $request->getContent(),
                (string) $request->header('X-Webhook-Signature'),
                (int) config('service-client.webhook.tolerance_seconds'),
            )) {
            return response()->json(['error' => ['code' => 'INVALID_WEBHOOK_SIGNATURE', 'message' => 'Invalid webhook signature.', 'details' => (object) []]], 401);
        }

        // The signature covers `timestamp.body` only; X-Webhook-Id is NOT signed, so it
        // can be forged or reused. De-dupe on the id AND on a hash of what was signed.
        $hash = hash('sha256', (string) $request->header('X-Webhook-Timestamp').'.'.$request->getContent());
        $keys = ["service-webhook:{$name}:id:{$id}", "service-webhook:{$name}:hash:{$hash}"];

        foreach ($keys as $key) {
            if (Cache::has("{$key}:processed")) {
                return response()->json(['data' => ['duplicate' => true]]);
            }
        }

        // Atomic locks stop concurrent redeliveries running the handler twice.
        $locks = [];

        foreach ($keys as $key) {
            $lock = Cache::lock("{$key}:lock", (int) config('service-client.webhook.lock_seconds', 60));

            if (! $lock->get()) {
                foreach ($locks as $held) {
                    $held->release();
                }

                // Same event is being handled right now: non-2xx so the sender redelivers.
                return response()->json(['error' => ['code' => 'WEBHOOK_IN_PROGRESS', 'message' => 'This webhook is already being processed.', 'details' => (object) []]], 409);
            }

            $locks[] = $lock;
        }

        try {
            $response = $next($request);

            // Only a handled (non-5xx) delivery is final; a failure stays redeliverable.
            if ($response->getStatusCode() < 500) {
                foreach ($keys as $key) {
                    Cache::put("{$key}:processed", 1, (int) config('service-client.webhook.dedupe_ttl_seconds'));
                }
            }

            return $response;
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }
}
