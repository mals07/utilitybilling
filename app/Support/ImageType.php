<?php

namespace App\Support;

/**
 * Detects an image type from the file's contents rather than trusting the client-provided name or MIME type.
 */
final class ImageType
{
    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x00\x00\x01\x00") => 'image/x-icon',
            // "RIFF" <size> "WEBP"
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            self::looksLikeSvg($bytes) => 'image/svg+xml',
            default => null,
        };
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 1024)));

        return str_starts_with($head, '<') && str_contains($head, '<svg');
    }
}
