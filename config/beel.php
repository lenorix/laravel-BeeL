<?php

return [
    'register_webhook_route' => true,
    'webhook_path' => 'beel/webhook',
    // Maximum age (in seconds) allowed for a webhook's signed timestamp, guarding against replay
    // attacks. Matches the SDK's own WebhookVerifier default.
    'webhook_replay_tolerance_seconds' => 300,
    // Safety net for `php artisan beel:retry-webhook-deliveries`, which asks BeeL to redeliver
    // webhook events that never reached this app (no attempt succeeded). It uses services.beel.key and
    // services.beel.account_id; if you use it, create that API key with the webhooks:read and
    // webhooks:write scopes.
    'webhook_delivery_retry' => [
        // Only events whose first delivery attempt is newer than this are retried.
        'max_age_minutes' => 1440,
        // Give up (and exit with failure) once an event has this many attempts, automatic ones included.
        'max_attempts' => 8,
        // Cron expression to schedule the command automatically, e.g. '*/15 * * * *'.
        // null leaves scheduling to the app. Either way `schedule:run` must be running.
        'schedule' => null,
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
