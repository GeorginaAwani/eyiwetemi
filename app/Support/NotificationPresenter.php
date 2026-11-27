<?php

namespace App\Support;

use App\Enums\WorkKind;
use App\Models\Comment;
use App\Models\Note;
use App\Models\Work;
use App\Models\WorkItem;

/**
 * How a notification reads, the same on the site and in its email:
 * "Chapter 8 of The Ember Gate is out" · "Low tide · 15 min", "A new poem: Tin Roof" · "Poetry · 2 min",
 * "A note on Salt Season" · the note's first sentence, "Tolu replied to your comment on Night bus" · the reply.
 *
 * Stored data is only what it's about: {kind: chapter, work_item_id} | {kind: work, work_id}
 * | {kind: note, note_id} | {kind: reply, comment_id} | {kind: site_change, page, line} (her Terms or Privacy page).
 * Wording is worked out when shown, so a renamed work reads right.
 */
class NotificationPresenter
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, small: string, href: string, thumb: array<string, mixed>}|null null when the thing is gone or hidden
     */
    public static function present(array $data): ?array
    {
        return match ($data['kind'] ?? null) {
            'chapter' => self::chapter((int) ($data['work_item_id'] ?? 0)),
            'work' => self::work((int) ($data['work_id'] ?? 0)),
            'note' => self::note((int) ($data['note_id'] ?? 0)),
            'reply' => self::reply((int) ($data['comment_id'] ?? 0)),
            'site_change' => self::siteChange((string) ($data['page'] ?? ''), (string) ($data['line'] ?? '')),
            default => null,
        };
    }

    /**
     * "My Terms have changed" or "My Privacy page has changed", with the line she wrote about it.
     *
     * @return array{title: string, small: string, href: string, thumb: array<string, mixed>}|null
     */
    private static function siteChange(string $page, string $line): ?array
    {
        return match ($page) {
            'terms' => ['title' => 'My Terms have changed', 'small' => $line, 'href' => '/terms', 'thumb' => ['type' => 'mark']],
            'privacy' => ['title' => 'My Privacy page has changed', 'small' => $line, 'href' => '/privacy', 'thumb' => ['type' => 'mark']],
            default => null,
        };
    }

    private static function chapter(int $id): ?array
    {
        $item = WorkItem::query()->released()->find($id, ['id', 'work_id', 'title', 'live_words']);
        $work = $item ? WorkPresenter::query()->find($item->work_id) : null;
        $row = $work ? WorkPresenter::numbered($work)->first(fn (array $row): bool => $row['item']->id === $item->id) : null;
        if (! $row) {
            return null;
        }
        $unit = ucfirst(WorkPresenter::unit($work));
        $minutes = Reading::minutes((int) $item->live_words).' min';

        return [
            'title' => $unit.' '.$row['number'].' of '.$work->title.' is out',
            'small' => $item->title ? $item->title.' · '.$minutes : $minutes,
            'href' => WorkPresenter::itemHref($work, $row['number']),
            'thumb' => self::picture($work),
        ];
    }

    private static function work(int $id): ?array
    {
        $work = WorkPresenter::query()->find($id);
        if (! $work) {
            return null;
        }
        $first = WorkPresenter::numbered($work)->first();
        $isLong = $work->kind === WorkKind::Novel || $work->kind === WorkKind::Collection;
        $small = match (true) {
            $isLong && $first !== null => ucfirst(WorkPresenter::unit($work)).' 1 · '.Reading::minutes((int) $first['item']->live_words).' min',
            $isLong => WorkPresenter::kindLabel($work),
            default => match ($work->kind) {
                WorkKind::Poem => 'Poetry',
                WorkKind::Essay => 'Essay',
                default => 'Short story',
            }.' · '.WorkPresenter::minutes($work).' min',
        };

        return [
            'title' => 'A new '.strtolower(str_replace(['A ', 'An '], '', WorkPresenter::kindLabel($work))).': '.$work->title,
            'small' => $small,
            'href' => WorkPresenter::href($work),
            'thumb' => self::picture($work),
        ];
    }

    private static function note(int $id): ?array
    {
        $note = Note::query()->released()->find($id, ['id', 'live_text', 'work_id']);
        if (! $note) {
            return null;
        }
        $work = $note->work_id ? WorkPresenter::query()->find($note->work_id) : null;

        return [
            'title' => $work ? 'A note on '.$work->title : 'A new note',
            'small' => '“'.Reading::firstSentence($note->live_text).'”',
            'href' => '/notes#n-'.$note->id,
            'thumb' => $work ? self::picture($work) : ['type' => 'note'],
        ];
    }

    private static function reply(int $id): ?array
    {
        $reply = Comment::query()->whereNull('hidden_at')->with('user:id,name,avatar_seed,role')->find($id, ['id', 'user_id', 'work_id', 'work_item_id', 'body']);
        $work = $reply ? WorkPresenter::query()->find($reply->work_id) : null;
        if (! $reply || ! $work || ! $reply->user) {
            return null;
        }
        $name = $reply->user->isAuthor() ? 'Georgina' : explode(' ', $reply->user->name)[0];
        $row = $reply->work_item_id ? WorkPresenter::numbered($work)->first(fn (array $row): bool => $row['item']->id === $reply->work_item_id) : null;
        $isLong = $work->kind === WorkKind::Novel || $work->kind === WorkKind::Collection;

        return [
            'title' => $name.' replied to your comment on '.$work->title,
            'small' => '“'.trim((string) $reply->body).'”',
            'href' => ($row && $isLong ? WorkPresenter::itemHref($work, $row['number']) : WorkPresenter::href($work)).'#comments',
            'thumb' => $reply->user->isAuthor()
                ? ['type' => 'photo', 'src' => ReaderPage::authorPhoto()]
                : ['type' => 'avatar', 'seed' => $reply->user->avatar_seed],
        ];
    }

    /**
     * A work's picture; poems without a cover get the plain poem card.
     *
     * @return array<string, mixed>
     */
    private static function picture(Work $work): array
    {
        $cover = WorkPresenter::cover($work);

        return $work->kind === WorkKind::Poem && $cover['src'] === null
            ? ['type' => 'poem', 'title' => $work->title]
            : ['type' => 'cover', 'title' => $work->title, ...$cover];
    }
}
