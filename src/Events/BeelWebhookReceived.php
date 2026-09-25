<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

final class BeelWebhookReceived
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly array $payload,
    ) {}
}
