<?php

declare(strict_types=1);

namespace App\Random\Palette;

use App\Random\Rng\Rng;

/**
 * Colour for every visual generator.
 *
 * Shared rather than per-generator because the palette is the site's decoration:
 * generated output supplies all the colour on screen, so it has to be consistently
 * good regardless of which generator produced it.
 */
final class Palette
{
    public const STRATEGIES = [
        'golden' => 'Golden angle',
        'analogous' => 'Analogous',
        'complementary' => 'Complementary',
        'triadic' => 'Triadic',
        'split' => 'Split complementary',
        'viridis' => 'Viridis (perceptually uniform)',
        'magma' => 'Magma (perceptually uniform)',
        'mono' => 'Monochrome',
    ];

    /**
     * Curated ramps, sampled from the matplotlib originals.
     *
     * These are perceptually uniform and colour-blind safe, which random hue
     * selection cannot promise. Offered alongside the generated strategies so a
     * user who needs a defensible colour scale has one.
     */
    private const RAMPS = [
        'viridis' => ['#440154', '#414487', '#2a788e', '#22a884', '#7ad151', '#fde725'],
        'magma' => ['#000004', '#3b0f70', '#8c2981', '#de4968', '#fe9f6d', '#fcfdbf'],
    ];

    /**
     * A *sequential* ramp, for colouring a continuous field.
     *
     * This is a different job from build() and the distinction matters more than
     * it looks. build() returns categorical colours — maximally distinguishable,
     * for marking unrelated things. A noise field is a height map, and colouring
     * one with categorical colours interpolates between hues 222° apart, which
     * passes through mud on every step. A ramp instead moves monotonically in
     * lightness across a narrow arc of hue, so every interpolated value between
     * two stops is a colour someone would have chosen on purpose.
     *
     * Chroma follows an arc rather than a constant: pinned low at both ends and
     * fullest in the middle, the shape viridis and magma both use. It keeps the
     * dark end from turning to soot and the light end from glowing.
     *
     * @return list<string> hex colours, dark to light
     */
    public static function ramp(Rng $rng, string $strategy, int $count): array
    {
        if (isset(self::RAMPS[$strategy])) {
            return self::resample(self::RAMPS[$strategy], $count);
        }

        $baseHue = $rng->float() * 360;
        $chroma = 0.11 + $rng->float() * 0.09;

        // How far the hue is allowed to travel from one end of the ramp to the
        // other. A complementary ramp crossing 180° is legible here precisely
        // because lightness is doing the ordering.
        $arc = match ($strategy) {
            'mono' => 0.0,
            'analogous' => 40.0,
            'triadic' => 120.0,
            'split' => 150.0,
            'complementary' => 180.0,
            default => 70.0 + $rng->float() * 50.0,
        } * ($rng->bool() ? 1 : -1);

        $colours = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $count === 1 ? 0.5 : $i / ($count - 1);

            $colours[] = Oklch::toHex(
                0.16 + $t * 0.76,
                $chroma * (0.35 + 0.65 * sin($t * M_PI)),
                fmod($baseHue + $t * $arc + 360, 360),
            );
        }

        return $colours;
    }

    /**
     * Categorical colours, for marking things that are unrelated to each other.
     *
     * @return list<string> hex colours
     */
    public static function build(Rng $rng, string $strategy, int $count): array
    {
        if (isset(self::RAMPS[$strategy])) {
            return self::resample(self::RAMPS[$strategy], $count);
        }

        $baseHue = $rng->float() * 360;
        $lightness = 0.55 + $rng->float() * 0.25;
        $chroma = 0.10 + $rng->float() * 0.12;

        return match ($strategy) {
            'analogous' => self::spread($baseHue, 60, $lightness, $chroma, $count, $rng),
            'complementary' => self::fromHues($rng, [$baseHue, $baseHue + 180], $lightness, $chroma, $count),
            'triadic' => self::fromHues($rng, [$baseHue, $baseHue + 120, $baseHue + 240], $lightness, $chroma, $count),
            'split' => self::fromHues($rng, [$baseHue, $baseHue + 150, $baseHue + 210], $lightness, $chroma, $count),
            'mono' => self::mono($baseHue, $chroma, $count),
            default => self::golden($baseHue, $lightness, $chroma, $count, $rng),
        };
    }

    /**
     * Advance the hue by the golden angle each step.
     *
     * 0.6180339887… is irrational, so successive hues never fall into a repeating
     * cycle and never cluster, however many colours you ask for. Any rational step
     * eventually revisits its own spacing and the palette starts to look striped.
     */
    private static function golden(float $baseHue, float $lightness, float $chroma, int $count, Rng $rng): array
    {
        $colours = [];
        $hue = $baseHue / 360;

        for ($i = 0; $i < $count; $i++) {
            $hue = fmod($hue + 0.618033988749895, 1.0);
            // Jitter lightness a little so the ramp has depth rather than reading
            // as one lightness in several hues.
            $colours[] = Oklch::toHex(
                max(0.25, min(0.9, $lightness + ($rng->float() - 0.5) * 0.22)),
                $chroma,
                $hue * 360,
            );
        }

        return $colours;
    }

    private static function spread(float $baseHue, float $arc, float $lightness, float $chroma, int $count, Rng $rng): array
    {
        $colours = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $count === 1 ? 0.5 : $i / ($count - 1);
            $colours[] = Oklch::toHex(
                max(0.25, min(0.9, $lightness - 0.18 + $t * 0.36)),
                $chroma,
                $baseHue - $arc / 2 + $t * $arc,
            );
        }

        return $colours;
    }

    private static function fromHues(Rng $rng, array $hues, float $lightness, float $chroma, int $count): array
    {
        $colours = [];

        for ($i = 0; $i < $count; $i++) {
            $hue = $hues[$i % count($hues)];
            $step = intdiv($i, count($hues));

            $colours[] = Oklch::toHex(
                max(0.22, min(0.92, $lightness + ($step - 1) * 0.16 + ($rng->float() - 0.5) * 0.06)),
                $chroma,
                fmod($hue + 360, 360),
            );
        }

        return $colours;
    }

    private static function mono(float $hue, float $chroma, int $count): array
    {
        $colours = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $count === 1 ? 0.5 : $i / ($count - 1);
            $colours[] = Oklch::toHex(0.18 + $t * 0.72, $chroma * (0.4 + $t * 0.6), $hue);
        }

        return $colours;
    }

    /** Linear interpolation along a curated ramp, in sRGB space. */
    private static function resample(array $ramp, int $count): array
    {
        if ($count === 1) {
            return [$ramp[intdiv(count($ramp), 2)]];
        }

        $out = [];
        $last = count($ramp) - 1;

        for ($i = 0; $i < $count; $i++) {
            $position = ($i / ($count - 1)) * $last;
            $lower = (int) floor($position);
            $upper = min($last, $lower + 1);
            $t = $position - $lower;

            $out[] = self::mix($ramp[$lower], $ramp[$upper], $t);
        }

        return $out;
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $channels = [];

        for ($i = 1; $i <= 5; $i += 2) {
            $channels[] = (int) round(
                hexdec(substr($a, $i, 2)) * (1 - $t) + hexdec(substr($b, $i, 2)) * $t
            );
        }

        return sprintf('#%02x%02x%02x', ...$channels);
    }
}
