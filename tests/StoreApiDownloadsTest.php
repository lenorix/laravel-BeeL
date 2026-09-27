<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Support\SignedDownloadStorage;
use Lenorix\LaravelBeel\Testing\BeelFake;

mutates(SignedDownloadStorage::class);

// Files BeeL's API returns in the response body: archives, exports and draft PDF previews.

const ZIP = "PK\x03\x04 fake zip with invoice PDFs";

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 3);
    config()->set('beel.http.retry_delay_ms', 0);
    Storage::fake('exports');
});

it('stores the invoice PDF archive with BeeL\'s counts and file name', function () {
    Http::fake(['*/invoices/pdf-archive' => Http::response(ZIP, 200, [
        'Content-Type' => 'application/zip',
        'Content-Disposition' => 'attachment; filename="facturas.zip"',
        'X-Bulk-Total' => '3', 'X-Bulk-Successful' => '2', 'X-Bulk-Failed' => '1',
    ])]);

    $stored = app(BeelManager::class)->company()->invoices->storePdfArchive(['invoice_ids' => ['i-1', 'i-2', 'i-3']], 'archives/2025-01.zip', disk: 'exports');

    expect(Storage::disk('exports')->get('archives/2025-01.zip'))->toBe(ZIP)
        ->and($stored->path)->toBe('archives/2025-01.zip')
        ->and($stored->fileName)->toBe('facturas.zip')
        ->and($stored->counts)->toMatchArray(['total' => 3, 'successful' => 2, 'failed' => 1]);
    Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST'
        && str_starts_with($r->header('Accept')[0] ?? '', 'application/zip')
        && $r->hasHeader('Authorization', 'Bearer beel_sk_test_fake'));
});

it('stores an invoice export as a spreadsheet', function () {
    Http::fake(['*/invoices/exports' => Http::response("PK\x03\x04 fake xlsx", 200, [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Content-Disposition' => 'attachment; filename="facturas_2025-01-15.xlsx"',
        'X-Total-Invoices' => '120',
    ])]);

    $stored = app(BeelManager::class)->company()->invoices->storeExport(['invoice_ids' => ['i-1']], 'exports/enero.xlsx', disk: 'exports');

    expect(Storage::disk('exports')->get('exports/enero.xlsx'))->toBe("PK\x03\x04 fake xlsx")
        ->and($stored->counts)->toMatchArray(['total' => 120]);
});

it('stores the PDF preview of a draft', function () {
    Http::fake(['*/invoices/inv-1/pdf/preview' => Http::response('%PDF-1.7 draft', 200, ['Content-Type' => 'application/pdf'])]);

    app(BeelManager::class)->company()->invoices->storePreviewPdf('inv-1', 'previews/inv-1.pdf', disk: 'exports');

    expect(Storage::disk('exports')->get('previews/inv-1.pdf'))->toBe('%PDF-1.7 draft');
});

it('refuses an existing file before asking BeeL to build anything', function () {
    Storage::disk('exports')->put('a.zip', 'previous');
    Http::fake();

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdfArchive(['invoice_ids' => ['i-1']], 'a.zip', disk: 'exports'))
        ->toThrow(DocumentAlreadyExists::class);

    Http::assertNothingSent();
});

it('never stores what is not the expected file, and never asks BeeL again', function () {
    Http::fake(['*/invoices/exports' => Http::response('<html>proxy error</html>', 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storeExport(['invoice_ids' => ['i-1']], 'a.xlsx', disk: 'exports'))
        ->toThrow(DocumentDownloadFailed::class, 'not a ZIP file');

    expect(Storage::disk('exports')->allFiles())->toBe([]);
    Http::assertSentCount(1);
});

it('does not repeat an archive after a 5xx, since BeeL would build it again', function () {
    Http::fake(['*/invoices/pdf-archive' => BeelFake::error(503, 'SERVICE_UNAVAILABLE')]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdfArchive(['invoice_ids' => ['i-1']], 'a.zip', disk: 'exports'))
        ->toThrow(BeelApiError::class);

    Http::assertSentCount(1);
    expect(Storage::disk('exports')->allFiles())->toBe([]);
});

/** A spreadsheet export of $size bytes, generated lazily so the fake itself holds nothing. */
function lazyExport(int $size): PsrResponse
{
    $produced = 0;

    return new PsrResponse(200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Length' => (string) $size], new PumpStream(function (int $length) use ($size, &$produced) {
        if ($produced >= $size) {
            return false;
        }
        $chunk = $produced === 0 ? "PK\x03\x04".str_repeat('x', min($length, $size) - 4) : str_repeat('x', min($length, $size - $produced));
        $produced += strlen($chunk);

        return $chunk;
    }));
}

it('streams a large export into the disk with small, constant memory', function () {
    $sizes = [1024 * 1024, 32 * 1024 * 1024];
    Http::fake(function () use (&$sizes) {
        return Create::promiseFor(lazyExport(array_shift($sizes)));
    });
    $invoices = app(BeelManager::class)->company()->invoices;

    // Warm-up: the first call loads the SDK's and Guzzle's classes, which is not the download.
    $invoices->storeExport(['invoice_ids' => ['i-1']], 'small.xlsx', disk: 'exports');
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();

    $invoices->storeExport(['invoice_ids' => ['i-1']], 'big.xlsx', disk: 'exports');

    expect(Storage::disk('exports')->size('big.xlsx'))->toBe(32 * 1024 * 1024)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(4 * 1024 * 1024);
});
