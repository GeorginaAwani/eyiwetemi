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

    /**
     * The reader's manage page is the unsubscribe link their mail client offers.
     */
    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->letter['manage'].'>']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.newsletter-welcome', text: 'mail.newsletter-welcome-text');
    }
}
