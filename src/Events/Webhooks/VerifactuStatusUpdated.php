<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataVeriFactuStatusUpdated;

/** `verifactu.status.updated`: the AEAT answered a VERI*FACTU submission (act on REJECTED). */
final class VerifactuStatusUpdated extends BeelWebhookEvent
{
    public function data(): WebhookEventDataVeriFactuStatusUpdated
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataVeriFactuStatusUpdated ? $data : throw $this->unexpectedData($data);
    }
}
