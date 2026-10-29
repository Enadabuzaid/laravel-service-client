<?php

namespace Enadstack\ServiceClient\Http;

/** Signature: sha256=HMAC(secret, timestamp + "." + body). */
class WebhookSignature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $body, string $signature, int $tolerance = 300, ?int $now = null): bool
    {
        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }
}
