<?php

return [
    'register_webhook_route' => true,
    'webhook_path' => 'beel/webhook',
    // Maximum age (in seconds) allowed for a webhook's signed timestamp, guarding against replay
    // attacks. Matches the SDK's own WebhookVerifier default.
    'webhook_replay_tolerance_seconds' => 300,
    // BeeL redelivers an event with the same id (its Idempotency-Key). Accepted events are remembered
    // for this many seconds (at least twice webhook_replay_tolerance_seconds, so a captured signature
    // can't be replayed after its claim expires) and a redelivery gets the same 202 without
    // dispatching BeelWebhookReceived again. null or 0 disables it (and reopens that replay window).
    // Listeners should still deduplicate on $event->id for longer windows.
    'webhook_dedupe_seconds' => 900,
    // Cache store for that memory; null uses the default store. The claim must be atomic and shared by
    // every process receiving webhooks: use redis, memcached, database or dynamodb (file only for a
    // single server). Never array or null: with them deduplication silently does nothing.
    'webhook_dedupe_store' => null,
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
        // With several servers running the scheduler, run the automatic schedule on only one of them.
        // Needs a cache store with atomic locks (redis, memcached, database, dynamodb, ...).
        'on_one_server' => false,
    ],
    // storePdf(), storePreview() and storeRepresentationDocument(): stream a document from BeeL's
    // pre-signed URL into a Laravel disk.
    'downloads' => [
        // Bytes read from the download per step. Memory stays at about this much (plus the disk
        // adapter's own buffer, e.g. S3's upload part) whatever the document's size.
        'buffer_bytes' => 65536,
        // Seconds without receiving any byte before a download is abandoned.
        'read_timeout' => 30,
        // Full attempts (new URL, new download) on connection errors, 5xx, an expired URL or a
        // download that fails verification.
        'attempts' => 3,
    ],
    // Requests per minute that queued jobs using the ThrottleBeelRequests middleware (StoreInvoicePdf
    // does) may send per API key. BeeL allows 300 per key in a fixed 60 s window; the default leaves
    // room for web requests. 0 or null disables the throttle.
    'queue_rate_limit' => 250,
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        // lenorix/beel-sdk retries 429s, 5xx and connection errors, only for requests that are safe to
        // repeat (idempotent methods, or POSTs with an Idempotency-Key). 0 disables retries.
        'retries' => 3,
        // First backoff delay when BeeL doesn't say how long to wait; it grows exponentially.
        'retry_delay_ms' => 500,
        // Longest wait between attempts. BeeL's rate limit window is 60 s, so a 429 is waited out; a
        // longer wait isn't: BeelRateLimitError is thrown with retryAfterSeconds instead. Waits block
        // the PHP process: in queued jobs prefer retries 0 and release($e->retryAfterSeconds).
        'max_retry_delay_ms' => 60_000,
        // Additional Guzzle options passed through Laravel's PendingRequest.
        'options' => [],
    ],
];
