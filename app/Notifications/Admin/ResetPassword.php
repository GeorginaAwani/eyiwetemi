<?php

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin forgot password: a link to the page that asks for a new password. It works for an hour.
 */
class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token, public readonly string $email) {}

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
            ->subject('Choose a new password')
            ->view(['mail.admin.reset-password', 'mail.admin.reset-password-text'], [
                'url' => route('admin.password.open', ['token' => $this->token, 'email' => $this->email]),
            ]);
    }
}
