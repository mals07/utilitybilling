<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\WorkOrder;
use App\Support\PhotoResizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShrinkPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A detailed full-quality JPEG, as a phone camera saves it.
     */
    private function cameraPhoto(int $width, int $height): string
    {
        mt_srand(7);
        $noise = imagecreatetruecolor(200, 100);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 100; $y++) {
                imagesetpixel($noise, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        $image = imagecreatetruecolor($width, $height);
        imagecopyresampled($image, $noise, 0, 0, 0, 0, $width, $height, 200, 100);

        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    }

    private function storedPhoto(string $table, int $id): string
    {
        return DB::table($table)->where('id', $id)->value('photo_data');
    }

    public function test_stored_photos_are_shrunk_once_and_photos_already_small_are_left_alone(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->for($company)->create(['photo_data' => $this->cameraPhoto(1000, 500), 'photo_mime_type' => 'image/jpeg']);
        $alreadySmall = PhotoResizer::shrink($this->cameraPhoto(600, 300), PhotoResizer::CUSTOMER_MAX_SIDE)['data'];
        $smallCustomer = Customer::factory()->for($company)->create(['photo_data' => $alreadySmall, 'photo_mime_type' => 'image/jpeg']);
        Customer::factory()->for($company)->create();
        $photo = WorkOrder::factory()->for($company)->create()->photos()->create([
            'photo_data' => $this->cameraPhoto(1800, 900), 'mime_type' => 'image/jpeg', 'file_name' => 'meter.jpg',
        ]);
        $updatedAt = $customer->updated_at->toDateTimeString();
        $this->travel(1)->day();

        $this->artisan('photos:shrink')
            ->expectsOutputToContain('Shrank 1 work order photo(s) and 1 customer photo(s)')
            ->assertSuccessful();

        $this->assertSame([800, 400], array_slice(getimagesizefromstring($this->storedPhoto('customers', $customer->id)), 0, 2));
        $this->assertSame([1600, 800], array_slice(getimagesizefromstring($this->storedPhoto('work_order_photos', $photo->id)), 0, 2));
        $this->assertSame($alreadySmall, $this->storedPhoto('customers', $smallCustomer->id));
        $this->assertSame($updatedAt, $customer->fresh()->updated_at->toDateTimeString());

        // A second run finds nothing more worth saving.
        $this->artisan('photos:shrink')
            ->expectsOutputToContain('Shrank 0 work order photo(s) and 0 customer photo(s)')
            ->assertSuccessful();
    }
}
