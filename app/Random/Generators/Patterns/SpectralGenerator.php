<?php

declare(strict_types=1);

namespace App\Random\Generators\Patterns;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Noise specified by its spectrum instead of by its construction.
 *
 *     |F(f)| ∝ f^(−β/2),   arg F(f) ~ U(0, 2π)
 *
 * Every other generator in this module builds a field in space and lets whatever
 * spectrum falls out, fall out. This one states the spectrum first — one exponent
 * — fills it with random phase and inverse-transforms. That inversion is the only
 * route to an exact power law, and it is why β is a slider rather than a menu:
 * white, pink, brown and blue are four points on one continuous line, and the
 * interesting values are the ones between them.
 *
 * It is also the clearest cross-module story the project has. `audio.noise`
 * shapes the same 1/f^β spectrum across audible frequency; this shapes it across
 * spatial frequency. The ear's pink noise and the eye's cloud are the same
 * statement made one dimension apart, and the β that sounds balanced is the β
 * that looks like weather.
 */
final class SpectralGenerator extends BaseGenerator
{
    /**
     * The named exponents, with what each one is called where people already have
     * a name for it.
     *
     * @var array<string, array{float, string}> preset => [beta, note]
     */
    private const PRESETS = [
        'custom' => [1.0, ''],
        'white' => [0.0, 'Every spatial frequency at equal power. Static, with no structure at any scale.'],
        'pink' => [1.0, 'Equal power per octave — the exponent almost everything in nature lands near, from coastlines to birdsong.'],
        'brown' => [2.0, 'The spectrum of a random walk. Soft, rolling, cloud-like: each step is small and the errors accumulate.'],
        'black' => [3.0, 'Steeper than brown. Almost all the energy is in the largest features, so the field reads as a few smooth masses.'],
        'blue' => [-1.0, 'Power rising with frequency. The opposite of clouds: detail everywhere, and the large scales cancel out.'],
        'violet' => [-2.0, 'The derivative of white noise. Nothing but the finest detail the raster can hold.'],
    ];

    public function key(): string
    {
        return 'patterns.spectral';
    }

    public function name(): string
    {
        return 'Spectral Noise';
    }

    public function tagline(): string
    {
        return '1/f^β synthesised in the frequency domain.';
    }

    public function module(): Module
    {
        return Module::Patterns;
    }

    public function renderer(): Renderer
    {
        return Renderer::Canvas;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('width', 'Width', default: 900, min: 200, max: 2048)
            ->int('height', 'Height', default: 600, min: 200, max: 2048)
            ->enum('preset', 'Colour of noise', self::presetLabels(), default: 'brown')
            ->float('beta', 'β', default: 1.0, min: -2.5, max: 4.0, step: 0.05, help: 'The exponent itself. 0 is white, 1 pink, 2 brown, −1 blue. Only read when the preset is Custom.')
            ->float('contrast', 'Contrast', default: 2.5, min: 0.6, max: 4.0, step: 0.1, help: 'Where the palette\'s ends are pinned, in standard deviations. A spectral field is Gaussian, so it has tails rather than bounds — stretching to the extremes would leave the whole picture in the middle third of the ramp.')
            ->float('zoom', 'Zoom', default: 1.0, min: 1.0, max: 12.0, step: 0.25, help: 'Magnifies the same field. At 1 you see every octave the raster can hold, which for pink noise is mostly grain; wind it up and the low frequencies take over. A 1/f field is scale-free, so this is a real zoom and not a blur.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->bool('contours', 'Banding', help: 'Quantise into flat bands. On a brown field this draws something very close to a contour map, because that is what a brown field is.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): this is a continuous field and nothing about it is
        // categorical. docs/07 §9.
        $palette = Palette::ramp($rng, $params->string('palette', 'magma'), $params->int('colours', 7));

        $beta = $this->beta($params);

        $spec = [
            'algorithm' => 'spectral',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'beta' => round($beta, 4),
            'zoom' => $params->float('zoom', 1.0),
            'contrast' => $params->float('contrast', 2.5),
            'contours' => $params->bool('contours'),
            'palette' => $palette,
            // One word. The browser needs two Gaussian draws per coefficient and a
            // 1024² transform has a million of them — sixteen megabytes of stream
            // for a field that is fully determined by a single seed.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $beta),
            meta: [
                'palette' => $palette,
                'beta' => round($beta, 3),
                // The slope of log power against log frequency. Stating it as the
                // negative of β rather than as β is the form a measurement comes
                // back in, and scripts/check-spectral-slope.mjs checks the render
                // against exactly this number.
                'spectral_slope' => round(-$beta, 3),
                'colour_name' => $this->colourName($beta),
                'audio_twin' => 'audio.noise',
                'note' => $this->note($params, $beta),
            ],
        );
    }

    /** @return array<string, string> */
    private static function presetLabels(): array
    {
        $labels = [];

        foreach (self::PRESETS as $key => [$beta]) {
            $labels[$key] = $key === 'custom'
                ? 'Custom (use the slider)'
                : sprintf('%s — β = %.0f', ucfirst($key), $beta);
        }

        return $labels;
    }

    /** The preset's exponent, or the slider's when the preset defers to it. */
    private function beta(Params $params): float
    {
        $preset = $params->string('preset', 'brown');

        return $preset === 'custom' || ! isset(self::PRESETS[$preset])
            ? $params->float('beta', 1.0)
            : self::PRESETS[$preset][0];
    }

    /**
     * The nearest named colour, for a β the slider landed between two of them.
     *
     * Half a unit of β is about the point where a field stops reading as one
     * colour and starts reading as the next, so that is the width of the band.
     */
    private function colourName(float $beta): string
    {
        $best = 'custom';
        $distance = INF;

        foreach (self::PRESETS as $name => [$value]) {
            if ($name === 'custom') {
                continue;
            }

            if (abs($value - $beta) < $distance) {
                $distance = abs($value - $beta);
                $best = $name;
            }
        }

        return $distance <= 0.5 ? $best : sprintf('between (β = %.2f)', $beta);
    }

    private function note(Params $params, float $beta): string
    {
        $preset = $params->string('preset', 'brown');
        $known = self::PRESETS[$preset][1] ?? '';

        return trim($known.' Built by filling a 1024×1024 complex spectrum with Gaussian coefficients scaled by f^(-β/2), then inverse-transforming and taking the real part — the same shaping audio.noise applies across audible frequency. One thing the shared name hides: a plane holds more modes at high frequency than a line does, about f of them per unit f, so the power in an octave goes as f^(2-β) rather than f^(1-β). The exponent that puts equal power in every octave is therefore 2 here where it is 1 in audio, which is why brown is the default and why pink reads as grain rather than as weather.');
    }

    private function describe(Params $params, float $beta): string
    {
        return sprintf(
            '%s · β = %.2f · slope %.2f · %d colours%s',
            ucfirst($this->colourName($beta)),
            $beta,
            -$beta,
            $params->int('colours'),
            $params->bool('contours') ? ' · banded' : '',
        );
    }
}
