<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Jobs\Middleware;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\RateLimiter;
use Lenorix\LaravelBeel\Contracts\CredentialsResolver;

/**
 * Job middleware that keeps queued jobs under BeeL's rate limit (a fixed 60-second window per API
 * key, 300 requests by default) instead of provoking 429s: a job that would go over it goes back
 * to the queue until the window has room.
 *
 *     public function middleware(): array
 *     {
 *         return [new ThrottleBeelRequests($this->tenantApiKey, requests: 2)]; // e.g. create + issue
 *     }
 *
 * The budget is `beel.queue_rate_limit` requests per minute (250 by default, leaving room for web
 * requests) and is shared by every job using the same key. It counts in Laravel's rate limiter
 * cache (`cache.limiter`, else the default store), which must be shared by all workers: redis,
 * memcached, database or dynamodb.
 *
 * Laravel counts each release as an attempt, so a throttled job needs room: prefer `retryUntil()`
 * with `$tries = 0` and `$maxExceptions` over a small `$tries` (see StoreInvoicePdf).
 */
final class ThrottleBeelRequests
{
    /**
     * @param  string|null  $apiKey  The key the job uses; null means the CredentialsResolver's default key.
     * @param  int  $requests  BeeL API requests the job makes.
     */
    public function __construct(#[\SensitiveParameter] private ?string $apiKey = null, private int $requests = 1) {}

    public function handle(object $job, callable $next): mixed
    {
        $perMinute = (int) config('beel.queue_rate_limit', 250);
        if ($perMinute <= 0) {
            return $next($job);
        }

        // Hashed: the limiter's cache keys must never hold the API key itself.
        $apiKey = $this->apiKey ?? Container::getInstance()->make(CredentialsResolver::class)->apiKey() ?? 'default';
        $key = 'beel-requests:'.hash('sha256', $apiKey);
        $requests = max(1, $this->requests);

        // Take the quota first (an atomic increment on the store), and give it back if it went over:
        // a separate check and increment would let concurrent workers overshoot together.
        if (RateLimiter::increment($key, 60, $requests) > $perMinute) {
            RateLimiter::decrement($key, 60, $requests);

            if (method_exists($job, 'release')) {
                $job->release(max(1, RateLimiter::availableIn($key)));
            }

            return null;
        }

        return $next($job);
    }
}
