<?php

namespace App\Notifications\Admin;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Five wrong tries: admin sign-in is paused, and this says until when (West Africa Time).
 */
class SignInPaused extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CarbonInterface $until) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Admin sign-in paused')
            ->view(['mail.admin.sign-in-paused', 'mail.admin.sign-in-paused-text'], [
                'pausedUntil' => $this->until->timezone(config('eyiwetemi.timezone'))->format('H:i'),
            ]);
    }
}
