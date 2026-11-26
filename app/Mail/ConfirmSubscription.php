<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one email a new address gets before anything else: a link to confirm it wants new work.
 */
class ConfirmSubscription extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $confirmUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.confirm-subscription', text: 'mail.confirm-subscription-text');
    }
}
