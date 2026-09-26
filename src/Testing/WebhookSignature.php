<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Testing;

use Lenorix\BeelSdk\Webhook\WebhookSigner;

/** Builds a BeeL-Signature header for tests, the way BeeL signs deliveries. */
final class WebhookSignature
{
    /**
     * Sign the exact raw body you will send: signing a re-encoded array can differ from the bytes
     * posted and fail verification.
     */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        return (new WebhookSigner($secret))->sign($rawBody, $timestamp);
    }
}
