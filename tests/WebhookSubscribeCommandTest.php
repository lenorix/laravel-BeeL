<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Lenorix\LaravelBeel\Contracts\WebhookSecretResolver;
use Lenorix\LaravelBeel\Support\EnvFileWriter;

beforeEach(function () {
    config()->set('app.url', 'https://app.test');
    config()->set('services.beel.key', 'beel_sk_test_key');
    config()->set('services.beel.account_id', 'acc-1');
    config()->set('services.beel.base_url', 'https://beel.test/api');
    config()->set('beel.http.retries', 0);

    $this->envDir = sys_get_temp_dir().'/beel-env-'.uniqid();
    mkdir($this->envDir);
    file_put_contents($this->envDir.'/.env', "APP_NAME=Test\nBEEL_WEBHOOK_SECRET=old\nOTHER=1\n");
    app()->useEnvironmentPath($this->envDir);
});

afterEach(function () {
    @chmod($this->envDir, 0755);
    array_map('unlink', array_filter(glob($this->envDir.'/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
    @rmdir($this->envDir);
});

/** @param array<int, array<string, mixed>> $existing */
function fakeBeelSubscriptionApi(array $existing = [], string $secret = 'whsec_new123'): void
{
    Http::fake(function (ClientRequest $request) use ($existing, $secret) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $subscription = fn (string $id, string $url) => ['id' => $id, 'url' => $url, 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM), 'secret' => $secret];

        return match (true) {
            $request->method() === 'GET' && str_ends_with($path, '/accounts/acc-1/webhooks') => Http::response(['success' => true, 'data' => ['webhooks' => $existing, 'pagination' => ['current_page' => 1, 'total_pages' => 1, 'total_items' => count($existing), 'items_per_page' => 100, 'has_next' => false, 'has_previous' => false]]], 200),
            $request->method() === 'POST' && str_ends_with($path, '/accounts/acc-1/webhooks') => Http::response(['success' => true, 'data' => $subscription('wh-new', $request['url'])], 201),
            $request->method() === 'POST' && preg_match('#/webhooks/([^/]+)/secret$#', $path, $m) === 1 => Http::response(['success' => true, 'data' => $subscription($m[1], 'https://app.test/beel/webhook')], 200),
            $request->method() === 'DELETE' => Http::response(null, 204),
            default => Http::response(['success' => false], 404),
        };
    });
}

function existingSubscription(string $url = 'https://app.test/beel/webhook'): array
{
    return ['id' => 'wh-1', 'url' => $url, 'events' => ['invoice.issued'], 'active' => true, 'created_at' => now()->format(DATE_ATOM)];
}

it('creates a subscription for this app and writes its secret to .env without printing it', function () {
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe')
        ->doesntExpectOutputToContain('whsec_new123')
        ->assertSuccessful();

    expect(file_get_contents($this->envDir.'/.env'))->toBe("APP_NAME=Test\nBEEL_WEBHOOK_SECRET=whsec_new123\nOTHER=1\n");
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
        && $r['url'] === 'https://app.test/beel/webhook'
        && in_array('invoice.issued', $r['events'], true)
        && ! in_array('account.claimed', $r['events'], true));
});

it('appends the key when .env does not have it yet', function () {
    file_put_contents($this->envDir.'/.env', "APP_NAME=Test\n");
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe', ['--env-key' => 'BEEL_WEBHOOK_SECRET'])->assertSuccessful();

    expect(file_get_contents($this->envDir.'/.env'))->toBe("APP_NAME=Test\nBEEL_WEBHOOK_SECRET=whsec_new123\n");
});

it('quotes a secret that is not a plain token', function () {
    fakeBeelSubscriptionApi(secret: 'abc#def');

    $this->artisan('beel:webhook:subscribe')->assertSuccessful();

    expect(file_get_contents($this->envDir.'/.env'))->toContain("BEEL_WEBHOOK_SECRET='abc#def'\n");
});

it('subscribes the given events and a per-tenant URL', function () {
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe', ['--url' => 'https://app.test/beel/webhook/tenant-a', '--event' => ['invoice.issued', 'invoice.voided']])->assertSuccessful();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
        && $r['url'] === 'https://app.test/beel/webhook/tenant-a'
        && $r['events'] === ['invoice.issued', 'invoice.voided']);
});

it('refuses to create a second subscription for the same URL', function () {
    fakeBeelSubscriptionApi([existingSubscription()]);

    $this->artisan('beel:webhook:subscribe')->expectsOutputToContain('--rotate')->assertFailed();

    Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'POST');
    expect(file_get_contents($this->envDir.'/.env'))->toContain('BEEL_WEBHOOK_SECRET=old');
});

it('rotates the existing subscription secret with --rotate', function () {
    fakeBeelSubscriptionApi([existingSubscription()], 'whsec_rotated');

    $this->artisan('beel:webhook:subscribe', ['--rotate' => true])
        ->doesntExpectOutputToContain('whsec_rotated')
        ->assertSuccessful();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/wh-1/secret'));
    expect(file_get_contents($this->envDir.'/.env'))->toContain("BEEL_WEBHOOK_SECRET=whsec_rotated\n");
});

it('fails with --rotate when there is no subscription to rotate', function () {
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe', ['--rotate' => true])->assertFailed();

    Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'POST');
});

it('refuses a non-HTTPS URL before calling BeeL', function () {
    Http::fake();

    $this->artisan('beel:webhook:subscribe', ['--url' => 'http://app.test/beel/webhook'])->expectsOutputToContain('HTTPS')->assertFailed();

    Http::assertNothingSent();
});

it('refuses before calling BeeL when .env is not writable', function () {
    Http::fake();
    chmod($this->envDir.'/.env', 0444);

    $this->artisan('beel:webhook:subscribe')->expectsOutputToContain('.env')->assertFailed();

    Http::assertNothingSent();
    chmod($this->envDir.'/.env', 0644);
});

it('deletes the new subscription when the secret cannot be saved, so no orphan is left', function () {
    fakeBeelSubscriptionApi();
    app()->instance(EnvFileWriter::class, new class extends EnvFileWriter
    {
        public function write(string $path, string $key, string $value): void
        {
            throw new RuntimeException('disk full');
        }
    });

    $this->artisan('beel:webhook:subscribe')->doesntExpectOutputToContain('whsec_new123')->assertFailed();

    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/webhooks/wh-new'));
});

it('prints the rotated secret once as the only way to recover when it cannot be saved', function () {
    fakeBeelSubscriptionApi([existingSubscription()], 'whsec_rotated');
    app()->instance(EnvFileWriter::class, new class extends EnvFileWriter
    {
        public function write(string $path, string $key, string $value): void
        {
            throw new RuntimeException('disk full');
        }
    });

    $this->artisan('beel:webhook:subscribe', ['--rotate' => true])->expectsOutputToContain('whsec_rotated')->assertFailed();
});

it('asks for confirmation in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Http::fake();

    $this->artisan('beel:webhook:subscribe')->expectsConfirmation('Are you sure you want to run this command?', 'no')->assertFailed();

    Http::assertNothingSent();
});

it('writes through a symlinked .env to the shared file and keeps the link', function () {
    $shared = $this->envDir.'/shared.env';
    rename($this->envDir.'/.env', $shared);
    symlink($shared, $this->envDir.'/.env');
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe')->assertSuccessful();

    expect(is_link($this->envDir.'/.env'))->toBeTrue()
        ->and(file_get_contents($shared))->toContain("BEEL_WEBHOOK_SECRET=whsec_new123\n");
});

it('updates .env in place, keeping the same file (and so its owner and mode)', function () {
    $inode = fileinode($this->envDir.'/.env');
    fakeBeelSubscriptionApi();

    $this->artisan('beel:webhook:subscribe')->assertSuccessful();

    clearstatcache();
    expect(fileinode($this->envDir.'/.env'))->toBe($inode);
});

it('refuses when a custom webhook secret resolver is bound, since the app would not read .env', function () {
    app()->bind(WebhookSecretResolver::class, fn () => new class implements WebhookSecretResolver
    {
        public function resolve(Request $request): ?string
        {
            return 'from-database';
        }
    });
    Http::fake();

    $this->artisan('beel:webhook:subscribe', ['--rotate' => true])->expectsOutputToContain('WebhookSecretResolver')->assertFailed();

    Http::assertNothingSent();
});

it('refuses when services.beel.webhook_secret reads a different variable than the one it would write', function () {
    putenv('BEEL_WEBHOOK_SECRET=from-env');
    config()->set('services.beel.webhook_secret', 'from-another-variable');
    Http::fake();

    $this->artisan('beel:webhook:subscribe')->expectsOutputToContain('services.beel.webhook_secret')->assertFailed();

    Http::assertNothingSent();
    putenv('BEEL_WEBHOOK_SECRET');
});

it('treats a trailing slash as the same URL when looking for an existing subscription', function () {
    fakeBeelSubscriptionApi([existingSubscription('https://app.test/beel/webhook/')]);

    $this->artisan('beel:webhook:subscribe')->expectsOutputToContain('--rotate')->assertFailed();

    Http::assertNotSent(fn (ClientRequest $r) => $r->method() === 'POST');
});

it('warns that rotating does not reactivate an inactive subscription', function () {
    fakeBeelSubscriptionApi([array_merge(existingSubscription(), ['active' => false])], 'whsec_rotated');

    $this->artisan('beel:webhook:subscribe', ['--rotate' => true])->expectsOutputToContain('inactive')->assertSuccessful();
});
