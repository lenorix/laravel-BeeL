<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\Support\DocumentResponse;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Symfony\Component\HttpFoundation\StreamedResponse;

mutates(DocumentResponse::class);

// Documents answered to the browser as streamed downloads, without storing them.

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Sleep::fake();
});

/** What the browser would receive. */
function sent(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('answers with the invoice PDF under BeeL\'s file name, without the API key reaching the storage host', function () {
    BeelFake::api()->invoicePdf('%PDF-1.7 the invoice')->fake();

    $response = app(BeelManager::class)->company()->invoices->downloadPdf('inv-1');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('A-2025-0042.pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen('%PDF-1.7 the invoice'))
        ->and(sent($response))->toBe('%PDF-1.7 the invoice');
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'beel-pdfs.test') && ! $r->hasHeader('Authorization'));
});

it('lets the app name the file', function () {
    BeelFake::api()->invoicePdf()->fake();

    $response = app(BeelManager::class)->company()->invoices->downloadPdf('inv-1', fileName: 'Factura Nº 42.pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('Factura')->toContain("filename*=utf-8''Factura%20N%C2%BA%2042.pdf");
});

it('throws before answering when BeeL is still generating the PDF', function () {
    BeelFake::api()->invoicePdf(Http::response(null, 202, ['Retry-After' => '3']))->fake();

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPdf('inv-1'))->toThrow(InvoicePdfNotReady::class);
});

it('throws before answering, so the app can still send an error, when the file is not what it should be', function () {
    Http::fake([
        '*/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf(['download_url' => 'https://beel-pdfs.test/x.pdf'])),
        'beel-pdfs.test/*' => fn () => Http::response('<Error>AccessDenied</Error>', 200),
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPdf('inv-1'))->toThrow(DocumentDownloadFailed::class, 'not a PDF');
});

it('answers with an invoice archive, an export and a draft preview', function () {
    BeelFake::api()->invoicePdfArchive("PK\x03\x04archive")->invoiceExport("PK\x03\x04sheet")->invoicePreviewPdf('%PDF-1.7 draft')->fake();
    $invoices = app(BeelManager::class)->company()->invoices;

    $archive = $invoices->downloadPdfArchive(['invoice_ids' => ['a']]);
    $export = $invoices->downloadExport(['invoice_ids' => ['a']], fileName: 'enero.xlsx');
    $preview = $invoices->downloadPreviewPdf('inv-1');

    expect(sent($archive))->toBe("PK\x03\x04archive")
        ->and($archive->headers->get('Content-Type'))->toBe('application/zip')
        ->and($archive->headers->get('Content-Disposition'))->toContain('invoices.zip')
        ->and(sent($export))->toBe("PK\x03\x04sheet")
        ->and($export->headers->get('Content-Disposition'))->toContain('enero.xlsx')
        ->and(sent($preview))->toBe('%PDF-1.7 draft');
});

it('surfaces BeeL errors on archives before answering', function () {
    Http::fake(['*/invoices/pdf-archive' => BeelFake::error(400, 'NO_PDFS_AVAILABLE')]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPdfArchive(['invoice_ids' => ['a']]))
        ->toThrow(fn (BeelApiError $e) => expect($e->apiCode)->toBe('NO_PDFS_AVAILABLE'));
});

it('answers with the preview image under its real type, whatever BeeL declares', function () {
    $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.test/inv-1_preview.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.test/*' => Http::response($png, 200, ['Content-Type' => 'image/webp']),
    ]);

    $response = app(BeelManager::class)->company()->invoices->downloadPreview('inv-1');

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toContain('invoice-inv-1-preview.png')
        ->and(sent($response))->toBe($png);
});

it('answers with the representation document', function () {
    Http::fake([
        '*/companies/company-1/representation/document' => BeelFake::ok(['download_url' => 'https://beel-docs.test/r.pdf?sig=x', 'expires_in_seconds' => 300]),
        'beel-docs.test/*' => BeelFake::pdf('%PDF-1.7 representation'),
    ]);

    $response = app(BeelManager::class)->company()->downloadRepresentationDocument();

    expect(sent($response))->toBe('%PDF-1.7 representation')
        ->and($response->headers->get('Content-Disposition'))->toContain('representation.pdf');
});

it('streams a large export to the browser with small, constant memory', function () {
    $sizes = [1024 * 1024, 32 * 1024 * 1024];
    Http::fake(function () use (&$sizes) {
        $size = array_shift($sizes);
        $produced = 0;

        return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Length' => (string) $size], new PumpStream(function (int $length) use ($size, &$produced) {
            if ($produced >= $size) {
                return false;
            }
            $chunk = $produced === 0 ? "PK\x03\x04".str_repeat('x', min($length, $size) - 4) : str_repeat('x', min($length, $size - $produced));
            $produced += strlen($chunk);

            return $chunk;
        })));
    });
    $invoices = app(BeelManager::class)->company()->invoices;
    $bytes = 0;
    $discard = function (string $output) use (&$bytes): string {
        $bytes += strlen($output);

        return '';
    };

    // Warm-up: the first call loads classes, which is not the download.
    ob_start($discard, 65536);
    $invoices->downloadExport(['invoice_ids' => ['a']])->sendContent();
    ob_end_clean();
    $bytes = 0;
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    ob_start($discard, 65536);
    $invoices->downloadExport(['invoice_ids' => ['a']])->sendContent();
    ob_end_clean();

    expect($bytes)->toBe(32 * 1024 * 1024)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(4 * 1024 * 1024);
});

it('answers 200 with the type and charset BeeL declares, told not to sniff it', function () {
    Http::fake(['*/invoices/exports' => Http::response("PK\x03\x04sheet", 200, ['Content-Type' => 'text/csv; charset=UTF-8'])]);

    $response = app(BeelManager::class)->company()->invoices->downloadExport(['invoice_ids' => ['a']]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('reads a signature that arrives a byte at a time, and sends every byte once', function () {
    $body = "PK\x03\x04".str_repeat('z', 100);
    $offset = 0;
    Http::fake(fn () => Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/zip'], new PumpStream(function () use ($body, &$offset) {
        return $offset < strlen($body) ? $body[$offset++] : false;
    }))));

    $response = app(BeelManager::class)->company()->invoices->downloadPdfArchive(['invoice_ids' => ['a']]);

    expect(sent($response))->toBe($body)
        ->and($response->headers->has('Content-Length'))->toBeFalse();
});

it('says the download is empty when BeeL sends nothing', function () {
    Http::fake(['*/invoices/exports' => Http::response('', 200)]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadExport(['invoice_ids' => ['a']]))
        ->toThrow(DocumentDownloadFailed::class, 'empty');
});

it('keeps the extension the app chose, and adds none to what is not an image', function () {
    $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";
    Http::fake([
        '*/invoices/inv-1/preview' => BeelFake::ok(['image_url' => 'https://beel-previews.test/p.webp?sig=x', 'expires_in_seconds' => 300]),
        'beel-previews.test/*' => Http::response($png, 200),
        '*/invoices/exports' => Http::response("PK\x03\x04sheet", 200),
    ]);
    $invoices = app(BeelManager::class)->company()->invoices;

    expect($invoices->downloadPreview('inv-1', fileName: 'vista.image')->headers->get('Content-Disposition'))->toContain('vista.image')->not->toContain('.png')
        ->and($invoices->downloadExport(['invoice_ids' => ['a']], fileName: 'enero')->headers->get('Content-Disposition'))->toBe('attachment; filename=enero');
});

it('gives browsers without UTF-8 file names a safe ASCII one, and accepts slashes in names', function () {
    BeelFake::api()->invoicePdf()->fake();
    $invoices = app(BeelManager::class)->company()->invoices;

    expect($invoices->downloadPdf('inv-1', fileName: 'Nº "42"/a.pdf')->headers->get('Content-Disposition'))->toContain('filename="N__ _42__a.pdf"')
        ->and($invoices->downloadPdf('inv-1', fileName: 'ñ')->headers->get('Content-Disposition'))->toContain('filename=_');
});

it('asks the storage host again, pausing between attempts, when it fails for a moment', function () {
    config()->set('beel.http.retry_delay_ms', 250);
    Http::fake([
        '*/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf()),
        'beel-pdfs.s3.*' => Http::sequence()->push('', 503)->push('', 503)->push('%PDF-1.7 third time', 200, ['Content-Type' => 'application/pdf']),
    ]);

    expect(sent(app(BeelManager::class)->company()->invoices->downloadPdf('inv-1')))->toBe('%PDF-1.7 third time');
    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(250)->milliseconds()]);
});

it('gives up after beel.downloads.attempts, 3 by default', function (?int $configured, int $attempts) {
    if ($configured !== null) {
        config()->set('beel.downloads.attempts', $configured);
    }
    $calls = 0;
    Http::fake([
        '*/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf()),
        'beel-pdfs.s3.*' => function () use (&$calls) {
            $calls++;

            return Http::response('', 503);
        },
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPdf('inv-1'))->toThrow(DocumentDownloadFailed::class);
    expect($calls)->toBe($attempts);
})->with([
    'default' => [null, 3],
    'configured' => [5, 5],
    'never fewer than one' => [0, 1],
]);

it('does not ask again when the file is not there (a 403 is retried: the signed URL may have expired)', function () {
    $calls = 0;
    Http::fake([
        '*/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf()),
        'beel-pdfs.s3.*' => function () use (&$calls) {
            $calls++;

            return Http::response('', 404);
        },
    ]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPdf('inv-1'))->toThrow(DocumentDownloadFailed::class);
    expect($calls)->toBe(1);
});

it('throws before answering while an issued invoice\'s preview waits for its PDF', function () {
    Http::fake(['*/invoices/inv-1/preview' => Http::response(null, 202, ['Retry-After' => '4'])]);

    expect(fn () => app(BeelManager::class)->company()->invoices->downloadPreview('inv-1'))
        ->toThrow(fn (InvoicePdfNotReady $e) => expect($e->retryAfter)->toBe(4));
});
