<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoicePdfGenerated;

/** `invoice.pdf.generated`: an invoice PDF is ready (e.g. dispatch StoreInvoicePdf). */
final class InvoicePdfGenerated extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoicePdfGenerated
    {
        /** @var WebhookEventDataInvoicePdfGenerated */
        return $this->webhook->typed()->getData();
    }
}
