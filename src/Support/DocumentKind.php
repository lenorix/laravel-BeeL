<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Support;

/**
 * The file types BeeL hands out through pre-signed URLs, each recognised by its signature bytes, so
 * an error page or an emptied body is never stored as the document.
 *
 * @internal
 */
enum DocumentKind
{
    case Pdf;
    /** BeeL documents its previews as WebP, but the sandbox serves PNG under a .webp name: accept any image. */
    case Image;
    case Zip;

    public function contentType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Image => 'image/webp',
            self::Zip => 'application/zip',
        };
    }

    /** Bytes needed from the start of the file to recognise it. */
    public function headLength(): int
    {
        return $this === self::Image ? 12 : 5;
    }

    public function matches(string $head): bool
    {
        return match ($this) {
            self::Pdf => str_starts_with($head, '%PDF-'),
            self::Image => (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
                || str_starts_with($head, "\x89PNG\r\n\x1a\n")
                || str_starts_with($head, "\xFF\xD8\xFF"),
            self::Zip => str_starts_with($head, "PK\x03\x04"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'a PDF',
            self::Image => 'an image',
            self::Zip => 'a ZIP file',
        };
    }
}
