<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The writer's digest of readers' comments: grouped by place, each comment as "username: text", and a button to them.
 */
class CommentsDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{title: string, lines: list<string>}>  $places
     */
    public function __construct(
        public int $count,
        public array $places,
        public int $more,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->count === 1 ? '1 new comment' : $this->count.' new comments');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin.comments-digest', text: 'mail.admin.comments-digest-text');
    }
}
