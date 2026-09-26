<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Contracts;

/**
 * Default BeeL credentials, used whenever a caller does not pass them explicitly.
 *
 * The package binds ConfigCredentialsResolver (services.beel.*). Bind your own implementation to take
 * them from anywhere else, e.g. a settings table or the current tenant. It is resolved from the
 * container on every call, so an implementation may depend on per-request state. Return null for a
 * value you don't have: callers then fail with a clear error unless they pass it explicitly.
 */
interface CredentialsResolver
{
    public function apiKey(): ?string;

    public function accountId(): ?string;

    public function companyId(): ?string;
}
