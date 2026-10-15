<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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
