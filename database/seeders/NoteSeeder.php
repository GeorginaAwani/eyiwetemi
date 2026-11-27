<?php

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\Media;
use App\Models\Note;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class NoteSeeder extends Seeder
{
    /** Diary notes that carry their headline as the first sentence (the prototype's decision, 25 Nov). */
    private const HEADLINE_NOTES = [
        'Salt Season is in print in the UK',
        'One chapter left',
        'Rain Stories is a book',
        'Harbour Songs has a name',
        'A new novel, slowly',
    ];

    /** The prototype's "About a work" tags, as work slugs. */
    private const WORK_TAGS = [
        'Salt Season' => 'salt-season',
        'The Ember Gate' => 'the-ember-gate',
        'Paper Boats' => 'paper-boats',
        'Starlings Go South In September' => 'starlings-go-south-in-september',
        'Rain Stories' => 'rain-stories',
        'Harmattan' => 'harmattan',
        'Midnight, again' => 'midnight-poems',
        'Harbour Songs' => 'harbour-songs',
    ];

    /** Hearts a note can carry from readers; each one needs an account, so the count is capped. */
    private const MAX_LIKES = 20;

    /** Hearts that came in since she last looked, by the note's day (the admin prototype's "+3", "+5", "+2"). */
    private const NEW_HEARTS = ['21 Nov' => 3, '20 Nov' => 5, '17 Nov' => 2];

    public function run(): void
    {
        $readers = User::query()->where('role', UserRole::Reader)->orderBy('id')->get();
        $works = Work::query()->get()->keyBy('slug');
        $harmattanPhoto = Media::query()->where('path', 'seed/covers/harmattan.webp')->firstOrFail();

        foreach (SeedData::N as $index => [$day, , $headline, $paragraphs, $workName, $likes, , $photo]) {
            $paragraphs = $this->withHeadline($headline, $paragraphs);
            $document = ProseBody::document("note-{$index}", array_map(fn (string $text): array => ['p', $text], $paragraphs));
            $text = ProseBody::text($document);
            $workId = $workName !== null ? $works[self::WORK_TAGS[$workName]]->id : null;
            $published = Carbon::createFromFormat('j M Y H:i', "{$day} 2026 09:00", 'Africa/Lagos')->utc();

            $note = Note::forceCreate([
                'status' => PublishStatus::Published,
                'draft_body' => $document,
                'live_body' => $document,
                'draft_text' => $text,
                'live_text' => $text,
                'media_id' => $photo !== null ? $harmattanPhoto->id : null,
                'draft_media_id' => $photo !== null ? $harmattanPhoto->id : null,
                'work_id' => $workId,
                'draft_work_id' => $workId,
                'notify_followers' => true,
                'scheduled_at' => null,
                'published_at' => $published,
                'live_updated_at' => $published,
                'likes_count' => 0,
            ]);

            $this->likes($note, min($likes, self::MAX_LIKES), $readers, self::NEW_HEARTS[$day] ?? 0);
        }

        $this->scheduledAndDraft($works);
    }

    /**
     * The scheduled note for Chapter 8 and the draft about Starlings, from the admin's diary.
     *
     * @param  Collection<string, Work>  $works
     */
    private function scheduledAndDraft($works): void
    {
        $scheduled = [
            'text' => 'Chapter 8 is ready. It’s up on Friday morning. The ending changed twice more after I told you it would be late. I think you’ll forgive me.',
            'work' => 'the-ember-gate',
            'at' => Carbon::createFromFormat('Y-m-d H:i', '2026-11-28 09:00', 'Africa/Lagos')->utc(),
        ];

        $draft = [
            'text' => 'I started chapter 2 three times this week. Each time the starlings left too early.',
            'work' => 'starlings-go-south-in-september',
        ];

        $this->pendingNote($scheduled, PublishStatus::Scheduled, $works, 'note-scheduled');
        $this->pendingNote($draft, PublishStatus::Draft, $works, 'note-draft');
    }

    /**
     * @param  array{text: string, work: string, at?: Carbon}  $spec
     * @param  Collection<string, Work>  $works
     */
    private function pendingNote(array $spec, PublishStatus $status, $works, string $key): void
    {
        $document = ProseBody::document($key, [['p', $spec['text']]]);
        $workId = $works[$spec['work']]->id;

        Note::forceCreate([
            'status' => $status,
            'draft_body' => $document,
            'live_body' => null,
            'draft_text' => ProseBody::text($document),
            'live_text' => null,
            'media_id' => null,
            'draft_media_id' => null,
            'work_id' => null,
            'draft_work_id' => $workId,
            'notify_followers' => true,
            'scheduled_at' => $spec['at'] ?? null,
            'published_at' => null,
            'live_updated_at' => null,
            'likes_count' => 0,
        ]);
    }

    /**
     * A headline the prototype kept becomes the note's first sentence.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private function withHeadline(?string $headline, array $paragraphs): array
    {
        if ($headline !== null && in_array($headline, self::HEADLINE_NOTES, true)) {
            $paragraphs[0] = $headline.'. '.$paragraphs[0];
        }

        return $paragraphs;
    }

    /**
     * @param  Collection<int, User>  $readers
     * @param  int  $new  how many of them came in since she last looked
     */
    private function likes(Note $note, int $count, $readers, int $new): void
    {
        $likers = $readers->take($count)->values();

        /* hearts come in over the hours after the note; the newest few came in just now */
        foreach ($likers as $i => $liker) {
            $at = $i >= $count - $new
                ? now()->subMinutes($count - $i)
                : $note->published_at->copy()->addMinutes(20 * ($i + 1));

            $note->likes()->forceCreate(['user_id' => $liker->id, 'created_at' => $at, 'updated_at' => $at]);
        }

        $note->forceFill(['likes_count' => $likers->count()])->save();
    }
}
