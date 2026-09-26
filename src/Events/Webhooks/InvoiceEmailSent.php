<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceEmailSent;

/** `invoice.email.sent`: an invoice email was sent. */
final class InvoiceEmailSent extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoiceEmailSent
    {
        /** @var WebhookEventDataInvoiceEmailSent */
        return $this->webhook->typed()->getData();
    }
}
