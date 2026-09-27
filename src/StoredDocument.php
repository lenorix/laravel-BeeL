<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

/** A file from BeeL stored on a disk, with what BeeL said about it. */
final class StoredDocument
{
    /**
     * @param  string  $path  Where it was stored on the disk.
     * @param  string|null  $fileName  The name BeeL suggested (Content-Disposition), e.g. facturas_2025-01-15.xlsx.
     * @param  array<string, int>  $counts  BeeL's counts: for an archive `total`, `successful` and `failed`
     *                                      (invoices without a PDF are left out); for an export `total`.
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $fileName = null,
        public readonly array $counts = [],
    ) {}
}
