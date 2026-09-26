<?php

use Illuminate\Support\Facades\RateLimiter;
use Lenorix\LaravelBeel\Jobs\Middleware\ThrottleBeelRequests;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdf;

/** A job double that records whether it ran or was released, and for how long. */
function throttledJob(): object
{
    return new class
    {
        public bool $ran = false;

        public ?int $releasedFor = null;

        public function release(int $delay): void
        {
            $this->releasedFor = $delay;
        }
    };
}

function runThrottled(ThrottleBeelRequests $middleware): object
{
    $job = throttledJob();
    $middleware->handle($job, function ($job) {
        $job->ran = true;
    });

    return $job;
}

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_default');
    config()->set('beel.queue_rate_limit', 3);
});

it('lets jobs through until the per-minute budget is spent, then releases them until the window resets', function () {
    $jobs = array_map(fn () => runThrottled(new ThrottleBeelRequests), range(1, 4));

    expect(array_map(fn ($job) => $job->ran, $jobs))->toBe([true, true, true, false])
        ->and($jobs[3]->releasedFor)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('counts the requests a job makes and gives back what it could not use', function () {
    expect(runThrottled(new ThrottleBeelRequests(requests: 2))->ran)->toBeTrue()
        ->and(runThrottled(new ThrottleBeelRequests(requests: 2))->ran)->toBeFalse()  // 4 > 3: released
        ->and(runThrottled(new ThrottleBeelRequests(requests: 1))->ran)->toBeTrue();  // the 2 were given back
});

it('keeps a separate budget per API key, never storing the key itself', function () {
    runThrottled(new ThrottleBeelRequests('beel_sk_test_tenant_a', requests: 3));

    expect(runThrottled(new ThrottleBeelRequests('beel_sk_test_tenant_a'))->ran)->toBeFalse()
        ->and(runThrottled(new ThrottleBeelRequests('beel_sk_test_tenant_b'))->ran)->toBeTrue()
        ->and(RateLimiter::attempts('beel-requests:'.hash('sha256', 'beel_sk_test_tenant_a')))->toBe(3);
});

it('can be disabled', function () {
    config()->set('beel.queue_rate_limit', 0);

    $jobs = array_map(fn () => runThrottled(new ThrottleBeelRequests), range(1, 5));

    expect(array_filter($jobs, fn ($job) => ! $job->ran))->toBe([]);
});

it('is used by StoreInvoicePdf, which counts only exceptions as failures', function () {
    $job = new StoreInvoicePdf('inv-1', 'a.pdf', apiKey: 'beel_sk_test_tenant');

    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(ThrottleBeelRequests::class)
        ->and($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});
