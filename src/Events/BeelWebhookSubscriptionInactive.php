<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

/**
 * A BeeL webhook subscription is inactive, so BeeL delivers nothing to it until it is reactivated.
 * Dispatched by `beel:retry-webhook-deliveries`; listen to it to notify someone or open an incident.
 */
final class BeelWebhookSubscriptionInactive
{
    /**
     * @param  string|null  $deactivatedBy  "beel" when BeeL paused it after 25 consecutive failed deliveries over more than 48 hours (reactivating it needs a successful test delivery first).
     * @param  string|null  $lastError  Last error BeeL saw delivering to it: usually what to fix.
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $subscriptionId,
        public readonly string $url,
        public readonly ?string $deactivatedBy,
        public readonly ?\DateTimeInterface $deactivatedAt,
        public readonly ?int $consecutiveFailures,
        public readonly ?string $lastError,
    ) {}
}
