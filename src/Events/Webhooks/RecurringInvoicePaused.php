<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataRecurringInvoicePaused;

/** `recurring_invoice.paused`: a recurring invoice was paused. */
final class RecurringInvoicePaused extends BeelWebhookEvent
{
    public function data(): WebhookEventDataRecurringInvoicePaused
    {
        /** @var WebhookEventDataRecurringInvoicePaused */
        return $this->webhook->typed()->getData();
    }
}
