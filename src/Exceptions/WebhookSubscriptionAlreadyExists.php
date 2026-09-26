<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

/** A subscription already delivers to that URL; a second one would sign with a secret the app can't verify. */
final class WebhookSubscriptionAlreadyExists extends \RuntimeException
{
    public function __construct(public readonly string $subscriptionId, public readonly string $url)
    {
        parent::__construct("BeeL webhook subscription {$subscriptionId} already delivers to {$url}; rotate its secret instead of creating another one.");
    }
}
