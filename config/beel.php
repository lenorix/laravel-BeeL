<?php

return [
    'register_webhook_route' => true,
    'webhook_path' => 'beel/webhook',
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
