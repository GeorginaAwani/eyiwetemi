<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The writer's mail about a reviewer's notes from one visit: each note by its place, with the words it quotes, a
 * suggested edit and the note, and a button to the reviewers' tab for that reviewer.
 */
class ReviewerNotes extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{title: string, notes: list<array{quote: ?string, suggestion: ?string, text: ?string}>}>  $places
     */
    public function __construct(
        public string $firstName,
        public int $count,
        public int $workCount,
        public string $workTitle,
        public array $places,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        $notes = $this->count === 1 ? 'a note' : $this->count.' notes';
        $on = $this->workCount === 1 ? $this->workTitle : $this->workCount.' works';

        return new Envelope(subject: $this->firstName.' left '.$notes.' on '.$on);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin.reviewer-notes', text: 'mail.admin.reviewer-notes-text');
    }
}
