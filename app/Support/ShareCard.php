<?php

namespace App\Support;

use App\Enums\SitePageKey;
use App\Enums\WorkKind;
use App\Models\Media;
use App\Models\SitePage;
use App\Models\Work;
use Closure;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The share cards, 1200 × 630, drawn with GD in the approved layouts (em = 32 px). The site's card is her portrait ringed,
 * with the wordmark and the site's line beside it. A work's card is its cover (or its colour, for a novel without one)
 * beside its kind, title and her name; a non-novel without a picture is its kind, title and wordmark. Cards are drawn at
 * twice the size and scaled down, so the curves are smooth, and kept on the local disk under a hash of what they draw.
 */
class ShareCard
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** The design unit (the prototype's em), in pixels. */
    private const UNIT = 32;

    /** Drawn at this multiple of the card's size, then scaled down. */
    private const SCALE = 2;

    /** Changes when the drawing changes, so cards drawn before are not reused. */
    private const LAYOUT = 2;

    private const JPEG_QUALITY = 85;

    private const PAPER = '#F5F0E6';

    private const INK = '#3E2723';

    private const ROSE = '#9A4762';

    private const PINK = '#E0A3B5';

    private const SITE_LINE = '#4E3A33';

    private const MUTED = '#7F6E66';

    private const PORTRAIT_FALLBACK = '#E9DFD3';

    /** A novel's colour when its cover picture cannot be read: the colour a cover shows by default. */
    private const COVER_FALLBACK = '#C24E77';

    /** The wordmark, with its dotless i's: the third and ninth letters get a bar above them. */
    private const WORDMARK = 'eyıwetemı';

    private const FONTS = [
        'black' => 'Archivo-Black.ttf',
        'bold' => 'Archivo-Bold.ttf',
        'regular' => 'Archivo-Regular.ttf',
        'caslon' => 'LibreCaslonDisplay-Regular.ttf',
        'newsreader' => 'Newsreader16pt-Regular.ttf',
    ];

    /** @var array<string, int> */
    private array $colours = [];

    private function __construct(private readonly GdImage $canvas) {}

    /**
     * The site's card: her portrait with its ring, the wordmark, and the site's line.
     */
    public static function site(): string
    {
        $line = SiteSharing::siteLine();
        $portrait = self::aboutPortrait();

        return self::remember(['site', $line, self::fingerprint($portrait)], function () use ($line, $portrait): string {
            $card = new self(self::canvas());
            $card->drawSite($line, $portrait);

            return $card->encode();
        });
    }

    /**
     * A work's card. The work's cover is loaded with it.
     */
    public static function work(Work $work): string
    {
        $kind = Str::upper(WorkPresenter::kindLabel($work));
        $cover = self::image($work->cover);
        $colour = $cover === null && $work->kind === WorkKind::Novel ? WorkPresenter::cover($work)['color'] : null;

        return self::remember(['work', $kind, $work->title, self::fingerprint($cover), $colour], function () use ($work, $kind, $cover, $colour): string {
            $card = new self(self::canvas());
            $card->drawWork($kind, $work->title, $cover, $colour);

            return $card->encode();
        });
    }

    private function drawSite(string $line, ?array $portrait): void
    {
        $radius = 4.75 * self::UNIT;
        $outer = $radius + 0.5 * self::UNIT + 0.08 * self::UNIT;
        $centreX = 108 + $outer;
        $centreY = self::HEIGHT / 2;

        $this->disk($centreX, $centreY, $outer, self::ROSE);
        $this->disk($centreX, $centreY, $radius + 0.5 * self::UNIT, self::PAPER);

        $portraitTile = $portrait !== null ? $this->portraitTile($portrait, $radius) : null;

        if ($portraitTile !== null) {
            $this->place($portraitTile, $centreX - $radius, $centreY - $radius);
        } else {
            $this->disk($centreX, $centreY, $radius, self::PORTRAIT_FALLBACK);
        }

        $textX = 108 + 2 * $outer + 84;
        $textWidth = self::WIDTH - 108 - $textX;
        $wordSize = 2.6 * self::UNIT;
        $lineSize = 1.15 * self::UNIT;
        $gap = 0.6 * self::UNIT;
        $wordHeight = 1.2 * $wordSize;
        $lineHeight = 1.3 * $lineSize;
        $lines = $this->wrap($line, 'newsreader', $lineSize, $textWidth);
        $total = $wordHeight + ($lines === [] ? 0 : $gap + count($lines) * $lineHeight);
        $top = (self::HEIGHT - $total) / 2;

        $this->wordmark($textX, self::baseline($top, $wordHeight, $wordSize), $wordSize);

        foreach ($lines as $i => $text) {
            $this->text($text, 'newsreader', $lineSize, self::SITE_LINE, $textX, self::baseline($top + $wordHeight + $gap + $i * $lineHeight, $lineHeight, $lineSize));
        }
    }

    private function drawWork(string $kind, string $title, ?array $cover, ?string $colour): void
    {
        if ($cover === null && $colour === null) {
            $this->drawTextOnly($kind, $title);

            return;
        }

        $x = 84;
        $y = 50;
        $width = 408;
        $height = 530;
        $radius = 0.15 * self::UNIT;

        $this->shadow($x, $y, $width, $height, $radius);
        $source = $cover !== null ? self::decode($cover['bytes']) : null;
        [$focusX, $focusY] = $cover['focus'] ?? [0.5, 0.5];
        $tile = $source !== null
            ? self::tile($source, $focusX, $focusY, $width * self::SCALE, $height * self::SCALE)
            : self::solid($width * self::SCALE, $height * self::SCALE, $colour ?? self::COVER_FALLBACK);
        self::carveCorners($tile, $radius);
        $this->place($tile, $x, $y);

        $textX = $x + $width + 84;
        $textWidth = self::WIDTH - 84 - $textX;
        $kindSize = 0.72 * self::UNIT;
        $titleLead = 1.08;
        $authorSize = 0.95 * self::UNIT;
        $gap = 0.5 * self::UNIT;
        $kindHeight = 1.2 * $kindSize;
        $authorHeight = 1.2 * $authorSize;

        [$titleSize, $lines] = $this->fitTitle($title, $textWidth, 2.7 * self::UNIT, 550 - $kindHeight - $authorHeight - 2 * $gap, $titleLead);
        $titleHeight = count($lines) * $titleSize * $titleLead;
        $total = $kindHeight + $gap + $titleHeight + $gap + $authorHeight;
        $top = (self::HEIGHT - $total) / 2;
        $titleTop = $top + $kindHeight + $gap;

        $this->spaced($kind, 'bold', $kindSize, $textX, self::baseline($top, $kindHeight, $kindSize), 0.14 * $kindSize, self::ROSE);
        $this->lines($lines, 'caslon', $titleSize, self::INK, $textX, $titleTop, $titleSize * $titleLead);
        $this->text('Eyiwetemi', 'regular', $authorSize, self::MUTED, $textX, self::baseline($titleTop + $titleHeight + $gap, $authorHeight, $authorSize));
        $this->wordmark(self::WIDTH - 84, 566, self::UNIT, alignRight: true);
    }

    private function drawTextOnly(string $kind, string $title): void
    {
        $textX = 108;
        $textWidth = self::WIDTH - 216;
        $kindSize = 0.72 * self::UNIT;
        $gap = 0.6 * self::UNIT;
        $wordSize = self::UNIT;
        $titleLead = 1.08;
        $kindHeight = 1.2 * $kindSize;
        $wordHeight = 1.2 * $wordSize;

        [$titleSize, $lines] = $this->fitTitle($title, $textWidth, 3 * self::UNIT, 470 - $kindHeight - $gap, $titleLead);
        $titleHeight = count($lines) * $titleSize * $titleLead;
        $total = $kindHeight + $gap + $titleHeight;
        $top = (self::HEIGHT - $total) / 2;
        $titleTop = $top + $kindHeight + $gap;

        $this->spaced($kind, 'bold', $kindSize, $textX, self::baseline($top, $kindHeight, $kindSize), 0.14 * $kindSize, self::ROSE);
        $this->lines($lines, 'caslon', $titleSize, self::INK, $textX, $titleTop, $titleSize * $titleLead);
        /* the wordmark sits in the corner, as on the cards with a cover */
        $this->wordmark(self::WIDTH - 84, 566, $wordSize, alignRight: true);
    }

    /**
     * The largest title size, from 3 em down, whose lines fit the budget; the title is wrapped at that size.
     *
     * @return array{0: float, 1: list<string>}
     */
    private function fitTitle(string $title, float $width, float $size, float $budget, float $lead): array
    {
        while (true) {
            $lines = $this->wrap($title, 'caslon', $size, $width);

            if (count($lines) * $size * $lead <= $budget || $size <= 2.2 * self::UNIT) {
                return [$size, $lines];
            }

            $size *= 0.94;
        }
    }

    /**
     * The soft shadow under a cover: rounded rectangles that grow from the cover outwards, each lighter than the one inside it.
     */
    private function shadow(float $x, float $y, float $width, float $height, float $radius): void
    {
        $steps = 12;
        $spread = 0.75 * self::UNIT;
        $drop = 0.25 * self::UNIT;

        for ($k = $steps; $k >= 1; $k--) {
            $grow = $spread * $k / $steps;
            $this->roundRect($x - $grow, $y + $drop - $grow, $width + 2 * $grow, $height + 2 * $grow, $radius + $grow, self::mix(self::PAPER, self::INK, 0.2 * (1 - $k / $steps)));
        }
    }

    private function wordmark(float $x, float $baseline, float $size, bool $alignRight = false): void
    {
        $letters = preg_split('//u', self::WORDMARK, -1, PREG_SPLIT_NO_EMPTY);
        $spacing = -0.02 * $size;
        $width = $this->width(self::WORDMARK, 'black', $size) + count($letters) * $spacing;
        $left = $alignRight ? $x - $width : $x;
        $prefix = '';

        foreach ($letters as $i => $letter) {
            $at = $left + $this->width($prefix, 'black', $size) + $i * $spacing;
            $this->text($letter, 'black', $size, $i < 2 ? self::ROSE : self::INK, $at, $baseline);

            if ($i === 2 || $i === 8) {
                $this->dotBar($at, $baseline, $size, $i === 2 ? self::PINK : self::ROSE);
            }

            $prefix .= $letter;
        }
    }

    /**
     * The bar that stands in for the dot of a dotless i: centred on the letter, .34 em wide and .13 em tall, its top .13 em below
     * the top of the letter box.
     */
    private function dotBar(float $x, float $baseline, float $size, string $hex): void
    {
        $dotless = imagettfbbox(self::at($size), 0, self::font('black'), 'ı');
        $centre = $x + ($dotless[0] + $dotless[2]) / (2 * self::SCALE);
        $ascender = -imagettfbbox(self::at($size), 0, self::font('black'), 'l')[5] / self::SCALE;

        $this->rect($centre - 0.17 * $size, $baseline - $ascender + 0.13 * $size, 0.34 * $size, 0.13 * $size, $hex);
    }

    /**
     * Letters with letter-spacing, one at a time so each sits after the ink of the ones before it.
     */
    private function spaced(string $text, string $font, float $size, float $x, float $baseline, float $spacing, string $hex): void
    {
        $prefix = '';

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $i => $letter) {
            $this->text($letter, $font, $size, $hex, $x + $this->width($prefix, $font, $size) + $i * $spacing, $baseline);
            $prefix .= $letter;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function lines(array $lines, string $font, float $size, string $hex, float $x, float $top, float $lineHeight): void
    {
        foreach ($lines as $i => $text) {
            $this->text($text, $font, $size, $hex, $x, self::baseline($top + $i * $lineHeight, $lineHeight, $size));
        }
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, string $font, float $size, float $width): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($line !== '' && $this->width($candidate, $font, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * How far the text runs across, in pixels: the right edge of its ink, measured after a '|' so the glyph widths add up.
     */
    private function width(string $text, string $font, float $size): float
    {
        return $this->inkRight($text.'|', $font, $size) - $this->inkRight('|', $font, $size);
    }

    private function inkRight(string $text, string $font, float $size): float
    {
        $box = imagettfbbox(self::at($size), 0, self::font($font), $text);

        return max($box[2], $box[4]) / self::SCALE;
    }

    private function text(string $text, string $font, float $size, string $hex, float $x, float $baseline): void
    {
        imagettftext($this->canvas, self::at($size), 0, self::s($x), self::s($baseline), $this->colour($hex), self::font($font), $text);
    }

    private function disk(float $centreX, float $centreY, float $radius, string $hex): void
    {
        imagefilledellipse($this->canvas, self::s($centreX), self::s($centreY), self::s(2 * $radius), self::s(2 * $radius), $this->colour($hex));
    }

    private function rect(float $x, float $y, float $width, float $height, string $hex): void
    {
        imagefilledrectangle($this->canvas, self::s($x), self::s($y), self::s($x + $width) - 1, self::s($y + $height) - 1, $this->colour($hex));
    }

    private function roundRect(float $x, float $y, float $width, float $height, float $radius, string $hex): void
    {
        $this->rect($x + $radius, $y, $width - 2 * $radius, $height, $hex);
        $this->rect($x, $y + $radius, $width, $height - 2 * $radius, $hex);

        foreach ([[$x + $radius, $y + $radius], [$x + $width - $radius, $y + $radius], [$x + $radius, $y + $height - $radius], [$x + $width - $radius, $y + $height - $radius]] as [$cx, $cy]) {
            $this->disk($cx, $cy, $radius, $hex);
        }
    }

    /**
     * Copies a tile onto the card, leaving out the pixels the tile has made transparent (the rounded corners, the outside of a ring).
     */
    private function place(GdImage $tile, float $x, float $y): void
    {
        $left = self::s($x);
        $top = self::s($y);

        for ($py = 0, $rows = imagesy($tile); $py < $rows; $py++) {
            for ($px = 0, $columns = imagesx($tile); $px < $columns; $px++) {
                $pixel = imagecolorat($tile, $px, $py);

                if (($pixel & 0x7F000000) === 0) {
                    imagesetpixel($this->canvas, $left + $px, $top + $py, $pixel);
                }
            }
        }
    }

    /**
     * Her portrait as a circle of the given radius, cropped square around her focus point.
     */
    private function portraitTile(array $portrait, float $radius): ?GdImage
    {
        $source = self::decode($portrait['bytes']);

        if ($source === null) {
            return null;
        }

        $size = (int) round(2 * $radius * self::SCALE);
        $tile = self::tile($source, $portrait['focus'][0], $portrait['focus'][1], $size, $size);
        $half = $size / 2;
        $clear = imagecolorallocatealpha($tile, 0, 0, 0, 127);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x + 0.5 - $half;
                $dy = $y + 0.5 - $half;

                if ($dx * $dx + $dy * $dy > $half * $half) {
                    imagesetpixel($tile, $x, $y, $clear);
                }
            }
        }

        return $tile;
    }

    /**
     * A tile the size of a box, filled with the picture cropped to cover it (its focus kept in view). Transparency is kept
     * for the corners that are carved away afterwards.
     */
    private static function tile(GdImage $source, float $focusX, float $focusY, int $width, int $height): GdImage
    {
        $tile = self::blankTile($width, $height);
        $region = ImageCrop::region(imagesx($source), imagesy($source), $focusX, $focusY, $width, $height);
        imagecopyresampled($tile, $source, 0, 0, $region['x'], $region['y'], $width, $height, $region['width'], $region['height']);

        return $tile;
    }

    private static function solid(int $width, int $height, string $hex): GdImage
    {
        $tile = self::blankTile($width, $height);
        imagefilledrectangle($tile, 0, 0, $width - 1, $height - 1, imagecolorallocate($tile, ...self::rgb($hex)));

        return $tile;
    }

    private static function blankTile(int $width, int $height): GdImage
    {
        $tile = imagecreatetruecolor($width, $height);
        imagesavealpha($tile, true);
        imagealphablending($tile, false);

        return $tile;
    }

    /**
     * Makes the four corners of a rounded box transparent.
     */
    private static function carveCorners(GdImage $tile, float $radius): void
    {
        $width = imagesx($tile);
        $height = imagesy($tile);
        $r = (int) round($radius * self::SCALE);
        $clear = imagecolorallocatealpha($tile, 0, 0, 0, 127);

        for ($y = 0; $y < $r; $y++) {
            for ($x = 0; $x < $r; $x++) {
                $dx = $r - $x - 0.5;
                $dy = $r - $y - 0.5;

                if ($dx * $dx + $dy * $dy > $r * $r) {
                    imagesetpixel($tile, $x, $y, $clear);
                    imagesetpixel($tile, $width - 1 - $x, $y, $clear);
                    imagesetpixel($tile, $x, $height - 1 - $y, $clear);
                    imagesetpixel($tile, $width - 1 - $x, $height - 1 - $y, $clear);
                }
            }
        }
    }

    private static function canvas(): GdImage
    {
        $canvas = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH * self::SCALE - 1, self::HEIGHT * self::SCALE - 1, imagecolorallocate($canvas, ...self::rgb(self::PAPER)));

        return $canvas;
    }

    private function encode(): string
    {
        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($out, $this->canvas, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);

        ob_start();
        imagejpeg($out, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }

    private function colour(string $hex): int
    {
        return $this->colours[$hex] ??= imagecolorallocate($this->canvas, ...self::rgb($hex));
    }

    private static function font(string $key): string
    {
        return resource_path('fonts/'.self::FONTS[$key]);
    }

    private static function at(float $size): float
    {
        return $size * self::SCALE;
    }

    /** A design coordinate as a pixel of the drawn (doubled) card. */
    private static function s(float $value): int
    {
        return (int) round($value * self::SCALE);
    }

    /** The baseline of a line of text in a box of the given height, with its capitals centred. */
    private static function baseline(float $top, float $lineHeight, float $size): float
    {
        return $top + $lineHeight / 2 + 0.35 * $size;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    private static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);

        return sprintf(
            '#%02X%02X%02X',
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount),
        );
    }

    private static function decode(string $bytes): ?GdImage
    {
        $image = @imagecreatefromstring($bytes);

        return $image === false ? null : $image;
    }

    /**
     * @return array{bytes: string, focus: array{0: float, 1: float}}|null
     */
    private static function image(?Media $media): ?array
    {
        if ($media === null || ! Storage::disk($media->disk)->exists($media->path)) {
            return null;
        }

        return [
            'bytes' => (string) Storage::disk($media->disk)->get($media->path),
            'focus' => [(float) ($media->focal_x ?? 0.5), (float) ($media->focal_y ?? 0.5)],
        ];
    }

    /**
     * Her portrait on the About page, if one is live.
     *
     * @return array{bytes: string, focus: array{0: float, 1: float}}|null
     */
    private static function aboutPortrait(): ?array
    {
        $live = SitePage::query()->firstWhere('key', SitePageKey::About)?->live;
        $id = is_array($live) ? ($live['image'] ?? null) : null;

        return self::image(is_numeric($id) ? Media::query()->find((int) $id) : null);
    }

    /**
     * @param  array{bytes: string, focus: array{0: float, 1: float}}|null  $image
     * @return array{0: string, 1: array{0: float, 1: float}}|null
     */
    private static function fingerprint(?array $image): ?array
    {
        return $image === null ? null : [sha1($image['bytes']), $image['focus']];
    }

    /**
     * The card's bytes: from the local disk when a card with the same inputs was drawn before, otherwise drawn and kept.
     *
     * @param  array<int, mixed>  $inputs
     * @param  Closure(): string  $draw
     */
    private static function remember(array $inputs, Closure $draw): string
    {
        $path = 'share-cards/'.hash('sha256', json_encode([self::LAYOUT, $inputs], JSON_THROW_ON_ERROR)).'.jpg';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $bytes = $draw();
        $disk->put($path, $bytes);

        return $bytes;
    }
}
