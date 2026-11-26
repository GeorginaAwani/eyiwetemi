<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Forgot password: a link that opens the site with the card asking for a new password. It works for an hour.
 */
class ResetPassword extends BaseResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Choose a new password')
            ->view(['mail.reset-password', 'mail.reset-password-text'], ['resetUrl' => $this->resetUrl($notifiable)]);
    }
}
