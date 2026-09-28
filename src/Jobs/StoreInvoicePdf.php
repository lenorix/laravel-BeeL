<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Jobs;

use Lenorix\LaravelBeel\BeelCompany;
use Lenorix\LaravelBeel\StoredDocument;

/**
 * Stores an issued invoice's PDF on a Laravel disk from the queue. See StoreBeelDocument for how it waits, retries
 * and fails.
 *
 *     StoreInvoicePdf::dispatch($invoiceId, 'invoices/A-42.pdf', disk: 's3');
 */
final class StoreInvoicePdf extends StoreBeelDocument
{
    /**
     * @param  string|null  $disk  Disk name; null uses the default disk.
     * @param  array<string, mixed>  $options  Passed to the disk (e.g. `visibility`).
     */
    public function __construct(
        public readonly string $invoiceId,
        string $path,
        ?string $disk = null,
        bool $overwrite = false,
        array $options = [],
        ?string $companyId = null,
        #[\SensitiveParameter] ?string $apiKey = null,
    ) {
        parent::__construct($path, $disk, $overwrite, $options, $companyId, $apiKey);
    }

    protected function store(BeelCompany $company): StoredDocument
    {
        return new StoredDocument($company->invoices->storePdf($this->invoiceId, $this->path, $this->disk, $this->overwrite, $this->options));
    }

    protected function invoiceId(): string
    {
        return $this->invoiceId;
    }
}
