<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The welcome a new reader gets once they confirm: her letter, and the works to start with (see NewsletterEmail::welcome).
 */
class NewsletterWelcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $letter  The view data from NewsletterEmail::welcome.
     */
    public function __construct(public array $letter) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.newsletter-welcome', text: 'mail.newsletter-welcome-text');
    }
}
