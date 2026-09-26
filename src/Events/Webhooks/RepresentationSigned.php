<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataRepresentationSigned;

/** `representation.signed`: integrators: an AEAT representation was signed. */
final class RepresentationSigned extends BeelWebhookEvent
{
    public function data(): WebhookEventDataRepresentationSigned
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataRepresentationSigned ? $data : throw $this->unexpectedData($data);
    }
}
