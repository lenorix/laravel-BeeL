<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceEmailSent;

/** `invoice.email.sent`: an invoice email was sent. */
final class InvoiceEmailSent extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoiceEmailSent
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataInvoiceEmailSent ? $data : throw $this->unexpectedData($data);
    }
}
