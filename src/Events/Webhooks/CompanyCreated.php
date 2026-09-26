<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\BeelSdk\Generated\Model\WebhookEventDataCompanyCreated;

/** `company.created`: integrators: a company was created in a managed account. */
final class CompanyCreated extends BeelWebhookEvent
{
    public function data(): WebhookEventDataCompanyCreated
    {
        /** @var WebhookEventDataCompanyCreated */
        return $this->webhook->typed()->getData();
    }
}
