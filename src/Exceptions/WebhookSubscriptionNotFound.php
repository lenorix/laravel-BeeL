<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Exceptions;

final class WebhookSubscriptionNotFound extends \RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("No BeeL webhook subscription delivers to {$url}.");
    }
}
