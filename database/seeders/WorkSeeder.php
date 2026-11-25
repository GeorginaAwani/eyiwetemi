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

    public function run(): void
    {
        $author = User::query()->where('username', 'eyiwetemi')->firstOrFail();

        $series = Series::forceCreate([
            'name' => 'The Harbour books',
            'name_key' => 'the harbour books',
            'slug' => 'the-harbour-books',
        ]);

        $genres = [];

        foreach (self::GENRES as $index => $name) {
            $genres[$name] = Genre::forceCreate(['name' => $name, 'position' => $index + 1]);
        }

        $works = [];

        foreach (SeedData::WORKS as $row) {
            $works[$row['slug']] = $this->createWork($row, $series, $genres, $author);
        }

        foreach (SeedData::WORKS as $row) {
            $this->createItems($row, $works[$row['slug']], $works, $author);
        }
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
            'likes_count' => 0,
            'comments_count' => 0,
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
            default => $this->singlePieceSpecs($row),
        };

        foreach ($specs as $index => $spec) {
            $this->createItem($work, $spec, $index + 1, $author);
        }

        if ($row['slug'] === 'midnight-poems') {
            $works['small-hours']->forceFill(['collection_id' => $work->id])->save();
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
     * @return list<array<string, mixed>>
     */
    private function emberSpecs(): array
    {
        $titles = [1 => 'The gate in the mud', 3 => 'Ember', 7 => 'Low water'];
        $specs = [];

        for ($n = 1; $n <= 7; $n++) {
            $blocks = $n === 3 ? $this->emberChapterThree() : $this->filler('novel', $n);
            $spec = $this->publishedChapter($titles[$n] ?? null, $blocks, sprintf('2026-10-%02d 18:00', $n * 3));

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
     * @return list<array<string, mixed>>
     */
    private function starlingsSpecs(): array
    {
        return [
            $this->publishedChapter('The last warm day', [
                ['p', 'The summer of the ferry was the last warm one, though none of them knew it yet.'],
            ], '2026-11-02 18:00'),
            array_merge(
                $this->draftChapter(SeedData::ST_TITLE, $this->seedParagraphs(SeedData::ST)),
                ['soundtrack' => ['https://youtu.be/o_1aF54DO60', 'Young and Beautiful · Lana Del Rey']],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paperBoatsSpecs(): array
    {
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
                $specs[] = $this->paperBoatsChapter($n);
            }
        }

        return $specs;
    }

    /**
     * @return array<string, mixed>
     */
    private function paperBoatsChapter(int $n): array
    {
        $title = SeedData::PB_T[$n - 1];

        if ($n <= 30) {
            return $this->publishedChapter($title, $this->filler('novel', $n), sprintf(
                '2026-%02d-%02d 18:00', 6 + intdiv($n, 6), ($n * 3) % 27 + 1,
            ));
        }

        return match ($n) {
            31 => [
                'type' => 'chapter',
                'title' => $title,
                'status' => PublishStatus::Published,
                'draft' => $this->seedBlocksFromStrings(self::V31_NOW),
                'live' => $this->seedBlocksFromStrings(self::V31_UPD),
                'published_at' => $this->wat('2026-11-12 18:00'),
                'live_updated_at' => $this->wat('2026-11-18 09:12'),
                'versions' => [
                    ['published', $this->wat('2026-11-12 18:00'), $this->seedBlocksFromStrings(self::V31_OLD)],
                    ['updated', $this->wat('2026-11-18 09:12'), $this->seedBlocksFromStrings(self::V31_UPD)],
                ],
            ],
            32 => [
                'type' => 'chapter',
                'title' => $title,
                'status' => PublishStatus::Scheduled,
                'draft' => $this->filler('novel', 32),
                'scheduled_at' => $this->wat('2026-11-28 18:00'),
            ],
            33 => [
                'type' => 'chapter',
                'title' => $title,
                'status' => PublishStatus::Draft,
                'draft' => $this->paperBoatsChapterThirtyThree(),
                'soundtrack' => ['https://youtu.be/cE6wxDqdOV0', 'Video Games · Lana Del Rey'],
            ],
            34 => $this->draftChapter($title, [
                ['p', 'The first drops came at dawn, fat and slow, and nobody in Ogbe believed them.'],
            ]),
            default => $this->draftChapter($title, null),
        };
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
     * @return list<array<string, mixed>>
     */
    private function saltSeasonSpecs(): array
    {
        $specs = [];

        foreach (self::SALT_SEASON_TITLES as $index => $title) {
            $n = $index + 1;
            $specs[] = $this->publishedChapter($title, $this->filler('novel', $n), sprintf(
                '2025-%02d-%02d 18:00', 1 + $n % 9, 10 + $n % 9,
            ));
        }

        return $specs;
    }

    /**
     * Eighteen chapters; chapter 18 has been pulled back, so it sits as unpublished.
     *
     * @return list<array<string, mixed>>
     */
    private function secondRainSpecs(): array
    {
        $specs = [];

        for ($n = 1; $n <= 17; $n++) {
            $specs[] = $this->publishedChapter(null, $this->filler('novel', $n), sprintf(
                '2025-%02d-%02d 18:00', 1 + $n % 9, 10 + $n % 9,
            ));
        }

        $publishedAt = $this->wat('2025-01-18 18:00');
        $unpublishedAt = $this->wat('2026-11-20 10:00');
        $blocks = $this->filler('novel', 18);

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

        return $specs;
    }

    /**
     * @param  array<string, Work>  $works
     * @return list<array<string, mixed>>
     */
    private function midnightSpecs(array $works): array
    {
        $specs = [];

        $specs[] = [
            'type' => 'piece',
            'title' => self::MIDNIGHT_TITLES[0],
            'status' => PublishStatus::Published,
            'piece_work' => $works['small-hours'],
            'published_at' => $this->wat('2026-02-02 22:00'),
            'live_updated_at' => $this->wat('2026-02-02 22:00'),
        ];

        for ($n = 2; $n <= 12; $n++) {
            $specs[] = $this->publishedChapter(
                self::MIDNIGHT_TITLES[$n - 1],
                $this->poemLines(SeedData::POEM_L),
                sprintf('2026-%02d-%02d 22:00', 1 + $n % 9, 1 + $n % 8),
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
     * @return list<array<string, mixed>>
     */
    private function rainStoriesSpecs(): array
    {
        $specs = [];

        foreach (self::RAIN_STORY_TITLES as $index => $title) {
            $n = $index + 1;
            $specs[] = $this->publishedChapter($title, $this->filler('story', $n), sprintf('2026-%02d-12 18:00', 1 + $n), 'piece');
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
