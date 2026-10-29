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

        // Duplicate delivery (at-least-once): acknowledge without reprocessing.
        $fresh = Cache::add("service-webhook:{$name}:{$id}", 1, (int) config('service-client.webhook.dedupe_ttl_seconds'));

        if (! $fresh) {
            return response()->json(['data' => ['duplicate' => true]]);
        }

        return $next($request);
    }
}
