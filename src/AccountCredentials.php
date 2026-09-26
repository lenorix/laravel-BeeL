<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

/** A BeeL account and the API key to act on it. A null value falls back to the bound CredentialsResolver. */
final class AccountCredentials
{
    public function __construct(
        public readonly ?string $accountId,
        public readonly ?string $apiKey,
    ) {}
}
