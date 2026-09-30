<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Fakes BeeL operations by name, so tests don't depend on the API's routes:
 *
 *     BeelFake::api()
 *         ->createInvoice(BeelFake::invoice(['status' => 'DRAFT']))
 *         ->issueInvoice(BeelFake::invoice())
 *         ->listCustomers([BeelFake::customer()])
 *         ->fake();
 *
 * - An array answers with BeeL's envelope (`BeelFake::ok()`, 201 for creations); list operations
 *   take the items and answer one page. Pass `BeelFake::error(...)` or any response to answer that.
 * - Registering an operation again queues another answer: they are used in order, the last one repeats
 *   (e.g. `->invoicePdf(BeelFake::error(...))->invoicePdf()` fails once, then succeeds).
 * - `$id` narrows an operation to one resource id; without it any id matches.
 * - Requests nothing matches fall through to other fakes and to `Http::preventStrayRequests()`.
 * - `on($method, $path, $response)` fakes any other operation (`{placeholders}` match one segment).
 */
final class BeelApiFake
{
    /** @var array<string, array{method: string, pattern: string, responses: list<PromiseInterface|\Closure>}> */
    private array $routes = [];

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $invoices */
    public function listInvoices(array|PromiseInterface $invoices = []): self
    {
        return $this->on('GET', '/v1/companies/{company}/invoices', self::page('invoices', $invoices));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $invoice */
    public function getInvoice(array|PromiseInterface $invoice, ?string $id = null): self
    {
        return $this->on('GET', '/v1/companies/{company}/invoices/'.self::id($id), self::ok($invoice));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $invoice */
    public function createInvoice(array|PromiseInterface $invoice): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices', self::ok($invoice, 201));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $invoice */
    public function issueInvoice(array|PromiseInterface $invoice, ?string $id = null): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/'.self::id($id).'/issue', self::ok($invoice));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $invoice */
    public function voidInvoice(array|PromiseInterface $invoice, ?string $id = null): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/'.self::id($id).'/void', self::ok($invoice));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $invoice */
    public function createCorrectiveInvoice(array|PromiseInterface $invoice, ?string $id = null): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/'.self::id($id).'/corrective', self::ok($invoice, 201));
    }

    /**
     * `createSimplifiedExchange()`: the STANDARD invoice issued (recorded as F3) in exchange for
     * simplified ones, which BeeL voids with `void_cause` `EXCHANGED`.
     *
     * @param  array<array-key, mixed>|PromiseInterface  $invoice
     */
    public function createSimplifiedExchange(array|PromiseInterface $invoice): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/simplified-exchanges', self::ok($invoice, 201));
    }

    /**
     * `listVerifactuRecords()`: an invoice's VERI*FACTU records, e.g. `[BeelFake::verifactuRecord()]`.
     *
     * @param  list<array<string, mixed>>|PromiseInterface  $records
     */
    public function listVerifactuRecords(array|PromiseInterface $records, ?string $id = null): self
    {
        return $this->on('GET', '/v1/companies/{company}/invoices/'.self::id($id).'/verifactu-records', $records instanceof PromiseInterface ? $records : self::ok(['records' => $records]));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $result */
    public function sendInvoice(array|PromiseInterface $result = ['queued' => true], ?string $id = null): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/'.self::id($id).'/send', self::ok($result));
    }

    /**
     * The PDF link (`getPdf()`) and its download, so `storePdf()` works end to end. Pass a response
     * (e.g. `Http::response(null, 202)` or `BeelFake::error(...)`) to fake the link call instead.
     */
    public function invoicePdf(string|PromiseInterface $contents = "%PDF-1.7\n%fake invoice\n%%EOF\n", ?string $id = null): self
    {
        if ($contents instanceof PromiseInterface) {
            return $this->on('GET', '/v1/companies/{company}/invoices/'.self::id($id).'/pdf', $contents);
        }

        $url = 'https://beel-pdfs.test/'.bin2hex(random_bytes(8)).'.pdf?X-Amz-Signature=fake';

        return $this->on('GET', '/v1/companies/{company}/invoices/'.self::id($id).'/pdf', BeelFake::ok(BeelFake::invoicePdf(['download_url' => $url])))
            ->onUrl($url, BeelFake::pdf($contents));
    }

    /**
     * The ZIP of invoice PDFs `storePdfArchive()` / `createPdfArchive()` receive.
     *
     * @param  array{total?: int, successful?: int, failed?: int}  $counts
     */
    public function invoicePdfArchive(string|PromiseInterface $contents = "PK\x03\x04fake archive", array $counts = ['total' => 1, 'successful' => 1, 'failed' => 0]): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/pdf-archive', $contents instanceof PromiseInterface ? $contents : Http::response($contents, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="invoices.zip"',
            'X-Bulk-Total' => (string) ($counts['total'] ?? 1),
            'X-Bulk-Successful' => (string) ($counts['successful'] ?? 1),
            'X-Bulk-Failed' => (string) ($counts['failed'] ?? 0),
        ]));
    }

    /** The spreadsheet `storeExport()` / `export()` receive. */
    public function invoiceExport(string|PromiseInterface $contents = "PK\x03\x04fake xlsx", int $total = 1): self
    {
        return $this->on('POST', '/v1/companies/{company}/invoices/exports', $contents instanceof PromiseInterface ? $contents : Http::response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="invoices.xlsx"',
            'X-Total-Invoices' => (string) $total,
        ]));
    }

    /** The draft PDF preview `storePreviewPdf()` / `previewPdf()` receive. */
    public function invoicePreviewPdf(string|PromiseInterface $contents = "%PDF-1.7\n%fake preview\n", ?string $id = null): self
    {
        return $this->on('GET', '/v1/companies/{company}/invoices/'.self::id($id).'/pdf/preview', $contents instanceof PromiseInterface ? $contents : Http::response($contents, 200, ['Content-Type' => 'application/pdf']));
    }

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $customers */
    public function listCustomers(array|PromiseInterface $customers = []): self
    {
        return $this->on('GET', '/v1/companies/{company}/customers', self::page('customers', $customers));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $customer */
    public function getCustomer(array|PromiseInterface $customer, ?string $id = null): self
    {
        return $this->on('GET', '/v1/companies/{company}/customers/'.self::id($id), self::ok($customer));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $customer */
    public function createCustomer(array|PromiseInterface $customer): self
    {
        return $this->on('POST', '/v1/companies/{company}/customers', self::ok($customer, 201));
    }

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $products */
    public function listProducts(array|PromiseInterface $products = []): self
    {
        return $this->on('GET', '/v1/companies/{company}/products', self::page('products', $products));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $product */
    public function createProduct(array|PromiseInterface $product): self
    {
        return $this->on('POST', '/v1/companies/{company}/products', self::ok($product, 201));
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $readiness */
    public function issuingReadiness(array|PromiseInterface $readiness = ['ready' => true, 'blockers' => []]): self
    {
        return $this->on('GET', '/v1/companies/{company}/issuing-readiness', self::ok($readiness));
    }

    /** @param  array<string, mixed>|PromiseInterface|null  $identity */
    public function identity(array|PromiseInterface|null $identity = null): self
    {
        return $this->on('GET', '/v1/me/identity', self::ok($identity ?? BeelFake::identity()));
    }

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $subscriptions */
    public function listWebhookSubscriptions(array|PromiseInterface $subscriptions = []): self
    {
        return $this->on('GET', '/v1/accounts/{account}/webhooks', self::page('webhooks', $subscriptions));
    }

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $accounts */
    public function listManagedAccounts(array|PromiseInterface $accounts = []): self
    {
        return $this->on('GET', '/v1/accounts', $accounts instanceof PromiseInterface ? $accounts : BeelFake::cursorPage('accounts', array_values($accounts)));
    }

    /**
     * Fake any operation by method and path under the base URL, e.g.
     * `on('POST', '/v1/companies/{company}/series', BeelFake::ok([...]))`.
     */
    public function on(string $method, string $path, PromiseInterface|\Closure $response): self
    {
        $pattern = '#'.preg_replace('/\\\\\{[a-z_]+\\\\\}/', '[^/]+', preg_quote($path, '#')).'$#';

        return $this->add(strtoupper($method).' '.$pattern, strtoupper($method), $pattern, $response);
    }

    /** Register the fake with `Http::fake()`. */
    public function fake(): self
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            // Most specific first (an operation narrowed to an id before the same one for any id),
            // whatever the order they were registered in.
            $routes = $this->routes;
            uasort($routes, fn (array $a, array $b): int => substr_count($a['pattern'], '[^/]+') <=> substr_count($b['pattern'], '[^/]+'));

            foreach ($routes as $key => $route) {
                if ($route['method'] !== $request->method()) {
                    continue;
                }
                $target = str_starts_with($route['pattern'], '#^') ? $request->url() : $path;
                if (preg_match($route['pattern'], $target) !== 1) {
                    continue;
                }

                $response = count($route['responses']) > 1 ? array_shift($this->routes[$key]['responses']) : $route['responses'][0];
                if ($response === null) {
                    continue;
                }

                return $response instanceof \Closure ? $response($request) : self::rewound($response);
            }

            return null;
        });

        return $this;
    }

    /** The last answer repeats, and a streamed download reads its body to the end: start it over each time. */
    private static function rewound(PromiseInterface $response): PromiseInterface
    {
        $psr = $response->wait();
        if ($psr instanceof ResponseInterface && $psr->getBody()->isSeekable()) {
            $psr->getBody()->rewind();
        }

        return $response;
    }

    private function onUrl(string $url, PromiseInterface $response): self
    {
        $pattern = '#^'.preg_quote($url, '#').'$#';

        return $this->add('GET '.$pattern, 'GET', $pattern, $response);
    }

    private function add(string $key, string $method, string $pattern, PromiseInterface|\Closure $response): self
    {
        $this->routes[$key] ??= ['method' => $method, 'pattern' => $pattern, 'responses' => []];
        $this->routes[$key]['responses'][] = $response;

        return $this;
    }

    /** @param  array<array-key, mixed>|PromiseInterface  $data */
    private static function ok(array|PromiseInterface $data, int $status = 200): PromiseInterface
    {
        return $data instanceof PromiseInterface ? $data : BeelFake::ok($data, $status);
    }

    /** @param  array<array-key, array<string, mixed>>|PromiseInterface  $items */
    private static function page(string $key, array|PromiseInterface $items): PromiseInterface
    {
        return $items instanceof PromiseInterface ? $items : BeelFake::page($key, array_values($items));
    }

    private static function id(?string $id): string
    {
        return $id ?? '{id}';
    }
}
