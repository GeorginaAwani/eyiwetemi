<?php

namespace App\Support;

use App\Enums\Visibility;
use App\Enums\WorkItemType;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Note;
use App\Models\Series;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Normalizer;

/**
 * The header search: works and series by name or words, chapters and pieces by what their text says, and notes.
 * Each word must match and the last may be partial (typeahead). Only what a visitor can read is matched or shown:
 * listed works, released chapters outside a print-only book's free ones, and released notes.
 */
class SiteSearch
{
    /** Shortest search, in characters, that runs anything. */
    public const MIN_LENGTH = 2;

    /** Words searched; anything typed after them is ignored. */
    private const MAX_TOKENS = 6;

    private const WORKS = 5;

    private const LINES = 6;

    private const NOTES = 3;

    /** Chapters pulled for ranking, before print-only and numbering are checked. */
    private const LINE_CANDIDATES = 30;

    /** Words in a snippet, and how many of them come before the match. */
    private const SNIPPET_WORDS = 7;

    private const SNIPPET_LEAD = 3;

    public function __construct(private string $query) {}

    /**
     * @return array{q: string, works: list<array{title: string, small: string, href: string}>, lines: list<array{title: string, small: string, href: string}>, notes: list<array{title: string, small: string, href: string}>}
     */
    public function toArray(): array
    {
        $query = trim($this->query);
        $tokens = self::tokens($query);

        if (mb_strlen($query) < self::MIN_LENGTH || $tokens === []) {
            return ['q' => $query, 'works' => [], 'lines' => [], 'notes' => []];
        }

        $terms = implode(' & ', array_map(fn (string $token): string => $token.':*', $tokens));
        $phrase = mb_strtolower(implode(' ', $tokens));

        return [
            'q' => $query,
            'works' => $this->works($terms, $phrase),
            'lines' => $this->lines($terms, $tokens),
            'notes' => $this->notes($terms, $tokens),
        ];
    }

    /**
     * The words typed: runs of letters and digits, at most six.
     *
     * @return list<string>
     */
    private static function tokens(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($tokens, 0, self::MAX_TOKENS);
    }

    /**
     * Listed works and series (with a public book out, as the series page requires), best match first.
     *
     * @return list<array{title: string, small: string, href: string}>
     */
    private function works(string $terms, string $phrase): array
    {
        $works = $this->ranked(
            WorkPresenter::query()->whereRaw("works.search @@ to_tsquery('simple', public.f_unaccent(?))", [$terms]),
            'works',
            'works.title',
            'works.search',
            $terms,
            $phrase,
        )->limit(self::WORKS)->get();

        $series = $this->ranked(
            Series::query()
                ->whereRaw("to_tsvector('simple', public.f_unaccent(series.name)) @@ to_tsquery('simple', public.f_unaccent(?))", [$terms])
                ->whereHas('works', fn (Builder $query) => $query->where('visibility', Visibility::Public)->whereNotNull('first_published_at'))
                ->withCount(['works as listed_count' => fn (Builder $query) => $query->listed()]),
            'series',
            'series.name',
            "to_tsvector('simple', public.f_unaccent(series.name))",
            $terms,
            $phrase,
        )->limit(self::WORKS)->get();

        return $works
            ->map(fn (Work $work): array => [
                'title' => $work->title,
                'small' => self::workSmall($work),
                'href' => WorkPresenter::href($work),
                'starts' => (int) $work->starts_rank,
                'whole' => (int) $work->title_rank,
                'rank' => (float) $work->match_rank,
            ])
            ->concat($series->map(fn (Series $series): array => [
                'title' => $series->name,
                'small' => 'Series · '.$series->listed_count.' '.Str::plural('book', $series->listed_count),
                'href' => '/series/'.$series->slug,
                'starts' => (int) $series->starts_rank,
                'whole' => (int) $series->title_rank,
                'rank' => (float) $series->match_rank,
            ]))
            ->sortBy([['starts', 'asc'], ['whole', 'asc'], ['rank', 'desc']])
            ->take(self::WORKS)
            ->map(fn (array $row): array => ['title' => $row['title'], 'small' => $row['small'], 'href' => $row['href']])
            ->values()
            ->all();
    }

    /**
     * Ranks matches: the name starts with the words typed, then the name holds every word, then how well the text matches.
     *
     * @template TModel of Work|Series
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function ranked(Builder $query, string $table, string $name, string $vector, string $terms, string $phrase): Builder
    {
        return $query
            ->addSelect($table.'.*')
            ->selectRaw("CASE WHEN lower(public.f_unaccent({$name})) LIKE (public.f_unaccent(?) || '%') THEN 0 ELSE 1 END AS starts_rank", [$phrase])
            ->selectRaw("CASE WHEN to_tsvector('simple', public.f_unaccent({$name})) @@ to_tsquery('simple', public.f_unaccent(?)) THEN 0 ELSE 1 END AS title_rank", [$terms])
            ->selectRaw("ts_rank({$vector}, to_tsquery('simple', public.f_unaccent(?))) AS match_rank", [$terms])
            ->orderBy('starts_rank')
            ->orderBy('title_rank')
            ->orderByDesc('match_rank');
    }

    /**
     * "Novel · 7 chapters", "Collection · 12 poems", "Poem · 2 min"; "Coming soon" while nothing is out.
     */
    private static function workSmall(Work $work): string
    {
        $detail = match (true) {
            $work->status === WorkStatus::ComingSoon => 'Coming soon',
            in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true) => WorkPresenter::units($work, WorkPresenter::numbered($work)->count()),
            default => Reading::duration(WorkPresenter::minutes($work)),
        };

        return self::kindWord($work->kind).' · '.$detail;
    }

    /**
     * Released chapters and pieces whose own text reads the words, numbered as readers see them, outside a
     * print-only book's paid chapters. Collection rows that point at their own piece work are left out.
     *
     * @param  list<string>  $tokens
     * @return list<array{title: string, small: string, href: string}>
     */
    private function lines(string $terms, array $tokens): array
    {
        $candidates = WorkItem::query()
            ->select(['work_items.id', 'work_items.work_id', 'work_items.title', 'work_items.live_text'])
            ->selectRaw("ts_rank(work_items.search, to_tsquery('simple', public.f_unaccent(?))) AS match_rank", [$terms])
            ->released()
            ->whereIn('work_items.type', [WorkItemType::Chapter, WorkItemType::Piece])
            ->whereNull('work_items.piece_work_id')
            ->whereHas('work', fn (Builder $query) => $query->listed())
            ->whereRaw("work_items.search @@ to_tsquery('simple', public.f_unaccent(?))", [$terms])
            ->orderByDesc('match_rank')
            ->limit(self::LINE_CANDIDATES)
            ->get();

        /** @var array<int, array{work: Work, numbered: Collection<int, array{number: int, item: WorkItem}>}|null> $worksById */
        $worksById = [];
        $lines = [];

        foreach ($candidates as $item) {
            if (count($lines) === self::LINES) {
                break;
            }

            if (! array_key_exists($item->work_id, $worksById)) {
                $work = WorkPresenter::query()->find($item->work_id);
                $worksById[$item->work_id] = $work === null ? null : ['work' => $work, 'numbered' => WorkPresenter::numbered($work)];
            }

            $found = $worksById[$item->work_id];
            $row = $found === null ? null : $found['numbered']->first(fn (array $row): bool => $row['item']->id === $item->id);

            if ($found === null || $row === null || (new ReaderPage($found['work']))->isInPrintOnly($row['number'])) {
                continue;
            }

            /* matched on a chapter's title only: show that title, not the opening words */
            $snippet = self::snippet((string) $item->live_text, $tokens);
            $isLong = in_array($found['work']->kind, [WorkKind::Novel, WorkKind::Collection], true);
            if ($snippet === null && (! $isLong || ! $item->title)) {
                continue;
            }

            $lines[] = self::line($found['work'], $row['number'], $snippet ?? (string) $item->title);
        }

        return $lines;
    }

    /**
     * @return array{title: string, small: string, href: string}
     */
    private static function line(Work $work, int $number, string $snippet): array
    {
        if (in_array($work->kind, [WorkKind::Novel, WorkKind::Collection], true)) {
            return [
                'title' => ucfirst(WorkPresenter::unit($work)).' '.$number.' · '.$work->title,
                'small' => $snippet,
                'href' => WorkPresenter::itemHref($work, $number),
            ];
        }

        return [
            'title' => $work->title,
            'small' => self::kindWord($work->kind).' · '.$snippet,
            'href' => WorkPresenter::href($work),
        ];
    }

    /**
     * Notes readers can see (as on the Notes page), newest first.
     *
     * @param  list<string>  $tokens
     * @return list<array{title: string, small: string, href: string}>
     */
    private function notes(string $terms, array $tokens): array
    {
        return Note::query()
            ->select(['notes.id', 'notes.live_text', 'notes.published_at'])
            ->released()
            ->whereRaw("notes.search @@ to_tsquery('simple', public.f_unaccent(?))", [$terms])
            ->orderByDesc('notes.published_at')
            ->orderByDesc('notes.id')
            ->limit(self::NOTES)
            ->get()
            ->map(fn (Note $note): array => [
                'title' => 'Note · '.Reading::day($note->published_at),
                'small' => self::snippet((string) $note->live_text, $tokens) ?? '“'.Reading::firstSentence((string) $note->live_text).'”',
                'href' => '/notes#n-'.$note->id,
            ])
            ->values()
            ->all();
    }

    /**
     * A few words from the text as written, around the first word that starts with one of the words typed,
     * in curly quotes with ellipses. Words match ignoring case and accents, as the database search does.
     *
     * @param  list<string>  $tokens
     */
    private static function snippet(string $text, array $tokens): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $words = $matches[0];

        if ($words === []) {
            return null;
        }

        $foldedWords = array_map(fn (array $word): string => self::fold($word[0]), $words);
        $foldedTokens = array_map(fn (string $token): string => self::fold($token), $tokens);

        $match = null;
        foreach ($foldedWords as $index => $word) {
            if (array_any($foldedTokens, fn (string $token): bool => str_starts_with($word, $token))) {
                $match = $index;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $start = max(0, $match - self::SNIPPET_LEAD);
        $last = min(count($words), $start + self::SNIPPET_WORDS) - 1;
        $from = $words[$start][1];
        $to = $words[$last][1] + strlen($words[$last][0]);
        $fragment = trim((string) preg_replace('/\s+/u', ' ', substr($text, $from, $to - $from)));

        return '“…'.$fragment.'…”';
    }

    /**
     * Lower case with accents removed, for comparing words.
     */
    private static function fold(string $text): string
    {
        $lower = mb_strtolower($text);
        $decomposed = Normalizer::normalize($lower, Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $lower : $decomposed);
    }

    private static function kindWord(WorkKind $kind): string
    {
        return match ($kind) {
            WorkKind::Novel => 'Novel',
            WorkKind::Collection => 'Collection',
            WorkKind::Story => 'Short story',
            WorkKind::Poem => 'Poem',
            WorkKind::Essay => 'Essay',
        };
    }
}
