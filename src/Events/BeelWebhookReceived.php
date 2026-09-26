<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

use Lenorix\BeelSdk\Exception\WebhookPayloadError;
use Lenorix\BeelSdk\Generated\Model\WebhookEvent;
use Lenorix\LaravelBeel\Support\WebhookEventHydrator;

final class BeelWebhookReceived
{
    /** BeeL company UUID the event belongs to, when the event carries one (not all event types do). */
    public readonly ?string $companyId;

    /** BeeL account UUID the event belongs to, when the event carries one. */
    public readonly ?string $accountId;

    private ?WebhookEvent $typed = null;

    /**
     * @param  string  $id  BeeL's event id. BeeL may redeliver the same event, so use this to deduplicate.
     * @param  array<string, mixed>  $payload
     * @param  string|null  $webhookKey  The optional URL segment the delivery arrived on (/beel/webhook/{key}).
     *                                   It identifies the tenant only when the bound WebhookSecretResolver
     *                                   returns a distinct secret per key and null for unknown keys; then
     *                                   prefer it over companyId/accountId from the payload.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly array $payload,
        public readonly ?string $webhookKey = null,
    ) {
        $companyId = $payload['company_id'] ?? null;
        $this->companyId = is_string($companyId) ? $companyId : null;

        $accountId = $payload['account_id'] ?? null;
        $this->accountId = is_string($accountId) ? $accountId : null;
    }

    /**
     * The event as the SDK's typed model, built lazily from `payload`: `getData()` returns the
     * per-type model (e.g. `WebhookEventDataInvoiceIssued` with `getInvoiceId()`), or a plain array
     * for event types the installed SDK doesn't know yet.
     *
     * @throws WebhookPayloadError If the payload doesn't match BeeL's event schema. The delivery was
     *                             already accepted: only the listener calling this sees the error.
     */
    public function typed(): WebhookEvent
    {
        return $this->typed ??= WebhookEventHydrator::hydrate($this->payload);
    }

    /** True only for test deliveries triggered from the BeeL dashboard, never for live events. */
    public function isTest(): bool
    {
        return ($this->payload['test'] ?? false) === true;
    }
}
