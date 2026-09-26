<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceVoided;

/** `invoice.voided`: an invoice was voided. */
final class InvoiceVoided extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoiceVoided
    {
        /** @var WebhookEventDataInvoiceVoided */
        return $this->webhook->typed()->getData();
    }
}
