<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\LaravelBeel\Contracts\CredentialsResolver;
use Lenorix\LaravelBeel\Contracts\WebhookRetryAccounts;

/** Default: the single account of the bound CredentialsResolver (services.beel.* by default). */
final class CredentialsWebhookRetryAccounts implements WebhookRetryAccounts
{
    public function __construct(private CredentialsResolver $credentials) {}

    public function accounts(): iterable
    {
        yield new AccountCredentials($this->credentials->accountId(), $this->credentials->apiKey());
    }
}
