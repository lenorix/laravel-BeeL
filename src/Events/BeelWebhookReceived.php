<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

final class BeelWebhookReceived
{
    /**
     * @param  string  $id  BeeL's event id. BeeL may redeliver the same event, so use this to deduplicate.
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
        public readonly array $payload,
    ) {}
}
