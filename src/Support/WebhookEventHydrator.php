<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

use Lenorix\BeelSdk\Exception\WebhookPayloadError;
use Lenorix\BeelSdk\Generated\Model\WebhookEvent;
use Lenorix\BeelSdk\Webhook\WebhookSigner;
use Lenorix\BeelSdk\Webhook\WebhookVerifier;

/**
 * Turns an already verified webhook payload into the SDK's typed WebhookEvent.
 *
 * lenorix/beel-sdk only hydrates through WebhookVerifier::verifyEvent() (its event-to-model map
 * and date normalization are private), so this signs the payload with a throwaway secret and
 * verifies it again: the result is exactly the SDK's hydration, with no copy of its internals.
 * The authenticity check already happened on the real request.
 *
 * @internal
 */
final class WebhookEventHydrator
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws WebhookPayloadError If the payload doesn't match BeeL's event schema.
     */
    public static function hydrate(array $payload): WebhookEvent
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret = bin2hex(random_bytes(16));
        $timestamp = time();

        return (new WebhookVerifier($secret))->verifyEvent($body, (new WebhookSigner($secret))->sign($body, $timestamp), $timestamp);
    }
}
