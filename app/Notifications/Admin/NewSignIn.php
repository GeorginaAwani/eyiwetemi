<?php

namespace App\Notifications\Admin;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A new device signed in to the admin: which device, and when (West Africa Time).
 */
class NewSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $device, public readonly CarbonInterface $at) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $at = $this->at->timezone(config('eyiwetemi.timezone'));

        return (new MailMessage)
            ->subject('New sign-in to the admin')
            ->view(['mail.admin.new-sign-in', 'mail.admin.new-sign-in-text'], [
                'device' => $this->device,
                'date' => $at->format('D j M'),
                'time' => $at->format('H:i'),
            ]);
    }
}
