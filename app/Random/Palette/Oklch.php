<?php

declare(strict_types=1);

namespace App\Random\Palette;

/**
 * OKLCH to sRGB.
 *
 * Generated colour is built in OKLCH rather than HSL because HSL lies about
 * lightness: hsl(60 100% 50%) and hsl(240 100% 50%) claim to be equally light and
 * are nothing of the sort. Random hues in HSL therefore produce palettes that
 * flicker between blinding and muddy, while the same draw in OKLCH holds a steady
 * perceived lightness — which is the difference between output that looks designed
 * and output that looks generated.
 */
final class Oklch
{
    /**
     * @param  float  $l  Perceptual lightness, 0-1
     * @param  float  $c  Chroma, 0 to roughly 0.37
     * @param  float  $h  Hue in degrees
     */
    public static function toHex(float $l, float $c, float $h): string
    {
        [$r, $g, $b] = self::toSrgb($l, self::fitChroma($l, $c, $h), $h);

        return sprintf('#%02x%02x%02x',
            (int) round(max(0, min(1, $r)) * 255),
            (int) round(max(0, min(1, $g)) * 255),
            (int) round(max(0, min(1, $b)) * 255),
        );
    }

    /**
     * Walk chroma down until the colour is actually representable in sRGB.
     *
     * Most of the OKLCH space has no sRGB equivalent, and naively clipping the
     * channels shifts the hue — a vivid orange clips into a flat red. Reducing
     * chroma instead keeps the hue and lightness the design asked for and gives up
     * only the saturation the monitor could never have shown.
     */
    public static function fitChroma(float $l, float $c, float $h): float
    {
        while ($c > 0 && ! self::inGamut(...self::toSrgb($l, $c, $h))) {
            $c -= 0.005;
        }

        return max(0, $c);
    }

    /** @return array{float, float, float} */
    public static function toSrgb(float $l, float $c, float $h): array
    {
        $rad = deg2rad($h);
        $a = $c * cos($rad);
        $bb = $c * sin($rad);

        // Oklab to LMS, cubed back out of the cube-root space Oklab works in.
        $lCube = ($l + 0.3963377774 * $a + 0.2158037573 * $bb) ** 3;
        $mCube = ($l - 0.1055613458 * $a - 0.0638541728 * $bb) ** 3;
        $sCube = ($l - 0.0894841775 * $a - 1.2914855480 * $bb) ** 3;

        return [
            self::gamma(4.0767416621 * $lCube - 3.3077115913 * $mCube + 0.2309699292 * $sCube),
            self::gamma(-1.2684380046 * $lCube + 2.6097574011 * $mCube - 0.3413193965 * $sCube),
            self::gamma(-0.0041960863 * $lCube - 0.7034186147 * $mCube + 1.7076147010 * $sCube),
        ];
    }

    private static function inGamut(float $r, float $g, float $b): bool
    {
        $epsilon = 0.0001;

        return $r >= -$epsilon && $r <= 1 + $epsilon
            && $g >= -$epsilon && $g <= 1 + $epsilon
            && $b >= -$epsilon && $b <= 1 + $epsilon;
    }

    private static function gamma(float $x): float
    {
        return $x <= 0.0031308
            ? 12.92 * $x
            : 1.055 * ($x ** (1 / 2.4)) - 0.055;
    }

    /**
     * WCAG relative luminance, for checking a generated palette is usable rather
     * than merely pretty.
     */
    public static function contrastRatio(string $hexA, string $hexB): float
    {
        $luminance = static function (string $hex): float {
            [$r, $g, $b] = array_map(
                fn (int $channel): float => ($v = $channel / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4,
                [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))],
            );

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };

        $a = $luminance($hexA);
        $b = $luminance($hexB);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
