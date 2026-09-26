<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Http\Controllers;

use Illuminate\Contracts\Cache\Repository;

/** @internal A webhook event id claimed in the cache, released when processing it fails. */
final class WebhookClaim
{
    public function __construct(private Repository $cache, private string $key) {}

    public function release(): void
    {
        $this->cache->forget($this->key);
    }
}
