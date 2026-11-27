<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The newsletter itself: what is new since the last send, for one reader's kinds (see NewsletterEmail::send).
 */
class Newsletter extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $email  The view data from NewsletterEmail::send.
     */
    public function __construct(public array $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New from Eyiwetemi');
    }

    /**
     * The reader's manage page is the unsubscribe link their mail client offers.
     */
    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->email['manage'].'>']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.newsletter', text: 'mail.newsletter-text');
    }
}
