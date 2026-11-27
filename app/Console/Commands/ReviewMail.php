<?php

namespace App\Console\Commands;

use App\Enums\CommentAudience;
use App\Enums\UserRole;
use App\Mail\ReviewerNotes;
use App\Models\Comment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CommentMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

#[Signature('eyiwetemi:review-mail')]
#[Description('Email the writer a reviewer\'s notes once the reviewer has stopped writing for a while')]
class ReviewMail extends Command
{
    /** A visit ends when a reviewer has written nothing for this long. */
    private const QUIET_MINUTES = 15;

    /**
     * Each reviewer's notes since their last mail go out as one mail, once the newest is old enough that the visit has
     * ended. A reviewer still writing is left for a later run.
     */
    public function handle(): int
    {
        if (! (bool) SiteSetting::get('mail.reviewers_now', true)) {
            return self::SUCCESS;
        }

        $now = now();
        $sent = SiteSetting::get('mail.reviewer_sent', []);

        foreach (User::query()->where('role', UserRole::Reviewer)->orderBy('id')->get() as $reviewer) {
            $since = isset($sent[$reviewer->id]) ? Carbon::parse($sent[$reviewer->id])->utc() : $now->copy()->subDay();

            $notes = Comment::query()
                ->where('audience', CommentAudience::Review)
                ->where('user_id', $reviewer->id)
                ->whereNull('seen_at')
                ->where('created_at', '>', $since)
                ->where('created_at', '<=', $now)
                ->with([
                    'work' => fn (Relation $query) => $query->select(['id', 'title', 'kind']),
                    'workItem' => fn (Relation $query) => $query->select(['id', 'work_id', 'type', 'position']),
                ])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($notes->isEmpty() || $notes->last()->created_at->gt($now->copy()->subMinutes(self::QUIET_MINUTES))) {
                continue;
            }

            $this->mailAuthors($reviewer, $notes);

            $sent[$reviewer->id] = $notes->last()->created_at->toIso8601String();
            SiteSetting::put('mail.reviewer_sent', $sent);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Comment>  $notes  Oldest first.
     */
    private function mailAuthors(User $reviewer, Collection $notes): void
    {
        $places = array_map(fn (array $place): array => [
            'title' => $place['title'],
            'notes' => $place['comments']->map(fn (Comment $note): array => CommentMail::note($note))->values()->all(),
        ], CommentMail::places($notes));

        $works = $notes->pluck('work_id')->unique()->count();

        User::query()
            ->where('role', UserRole::Author)
            ->get()
            ->each(fn (User $author) => Mail::to($author->email)->send(new ReviewerNotes(
                firstName: Str::before($reviewer->name, ' '),
                count: $notes->count(),
                workCount: $works,
                workTitle: $works === 1 ? $notes->first()->work->title : '',
                places: $places,
                url: CommentMail::url('/comments?tab=rev&who='.rawurlencode($reviewer->name)),
            )));
    }
}
