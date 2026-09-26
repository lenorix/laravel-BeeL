<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceScheduleFailed;

/** `invoice.schedule_failed`: a scheduled invoice could not be issued. */
final class InvoiceScheduleFailed extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoiceScheduleFailed
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataInvoiceScheduleFailed ? $data : throw $this->unexpectedData($data);
    }
}
