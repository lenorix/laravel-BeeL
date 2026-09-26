<?php

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Http\RequestOptions;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfDownloadFailed;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\Testing\BeelFake;

const PDF_URL = 'https://beel-pdfs.s3.eu-west-1.amazonaws.com/invoices/f47ac10b.pdf?X-Amz-Signature=fake';

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Storage::fake('invoices');
    Sleep::fake();
});

/** Fakes BeeL's PDF endpoint and the pre-signed URL; each download answers the next of $downloads. */
function fakePdfDownloads(mixed ...$downloads): void
{
    Http::fake(function (ClientRequest $request) use (&$downloads) {
        if (str_contains($request->url(), '/invoices/inv-1/pdf')) {
            return BeelFake::ok(BeelFake::invoicePdf());
        }

        $next = array_shift($downloads);

        return $next instanceof Closure ? $next() : $next;
    });
}

function pdfUrlRequests(): int
{
    return Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/invoices/inv-1/pdf'))->count();
}

/** Files left in the fake disk, temporary ones included. */
function storedFiles(): array
{
    return Storage::disk('invoices')->allFiles();
}

it('streams the PDF into the disk and returns the path', function () {
    fakePdfDownloads(BeelFake::pdf('%PDF-1.7 invoice'));

    $path = app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'invoices/2025/A-42.pdf', disk: 'invoices');

    expect($path)->toBe('invoices/2025/A-42.pdf')
        ->and(Storage::disk('invoices')->get($path))->toBe('%PDF-1.7 invoice')
        ->and(storedFiles())->toBe(['invoices/2025/A-42.pdf']);
});

it('never sends the BeeL API key to the pre-signed URL', function () {
    fakePdfDownloads(BeelFake::pdf());

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices');

    Http::assertSent(fn (ClientRequest $r) => $r->url() === PDF_URL && ! $r->hasHeader('Authorization'));
    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/pdf') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_fake'));
});

it('refuses an existing file before calling BeeL', function () {
    Storage::disk('invoices')->put('a.pdf', 'previous');
    fakePdfDownloads(BeelFake::pdf());

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(InvoicePdfAlreadyExists::class, 'overwrite: true');

    Http::assertNothingSent();
    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('previous');
});

it('replaces an existing file with overwrite', function () {
    Storage::disk('invoices')->put('a.pdf', 'previous');
    fakePdfDownloads(BeelFake::pdf('%PDF-new'));

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices', overwrite: true);

    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('%PDF-new')
        ->and(storedFiles())->toBe(['a.pdf']);
});

it('leaves the existing file untouched when an overwrite fails', function () {
    Storage::disk('invoices')->put('a.pdf', 'previous');
    fakePdfDownloads(...array_fill(0, 3, fn () => Http::response('<Error>AccessDenied</Error>', 200)));

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices', overwrite: true))
        ->toThrow(InvoicePdfDownloadFailed::class, 'not a PDF');

    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('previous')
        ->and(storedFiles())->toBe(['a.pdf']);
});

it('retries a download cut short with a fresh URL and never keeps the partial file', function () {
    fakePdfDownloads(
        Http::response('%PDF-1.7 half', 200, ['Content-Length' => '100']),
        BeelFake::pdf('%PDF-1.7 whole'),
    );

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices');

    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('%PDF-1.7 whole')
        ->and(storedFiles())->toBe(['a.pdf'])
        ->and(pdfUrlRequests())->toBe(2);
});

it('asks for a new URL when the previous one expired', function () {
    fakePdfDownloads(Http::response('<Error>Request has expired</Error>', 403), BeelFake::pdf());

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices');

    expect(pdfUrlRequests())->toBe(2)->and(storedFiles())->toBe(['a.pdf']);
});

it('gives up after the configured attempts, writing nothing', function () {
    config()->set('beel.pdf.attempts', 2);
    fakePdfDownloads(Http::response('', 503), Http::response('', 503), BeelFake::pdf());

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(fn (InvoicePdfDownloadFailed $e) => expect($e->attempts)->toBe(2)->and($e->getMessage())->toContain('HTTP 503'));

    expect(storedFiles())->toBe([]);
    Sleep::assertSleptTimes(1);
});

it('does not retry a download that can not succeed', function () {
    fakePdfDownloads(Http::response('', 404), BeelFake::pdf());

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(fn (InvoicePdfDownloadFailed $e) => expect($e->attempts)->toBe(1));

    expect(pdfUrlRequests())->toBe(1);
});

it('keeps the pre-signed URL out of error messages', function () {
    fakePdfDownloads(...array_fill(0, 3, fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.PDF_URL)));

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(function (InvoicePdfDownloadFailed $e) {
            expect($e->getMessage())->toContain('timed out')->not->toContain('X-Amz-Signature')->not->toContain('beel-pdfs.s3')
                ->and($e->getPrevious())->toBeNull();
        });
});

it('fails loudly when something else consumed the download', function () {
    // e.g. a ResponseReceived listener that reads the body for logging.
    Event::listen(fn (ResponseReceived $event) => $event->response->body());
    fakePdfDownloads(...array_fill(0, 3, fn () => BeelFake::pdf()));

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(InvoicePdfDownloadFailed::class, 'empty');

    expect(storedFiles())->toBe([]);
});

it('says when BeeL is still generating the PDF', function () {
    Http::fake(['*/invoices/inv-1/pdf' => Http::response(null, 202, ['Retry-After' => '2'])]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(InvoicePdfNotReady::class);
});

it('surfaces that a draft has no PDF', function () {
    Http::fake(['*/invoices/inv-1/pdf' => BeelFake::error(400, 'INVOICE_NOT_ISSUED_NO_PDF')]);

    expect(fn () => app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices'))
        ->toThrow(fn (BeelApiError $e) => expect($e->apiCode)->toBe('INVOICE_NOT_ISSUED_NO_PDF'));
});

it('passes disk options such as visibility', function () {
    fakePdfDownloads(BeelFake::pdf());

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices', options: ['visibility' => 'public']);

    expect(Storage::disk('invoices')->getVisibility('a.pdf'))->toBe('public');
});

it('works after withOptions()', function () {
    fakePdfDownloads(BeelFake::pdf());

    app(BeelManager::class)->company()->invoices
        ->withOptions(new RequestOptions(headers: ['X-Trace' => 't-1']))
        ->storePdf('inv-1', 'a.pdf', disk: 'invoices');

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/pdf') && $r->hasHeader('X-Trace', 't-1'));
    expect(storedFiles())->toBe(['a.pdf']);
});

/**
 * A lazily generated PDF of $size bytes that records the size of every read, so memory can be
 * measured without the fake itself holding the file.
 */
function lazyPdf(int $size, array &$reads): PsrResponse
{
    $produced = 0;
    $body = new PumpStream(function (int $length) use ($size, &$produced, &$reads) {
        if ($produced >= $size) {
            return false;
        }
        $reads[] = $length;
        $chunk = $produced === 0 ? '%PDF-'.str_repeat('x', min($length, $size) - 5) : str_repeat('x', min($length, $size - $produced));
        $produced += strlen($chunk);

        return $chunk;
    });

    return new PsrResponse(200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) $size], $body);
}

it('uses the same small memory for any PDF size, reading in buffer-sized steps', function () {
    $peakFor = function (int $size, array &$reads): int {
        fakePdfDownloads(function () use ($size, &$reads) {
            return Create::promiseFor(lazyPdf($size, $reads));
        });
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        app(BeelManager::class)->company()->invoices->storePdf('inv-1', "{$size}.pdf", disk: 'invoices');

        expect(Storage::disk('invoices')->size("{$size}.pdf"))->toBe($size);

        return memory_get_peak_usage() - $before;
    };

    $smallReads = $bigReads = [];
    $small = $peakFor(8 * 1024 * 1024, $smallReads);
    $big = $peakFor(64 * 1024 * 1024, $bigReads);

    // Eight times the data, not eight times the memory: both stay around the buffer size.
    expect($big)->toBeLessThan(2 * 1024 * 1024)
        ->and($big - $small)->toBeLessThan(512 * 1024)
        ->and(max($bigReads))->toBe(65536);
});

it('reads in the configured buffer size', function () {
    config()->set('beel.pdf.buffer_bytes', 256 * 1024);
    $reads = [];
    fakePdfDownloads(function () use (&$reads) {
        return Create::promiseFor(lazyPdf(2 * 1024 * 1024, $reads));
    });

    app(BeelManager::class)->company()->invoices->storePdf('inv-1', 'a.pdf', disk: 'invoices');

    expect(max($reads))->toBe(256 * 1024);
});
