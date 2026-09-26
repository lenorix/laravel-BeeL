<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Contracts;

use Lenorix\LaravelBeel\AccountCredentials;

/**
 * The BeeL accounts `beel:retry-webhook-deliveries` checks when run without --account-id/--api-key
 * (including from the automatic schedule).
 *
 * The package binds CredentialsWebhookRetryAccounts: a single account from the bound CredentialsResolver
 * (config by default). Bind your own implementation to check several accounts, e.g. every tenant
 * stored in the database, without putting API keys on a command line. The key of each account needs
 * the webhooks:read and webhooks:write scopes.
 */
interface WebhookRetryAccounts
{
    /** @return iterable<AccountCredentials> */
    public function accounts(): iterable;
}
