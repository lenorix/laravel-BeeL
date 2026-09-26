<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Testing\BeelFake;

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

it('refuses to store a preview that is not a WebP image', function () {
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.s3.test/inv-1.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.s3.test/*' => fn () => Http::response('%PDF-1.7 not an image', 200),
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePreview('inv-1', 'p.webp', disk: 'docs'))
        ->toThrow(DocumentDownloadFailed::class, 'not a WebP image');

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
