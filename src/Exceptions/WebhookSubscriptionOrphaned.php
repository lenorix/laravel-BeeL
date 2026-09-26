<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** The store callback failed after creating a subscription, and deleting it failed too: delete it by hand. */
final class WebhookSubscriptionOrphaned extends \RuntimeException
{
    public function __construct(public readonly string $subscriptionId, \Throwable $storeFailure)
    {
        parent::__construct("Could not store the secret of new BeeL webhook subscription {$subscriptionId} nor delete it; delete it in BeeL.", 0, $storeFailure);
    }
}
