<?php

namespace App\Support;

use App\Models\EmailOutbox;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the app's plain-text emails.
 *
 * EMAIL_DELIVERY=console (default) writes emails to the log and stores them in the email_outbox
 * table instead of sending them, for networks where SMTP is blocked. The pages then show the
 * emailed link as a development shortcut. EMAIL_DELIVERY=smtp sends through the configured mailer.
 */
final class EmailDelivery
{
    public static function isConsole(): bool
    {
        return config('mail.delivery') !== 'smtp';
    }

    public static function send(string $to, string $subject, string $text, ?string $actionUrl = null): void
    {
        if (! self::isConsole()) {
            Mail::raw($text, fn (Message $message) => $message->to($to)->subject($subject));

            return;
        }

        EmailOutbox::query()->create([
            'to' => $to,
            'subject' => $subject,
            'body' => $text,
            'action_url' => $actionUrl,
        ]);

        Log::info(implode("\n", [
            'Email (EMAIL_DELIVERY=console, not sent)',
            "To:      {$to}",
            "Subject: {$subject}",
            '',
            $text,
        ]));
    }

    /**
     * Latest captured email link for an address. Always null unless in console mode.
     */
    public static function latestLink(string $to, string $subject): ?string
    {
        if (! self::isConsole()) {
            return null;
        }

        return EmailOutbox::query()
            ->where('to', $to)
            ->where('subject', $subject)
            ->whereNotNull('action_url')
            ->latest('id')
            ->value('action_url');
    }
}
