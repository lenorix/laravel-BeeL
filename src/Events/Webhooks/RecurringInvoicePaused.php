<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataRecurringInvoicePaused;

/** `recurring_invoice.paused`: a recurring invoice was paused. */
final class RecurringInvoicePaused extends BeelWebhookEvent
{
    public function data(): WebhookEventDataRecurringInvoicePaused
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataRecurringInvoicePaused ? $data : throw $this->unexpectedData($data);
    }
}
