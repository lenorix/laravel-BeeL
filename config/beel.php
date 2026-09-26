<?php

return [
    'register_webhook_route' => true,
    'webhook_path' => 'beel/webhook',
    // Maximum age (in seconds) allowed for a webhook's signed timestamp, guarding against replay
    // attacks. Matches the SDK's own WebhookVerifier default.
    'webhook_replay_tolerance_seconds' => 300,
    // Safety net for `php artisan beel:retry-webhook-deliveries`, which asks BeeL to redeliver
    // webhook events that never reached this app (no attempt succeeded).
    'webhook_delivery_retry' => [
        // Only events whose first delivery attempt is newer than this are retried.
        'max_age_minutes' => 1440,
        // Give up (and exit with failure) once an event has this many attempts, automatic ones included.
        'max_attempts' => 8,
        // Retrying needs the webhooks:write scope. To keep that scope off the app's main key, set a
        // dedicated key (and account) here in the published config, e.g. env('BEEL_WEBHOOK_RETRY_API_KEY').
        // null falls back to services.beel.key / services.beel.account_id.
        'api_key' => null,
        'account_id' => null,
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
