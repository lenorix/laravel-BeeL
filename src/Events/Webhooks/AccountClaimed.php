<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataAccountClaimed;

/** `account.claimed`: integrators: a provisioned account was claimed. */
final class AccountClaimed extends BeelWebhookEvent
{
    public function data(): WebhookEventDataAccountClaimed
    {
        $data = $this->webhook->typed()->getData();

        return $data instanceof WebhookEventDataAccountClaimed ? $data : throw $this->unexpectedData($data);
    }
}
