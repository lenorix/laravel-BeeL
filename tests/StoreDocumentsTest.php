<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Support\DocumentKind;
use Lenorix\LaravelBeel\Support\SignedDownloadStorage;
use Lenorix\LaravelBeel\Support\VerifiedDownloadStream;
use Lenorix\LaravelBeel\Testing\BeelFake;

mutates(SignedDownloadStorage::class, VerifiedDownloadStream::class, DocumentKind::class);

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Storage::fake('docs');
    Sleep::fake();
});

const WEBP = "RIFF\x24\x00\x00\x00WEBPVP8 fake preview";

it('stores an invoice preview image, verified as WebP', function () {
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.s3.test/inv-1.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.s3.test/*' => Http::response(WEBP, 200, ['Content-Type' => 'image/webp']),
    ]);

    $path = app(BeelManager::class)->company()->invoices->storePreview('inv-1', 'previews/inv-1.webp', disk: 'docs');

    expect(Storage::disk('docs')->get($path))->toBe(WEBP);
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'beel-previews') && ! $r->hasHeader('Authorization'));
});

it('accepts the PNG BeeL\'s sandbox serves under a .webp name, without stamping a wrong type', function () {
    $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR fake";
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.s3.test/inv-1_preview.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.s3.test/*' => Http::response($png, 200, ['Content-Type' => 'image/webp']),
    ]);

    app(BeelManager::class)->company()->invoices->storePreview('inv-1', 'previews/inv-1.png', disk: 'docs');

    expect(Storage::disk('docs')->get('previews/inv-1.png'))->toBe($png)
        ->and(Storage::disk('docs')->mimeType('previews/inv-1.png'))->toBe('image/png');
});

it('refuses to store a preview that is not an image', function () {
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.s3.test/inv-1.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.s3.test/*' => fn () => Http::response('%PDF-1.7 not an image', 200),
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePreview('inv-1', 'p.webp', disk: 'docs'))
        ->toThrow(DocumentDownloadFailed::class, 'not an image');

    expect(Storage::disk('docs')->allFiles())->toBe([]);
});

it('stores the company representation document', function () {
    Http::fake([
        '*/companies/company-1/representation/document' => BeelFake::ok(['download_url' => 'https://beel-docs.s3.test/representation.pdf?sig=x', 'expires_in_seconds' => 300]),
        'beel-docs.s3.test/*' => BeelFake::pdf('%PDF-1.7 representation'),
    ]);

    app(BeelManager::class)->company()->storeRepresentationDocument('aeat/representation.pdf', disk: 'docs');

    expect(Storage::disk('docs')->get('aeat/representation.pdf'))->toBe('%PDF-1.7 representation');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'beel-docs') && ! $r->hasHeader('Authorization'));
});

it('surfaces BeeL errors for a representation not generated yet', function () {
    Http::fake(['*/companies/company-1/representation/document' => BeelFake::error(400, 'REPRESENTATION_NOT_GENERATED')]);

    expect(fn () => app(BeelManager::class)->company()->storeRepresentationDocument('r.pdf', disk: 'docs'))
        ->toThrow(fn (BeelApiError $e) => expect($e->statusCode)->toBe(400)->and($e->apiCode)->toBe('REPRESENTATION_NOT_GENERATED'));
});

it('refuses an existing representation document before calling BeeL', function () {
    Storage::disk('docs')->put('r.pdf', 'previous');
    Http::fake();

    expect(fn () => app(BeelManager::class)->company()->storeRepresentationDocument('r.pdf', disk: 'docs'))
        ->toThrow(DocumentAlreadyExists::class, 'representation document of company company-1');

    Http::assertNothingSent();
});

it('maps every BeeL error of the representation link to BeelApiError', function (int $status) {
    Http::fake(['*/companies/company-1/representation/document' => BeelFake::error($status, 'SOMETHING_WRONG')]);

    expect(fn () => app(BeelManager::class)->company()->storeRepresentationDocument('r.pdf', disk: 'docs'))
        ->toThrow(fn (BeelApiError $e) => expect($e->apiCode)->toBe('SOMETHING_WRONG')->and($e->getMessage())->not->toBeEmpty());
})->with([401, 403, 404, 409, 429, 500]);

it('asks the storage host again when storing, up to beel.downloads.attempts, pausing between attempts', function (?int $configured, int $attempts) {
    if ($configured !== null) {
        config()->set('beel.downloads.attempts', $configured);
    }
    config()->set('beel.http.retry_delay_ms', 250);
    $calls = 0;
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.s3.test/inv-1.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.s3.test/*' => function () use (&$calls) {
            $calls++;

            return Http::response('', 503);
        },
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePreview('inv-1', 'p.webp', disk: 'docs'))->toThrow(DocumentDownloadFailed::class);
    expect($calls)->toBe($attempts);
    Sleep::assertSleptTimes($attempts - 1);
    if ($attempts > 1) {
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 250, $attempts - 1);
    }
})->with([
    'default' => [null, 3],
    'configured' => [2, 2],
    'never fewer than one' => [0, 1],
]);
