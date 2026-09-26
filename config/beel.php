<?php

return [
    'register_webhook_route' => true,
    'webhook_path' => 'beel/webhook',
    'webhook_rate_limit' => [
        // Generous on purpose: this throttles abuse/flooding, not legitimate BeeL traffic.
        // Set to null to disable rate limiting entirely.
        'max_attempts_per_minute' => 300,
    ],
    'http' => [
        // Laravel owns retries; SDK maxRetries is disabled to prevent stacked retries.
        'timeout' => 30,
        'connect_timeout' => 10,
        'retries' => 3,
        'retry_delay_ms' => 100,
        // Additional Guzzle options passed through Laravel's PendingRequest.
        'options' => [],
    ],
];
