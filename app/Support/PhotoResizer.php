<?php

namespace App\Support;

use GdImage;

/**
 * Shrinks uploaded photos before they are stored in the database: scaled down to a longest side,
 * turned upright from the camera's orientation tag, and saved again as a JPEG. Saving again also
 * drops the camera's metadata, such as where the photo was taken.
 */
final class PhotoResizer
{
    /**
     * Longest side of a work order photo; enough to read a meter's dials.
     */
    public const WORK_ORDER_MAX_SIDE = 1600;

    public const CUSTOMER_MAX_SIDE = 800;

    /**
     * Larger images are left alone rather than risk running out of memory decoding them: GD holds
     * 4 bytes a pixel, so 16 megapixels (any phone's normal photo size) needs about 64MB.
     */
    private const MAX_PIXELS = 16_000_000;

    private const JPEG_QUALITY = 80;

    /**
     * The photo as a JPEG no larger than $maxSide on its longest side, or null when it can't be read.
     *
     * @return array{data: string, mime: string}|null
     */
    public static function shrink(string $bytes, int $maxSide): ?array
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        // Scaled before it is turned, so a large photo is only ever held in memory once at full size.
        $image = self::scaled($source, $maxSide);
        unset($source);

        if ($size[2] === IMAGETYPE_JPEG) {
            $image = self::upright($image, self::orientation($bytes));
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $data = (string) ob_get_clean();

        return ['data' => $data, 'mime' => 'image/jpeg'];
    }

    /**
     * A copy no larger than $maxSide on its longest side, on white so transparent parts don't turn black.
     */
    private static function scaled(GdImage $source, int $maxSide): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $image = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $image;
    }

    /**
     * The EXIF orientation of a JPEG (1 = upright), from how the camera was held.
     */
    private static function orientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /**
     * Turns and mirrors the image so it displays the way the camera saw it.
     */
    private static function upright(GdImage $image, int $orientation): GdImage
    {
        // imagerotate() turns counterclockwise.
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
        }

        match ($orientation) {
            2, 5, 7 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4 => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $image;
    }
}
