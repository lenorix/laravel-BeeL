<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

final class BeelWebhookReceived
{
    /** BeeL company UUID the event belongs to, when the event carries one (not all event types do). */
    public readonly ?string $companyId;

    /** BeeL account UUID the event belongs to, when the event carries one. */
    public readonly ?string $accountId;

    /**
     * @param  string  $id  BeeL's event id. BeeL may redeliver the same event, so use this to deduplicate.
     * @param  array<string, mixed>  $payload
     * @param  string|null  $webhookKey  The optional URL segment the delivery arrived on (/beel/webhook/{key}),
     *                                   i.e. whose secret verified it. In multi-tenant setups identify the
     *                                   tenant by this, not by companyId/accountId from the payload.
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

    /** True only for test deliveries triggered from the BeeL dashboard, never for live events. */
    public function isTest(): bool
    {
        return ($this->payload['test'] ?? false) === true;
    }
}
