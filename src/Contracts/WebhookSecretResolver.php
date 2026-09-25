<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Contracts;

use Illuminate\Http\Request;

interface WebhookSecretResolver
{
    /** Resolve the signing secret from request metadata without trusting the payload. */
    public function resolve(Request $request): ?string;
}
