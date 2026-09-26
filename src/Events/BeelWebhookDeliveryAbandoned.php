<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

/**
 * A BeeL webhook event never reached this app and `beel:retry-webhook-deliveries` stopped retrying it
 * (it reached the configured max attempts). The app has not seen this event: fix the cause, then
 * recover it, e.g. by re-reading the affected resource from the API.
 *
 * Dispatched on every run while the event stays inside the retry window, so deduplicate
 * notifications on `eventId`.
 */
final class BeelWebhookDeliveryAbandoned
{
    /**
     * @param  array<string, mixed>|null  $payload  The webhook body BeeL tried to deliver (null if BeeL did not record it).
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $subscriptionId,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly int $attempts,
        public readonly string $lastDeliveryId,
        public readonly ?int $lastHttpStatus,
        public readonly ?string $lastError,
        public readonly ?array $payload,
    ) {}
}
