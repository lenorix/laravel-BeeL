<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataInvoiceIssued;

/** `invoice.issued`: an invoice was issued. */
final class InvoiceIssued extends BeelWebhookEvent
{
    public function data(): WebhookEventDataInvoiceIssued
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataInvoiceIssued ? $data : throw $this->unexpectedData($data);
    }
}
