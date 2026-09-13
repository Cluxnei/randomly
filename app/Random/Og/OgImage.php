<?php

declare(strict_types=1);

namespace App\Random\Og;

use App\Random\Palette\Oklch;
use GdImage;

/**
 * The share card, drawn with GD.
 *
 * What this deliberately does *not* do is redraw a generator's artwork. Every
 * canvas renderer in this project lives in JavaScript and only in JavaScript —
 * a second implementation in PHP would be a second implementation, and the
 * first time one of them changed the picture in the preview would stop being
 * the picture on the page. So the card is typographic: the generator's real
 * `display` string, the real receipt sentence, and the real palette if the
 * result had one. Everything on it came off the result it claims to preview.
 */
final class OgImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * Bump when the layout changes.
     *
     * Folded into the fingerprint that names each cached file, so a redesign
     * invalidates the whole directory rather than leaving old cards to be served
     * forever beside new ones.
     */
    public const VERSION = 1;

    private const MARGIN = 64;

    private const HEADER = 104;

    private const FOOTER = 106;

    /** Largest first: the title takes the biggest size it fits in. */
    private const TITLE_SIZES = [72, 58, 48, 40, 33, 27, 22, 18];

    private const NARRATIVE_SIZE = 19;

    /** @var array<string, int> */
    private array $colours = [];

    public function __construct(private readonly string $fontDirectory) {}

    /**
     * The path to this card's PNG, rendering it first if it is not on disk.
     *
     * Content-addressed, so the same card is only ever drawn once and a changed
     * card is a different file rather than a stale one.
     */
    public function path(OgCard $card): string
    {
        $directory = storage_path('app/og');
        $path = $directory.'/'.$card->fingerprint().'.png';

        if (is_file($path)) {
            return $path;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        // Written under a unique name and moved into place, because two crawlers
        // hitting a cold card at once would otherwise interleave their bytes into
        // one corrupt file.
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        file_put_contents($temporary, $this->render($card));
        rename($temporary, $path);

        return $path;
    }

    public function render(OgCard $card): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagealphablending($image, true);
        imageantialias($image, true);

        $this->colours = [];

        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $this->colour($image, 'ground'));

        $this->drawGrid($image);
        $this->drawHeader($image, $card);
        $this->drawFooter($image, $card);

        // Bottom-up: the narrative and the palette claim the space they need and
        // the title takes whatever is left, which is what lets a six-number
        // lottery draw be enormous and a page of quadratics still fit.
        $floor = self::HEIGHT - self::FOOTER;
        $floor = $this->drawPalette($image, $card, $floor);
        $floor = $this->drawNarrative($image, $card, $floor);

        $this->drawTitle($image, $card, self::HEADER, $floor);

        ob_start();
        imagepng($image, null, 6);

        // No imagedestroy(): a GdImage is garbage-collected like any other object
        // since PHP 8.0, and the call has been deprecated as of 8.5.
        return (string) ob_get_clean();
    }

    /**
     * A faint measuring grid.
     *
     * The brand's reference points are an oscilloscope and a seismograph
     * (docs/10 §2), and a plain dark rectangle reads as neither. Drawn at low
     * alpha so it is texture at full size and invisible in a timeline thumbnail.
     */
    private function drawGrid(GdImage $image): void
    {
        $faint = $this->alpha($image, 'line', 104);

        for ($x = 0; $x < self::WIDTH; $x += 40) {
            imageline($image, $x, 0, $x, self::HEIGHT, $faint);
        }

        for ($y = 0; $y < self::HEIGHT; $y += 40) {
            imageline($image, 0, $y, self::WIDTH, $y, $faint);
        }
    }

    private function drawHeader(GdImage $image, OgCard $card): void
    {
        $baseline = 62;

        $wordmark = $this->text($image, 'Randomly', 27, self::MARGIN, $baseline, 'text', bold: true);
        $this->text($image, '.', 27, self::MARGIN + $wordmark, $baseline, 'signal', bold: true);

        if ($card->badge !== null) {
            $width = $this->width($card->badge, 16);
            $x = self::WIDTH - self::MARGIN - $width;

            $this->text($image, $card->badge, 16, $x, $baseline - 2, $card->tone);

            // The status dot from the site's own cards, same colour rule: green
            // when the draw was clean, amber when the receipt says degraded. A
            // square rather than a circle: at eight pixels GD's antialiasing
            // turns a circle into a visible diamond, and the grid behind it makes
            // a square look intentional anyway.
            imagefilledrectangle($image, $x - 22, $baseline - 12, $x - 14, $baseline - 4, $this->colour($image, $card->tone));
        }

        imageline($image, 0, self::HEADER, self::WIDTH, self::HEADER, $this->colour($image, 'line'));
    }

    private function drawFooter(GdImage $image, OgCard $card): void
    {
        $top = self::HEIGHT - self::FOOTER;
        $baseline = $top + 60;

        imageline($image, 0, $top, self::WIDTH, $top, $this->colour($image, 'line'));

        if ($card->footerLeft !== null) {
            $this->text($image, $this->clip($card->footerLeft, 17, 500), 17, self::MARGIN, $baseline, 'muted');
        }

        if ($card->footerRight !== null) {
            $right = $this->clip($card->footerRight, 17, 570);

            $this->text($image, $right, 17, self::WIDTH - self::MARGIN - $this->width($right, 17), $baseline, 'signal');
        }
    }

    /**
     * The result's own colours, as a strip.
     *
     * Only visual generators report a palette, and when one does it is the most
     * honest decoration available: it is literally part of the output being
     * previewed rather than a designer's idea of what the output looks like.
     *
     * @return int the new floor for everything above it
     */
    private function drawPalette(GdImage $image, OgCard $card, int $floor): int
    {
        if ($card->palette === []) {
            return $floor;
        }

        $height = 16;
        $top = $floor - 40 - $height;
        $width = self::WIDTH - 2 * self::MARGIN;
        $count = count($card->palette);

        foreach (array_values($card->palette) as $i => $hex) {
            $left = self::MARGIN + (int) round($i * $width / $count);
            $right = self::MARGIN + (int) round(($i + 1) * $width / $count) - 1;

            imagefilledrectangle($image, $left, $top, $right, $top + $height, $this->hex($image, $hex));
        }

        return $top;
    }

    /** @return int the new floor for the title above it */
    private function drawNarrative(GdImage $image, OgCard $card, int $floor): int
    {
        if ($card->narrative === null || $card->narrative === '') {
            return $floor;
        }

        $lineHeight = (int) round(self::NARRATIVE_SIZE * 1.55);
        $lines = $this->fit([$card->narrative], self::NARRATIVE_SIZE, 3);
        $top = $floor - 34 - count($lines) * $lineHeight;

        foreach ($lines as $i => $line) {
            $this->text($image, $line, self::NARRATIVE_SIZE, self::MARGIN, $top + ($i + 1) * $lineHeight - 6, 'muted');
        }

        return $top;
    }

    /**
     * The result itself, as large as it goes.
     *
     * Sizes are tried largest first and the first one whose wrapped text fits the
     * box wins, so the card scales itself to the generator rather than to a size
     * someone picked once while looking at a lottery draw. Displays arrive as
     * anything from six numbers to a page of Latin.
     */
    private function drawTitle(GdImage $image, OgCard $card, int $ceiling, int $floor): void
    {
        $available = $floor - $ceiling - 56;
        $source = $this->sourceLines($card->title);

        foreach (self::TITLE_SIZES as $size) {
            $lineHeight = (int) round($size * 1.42);
            $maximum = max(1, min(6, intdiv($available, $lineHeight)));
            $lines = $this->fit($source, $size, $maximum);
            $last = $size === self::TITLE_SIZES[count(self::TITLE_SIZES) - 1];

            if (! $last && $this->wraps($source, $size) > $maximum) {
                continue;
            }

            // Centred in the space left over, so a short result sits in the middle
            // of the card and a long one fills it. Both read as deliberate.
            $top = $ceiling + (int) round(($available + 56 - count($lines) * $lineHeight) / 2);

            foreach ($lines as $i => $line) {
                $this->text($image, $line, $size, self::MARGIN, $top + ($i + 1) * $lineHeight - (int) round($size * 0.32), 'text', bold: true);
            }

            return;
        }
    }

    /**
     * Split a display string into the lines its generator meant.
     *
     * Several generators hand back a numbered list — one problem per line — and
     * reflowing that into a paragraph would destroy the only structure the result
     * has. Blank lines go: they are vertical rhythm on a page and wasted card.
     *
     * @return list<string>
     */
    private function sourceLines(string $display): array
    {
        $lines = array_map(trim(...), preg_split('/\R/', $display) ?: []);

        return array_values(array_filter($lines, fn (string $line): bool => $line !== '')) ?: [' '];
    }

    /** How many lines this text would take at this size, unlimited. */
    private function wraps(array $source, float $size): int
    {
        return count($this->fit($source, $size, PHP_INT_MAX));
    }

    /**
     * Wrap to the content width, then cut to `$maximum` lines with an ellipsis.
     *
     * The ellipsis is the honest part: a card that ran out of room should say so
     * rather than end mid-sentence and look like a truncation bug.
     *
     * @param  list<string>  $source
     * @return list<string>
     */
    private function fit(array $source, float $size, int $maximum): array
    {
        $columns = max(8, (int) floor((self::WIDTH - 2 * self::MARGIN) / $this->advance($size)));
        $lines = [];

        foreach ($source as $line) {
            foreach ($this->wrapOne($line, $columns) as $wrapped) {
                $lines[] = $wrapped;

                if (count($lines) > $maximum) {
                    break 2;
                }
            }
        }

        if (count($lines) <= $maximum) {
            return $lines;
        }

        $lines = array_slice($lines, 0, $maximum);
        $last = rtrim($lines[$maximum - 1]);

        $lines[$maximum - 1] = mb_strlen($last) >= $columns
            ? mb_substr($last, 0, $columns - 1).'…'
            : $last.'…';

        return $lines;
    }

    /** @return list<string> */
    private function wrapOne(string $line, int $columns): array
    {
        if (mb_strlen($line) <= $columns) {
            return [$line];
        }

        // wordwrap() is byte-based and these strings carry °, × and em dashes, so
        // it would cut a multibyte character in half. Words are split by hand.
        $lines = [];
        $current = '';

        foreach (preg_split('/(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if (mb_strlen($current.$piece) <= $columns) {
                $current .= $piece;

                continue;
            }

            if (trim($current) !== '') {
                $lines[] = rtrim($current);
            }

            $current = ltrim($piece);

            // A single token longer than the line — a hash, a long URL — is cut
            // rather than allowed to run off the edge of the image.
            while (mb_strlen($current) > $columns) {
                $lines[] = mb_substr($current, 0, $columns);
                $current = mb_substr($current, $columns);
            }
        }

        if (trim($current) !== '') {
            $lines[] = rtrim($current);
        }

        return $lines === [] ? [''] : $lines;
    }

    /** Cut a single line to a pixel width, with an ellipsis if anything went. */
    private function clip(string $text, float $size, int $pixels): string
    {
        $columns = max(4, (int) floor($pixels / $this->advance($size)));

        return mb_strlen($text) <= $columns ? $text : mb_substr($text, 0, $columns - 1).'…';
    }

    private function text(GdImage $image, string $text, float $size, int $x, int $baseline, string $colour, bool $bold = false): int
    {
        $box = imagettftext($image, $size, 0, $x, $baseline, $this->colour($image, $colour), $this->font($bold), $text);

        return is_array($box) ? $box[2] - $box[0] : 0;
    }

    private function width(string $text, float $size, bool $bold = false): int
    {
        $box = imagettfbbox($size, 0, $this->font($bold), $text);

        return is_array($box) ? $box[2] - $box[0] : 0;
    }

    /**
     * The width of one character.
     *
     * Both faces are monospaced, so this is exact for every glyph and wrapping
     * becomes arithmetic instead of a measurement per candidate line — which
     * matters, because the title is laid out up to seven times while it looks for
     * a size that fits.
     */
    private function advance(float $size): float
    {
        return $this->width(str_repeat('M', 20), $size, bold: true) / 20;
    }

    private function font(bool $bold): string
    {
        return $this->fontDirectory.'/JetBrainsMono-'.($bold ? 'Bold' : 'Regular').'.ttf';
    }

    /**
     * The brand palette, computed from the OKLCH values in resources/css/app.css.
     *
     * Kept as the same three numbers per colour rather than hex copies, so the
     * card and the site cannot drift apart silently — and it is the project's own
     * colour engine doing the conversion, which is a reasonable thing for the
     * project to be able to claim.
     */
    private function colour(GdImage $image, string $name): int
    {
        return $this->colours[$name] ??= $this->hex($image, match ($name) {
            'ground' => Oklch::toHex(0.15, 0.01, 260),
            'surface' => Oklch::toHex(0.20, 0.015, 260),
            'line' => Oklch::toHex(0.30, 0.02, 260),
            'text' => Oklch::toHex(0.96, 0.005, 260),
            'muted' => Oklch::toHex(0.65, 0.015, 260),
            'signal' => Oklch::toHex(0.78, 0.19, 145),
            'warn' => Oklch::toHex(0.75, 0.17, 65),
            default => Oklch::toHex(0.96, 0.005, 260),
        });
    }

    private function alpha(GdImage $image, string $name, int $alpha): int
    {
        $rgb = imagecolorsforindex($image, $this->colour($image, $name));

        return (int) imagecolorallocatealpha($image, $rgb['red'], $rgb['green'], $rgb['blue'], $alpha);
    }

    private function hex(GdImage $image, string $hex): int
    {
        $clean = ltrim($hex, '#');

        if (strlen($clean) !== 6 || ! ctype_xdigit($clean)) {
            $clean = '000000';
        }

        return (int) imagecolorallocate(
            $image,
            (int) hexdec(substr($clean, 0, 2)),
            (int) hexdec(substr($clean, 2, 2)),
            (int) hexdec(substr($clean, 4, 2)),
        );
    }
}
