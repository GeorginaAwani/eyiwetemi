<?php

namespace Database\Seeders;

use App\Enums\CollectionKind;
use App\Enums\HeadingStyle;
use App\Enums\PublishStatus;
use App\Enums\SceneBreak;
use App\Enums\Visibility;
use App\Enums\WorkKind;
use App\Enums\WorkStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Series;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class WorkSeeder extends Seeder
{
    private const GENRES = [
        'Literary fiction', 'Historical fiction', 'Romance', 'Fantasy', 'Coming of age',
        'Mystery', 'Thriller', 'Science fiction', 'Horror',
    ];

    /** Stand-in poems for pieces the prototype only shows by title. */
    private const PLACEHOLDER_POEMS = [
        ['the rain keeps its own hours', '  and so do I,', 'a lamp left on the stair', '', 'the kettle, the window,', '  the bus that is always late.'],
        ['Nobody told me the house', '  would keep the sound of you,', 'the wardrobe, the radio,', '  the step that still sings.'],
        ['Morning comes in slowly,', '  one window at a time.', '', 'I count what I kept', '  and find that I kept you.'],
    ];

    private const RAIN_STORY_TITLES = [
        'The first rain', 'Umbrella', 'Flood year', 'The roof', 'Puddles', 'Wet season', 'The leak', 'After',
    ];

    private const MIDNIGHT_TITLES = [
        'Small hours', 'Fan', 'Hallway light', 'Insomnia, again', 'Lagos at 2 am', 'The generator sleeps',
        'Moths', 'Unsent', 'Blue', 'Night bus', 'Dawn chorus', 'Morning, finally',
    ];

    private const SALT_SEASON_TITLES = [
        'Brine', 'The salt flats', 'Mother', 'Tide tables', 'The market', 'A white wedding',
        'Drought', 'The well', 'Letters home', 'Harmattan', 'Crystal', 'Salt season',
    ];

    private const HARBOUR_SONG_TITLES = ['The harbour wakes', 'Salt and diesel', 'Songs for nobody', 'The last ferry'];

    private const V31_OLD = [
        'They carried the boat between them, Dayo at the front and Ifeoma at the back, and neither of them spoke.',
        'At the bend the path narrowed. Dayo stopped, shifted his grip, and kept going.',
        'It was not heavy. That was the worst of it.',
    ];

    private const V31_UPD = [
        'They carried the boat between them, Dayo at the front and Ifeoma at the back, and for once neither of them filled the quiet.',
        'At the bend the path narrowed. Dayo stopped, shifted his grip, and kept going.',
        'It was not heavy. That was the worst of it.',
    ];

    private const V31_NOW = [
        'They carried the boat between them, Dayo at the front and Ifeoma at the back, and for once neither of them filled the quiet.',
        'At the bend the path narrowed. Somewhere behind them a radio was playing the same song it had played all week. Dayo stopped, shifted his grip, and kept going.',
        'It was not heavy. That was the worst of it.',
        'At the water’s edge Ifeoma set her end down first, and the boat tipped, and for a moment it looked like it might go on without them.',
    ];

    private const PLUM_RAIN_EXTRA = [
        'Xu Wei paid the two coins without a word. Li counted them twice, then a third time, and pushed off.',
        'Halfway across, Lin Yue said, “You came back.” Xu Wei’s answer was lost under the rain, and she did not ask again.',
        'Inside the lid of Lin Yue’s ink box someone had written two lines, 床前明月光，疑是地上霜, and under them, in a smaller hand, a single character: 月.',
    ];

    /** @var array<string, Media> */
    private array $mediaByPath = [];

    /** @var array<string, array<string, mixed>> The public prototype's works by slug. */
    private array $proto = [];

    public function run(): void
    {
        $author = User::query()->where('username', 'eyiwetemi')->firstOrFail();

        $series = Series::forceCreate([
            'name' => 'The Harbour books',
            'name_key' => 'the harbour books',
            'slug' => 'the-harbour-books',
            'description' => 'Three novels about one harbour town, and the families who never quite leave it.',
        ]);

        $genres = [];

        foreach (self::GENRES as $index => $name) {
            $genres[$name] = Genre::forceCreate(['name' => $name, 'position' => $index + 1]);
        }

        $rows = $this->rows();
        $works = [];

        foreach ($rows as $row) {
            $works[$row['slug']] = $this->createWork($row, $series, $genres, $author);
        }

        foreach ($rows as $row) {
            $this->createItems($row, $works[$row['slug']], $works, $author);
        }

        /* singles that also sit in a collection point at it (a piece belongs to one collection) */
        foreach ($this->proto['midnight-poems']['chapters'] as $chapter) {
            if ($chapter['piece_slug'] !== null) {
                $works[$chapter['piece_slug']]->forceFill(['collection_id' => $works['midnight-poems']->id])->save();
            }
        }
    }

    /**
     * Every work readers see comes from the public prototype (database/seeders/data/prototype.json);
     * the admin's private drafts are kept from the admin data so the admin pages still have them.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $data = json_decode((string) file_get_contents(database_path('seeders/data/prototype.json')), true);
        $admin = collect(SeedData::WORKS)->keyBy('slug');
        $rows = [];

        foreach ($data['works'] as $work) {
            $this->proto[$work['slug']] = $work;
            $extra = $admin[$work['slug']] ?? [];
            $isCollection = $work['kind'] === 'collection';
            $adminColor = ($extra['cov'][0] ?? null) === 'col' ? $extra['cov'][1] : null;
            $color = $work['cover_color'] ?? $adminColor ?? '#F2D7DF';

            $rows[] = [
                'slug' => $work['slug'],
                't' => $work['title'],
                'k' => $isCollection ? $work['collection_kind'] : $work['kind'],
                'sub' => $isCollection ? 'Collection' : ucfirst($work['kind']),
                'st' => ['in_progress' => 'prog', 'finished' => 'fin', 'coming_soon' => 'soon'][$work['status']],
                'vis' => 'pub',
                'cov' => $work['cover_image'] !== null ? ['img', $work['cover_image']] : ['col', $color],
                'desc' => $work['blurb'] ?? ($extra['desc'] ?? null),
                'excerpt' => $work['excerpt'],
                'genres' => $work['genres'] !== [] && $work['genres'] !== null ? $work['genres'] : ($extra['genres'] ?? []),
                'series' => $work['series'] !== null ? 'harbour' : null,
                'book' => $work['series']['position'] ?? null,
                'notes' => $extra['notes'] ?? '',
                'likes' => $work['likes'] ?? 0,
                'comments' => $work['comments'] ?? 0,
            ];
        }

        foreach (SeedData::WORKS as $row) {
            if ($row['vis'] === 'pri' && ! isset($this->proto[$row['slug']])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Genre>  $genres
     */
    private function createWork(array $row, Series $series, array $genres, User $author): Work
    {
        $isCollection = str_starts_with($row['sub'], 'Collection');
        $kind = $isCollection ? WorkKind::Collection : WorkKind::from($row['k']);
        $collectionKind = $isCollection ? CollectionKind::from($row['k']) : null;
        [$coverType, $coverValue] = $row['cov'];
        $slug = $row['slug'];

        $work = Work::forceCreate([
            'kind' => $kind,
            'collection_kind' => $collectionKind,
            'title' => $row['t'],
            'title_key' => self::titleKey($row['t']),
            'slug' => $slug,
            'description' => $row['desc'] ?? null,
            'status' => $this->statusFor($row['st']),
            'visibility' => $this->visibilityFor($row['vis']),
            'series_id' => ($row['series'] ?? null) === 'harbour' ? $series->id : null,
            'series_position' => $row['book'] ?? null,
            'cover_media_id' => $coverType === 'img'
                ? $this->media('seed/covers/'.$coverValue, 'image/webp', $author)->id
                : null,
            'cover_color' => $coverType === 'col' ? $coverValue : null,
            'heading_style' => $kind === WorkKind::Novel
                ? ($slug === 'salt-season' ? HeadingStyle::ChapterWord : HeadingStyle::ChapterNumber)
                : null,
            'show_chapter_titles' => true,
            'show_pov' => in_array($slug, ['paper-boats', 'under-the-plum-rain'], true),
            'part_word' => $slug === 'paper-boats' ? 'Part' : null,
            'part_style' => $slug === 'paper-boats' ? HeadingStyle::Word : null,
            'show_part_numbers' => true,
            'scene_break' => SceneBreak::LineDot,
            'opening_quote' => SeedData::OPSEED[$slug]['q'] ?? null,
            'opening_quote_by' => SeedData::OPSEED[$slug]['by'] ?? null,
            'content_notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
            'free_chapters' => $row['slug'] === 'the-second-rain' ? 1 : null,
            'mark_names' => false,
            'likes_count' => $row['likes'] ?? 0,
            'comments_count' => $row['comments'] ?? 0,
        ]);

        $work->genres()->attach(
            collect($row['genres'] ?? [])->map(fn (string $name): int => $genres[$name]->id)->all(),
        );

        return $work;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Work>  $works
     */
    private function createItems(array $row, Work $work, array $works, User $author): void
    {
        $specs = match ($row['slug']) {
            'the-ember-gate' => $this->emberSpecs(),
            'starlings-go-south-in-september' => $this->starlingsSpecs(),
            'paper-boats' => $this->paperBoatsSpecs(),
            'under-the-plum-rain' => $this->plumRainSpecs(),
            'harbour-songs' => $this->draftNovelSpecs(),
            'salt-season' => $this->saltSeasonSpecs(),
            'the-second-rain' => $this->secondRainSpecs(),
            'midnight-poems' => $this->midnightSpecs($works),
            'rain-stories' => $this->rainStoriesSpecs(),
            default => isset($this->proto[$row['slug']]) ? $this->protoPieceSpecs($row) : $this->singlePieceSpecs($row),
        };

        foreach ($specs as $index => $spec) {
            $this->createItem($work, $spec, $index + 1, $author);
        }

        $work->forceFill([
            'first_published_at' => $work->items()->min('published_at'),
            'last_published_at' => $work->items()->max('published_at'),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function createItem(Work $work, array $spec, int $position, User $author): WorkItem
    {
        $key = $work->slug.'#'.$position;
        $draftDoc = isset($spec['draft']) ? ProseBody::document($key.'-draft', $spec['draft']) : null;
        $liveDoc = null;

        if (isset($spec['live'])) {
            $liveDoc = $spec['live'] === ($spec['draft'] ?? null)
                ? $draftDoc
                : ProseBody::document($key.'-live', $spec['live']);
        }

        $draftText = $draftDoc !== null ? ProseBody::text($draftDoc) : null;
        $liveText = $liveDoc !== null ? ProseBody::text($liveDoc) : null;

        $item = $work->items()->forceCreate([
            'type' => $spec['type'],
            'position' => $position,
            'piece_work_id' => ($spec['piece_work'] ?? null)?->id,
            'title' => $spec['title'] ?? null,
            'description' => $spec['description'] ?? null,
            'epigraph' => $spec['epigraph']['q'] ?? null,
            'epigraph_by' => $spec['epigraph']['by'] ?? null,
            'draft_body' => $draftDoc,
            'live_body' => $liveDoc,
            'draft_text' => $draftText,
            'live_text' => $liveText,
            'draft_words' => $draftText !== null ? ProseBody::words($draftText) : 0,
            'live_words' => $liveText !== null ? ProseBody::words($liveText) : 0,
            'draft_revision' => $draftDoc !== null ? 1 : 0,
            'draft_saved_at' => $draftDoc !== null ? now()->subDays(2) : null,
            'status' => $spec['status'],
            'scheduled_at' => $spec['scheduled_at'] ?? null,
            'published_at' => $spec['published_at'] ?? null,
            'live_updated_at' => $spec['live_updated_at'] ?? null,
            'soundtrack_url' => $spec['soundtrack'][0] ?? null,
            'soundtrack_label' => $spec['soundtrack'][1] ?? null,
            'likes_count' => 0,
            'comments_count' => 0,
        ]);

        $previous = null;

        foreach ($spec['versions'] ?? [] as $index => [$label, $at, $blocks]) {
            $document = ProseBody::document("{$key}-{$label}-{$index}", $blocks);
            $text = ProseBody::text($document);
            $change = ProseBody::wordChange($previous ?? '', $text);

            $item->versions()->forceCreate([
                'label' => $label,
                'body' => $document,
                'words' => ProseBody::words($text),
                'words_added' => $change['added'],
                'words_cut' => $change['cut'],
                'created_by' => $author->id,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $previous = $text;
        }

        return $item;
    }

    /**
     * The Ember Gate: the prototype's seven chapters, May (when it started) to 19 Nov, then the admin's draft of chapter 8.
     *
     * @return list<array<string, mixed>>
     */
    private function emberSpecs(): array
    {
        $chapters = $this->proto['the-ember-gate']['chapters'];
        $dates = $this->spread('2026-05-09 18:00', '2026-11-19 18:00', count($chapters));
        $specs = [];

        foreach ($chapters as $i => $chapter) {
            $n = $chapter['n'];
            $blocks = $this->sized($n === 3 ? $this->emberChapterThree() : $this->filler('novel', $n), $chapter['minutes'], 'novel');
            $spec = $this->publishedChapter($chapter['title'], $blocks, $dates[$i]);
            $spec['soundtrack'] = ['https://youtu.be/cE6wxDqdOV0', 'Video Games · Lana Del Rey'];

            if ($n === 3) {
                $spec['epigraph'] = SeedData::QSEED['the-ember-gate']['c3'];
            }

            $specs[] = $spec;
        }

        $specs[] = $this->draftChapter(null, [
            ['p', 'The second gate was smaller, and older, and nobody had ever written it down.'],
            ['p', 'Adaeze found it by accident, the way she found most things that mattered: looking for something else.'],
        ]);

        return $specs;
    }

    /**
     * Starlings: chapter 1 is out (2 Nov); chapter 2 is the admin's draft.
     *
     * @return list<array<string, mixed>>
     */
    private function starlingsSpecs(): array
    {
        $first = $this->proto['starlings-go-south-in-september']['chapters'][0];

        return [
            $this->publishedChapter($first['title'], $this->sized([
                ['p', 'The summer of the ferry was the last warm one, though none of them knew it yet.'],
                ...$this->filler('novel', 1),
            ], $first['minutes'], 'novel'), '2026-11-02 18:00'),
            array_merge(
                $this->draftChapter(SeedData::ST_TITLE, $this->seedParagraphs(SeedData::ST)),
                ['soundtrack' => ['https://youtu.be/o_1aF54DO60', 'Young and Beautiful · Lana Del Rey']],
            ),
        ];
    }

    /**
     * Paper Boats: thirty-five chapters out (March 2025 to 12 Nov 2026) in three parts; chapter 36, the last,
     * is scheduled.
     *
     * @return list<array<string, mixed>>
     */
    private function paperBoatsSpecs(): array
    {
        $chapters = collect($this->proto['paper-boats']['chapters'])->keyBy('n');
        $dates = $this->spread('2025-03-06 18:00', '2026-11-12 18:00', $chapters->count());
        $specs = [];

        foreach (SeedData::PB_PARTS as $index => [$numberWord, $title, $description, $numbers]) {
            $part = [
                'type' => 'part',
                'title' => $title,
                'description' => $description,
                'status' => PublishStatus::Draft,
            ];

            if ($index === 2) {
                $part['epigraph'] = SeedData::QSEED['paper-boats']['pt3'];
            }

            $specs[] = $part;

            foreach ($numbers as $n) {
                $specs[] = $this->paperBoatsChapter($n, $chapters[$n], $dates[$n - 1]);
            }
        }

        $specs[] = [
            'type' => 'chapter',
            'title' => null,
            'status' => PublishStatus::Scheduled,
            'draft' => $this->filler('novel', 36),
            'scheduled_at' => $this->wat('2026-11-28 18:00'),
        ];

        return $specs;
    }

    /**
     * @param  array<string, mixed>  $chapter
     * @return array<string, mixed>
     */
    private function paperBoatsChapter(int $n, array $chapter, string $publishedAt): array
    {
        $blocks = $this->sized(match ($n) {
            31 => $this->seedBlocksFromStrings(self::V31_UPD),
            33 => $this->paperBoatsChapterThirtyThree(),
            34 => [['p', 'The first drops came at dawn, fat and slow, and nobody in Ogbe believed them.']],
            default => $this->filler('novel', $n),
        }, $chapter['minutes'], 'novel');

        $spec = $this->publishedChapter($chapter['title'], $blocks, $publishedAt);

        if ($n === 31) {
            /* chapter 31 was updated after it went out: its history keeps both versions */
            $updated = $spec['published_at']->copy()->addDays(6);
            $spec['draft'] = $this->sized($this->seedBlocksFromStrings(self::V31_NOW), $chapter['minutes'], 'novel');
            $spec['live_updated_at'] = $updated;
            $spec['versions'] = [
                ['published', $spec['published_at'], $this->sized($this->seedBlocksFromStrings(self::V31_OLD), $chapter['minutes'], 'novel')],
                ['updated', $updated, $blocks],
            ];
        }

        return $spec;
    }

    /**
     * Salt Season: twelve chapters, January 2025 to 29 Aug 2026.
     *
     * @return list<array<string, mixed>>
     */
    private function saltSeasonSpecs(): array
    {
        return $this->chapterSpecs('salt-season', '2025-01-10 18:00', '2026-08-29 18:00', 'novel');
    }

    /**
     * The Second Rain: in print, twenty-four chapters with only the first free to read here. Chapter 18 was
     * pulled back, so it sits as unpublished.
     *
     * @return list<array<string, mixed>>
     */
    private function secondRainSpecs(): array
    {
        $minutes = $this->proto['the-second-rain']['chapters'][0]['minutes'];
        $dates = $this->spread('2025-04-10 18:00', '2026-03-15 18:00', 24);
        $specs = [];

        foreach ($dates as $i => $date) {
            $n = $i + 1;
            $blocks = $this->sized($this->filler('novel', $n), $minutes, 'novel');

            if ($n !== 18) {
                $specs[] = $this->publishedChapter(null, $blocks, $date);

                continue;
            }

            $publishedAt = $this->wat($date);
            $unpublishedAt = $this->wat('2026-11-20 10:00');
            $specs[] = [
                'type' => 'chapter',
                'title' => null,
                'status' => PublishStatus::Unpublished,
                'draft' => $blocks,
                'published_at' => $publishedAt,
                'live_updated_at' => $unpublishedAt,
                'versions' => [
                    ['published', $publishedAt, $blocks],
                    ['unpublished', $unpublishedAt, $blocks],
                ],
            ];
        }

        return $specs;
    }

    /**
     * Midnight Poems: the prototype's twelve poems; six of them are also single poems, which the collection
     * points at. One more is scheduled (admin).
     *
     * @param  array<string, Work>  $works
     * @return list<array<string, mixed>>
     */
    private function midnightSpecs(array $works): array
    {
        $chapters = $this->proto['midnight-poems']['chapters'];
        $dates = $this->spread('2024-02-02 22:00', '2026-11-02 22:00', count($chapters));
        $specs = [];

        foreach ($chapters as $i => $chapter) {
            if ($chapter['piece_slug'] !== null) {
                $piece = $works[$chapter['piece_slug']];
                $specs[] = [
                    'type' => 'piece',
                    'title' => $chapter['title'],
                    'status' => PublishStatus::Published,
                    'piece_work' => $piece,
                    'published_at' => $this->wat($dates[$i]),
                    'live_updated_at' => $this->wat($dates[$i]),
                ];

                continue;
            }

            $specs[] = $this->publishedChapter(
                $chapter['title'],
                $this->sized($this->poemLines(SeedData::POEM_L), $chapter['minutes'], 'poem'),
                $dates[$i],
                'piece',
            );
        }

        $specs[] = [
            'type' => 'piece',
            'title' => 'After the light goes',
            'status' => PublishStatus::Scheduled,
            'draft' => $this->poemLines(['The last bulb in the street', '  gives up without a sound.']),
            'scheduled_at' => $this->wat('2026-12-01 00:00'),
        ];

        return $specs;
    }

    /**
     * Rain Stories: the prototype's eight stories, 2023 to 3 Oct 2026.
     *
     * @return list<array<string, mixed>>
     */
    private function rainStoriesSpecs(): array
    {
        return $this->chapterSpecs('rain-stories', '2023-06-10 18:00', '2026-10-03 18:00', 'story', 'piece');
    }

    /**
     * Published chapters (or pieces) with the prototype's titles and reading times, spread between two dates.
     *
     * @return list<array<string, mixed>>
     */
    private function chapterSpecs(string $slug, string $from, string $to, string $kind, string $type = 'chapter'): array
    {
        $chapters = $this->proto[$slug]['chapters'];
        $dates = $this->spread($from, $to, count($chapters));

        return array_map(
            fn (array $chapter, string $date): array => $this->publishedChapter(
                $chapter['title'],
                $this->sized($this->filler($kind, $chapter['n']), $chapter['minutes'], $kind),
                $date,
                $type,
            ),
            $chapters,
            $dates,
        );
    }

    /**
     * A single story, poem or essay from the prototype: its own opening (the excerpt or first line readers see),
     * sized to its reading time, out on its date.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function protoPieceSpecs(array $row): array
    {
        $work = $this->proto[$row['slug']];
        $kind = $work['kind'];
        $opening = $work['excerpt'] ?? $work['title'];

        $blocks = match ($row['slug']) {
            'harmattan' => $this->poemLines(explode("\n", SeedData::POEM_LINES)),
            'small-hours' => $this->poemLines(explode("\n", SeedData::MIDPOEM)),
            'night-bus' => $this->seedBlocksFromStrings(SeedData::NIGHT_BODY),
            'on-leaving-home-twice' => $this->essayBlocks(SeedData::ESSAY_BODY),
            default => $kind === 'poem'
                ? $this->poemLines([$opening, ...array_slice(self::PLACEHOLDER_POEMS[abs(crc32($row['slug'])) % 3], 1)])
                : [['p', $opening], ...$this->filler($kind, abs(crc32($row['slug'])) % 3)],
        };

        $spec = $this->publishedChapter(null, $this->sized($blocks, $work['minutes'] ?? 1, $kind), $work['date'].' 18:00', 'piece');

        if ($row['slug'] === 'on-leaving-home-twice') {
            $spec['soundtrack'] = ['https://open.spotify.com/track/2bG6BG8GbX7d3bW3k3BzJb', 'Spotify'];
        }

        if ($row['slug'] === 'harmattan') {
            $spec['soundtrack'] = ['https://youtu.be/cE6wxDqdOV0', 'Video Games · Lana Del Rey'];
        }

        return [$spec];
    }

    /**
     * Pad a stand-in body with more of the same until it takes the prototype's reading time
     * (the site counts 230 words a minute).
     *
     * @param  list<array<int, mixed>>  $blocks
     * @return list<array<int, mixed>>
     */
    private function sized(array $blocks, ?int $minutes, string $kind): array
    {
        $minutes = max(1, (int) $minutes);
        $floor = ($minutes - 1) * 230;
        $words = fn (array $list): int => ProseBody::words(implode(' ', array_map(
            fn (array $block): string => isset($block[1]) && is_string($block[1]) ? $block[1] : '',
            $list,
        )));

        $more = $kind === 'poem'
            ? array_merge(...array_map(fn (array $poem): array => [['line', '', 0], ...$this->poemLines($poem)], self::PLACEHOLDER_POEMS))
            : SeedData::FILL[$kind === 'collection' ? 'story' : $kind] ?? SeedData::FILL['novel'];
        $more = $kind === 'poem' ? $more : array_map(fn (string $text): array => ['p', $text], $more);

        $i = 0;
        while ($words($blocks) <= $floor) {
            $blocks[] = $more[$i % count($more)];
            $i++;
        }

        return $blocks;
    }

    /**
     * `$count` evenly spaced local times from `$from` to `$to` (WAT), oldest first.
     *
     * @return list<string>
     */
    private function spread(string $from, string $to, int $count): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i', $from, 'Africa/Lagos');
        $end = Carbon::createFromFormat('Y-m-d H:i', $to, 'Africa/Lagos');

        if ($count <= 1) {
            return [$end->format('Y-m-d H:i')];
        }

        $step = ($end->getTimestamp() - $start->getTimestamp()) / ($count - 1);

        return array_map(
            fn (int $i): string => $start->copy()->addSeconds((int) round($step * $i))->format('Y-m-d H:i'),
            range(0, $count - 1),
        );
    }

    /**
     * Chapter 33 as the prototype stands, with its reviewer marks and footnote left out of the body text.
     *
     * @return list<array{0: string, 1?: string}>
     */
    private function paperBoatsChapterThirtyThree(): array
    {
        $blocks = [];

        foreach (SeedData::P33 as [$kind, $text]) {
            if ($kind === 'brk') {
                $blocks[] = ['break'];

                continue;
            }

            $blocks[] = ['p', trim(strtr($text, [
                '{MARK}' => 'like small boats that had given up',
                '{SEL}' => 'no name on it',
                '{FN}' => '',
                '{CARET}' => '',
            ]))];
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plumRainSpecs(): array
    {
        return [
            $this->draftChapter('Plum rain', [
                ['p', 'The plum rain came early that year, and Xu Wei took it as a sign. Lin Yue took it as rain.'],
            ]),
            $this->draftChapter('The tea house', [
                ['p', 'Old Li ran the tea house by the ferry, and Xu Wei owed him money he had no intention of paying.'],
            ]),
            $this->draftChapter('Ink', [
                ['p', 'Lin Yue ground the ink herself. Xu Wei watched and pretended not to.'],
            ]),
            array_merge(
                $this->draftChapter('The ferry bell', $this->seedBlocksFromStrings(array_merge(SeedData::UP, self::PLUM_RAIN_EXTRA))),
                ['epigraph' => SeedData::QSEED['under-the-plum-rain']['c4']],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftNovelSpecs(): array
    {
        $specs = [];

        foreach (self::HARBOUR_SONG_TITLES as $index => $title) {
            $specs[] = $this->draftChapter($title, $this->filler('novel', $index + 1));
        }

        return $specs;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function singlePieceSpecs(array $row): array
    {
        $slug = $row['slug'];
        $variant = abs(crc32($slug)) % 3;
        $published = $row['st'] === 'pub';

        $blocks = match ($slug) {
            'harmattan' => $this->poemLines(explode("\n", SeedData::POEM_LINES)),
            'small-hours' => $this->poemLines(explode("\n", SeedData::MIDPOEM)),
            'night-bus' => $this->seedBlocksFromStrings(SeedData::NIGHT_BODY),
            'on-leaving-home-twice' => $this->essayBlocks(SeedData::ESSAY_BODY),
            'rainy-season-again' => $this->poemLines(['The rain is back', '  and so am I,', 'both of us late,', '  both of us sorry.', '', 'Nobody asked', '  either of us to stay.']),
            default => match ($row['k']) {
                'story' => $this->filler('story', $variant),
                'essay' => $this->filler('essay', $variant),
                default => $this->poemLines(self::PLACEHOLDER_POEMS[$variant]),
            },
        };

        if (! $published) {
            return [[
                'type' => 'piece',
                'status' => PublishStatus::Draft,
                'draft' => $blocks,
            ]];
        }

        $spec = $this->publishedChapter(null, $blocks, '2026-01-01 18:00', 'piece');
        $spec['published_at'] = $this->dateFromLabel($row['ed']);
        $spec['live_updated_at'] = $spec['published_at'];
        $spec['versions'] = [['published', $spec['published_at'], $blocks]];

        if ($slug === 'on-leaving-home-twice') {
            $spec['soundtrack'] = ['https://open.spotify.com/track/2bG6BG8GbX7d3bW3k3BzJb', 'Spotify'];
        }

        return [$spec];
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{0: string, 1?: string, 2?: int}>
     */
    private function poemLines(array $lines): array
    {
        $blocks = [];

        foreach ($lines as $line) {
            $leading = strlen($line) - strlen(ltrim($line, ' '));
            $blocks[] = ['line', trim($line), min(3, intdiv($leading, 2))];
        }

        return $blocks;
    }

    /**
     * @return list<array{0: string, 1?: string}>
     */
    private function essayBlocks(array $body): array
    {
        $blocks = [];

        foreach ($body as $part) {
            if ($part[0] === 'p') {
                $text = '';

                foreach (array_slice($part, 1) as $segment) {
                    $text .= is_array($segment) ? $segment[1] : $segment;
                }

                $blocks[] = ['p', trim($text)];
            } elseif ($part[0] === 'h') {
                $blocks[] = ['p', $part[1]];
            } elseif (in_array($part[0], ['ul', 'ol'], true)) {
                foreach ($part[1] as $item) {
                    $blocks[] = ['p', $item];
                }
            }
        }

        return $blocks;
    }

    /**
     * @param  list<string|array<int, string>>  $paragraphs
     * @return list<array{0: string, 1?: string}>
     */
    private function seedBlocksFromStrings(array $paragraphs): array
    {
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            $blocks[] = $paragraph === '*' ? ['break'] : ['p', $paragraph];
        }

        return $blocks;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $paragraphs
     * @return list<array{0: string, 1?: string}>
     */
    private function seedParagraphs(array $paragraphs): array
    {
        return array_map(fn (array $paragraph): array => ['p', $paragraph[1]], $paragraphs);
    }

    /**
     * Chapter 3 of The Ember Gate, the prototype's reader text, with its reader marks and suggestions left out.
     *
     * @return list<array{0: string, 1?: string}>
     */
    private function emberChapterThree(): array
    {
        $blocks = [];

        foreach (SeedData::CH as $paragraph) {
            if ($paragraph === '*') {
                $blocks[] = ['break'];

                continue;
            }

            $text = '';

            foreach ($paragraph as $segment) {
                $text .= is_string($segment) ? $segment : $segment[2];
            }

            $blocks[] = ['p', trim($text)];
        }

        return $blocks;
    }

    /**
     * Two to three short placeholder paragraphs, rotated so chapters differ.
     *
     * @return list<array{0: string, 1?: string}>
     */
    private function filler(string $kind, int $n): array
    {
        $source = SeedData::FILL[$kind];
        $offset = $n % count($source);

        return array_map(
            fn (string $text): array => ['p', $text],
            array_merge(array_slice($source, $offset), array_slice($source, 0, $offset)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedChapter(?string $title, array $blocks, string $publishedAt, string $type = 'chapter'): array
    {
        $when = $this->wat($publishedAt);

        return [
            'type' => $type,
            'title' => $title,
            'status' => PublishStatus::Published,
            'draft' => $blocks,
            'live' => $blocks,
            'published_at' => $when,
            'live_updated_at' => $when,
            'versions' => [['published', $when, $blocks]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftChapter(?string $title, ?array $blocks, string $type = 'chapter'): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'status' => PublishStatus::Draft,
            'draft' => $blocks,
        ];
    }

    private function wat(string $local): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $local, 'Africa/Lagos')->utc();
    }

    /**
     * "22 Oct" or "Feb" from the prototype's date labels, at 18:00 WAT.
     */
    private function dateFromLabel(string $label): Carbon
    {
        if (preg_match('/^(\d{1,2}) ([A-Za-z]{3})$/', $label, $match) === 1) {
            return Carbon::createFromFormat('d M Y H:i', "{$match[1]} {$match[2]} 2026 18:00", 'Africa/Lagos')->utc();
        }

        if (preg_match('/^[A-Za-z]{3}$/', $label) === 1) {
            return Carbon::createFromFormat('d M Y H:i', "15 {$label} 2026 18:00", 'Africa/Lagos')->utc();
        }

        return now()->subDays(10);
    }

    private function statusFor(string $prototypeStatus): WorkStatus
    {
        return match ($prototypeStatus) {
            'prog', 'draft' => WorkStatus::InProgress,
            'fin', 'print', 'pub' => WorkStatus::Finished,
            'soon' => WorkStatus::ComingSoon,
        };
    }

    private function visibilityFor(string $prototypeVisibility): Visibility
    {
        return match ($prototypeVisibility) {
            'pri' => Visibility::Private,
            'rev' => Visibility::Reviewers,
            'pub' => Visibility::Public,
        };
    }

    private static function titleKey(string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title)));
    }

    private function media(string $path, string $mime, User $author): Media
    {
        return $this->mediaByPath[$path] ??= Media::forceCreate([
            'disk' => 'public',
            'path' => $path,
            'original_name' => basename($path),
            'mime' => $mime,
            'size' => 0,
            'uploaded_by' => $author->id,
        ]);
    }
}
