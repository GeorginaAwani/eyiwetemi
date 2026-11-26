<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a new address a reader wants on their account: a link that works for a day to confirm it.
 */
class ConfirmEmailChange extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.confirm-email-change', text: 'mail.confirm-email-change-text');
    }
}
