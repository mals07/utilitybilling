<?php

use App\Enums\OverdueHandling;
use App\Models\Company;
use App\Support\Billing\Surcharges;
use App\Support\Disconnections;
use App\Support\Format;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
