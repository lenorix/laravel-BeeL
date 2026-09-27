<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Jobs;

use Lenorix\LaravelBeel\BeelCompany;

/**
 * Stores a spreadsheet export of up to 50,000 invoices on a Laravel disk from the queue. See StoreBeelDocument for how it waits, retries
 * and fails.
 *
 *     StoreInvoiceExport::dispatch(['filters' => [...]], 'exports/2025-01.xlsx', disk: 's3');
 */
final class StoreInvoiceExport extends StoreBeelDocument
{
    /**
     * @param  array<string, mixed>  $request  In API format, e.g. `['invoice_ids' => [...]]`.
     * @param  string|null  $disk  Disk name; null uses the default disk.
     * @param  array<string, mixed>  $options  Passed to the disk (e.g. `visibility`).
     */
    public function __construct(
        public readonly array $request,
        string $path,
        ?string $disk = null,
        bool $overwrite = false,
        array $options = [],
        ?string $companyId = null,
        #[\SensitiveParameter] ?string $apiKey = null,
    ) {
        parent::__construct($path, $disk, $overwrite, $options, $companyId, $apiKey);
    }

    protected function store(BeelCompany $company): void
    {
        $company->invoices->storeExport($this->request, $this->path, $this->disk, $this->overwrite, $this->options);
    }
}
