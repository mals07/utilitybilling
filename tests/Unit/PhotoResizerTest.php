<?php

namespace Tests\Unit;

use App\Support\PhotoResizer;
use PHPUnit\Framework\TestCase;

class PhotoResizerTest extends TestCase
{
    /**
     * A photo whose left half is red and right half is blue.
     */
    private function photo(int $width, int $height, string $type = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));

        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /**
     * Adds the EXIF orientation tag a phone camera writes, right after the JPEG's start marker.
     */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A".pack('N', 8)
            .pack('n', 1)
            .pack('nnNnn', 0x0112, 3, 1, $orientation, 0)
            .pack('N', 0);
        $segment = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);

        return [$size[0], $size[1]];
    }

    public function test_a_large_photo_is_scaled_to_its_longest_side_and_saved_as_a_jpeg(): void
    {
        $photo = PhotoResizer::shrink($this->photo(1000, 500), 800);

        $this->assertSame('image/jpeg', $photo['mime']);
        $this->assertSame([800, 400], $this->dimensions($photo['data']));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($photo['data'])[2]);
    }

    public function test_a_small_photo_is_not_enlarged(): void
    {
        $this->assertSame([300, 200], $this->dimensions(PhotoResizer::shrink($this->photo(300, 200), 800)['data']));
    }

    public function test_a_sideways_camera_photo_is_turned_upright_and_its_metadata_dropped(): void
    {
        // Orientation 6: the camera was turned, so the stored picture must be turned clockwise to show.
        $photo = PhotoResizer::shrink($this->withOrientation($this->photo(40, 20, 'jpeg'), 6), 800);

        $this->assertSame([20, 40], $this->dimensions($photo['data']));
        $image = imagecreatefromstring($photo['data']);
        $this->assertGreaterThan(200, (imagecolorat($image, 10, 5) >> 16) & 0xFF, 'The red left half is now on top.');
        $this->assertGreaterThan(200, imagecolorat($image, 10, 34) & 0xFF, 'The blue right half is now at the bottom.');
        $this->assertStringNotContainsString("Exif\x00\x00", $photo['data']);
    }

    public function test_a_file_that_is_not_an_image_is_left_alone(): void
    {
        $this->assertNull(PhotoResizer::shrink('not an image', 800));
    }
}
