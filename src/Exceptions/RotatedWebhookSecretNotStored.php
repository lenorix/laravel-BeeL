<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/**
 * The secret was rotated but the store callback failed. BeeL already invalidated the old secret and
 * won't show the new one again, so it travels in $secret (never in the message): persist it now.
 */
final class RotatedWebhookSecretNotStored extends \RuntimeException
{
    public function __construct(
        public readonly string $subscriptionId,
        #[\SensitiveParameter] public readonly string $secret,
        \Throwable $storeFailure,
    ) {
        parent::__construct("Rotated the secret of BeeL webhook subscription {$subscriptionId} but could not store it; the old secret no longer works.", 0, $storeFailure);
    }
}
