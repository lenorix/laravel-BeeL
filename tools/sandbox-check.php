<?php

declare(strict_types=1);

/*
 * Manual check of the package against BeeL's real sandbox. NOT part of the test suite: tests never
 * reach a real service. Run it by hand before a release, or after BeeL changes its API:
 *
 *     BEEL_SANDBOX_KEY=beel_sk_test_... BEEL_SANDBOX_COMPANY_ID=<uuid> php tools/sandbox-check.php
 *
 * It changes nothing: GET requests, plus document downloads into a temporary directory it deletes
 * (the invoice archive and export are POSTs that only build a file). It refuses live keys, and
 * reports:
 * - whether the SDK parses BeeL's real responses through the package;
 * - fields BeeL returns that BeelFake doesn't fake, and fields BeelFake fakes that BeeL no longer
 *   returns (the fakes drifting from the API);
 * - whether every store*() method stores a real file of its kind, and every download*() method
 *   streams one.
 */

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\LaravelBeel\BeelManager;
use Lenorix\LaravelBeel\Exceptions\InvoicePdfNotReady;
use Lenorix\LaravelBeel\LaravelBeelServiceProvider;
use Lenorix\LaravelBeel\Testing\BeelFake;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__).'/vendor/autoload.php';

$key = getenv('BEEL_SANDBOX_KEY') ?: '';
$companyId = getenv('BEEL_SANDBOX_COMPANY_ID') ?: null;

if (! str_starts_with($key, 'beel_sk_test_')) {
    fwrite(STDERR, "Set BEEL_SANDBOX_KEY to a sandbox key (beel_sk_test_...). Live keys are refused.\n");
    exit(2);
}

$app = Application::create();
$app['config']->set('services.beel.key', $key);
$app['config']->set('services.beel.company_id', $companyId);
$app['config']->set('filesystems.disks.sandbox-check', ['driver' => 'local', 'root' => sys_get_temp_dir().'/beel-sandbox-check-'.getmypid()]);
$app->register(LaravelBeelServiceProvider::class);

$failures = 0;
$report = function (string $status, string $message) use (&$failures): void {
    $failures += $status === 'FAIL' ? 1 : 0;
    echo str_pad("[{$status}]", 8).$message.PHP_EOL;
};

// Raw JSON bodies by path, to compare real field names with BeelFake's.
$bodies = [];
Event::listen(function (ResponseReceived $event) use (&$bodies): void {
    if (str_contains($event->response->header('Content-Type'), 'json')) {
        $bodies[(string) parse_url($event->request->url(), PHP_URL_PATH)] = $event->response->json();
    }
});

/** @param array<string, mixed> $real @param array<string, mixed> $fake */
$compare = function (string $what, array $real, array $fake) use ($report): void {
    $new = array_diff(array_keys($real), array_keys($fake));
    $gone = array_diff(array_keys($fake), array_keys($real));
    $report($new === [] && $gone === [] ? 'OK' : 'DRIFT', "{$what} fields".($new !== [] ? '; BeeL also returns: '.implode(', ', $new) : '').($gone !== [] ? '; BeelFake has, BeeL did not return: '.implode(', ', $gone) : ''));
};

$step = function (string $what, callable $run) use ($report): mixed {
    try {
        $result = $run();
        $report('OK', $what);

        return $result;
    } catch (Throwable $e) {
        $report('FAIL', "{$what}: ".$e::class.': '.$e->getMessage());

        return null;
    }
};

$beel = $app->make(BeelManager::class);

$identity = $step('identity parses (me->identity())', fn () => $beel->client()->me->identity());
if ($identity !== null) {
    echo '        account '.$identity->getAccountId().', environment '.$identity->getCredential()->getEnvironment().PHP_EOL;
    $compare('identity', $bodies['/api/v1/me/identity']['data'] ?? [], BeelFake::identity());
}

$step('tax types catalogue parses', fn () => $beel->client()->catalogs->taxTypes());

if ($companyId === null) {
    $report('SKIP', 'company checks: set BEEL_SANDBOX_COMPANY_ID');
} else {
    $company = $beel->company();
    $step('issuing readiness parses', fn () => $company->issuingReadiness());

    $invoices = $step('invoices list parses', fn () => $company->invoices->list(['limit' => 5])->getInvoices());
    $rawInvoice = $bodies["/api/v1/companies/{$companyId}/invoices"]['data']['invoices'][0] ?? null;
    is_array($rawInvoice) ? $compare('invoice', $rawInvoice, BeelFake::invoice()) : $report('SKIP', 'invoice fields: the company has no invoices');

    $step('customers list parses', fn () => $company->customers->list(['limit' => 5]));
    $rawCustomer = $bodies["/api/v1/companies/{$companyId}/customers"]['data']['customers'][0] ?? null;
    is_array($rawCustomer) ? $compare('customer', $rawCustomer, BeelFake::customer()) : $report('SKIP', 'customer fields: the company has no customers');

    $disk = Storage::disk('sandbox-check');
    $pdf = '/^%PDF-/';
    $image = '/^(\x89PNG|RIFF....WEBP|\xFF\xD8\xFF)/s';
    $zip = '/^PK\x03\x04/';

    /**
     * Checks one document method: `store` runs a store*() and reads the file back, `stream` sends a
     * download*() response into memory. With $mayBeUnavailable, a document BeeL can't serve yet (202,
     * or a 4xx such as a representation not generated) is a SKIP instead of a failure.
     */
    $document = function (string $what, string $signature, string $mode, callable $call, bool $mayBeUnavailable = false) use ($report, $disk): void {
        try {
            if ($mode === 'store') {
                $result = $call();
                $body = (string) $disk->get(is_string($result) ? $result : $result->path);
            } else {
                ob_start();
                try {
                    $call()->sendContent();
                } finally {
                    $body = (string) ob_get_clean();
                }
            }
            preg_match($signature, $body) === 1
                ? $report('OK', "{$what}: ".strlen($body).' bytes')
                : $report('FAIL', "{$what}: not the expected kind of file");
        } catch (InvoicePdfNotReady $e) {
            $report($mayBeUnavailable ? 'SKIP' : 'FAIL', "{$what}: BeeL is still generating it");
        } catch (BeelApiError $e) {
            $report($mayBeUnavailable && $e->statusCode < 500 ? 'SKIP' : 'FAIL', "{$what}: BeeL answered {$e->statusCode} {$e->apiCode}");
        } catch (Throwable $e) {
            $report('FAIL', "{$what}: ".$e::class.': '.$e->getMessage());
        } finally {
            $disk->deleteDirectory('');
        }
    };

    $issued = collect($invoices ?? [])->filter(fn ($invoice) => $invoice->getStatus() !== 'DRAFT')->values();
    $draft = collect($invoices ?? [])->first(fn ($invoice) => $invoice->getStatus() === 'DRAFT');
    if ($issued->isEmpty()) {
        $report('SKIP', 'issued-invoice documents: no issued invoice among the first five');
    } else {
        $id = $issued->first()->getId();
        $request = ['invoice_ids' => $issued->take(2)->map(fn ($invoice) => $invoice->getId())->all()];
        $document("storePdf() of invoice {$id}", $pdf, 'store', fn () => $company->invoices->storePdf($id, 'check.pdf', disk: 'sandbox-check', overwrite: true));
        $document("downloadPdf() of invoice {$id}", $pdf, 'stream', fn () => $company->invoices->downloadPdf($id));
        $document("storePreview() of invoice {$id}", $image, 'store', fn () => $company->invoices->storePreview($id, 'check.img', disk: 'sandbox-check', overwrite: true), true);
        $document("downloadPreview() of invoice {$id}", $image, 'stream', fn () => $company->invoices->downloadPreview($id), true);
        $document('storePdfArchive()', $zip, 'store', fn () => $company->invoices->storePdfArchive($request, 'check.zip', disk: 'sandbox-check', overwrite: true));
        $document('downloadPdfArchive()', $zip, 'stream', fn () => $company->invoices->downloadPdfArchive($request));
        $document('storeExport()', $zip, 'store', fn () => $company->invoices->storeExport($request, 'check.xlsx', disk: 'sandbox-check', overwrite: true));
        $document('downloadExport()', $zip, 'stream', fn () => $company->invoices->downloadExport($request));
        $step("listVerifactuRecords() of invoice {$id} parses", fn () => $company->invoices->listVerifactuRecords($id));
    }

    if ($draft === null) {
        $report('SKIP', 'draft documents: no draft among the first five');
    } else {
        $document("storePreviewPdf() of draft {$draft->getId()}", $pdf, 'store', fn () => $company->invoices->storePreviewPdf($draft->getId(), 'draft.pdf', disk: 'sandbox-check', overwrite: true));
        $document("downloadPreviewPdf() of draft {$draft->getId()}", $pdf, 'stream', fn () => $company->invoices->downloadPreviewPdf($draft->getId()));
    }

    $document('storeRepresentationDocument()', $pdf, 'store', fn () => $company->storeRepresentationDocument('representation.pdf', disk: 'sandbox-check', overwrite: true), true);
    $document('downloadRepresentationDocument()', $pdf, 'stream', fn () => $company->downloadRepresentationDocument(), true);
}

echo PHP_EOL.($failures === 0 ? 'No failures.' : "{$failures} failure(s).").PHP_EOL;
exit($failures === 0 ? 0 : 1);
