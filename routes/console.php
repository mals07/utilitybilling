<?php

use App\Enums\OverdueHandling;
use App\Models\Company;
use App\Support\Billing\Surcharges;
use App\Support\Disconnections;
use App\Support\Format;
use App\Support\PhotoResizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mail:test {email : The address to send the test email to}', function (string $email) {
    $this->line('Mailer: '.config('mail.default').' via '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));

    try {
        Mail::raw('This is a test email from '.config('app.name').'. Your mail settings are working.', function ($message) use ($email) {
            $message->to($email)->subject(config('app.name').' test email');
        });
    } catch (TransportExceptionInterface|MimeException $exception) {
        $this->error('Sending failed: '.$exception->getMessage());

        return 1;
    }

    $this->info('Test email sent to '.$email.'.');
})->purpose('Send a test email to check the mail configuration');

Artisan::command('invoices:surcharge-overdue', function () {
    $total = 0;
    foreach (Company::query()->where('overdue_handling', OverdueHandling::SameInvoice)->get() as $company) {
        $total += Surcharges::applyToOverdueInvoices($company, Format::todayIn($company->timezone));
    }
    $this->info("Surcharged {$total} overdue invoice(s).");
})->purpose('Add after-due charges to overdue invoices, for companies that surcharge the invoice itself');

Schedule::command('invoices:surcharge-overdue')->dailyAt('00:30');

Artisan::command('disconnections:review', function () {
    $total = 0;
    foreach (Company::query()->get() as $company) {
        $total += Disconnections::reviewAll($company);
    }
    $this->info("Closed {$total} disconnection notice(s) that no longer apply.");
})->purpose('Settle or cancel notices of disconnection whose customers have paid or are no longer connected');

Schedule::command('disconnections:review')->dailyAt('00:45');

Artisan::command('photos:shrink', function () {
    // Photos are read ten at a time, since each may be a few megabytes. A photo is saved again only when
    // it comes out at least 10% smaller, so running the command twice doesn't wear down photos already shrunk.
    $shrink = function (string $table, string $mimeColumn, int $maxSide): array {
        $shrunk = 0;
        $savedBytes = 0;

        DB::table($table)->whereNotNull('photo_data')->select(['id', 'photo_data', $mimeColumn])
            ->chunkById(10, function ($rows) use ($table, $mimeColumn, $maxSide, &$shrunk, &$savedBytes): void {
                foreach ($rows as $row) {
                    $photo = PhotoResizer::shrink($row->photo_data, $maxSide);
                    if ($photo === null || strlen($photo['data']) > strlen($row->photo_data) * 0.9) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update(['photo_data' => $photo['data'], $mimeColumn => $photo['mime']]);
                    $shrunk++;
                    $savedBytes += strlen($row->photo_data) - strlen($photo['data']);
                }
            });

        return [$shrunk, $savedBytes];
    };

    [$orderPhotos, $orderBytes] = $shrink('work_order_photos', 'mime_type', PhotoResizer::WORK_ORDER_MAX_SIDE);
    [$customerPhotos, $customerBytes] = $shrink('customers', 'photo_mime_type', PhotoResizer::CUSTOMER_MAX_SIDE);

    $this->info("Shrank {$orderPhotos} work order photo(s) and {$customerPhotos} customer photo(s), saving "
        .number_format(($orderBytes + $customerBytes) / (1024 * 1024), 1).'MB.');
})->purpose('Shrink stored work order and customer photos, like new uploads are');
