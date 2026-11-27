<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Mail\CommentsDigest as CommentsDigestMail;
use App\Models\Comment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CommentMail;
use App\Support\DeskPage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

#[Signature('eyiwetemi:comments-digest')]
#[Description('Email the writer the readers\' comments that came in since the last digest')]
class CommentsDigest extends Command
{
    /** Most comments one digest lists; the rest are only counted. */
    private const LISTED = 30;

    /**
     * Covers the comments since the last digest (the first one covers the last day). The clock moves on even when
     * nothing came in, so the next digest starts from now.
     */
    public function handle(): int
    {
        if (! (bool) SiteSetting::get('mail.readers_daily', true)) {
            return self::SUCCESS;
        }

        $now = now();
        $since = SiteSetting::get('mail.readers_digest_at');
        $from = $since !== null ? Carbon::parse($since)->utc() : $now->copy()->subDay();

        $comments = DeskPage::readerComments()
            ->with([
                'user' => fn (Relation $query) => $query->select(['id', 'name', 'username']),
                'work' => fn (Relation $query) => $query->select(['id', 'title', 'kind']),
                'workItem' => fn (Relation $query) => $query->select(['id', 'work_id', 'type', 'position']),
            ])
            ->whereNull('seen_at')
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $now)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($comments->isNotEmpty()) {
            $this->mailAuthors($comments);
        }

        SiteSetting::put('mail.readers_digest_at', $now->toIso8601String());

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Comment>  $comments  Newest first.
     */
    private function mailAuthors(Collection $comments): void
    {
        $listed = $comments->take(self::LISTED);
        $places = array_map(fn (array $place): array => [
            'title' => $place['title'],
            'lines' => $place['comments']->map(fn (Comment $comment): string => $this->digestLine($comment))->values()->all(),
        ], CommentMail::places($listed));

        User::query()
            ->where('role', UserRole::Author)
            ->get()
            ->each(fn (User $author) => Mail::to($author->email)->send(new CommentsDigestMail(
                count: $comments->count(),
                places: $places,
                more: $comments->count() - $listed->count(),
                url: CommentMail::url('/comments'),
            )));
    }

    /** "username: text", the text squished onto one line and cut. */
    private function digestLine(Comment $comment): string
    {
        return CommentMail::who($comment->user).': '.Str::limit(Str::squish((string) $comment->body), 200);
    }
}
