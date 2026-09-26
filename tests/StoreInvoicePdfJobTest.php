<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lenorix\LaravelBeel\Events\Webhooks\InvoicePdfGenerated;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfDownloadFailed;
use Lenorix\LaravelBeel\Jobs\StoreInvoicePdf;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Lenorix\LaravelBeel\Testing\InteractsWithBeelWebhooks;

uses(InteractsWithBeelWebhooks::class);

beforeEach(function () {
    config()->set('services.beel.key', 'beel_sk_test_fake');
    config()->set('services.beel.company_id', 'company-1');
    config()->set('beel.http.retries', 0);
    Storage::fake('invoices');
    Sleep::fake();
});

/** Fakes BeeL's PDF endpoint with $pdfEndpoint and the pre-signed URL with $download. */
function fakeJobPdfApi(mixed $pdfEndpoint = null, mixed $download = null): void
{
    Http::fake([
        '*/invoices/inv-1/pdf' => $pdfEndpoint ?? BeelFake::ok(BeelFake::invoicePdf()),
        'beel-pdfs.s3.*' => $download ?? BeelFake::pdf('%PDF-queued'),
    ]);
}

function runJob(StoreInvoicePdf $job): StoreInvoicePdf
{
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('stores the invoice PDF on the given disk and path', function () {
    fakeJobPdfApi();

    runJob(new StoreInvoicePdf('inv-1', 'invoices/A-42.pdf', disk: 'invoices'))
        ->assertNotFailed()
        ->assertNotReleased();

    expect(Storage::disk('invoices')->get('invoices/A-42.pdf'))->toBe('%PDF-queued');
});

it('uses the credentials given for the job', function () {
    fakeJobPdfApi();
    Http::fake(['*/companies/company-9/invoices/inv-1/pdf' => BeelFake::ok(BeelFake::invoicePdf())]);

    runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices', companyId: 'company-9', apiKey: 'beel_sk_test_tenant'));

    Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/companies/company-9/') && $r->hasHeader('Authorization', 'Bearer beel_sk_test_tenant'));
});

it('goes back to the queue for as long as BeeL says while the PDF is being generated', function () {
    fakeJobPdfApi(Http::response(null, 202, ['Retry-After' => '7']));

    runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices'))->assertReleased(delay: 7)->assertNotFailed();
});

it('counts an existing file as done', function () {
    Storage::disk('invoices')->put('a.pdf', 'already stored');
    fakeJobPdfApi();

    runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices'))->assertNotFailed()->assertNotReleased();

    Http::assertNothingSent();
    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('already stored');
});

it('replaces an existing file with overwrite', function () {
    Storage::disk('invoices')->put('a.pdf', 'old');
    fakeJobPdfApi();

    runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices', overwrite: true));

    expect(Storage::disk('invoices')->get('a.pdf'))->toBe('%PDF-queued');
});

it('fails at once when retrying can not help, such as a draft', function () {
    fakeJobPdfApi(BeelFake::error(400, 'INVOICE_NOT_ISSUED_NO_PDF'));

    runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices'))->assertFailed()->assertNotReleased();
});

it('lets the queue retry a download that failed', function () {
    fakeJobPdfApi(download: Http::response('', 503));

    expect(fn () => runJob(new StoreInvoicePdf('inv-1', 'a.pdf', disk: 'invoices')))->toThrow(InvoicePdfDownloadFailed::class);
});

it('keeps its payload, which may hold an API key, encrypted in the queue', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $job = new StoreInvoicePdf('inv-1', 'a.pdf', apiKey: 'beel_sk_live_secret');
    $queue = app('queue')->connection('null');

    // The exact payload a queue driver would store (Redis, database, failed_jobs, ...).
    $payload = (fn () => $this->createPayload($job, 'default'))->call($queue);

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($payload)->not->toContain('beel_sk_live_secret')
        ->and($payload)->not->toContain('inv-1');
});

it('queues the PDF from the invoice.pdf.generated webhook, as the README shows', function () {
    config()->set('services.beel.webhook_secret', 'test-webhook-secret');
    Queue::fake();
    Event::listen(function (InvoicePdfGenerated $event): void {
        if (! $event->webhook->isTest()) {
            $invoiceId = $event->data()->getInvoiceId();

            StoreInvoicePdf::dispatch($invoiceId, "invoices/{$invoiceId}.pdf", disk: 's3', companyId: $event->webhook->companyId);
        }
    });

    $this->postBeelWebhook('invoice.pdf.generated', overrides: ['company_id' => 'company-7'])->assertStatus(202);

    Queue::assertPushed(StoreInvoicePdf::class, fn (StoreInvoicePdf $job) => $job->invoiceId === 'f47ac10b-58cc-4372-a567-0e02b2c3d479'
        && $job->path === 'invoices/f47ac10b-58cc-4372-a567-0e02b2c3d479.pdf'
        && $job->disk === 's3'
        && $job->companyId === 'company-7');
});

it('runs the next step of a chain only once the PDF is stored, as the README shows', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    fakeJobPdfApi();

    Bus::chain([
        new StoreInvoicePdf('inv-1', 'beel-tmp/inv-1.pdf', disk: 'invoices', overwrite: true),
        function () {
            // Stands in for addMediaFromDisk(): the file is there when this step runs.
            Storage::disk('invoices')->move('beel-tmp/inv-1.pdf', 'media/inv-1.pdf');
        },
    ])->catch(function () {
        Storage::disk('invoices')->delete('beel-tmp/inv-1.pdf');
    })->dispatch();

    expect(Storage::disk('invoices')->get('media/inv-1.pdf'))->toBe('%PDF-queued')
        ->and(Storage::disk('invoices')->allFiles())->toBe(['media/inv-1.pdf']);
});

it('stops the chain, cleaning up, when the PDF can not be stored', function () {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    fakeJobPdfApi(BeelFake::error(400, 'INVOICE_NOT_ISSUED_NO_PDF'));

    Bus::chain([
        new StoreInvoicePdf('inv-1', 'beel-tmp/inv-1.pdf', disk: 'invoices', overwrite: true),
        function () {
            throw new LogicException('must not run');
        },
    ])->catch(function () {
        Storage::disk('invoices')->delete('beel-tmp/inv-1.pdf');
    })->dispatch();

    expect(Storage::disk('invoices')->allFiles())->toBe([]);
});
