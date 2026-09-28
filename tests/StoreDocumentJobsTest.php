<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\LaravelBeel\Events\BeelDocumentStored;
use Lenorix\LaravelBeel\Jobs\StoreBeelDocument;
use Lenorix\LaravelBeel\Jobs\StoreInvoiceExport;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdf;
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

it('announces an archive it stored with BeeL\'s counts, so the app sees invoices left out', function () {
    Event::fake([BeelDocumentStored::class]);
    Http::fake(['*/invoices/pdf-archive' => Http::response("PK\x03\x04zip", 200, [
        'Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="facturas.zip"',
        'X-Bulk-Total' => '3', 'X-Bulk-Successful' => '2', 'X-Bulk-Failed' => '1',
    ])]);

    runDocumentJob(new StoreInvoicePdfArchive(['invoice_ids' => ['a', 'b', 'c']], 'a.zip', disk: 'docs', companyId: 'company-1'));

    Event::assertDispatched(fn (BeelDocumentStored $e) => $e->job === StoreInvoicePdfArchive::class
        && $e->document->path === 'a.zip' && $e->document->fileName === 'facturas.zip'
        && $e->document->counts === ['total' => 3, 'successful' => 2, 'failed' => 1]
        && $e->disk === 'docs' && $e->invoiceId === null && $e->companyId === 'company-1');
});

it('announces an invoice PDF it stored with the invoice id', function () {
    Event::fake([BeelDocumentStored::class]);
    Http::fake([
        '*/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf()),
        'beel-pdfs.s3.*' => BeelFake::pdf('%PDF-queued'),
    ]);

    runDocumentJob(new StoreInvoicePdf('inv-1', 'i.pdf', disk: 'docs'));

    Event::assertDispatched(fn (BeelDocumentStored $e) => $e->job === StoreInvoicePdf::class && $e->invoiceId === 'inv-1' && $e->document->path === 'i.pdf');
});

it('announces nothing when the file was already there, or the job did not store it', function () {
    Event::fake([BeelDocumentStored::class]);
    Storage::disk('docs')->put('a.zip', 'previous');
    Http::fake(['*/invoices/exports' => BeelFake::error(400, 'VALIDATION_ERROR')]);

    runDocumentJob(new StoreInvoicePdfArchive(['invoice_ids' => ['a']], 'a.zip', disk: 'docs'));
    runDocumentJob(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs'));

    Event::assertNotDispatched(BeelDocumentStored::class);
});

it('carries no credentials, so a queued listener can serialize it', function () {
    Event::fake([BeelDocumentStored::class]);
    BeelFake::api()->invoiceExport("PK\x03\x04sheet")->fake();

    runDocumentJob(new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs', apiKey: 'beel_sk_test_secret'));

    Event::assertDispatched(fn (BeelDocumentStored $e) => ! str_contains(serialize($e), 'beel_sk_test_secret'));
});

it('waits in the queue, not in the worker, and gives up only after repeated exceptions', function () {
    $job = new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx');

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(5)
        ->and($job->backoff())->toBe([10, 30, 60, 120])
        ->and($job->retryUntil()->getTimestamp())->toBe(now()->addDay()->getTimestamp());
});

it('checks again in 5 seconds when BeeL is generating the PDF without saying for how long', function () {
    Http::fake(['*/invoices/inv-1/pdf' => Http::response(null, 202)]);

    runDocumentJob(new StoreInvoicePdf('inv-1', 'i.pdf', disk: 'docs'))->assertReleased(5);
});

it('fails at once only on client errors retrying can not fix', function (int $status, bool $fails) {
    Http::fake(['*/invoices/exports' => BeelFake::error($status, 'ERROR')]);
    $job = new StoreInvoiceExport(['invoice_ids' => ['a']], 'e.xlsx', disk: 'docs');
    $job->withFakeQueueInteractions();

    try {
        app()->call([$job, 'handle']);
    } catch (Exception) {
        // Thrown for the queue to retry.
    }

    $fails ? $job->assertFailed() : $job->assertNotFailed();
})->with([
    'bad request' => [400, true],
    'not found' => [404, true],
    'last client error' => [499, true],
    'request timeout' => [408, false],
    'server error' => [500, false],
]);

it('goes back to the queue while an issued invoice\'s preview waits for its PDF', function () {
    Http::fake(['*/invoices/inv-1/preview' => Http::response(null, 202, ['Retry-After' => '4'])]);

    runDocumentJob(new StoreInvoicePreview('inv-1', 'p.png', disk: 'docs'))->assertReleased(4);
});
