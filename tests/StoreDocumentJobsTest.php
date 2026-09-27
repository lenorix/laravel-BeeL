<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\LaravelBeel\Jobs\StoreBeelDocument;
use Lenorix\LaravelBeel\Jobs\StoreInvoiceExport;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdfArchive;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePreview;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePreviewPdf;
use Lenorix\LaravelBeel\Jobs\StoreRepresentationDocument;
use Lenorix\LaravelBeel\Testing\BeelFake;

mutates(StoreBeelDocument::class);

// The queued jobs for the documents other than the invoice PDF.

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Storage::fake('docs');
    Sleep::fake();
});

function runDocumentJob(StoreBeelDocument $job): StoreBeelDocument
{
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('stores an invoice PDF archive', function () {
    BeelFake::api()->invoicePdfArchive("PK\x03\x04archive")->fake();

    runDocumentJob(new StoreInvoicePdfArchive(['invoice_ids' => ['a']], 'a.zip', disk: 'docs'))->assertNotFailed()->assertNotReleased();

    expect(Storage::disk('docs')->get('a.zip'))->toBe("PK\x03\x04archive");
    Http::assertSent(fn (ClientRequest $r) => $r['invoice_ids'] === ['a']);
});

it('stores an invoice export', function () {
    BeelFake::api()->invoiceExport("PK\x03\x04sheet")->fake();

    runDocumentJob(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs'))->assertNotFailed();

    expect(Storage::disk('docs')->get('e.xlsx'))->toBe("PK\x03\x04sheet");
});

it('stores a draft PDF preview', function () {
    BeelFake::api()->invoicePreviewPdf('%PDF-1.7 draft')->fake();

    runDocumentJob(new StoreInvoicePreviewPdf('inv-1', 'd.pdf', disk: 'docs'))->assertNotFailed();

    expect(Storage::disk('docs')->get('d.pdf'))->toBe('%PDF-1.7 draft');
});

it('stores an invoice preview image', function () {
    $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.test/p.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.test/*' => Http::response($png, 200),
    ]);

    runDocumentJob(new StoreInvoicePreview('inv-1', 'p.png', disk: 'docs'))->assertNotFailed();

    expect(Storage::disk('docs')->get('p.png'))->toBe($png);
});

it('stores the representation document with the credentials given for the job', function () {
    Http::fake([
        '*/companies/company-9/representation/document' => BeelFake::ok(['download_url' => 'https://beel-docs.test/r.pdf?sig=x', 'expires_in_seconds' => 300]),
        'beel-docs.test/*' => BeelFake::pdf('%PDF-1.7 representation'),
    ]);

    runDocumentJob(new StoreRepresentationDocument('r.pdf', disk: 'docs', companyId: 'company-9', apiKey: 'beel_sk_test_tenant'))->assertNotFailed();

    expect(Storage::disk('docs')->get('r.pdf'))->toBe('%PDF-1.7 representation');
    Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
});

it('goes back to the queue when rate limited', function () {
    Http::fake(['*/invoices/exports' => BeelFake::error(429, 'RATE_LIMIT_EXCEEDED', retryAfter: 42)]);

    runDocumentJob(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs'))->assertReleased(42);
});

it('counts an existing file as done without asking BeeL to build anything', function () {
    Storage::disk('docs')->put('a.zip', 'previous');
    Http::fake();

    runDocumentJob(new StoreInvoicePdfArchive(['invoice_ids' => ['a']], 'a.zip', disk: 'docs'))->assertNotFailed()->assertNotReleased();

    Http::assertNothingSent();
});

it('fails at once on an error retrying cannot fix', function () {
    Http::fake(['*/invoices/pdf-archive' => BeelFake::error(400, 'NO_PDFS_AVAILABLE')]);

    runDocumentJob(new StoreInvoicePdfArchive(['invoice_ids' => ['a']], 'a.zip', disk: 'docs'))->assertFailed();
});

it('lets the queue retry a server error', function () {
    Http::fake(['*/invoices/exports' => BeelFake::error(503, 'SERVICE_UNAVAILABLE')]);

    expect(fn () => runDocumentJob(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs')))->toThrow(Exception::class);
});

it('encrypts the payload, since it may hold the API key', function () {
    expect(new StoreRepresentationDocument('r.pdf'))->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(unserialize(serialize(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx')))->request)->toBe(['invoice_ids' => ['a']]);
});
