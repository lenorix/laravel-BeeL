<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Testing;

/** Builds a BeeL-Signature header for tests, the way BeeL signs deliveries. */
final class WebhookSignature
{
    /**
     * Sign the exact raw body you will send: signing a re-encoded array can differ from the bytes
     * posted and fail verification.
     */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
