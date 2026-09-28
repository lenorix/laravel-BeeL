<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events;

use Lenorix\LaravelBeel\StoredDocument;

/**
 * A queued job (StoreInvoicePdf, StoreInvoicePdfArchive, ...) stored a BeeL document. Listen to it
 * for the next step: attach the file to a model, notify someone, or check `counts['failed']` for
 * invoices an archive left out (they had no PDF).
 *
 * Not dispatched when the job found the file already there (no `overwrite`): nothing was stored.
 * It carries no credentials, so a queued listener may serialize it safely.
 */
final class BeelDocumentStored
{
    /**
     * @param  class-string  $job  The job that stored it, e.g. `StoreInvoicePdfArchive::class`.
     * @param  string|null  $disk  Disk name; null is the default disk.
     * @param  string|null  $invoiceId  The invoice, for jobs that store one invoice's document.
     * @param  string|null  $companyId  The company given to the job; null when it came from the CredentialsResolver.
     */
    public function __construct(
        public readonly string $job,
        public readonly StoredDocument $document,
        public readonly ?string $disk,
        public readonly ?string $invoiceId,
        public readonly ?string $companyId,
    ) {}
}
