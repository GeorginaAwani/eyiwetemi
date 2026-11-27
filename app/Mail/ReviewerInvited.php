<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invite to read works before they are out: a link that works for fourteen days, and the author's note with it.
 */
class ReviewerInvited extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $firstName,
        public string $firstTitle,
        public string $worksList,
        public ?string $note,
        public string $startUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Read '.$this->firstTitle.' before everyone else');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reviewer-invited', text: 'mail.reviewer-invited-text');
    }
}
