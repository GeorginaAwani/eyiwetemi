<?php

namespace App\Support;

use App\Enums\CommentAudience;
use App\Enums\HeadingStyle;
use App\Enums\PublishStatus;
use App\Enums\SceneBreak;
use App\Enums\SitePageKey;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Comment;
use App\Models\Media;
use App\Models\SitePage;
use App\Models\Work;
use App\Models\WorkItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One reading page (R2 Book page): a chapter of a novel, a piece of a collection, or a single story,
 * poem or essay. Live text only; reader comments (public audience) under it.
 */
class ReaderPage
{
    /** Comments shown before "Show N more comments". */
    public const COMMENTS_SHOWN = 3;

    /** Replies shown before "Show N more replies". */
    public const REPLIES_SHOWN = 4;

    private const ROMAN = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];

    private const WORDS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];

    /**
     * @param  Work  $work  loaded with WorkPresenter::with()
     * @param  int|null  $number  the chapter or piece; null for a single story, poem or essay
     */
    public function __construct(private Work $work, private ?int $number = null) {}

    /**
     * The chapter's place in the work: its row in the contents, or null when it doesn't exist.
     *
     * @return array{number: int, item: WorkItem}|null
     */
    public function row(): ?array
    {
        $index = ($this->number ?? 1) - 1;
        $item = $this->work->items->values()[$index] ?? null;

        return $item ? ['number' => $index + 1, 'item' => $item] : null;
    }

    public function isOut(WorkItem $item): bool
    {
        return $item->status === PublishStatus::Published && $item->published_at !== null && $item->published_at->lte(now());
    }

    /**
     * Whether this chapter is past the free ones of a book that is only in print.
     */
    public function isInPrintOnly(int $number): bool
    {
        return $this->work->free_chapters !== null && $number > $this->work->free_chapters;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $work = $this->work;
        ['number' => $number, 'item' => $listed] = $this->row();
        $item = WorkItem::query()->findOrFail($listed->id);
        $isPiece = ! in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true);
        $unit = WorkPresenter::unit($work);
        $items = collect(WorkPresenter::allItems($work));
        $total = $items->count();

        /* a poem in a collection reads from its own single work */
        $source = $item->piece_work_id !== null
            ? WorkItem::query()->where('work_id', $item->piece_work_id)->orderBy('position')->first()
            : $item;
        $body = $source?->live_body;
        $minutes = Reading::minutes((int) ($source->live_words ?? $item->live_words));

        return [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'href' => $isPiece ? null : WorkPresenter::href($work),
                'kind' => $work->kind->value,
                'unitPlural' => ucfirst($unit === 'story' ? 'stories' : $unit.'s'),
                'cover' => $isPiece && $work->cover ? WorkPresenter::mediaUrl($work->cover->disk, $work->cover->path) : null,
                'kindLabel' => WorkPresenter::kindLabel($work),
                'thumb' => [
                    'src' => $work->cover ? WorkPresenter::mediaUrl($work->cover->disk, $work->cover->path) : null,
                    'color' => $work->cover_color,
                ],
            ],
            'item' => [
                'id' => $item->id,
                'number' => $number,
                'total' => $total,
                'isPiece' => $isPiece,
                'label' => $this->label($number, $unit),
                'title' => $isPiece ? $work->title : $item->title,
                'meta' => $isPiece ? $minutes.' min · '.Reading::day($item->published_at) : $minutes.' min',
                'minutes' => $minutes,
                'epigraph' => $item->epigraph ? ['q' => $item->epigraph, 'by' => $item->epigraph_by] : null,
                'soundtrack' => $item->soundtrack_url ? ['url' => $item->soundtrack_url, 'label' => $item->soundtrack_label] : null,
                'likes' => (int) $item->likes_count,
                'blocks' => $this->blocks($body),
                'poem' => $work->kind === WorkKind::Poem || ($work->kind === WorkKind::Collection && $work->collection_kind?->value === 'poem'),
            ],
            'sceneBreak' => match ($work->scene_break) {
                SceneBreak::Asterisks => 'ast',
                SceneBreak::Asterism => 'asterism',
                SceneBreak::ShortLine => 'rule',
                SceneBreak::Space => 'space',
                default => null,
            },
            'nav' => $isPiece ? null : $this->nav($number, $items),
            'chapters' => $isPiece ? [] : $items->reject(fn (array $c): bool => $c['unavailable'])
                ->reject(fn (array $c): bool => $this->isInPrintOnly($c['number']))->values()->all(),
            'comments' => $this->comments($item),
        ];
    }

    /**
     * "Chapter 3" in the work's chosen style; collections say "Story 3", "Poem 6".
     */
    private function label(int $number, string $unit): string
    {
        if ($this->work->kind !== WorkKind::Novel) {
            return ucfirst($unit).' '.$number;
        }

        $word = self::WORDS[$number] ?? (string) $number;

        return match ($this->work->heading_style) {
            HeadingStyle::ChapterWord => 'Chapter '.ucfirst($word),
            HeadingStyle::ChapterWordLower => 'chapter '.$word,
            HeadingStyle::ChapterRoman => 'Chapter '.$this->roman($number),
            HeadingStyle::Word => ucfirst($word),
            HeadingStyle::Number => (string) $number,
            default => 'Chapter '.$number,
        };
    }

    private function roman(int $n): string
    {
        $out = '';
        foreach (self::ROMAN as $value => $letters) {
            while ($n >= $value) {
                $out .= $letters;
                $n -= $value;
            }
        }

        return $out;
    }

    /**
     * Previous and next, skipping chapters that aren't out; what to say after the last one.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{prev: string|null, next: string|null, end: string|null, follow: bool}
     */
    private function nav(int $number, Collection $items): array
    {
        $readable = $items->reject(fn (array $c): bool => $c['unavailable'] || $this->isInPrintOnly($c['number']));
        $prev = $readable->filter(fn (array $c): bool => $c['number'] < $number)->last();
        $next = $readable->first(fn (array $c): bool => $c['number'] > $number);
        $end = null;

        if ($next === null) {
            $end = match (true) {
                $this->work->free_chapters !== null => 'The rest is in print',
                $this->work->status === WorkStatus::Finished => 'The end',
                default => ($this->work->kind === WorkKind::Novel ? 'Next chapter' : 'Next '.WorkPresenter::unit($this->work)).' soon',
            };
        }

        return [
            'prev' => $prev['href'] ?? null,
            'next' => $next['href'] ?? null,
            'end' => $end,
            'follow' => $next === null && $this->work->status === WorkStatus::InProgress,
        ];
    }

    /**
     * The editor document as plain blocks the page can draw.
     *
     * @param  array<string, mixed>|null  $doc
     * @return list<array<string, mixed>>
     */
    private function blocks(?array $doc): array
    {
        $blocks = [];
        $stanza = [];

        $flush = function () use (&$blocks, &$stanza): void {
            if ($stanza !== []) {
                $blocks[] = ['type' => 'stanza', 'lines' => $stanza];
                $stanza = [];
            }
        };

        foreach ($doc['content'] ?? [] as $node) {
            $type = $node['type'] ?? '';

            if ($type === 'poemLine') {
                $text = Reading::text($node);
                if (trim($text) === '') {
                    $flush();
                } else {
                    $stanza[] = ['text' => $text, 'indent' => (int) ($node['attrs']['indent'] ?? 0)];
                }

                continue;
            }

            $flush();

            $blocks[] = match ($type) {
                'paragraph' => ['type' => 'p', 'id' => $node['attrs']['id'] ?? null, 'runs' => Doc::runs($node)],
                'heading' => ['type' => 'h', 'runs' => Doc::runs($node)],
                'blockquote' => ['type' => 'quote', 'runs' => Doc::runs($node['content'][0] ?? [])],
                'sceneBreak' => ['type' => 'break'],
                'bulletList', 'orderedList' => [
                    'type' => $type === 'bulletList' ? 'ul' : 'ol',
                    'items' => array_map(fn (array $li): array => Doc::runs($li['content'][0] ?? $li), $node['content'] ?? []),
                ],
                'image' => [
                    'type' => 'img',
                    'src' => $this->imageSrc($node['attrs'] ?? []),
                    'alt' => $node['attrs']['alt'] ?? '',
                    'caption' => $node['attrs']['caption'] ?? null,
                    'credit' => $node['attrs']['credit'] ?? null,
                ],
                default => ['type' => 'p', 'id' => null, 'runs' => Doc::runs($node)],
            };
        }

        $flush();

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function imageSrc(array $attrs): ?string
    {
        if (isset($attrs['mediaId']) && ($media = Media::query()->find($attrs['mediaId']))) {
            return WorkPresenter::mediaUrl($media->disk, $media->path);
        }

        return $attrs['src'] ?? null;
    }

    /**
     * Reader comments, oldest first; replies sit one level under their comment. A deleted comment keeps its
     * place as "Comment deleted" while its replies stay. Her comments carry her photo and "Author".
     *
     * @return array{count: int, list: list<array<string, mixed>>, shown: int, repliesShown: int}
     */
    private function comments(WorkItem $item): array
    {
        $all = Comment::withTrashed()
            ->with(['user:id,name,username,avatar_seed,role', 'replyToUser:id,name,username,role'])
            ->where('work_item_id', $item->id)
            ->where('audience', CommentAudience::Public)
            ->whereNull('hidden_at')
            ->oldest('id')
            ->get();

        $authorPhoto = self::authorPhoto();
        $replies = $all->whereNotNull('parent_id')->whereNull('deleted_at')->groupBy('parent_id');
        $list = [];

        foreach ($all->whereNull('parent_id') as $comment) {
            $kids = $replies->get($comment->id, collect());

            if ($comment->trashed() && $kids->isEmpty()) {
                continue;
            }

            $list[] = [
                ...$this->comment($comment, $authorPhoto),
                'deleted' => $comment->trashed(),
                'replies' => $kids->map(fn (Comment $reply): array => $this->comment($reply, $authorPhoto))->values()->all(),
            ];
        }

        return [
            'count' => $all->whereNull('deleted_at')->count(),
            'list' => $list,
            'shown' => self::COMMENTS_SHOWN,
            'repliesShown' => self::REPLIES_SHOWN,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comment(Comment $comment, ?string $authorPhoto): array
    {
        $user = $comment->user;
        $isAuthor = $user?->isAuthor() ?? false;

        return [
            'id' => $comment->id,
            'name' => $isAuthor ? 'Georgina' : ($user->name ?? ''),
            'username' => $user->username ?? null,
            'seed' => $user->avatar_seed ?? ($user->name ?? 'reader'),
            'photo' => $isAuthor ? $authorPhoto : null,
            'author' => $isAuthor,
            'replyTo' => $comment->replyToUser ? ($comment->replyToUser->isAuthor() ? 'Georgina' : $comment->replyToUser->name) : null,
            'body' => $comment->trashed() ? null : $comment->body,
            'when' => $this->ago($comment->created_at),
            'likes' => (int) $comment->likes_count,
        ];
    }

    /**
     * Her photo for her own comments: the About portrait.
     */
    public static function authorPhoto(): ?string
    {
        $id = SitePage::query()->firstWhere('key', SitePageKey::About)?->live['image'] ?? null;
        $media = $id ? Media::query()->find($id) : null;

        return $media ? WorkPresenter::mediaUrl($media->disk, $media->path) : null;
    }

    /**
     * "now", "12 min", "5 h", "2 d", then the day.
     */
    private function ago(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'now',
            $minutes < 60 => $minutes.' min',
            $minutes < 60 * 24 => intdiv($minutes, 60).' h',
            $minutes < 60 * 24 * 7 => intdiv($minutes, 60 * 24).' d',
            default => (string) Reading::day($at),
        };
    }
}
