<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Generated\Model\WebhookSubscription;
use Lenorix\BeelSdk\Generated\Model\WebhookSubscriptionWithSecret;

/** A BeeL webhook subscription, without its secret (which only ever reaches a store callback). */
final class BeelWebhookSubscription
{
    /** @param list<string> $events */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly array $events,
        public readonly bool $active,
    ) {}

    public static function fromSdk(WebhookSubscription|WebhookSubscriptionWithSecret $subscription): self
    {
        return new self($subscription->getId(), $subscription->getUrl(), $subscription->getEvents(), $subscription->getActive());
    }
}
