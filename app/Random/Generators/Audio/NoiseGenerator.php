<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * The noise colours — docs/09 §2. The audible twin of the 1/f^β fields in
 * docs/07 §4, one dimension instead of two.
 *
 * A colour is nothing but a spectral slope, and the slopes do not map onto the
 * implementations the way anyone guesses. Differentiating a signal multiplies it
 * by jω, so amplitude scales with f and power with f², and 10·log₁₀(f²) is **six**
 * decibels per octave, not three. Violet is therefore differentiated white and
 * blue is differentiated *pink* — an error that is completely inaudible and
 * completely obvious in a spectrum, which is why every colour here is measured in
 * tests/Unit/AudioSpectrumTest.php rather than trusted.
 *
 * Grey is the interesting one: white shaped by the inverse of the ear's own
 * frequency response, so it measures as a tilted mess and *sounds* flatter than
 * white does. It is the only colour on the list defined by perception rather than
 * by arithmetic.
 *
 * The practical justification for shipping six of these is docs/09 §2: with a
 * filter and a loop they are a focus and sleep sound machine, which is a real
 * reason for somebody to leave the tab open.
 */
final class NoiseGenerator extends AudioGenerator
{
    /**
     * Slope in dB per octave, and what the colour is for.
     *
     * Grey has no entry because it has no single slope by construction; claiming
     * one would be the same false-precision mistake the module exists to avoid.
     */
    public const COLOURS = [
        'white' => ['label' => 'White', 'slope' => 0.0, 'character' => 'Flat. Hiss — harsh, and the reference everything else is measured against.'],
        'pink' => ['label' => 'Pink', 'slope' => -3.0, 'character' => 'Equal energy per octave. The one most people call “natural”: rain, surf, a busy room.'],
        'brown' => ['label' => 'Brown / red', 'slope' => -6.0, 'character' => 'Integrated white — a random walk. Deep rumble, a waterfall heard from indoors.'],
        'blue' => ['label' => 'Blue', 'slope' => 3.0, 'character' => 'Bright and airy. Differentiated pink, not differentiated white.'],
        'violet' => ['label' => 'Violet', 'slope' => 6.0, 'character' => 'Thin and sizzly. Differentiated white; also what tape hiss reduction leaves behind.'],
        'grey' => ['label' => 'Grey', 'slope' => null, 'character' => 'White bent through an inverse equal-loudness curve — perceptually flat rather than numerically flat.'],
    ];

    public const FILTERS = [
        'none' => 'None (hear the colour itself)',
        'lowpass' => 'Low pass (warmer, further away)',
        'highpass' => 'High pass (thinner, airier)',
        'bandpass' => 'Band pass (a narrow window)',
    ];

    public function key(): string
    {
        return 'audio.noise';
    }

    public function name(): string
    {
        return 'Noise Colours';
    }

    public function tagline(): string
    {
        return 'White, pink, brown, blue, violet — and perceptually flat grey.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('colour', 'Colour', array_map(fn (array $c): string => $c['label'], self::COLOURS), default: 'pink', help: 'A colour is a spectral slope. The measured slope is printed under the waveform.')
            ->float('duration', 'Length (seconds)', default: 10.0, min: 2.0, max: 30.0, step: 1.0, help: 'Kept short on purpose — with Loop on, the buffer is crossfaded into itself and plays forever.')
            ->bool('loop', 'Loop', default: true, help: 'Seamless: the tail is crossfaded onto the head, so there is no click at the seam.')
            ->enum('filter', 'Filter', self::FILTERS, default: 'none')
            ->float('cutoff', 'Cutoff (Hz)', default: 1200.0, min: 60.0, max: 16000.0, step: 20.0)
            ->float('resonance', 'Resonance (Q)', default: 0.7, min: 0.4, max: 8.0, step: 0.1, help: 'Above about 4 the filter starts to ring, which turns noise into a pitch.')
            ->float('movement', 'Movement', default: 0.3, min: 0.0, max: 1.0, step: 0.05, help: 'How far the cutoff drifts over the loop. A little of this is the difference between a hiss and something that sounds like weather.');

        return $this->volumeParam($schema);
    }

    /**
     * How many breakpoints the cutoff drift is drawn from.
     *
     * Eight over the whole buffer, whatever its length, so the drift is always
     * slow relative to the loop rather than a tremolo at short durations. The
     * last point is not drawn — it is the first one again, because a loop whose
     * filter jumps at the seam is a loop with a click in it.
     */
    private const SWEEP_POINTS = 8;

    public function generate(Rng $rng, Params $params): Result
    {
        $colour = $params->string('colour', 'pink');
        $duration = $this->quantise($params->float('duration', 10.0));
        $filter = $params->string('filter', 'none');

        // Drawn whether or not the filter is switched on. The alternative is a
        // generator that consumes a different number of bytes depending on a
        // checkbox, which makes two otherwise identical seeds diverge for a
        // reason nobody could see in the receipt.
        $sweep = [];
        for ($i = 0; $i < self::SWEEP_POINTS; $i++) {
            // Octave multipliers, not linear ones: the ear hears cutoff
            // logarithmically, so ±1 octave is a symmetrical wobble and
            // ±1000 Hz is not.
            $sweep[] = round(2 ** ($rng->float() * 2 - 1), 4);
        }

        $score = [
            'algorithm' => 'noise',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 1,
            'colour' => $colour,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'loop' => $params->bool('loop', true),
            'filter' => [
                'type' => $filter,
                'cutoff' => round($params->float('cutoff', 1200.0), 2),
                'q' => round($params->float('resonance', 0.7), 3),
                'movement' => round($params->float('movement', 0.3), 3),
                'sweep' => $sweep,
            ],
            // 20 ms in and out when the buffer is played once. Noise starting at
            // full amplitude on sample zero is a click, and a click is the first
            // thing anyone hears.
            'fade' => 0.02,
        ];

        $slope = self::COLOURS[$colour]['slope'] ?? null;

        return new Result(
            value: $score,
            display: $this->describe($params, $colour, $duration),
            meta: array_filter([
                'colour' => self::COLOURS[$colour]['label'],
                'expected_slope' => $slope === null ? 'perceptual' : sprintf('%+.0f dB/octave', $slope),
                'duration' => sprintf('%.1f s', $duration),
                'samples' => (int) round($duration * self::SAMPLE_RATE),
                'note' => self::COLOURS[$colour]['character'],
            ], fn ($v) => $v !== null),
        );
    }

    private function describe(Params $params, string $colour, float $duration): string
    {
        $slope = self::COLOURS[$colour]['slope'] ?? null;
        $filter = $params->string('filter', 'none');

        return sprintf(
            '%s noise · %s · %.1f s%s%s',
            self::COLOURS[$colour]['label'],
            $slope === null ? 'perceptually flat' : sprintf('%+.0f dB/octave', $slope),
            $duration,
            $filter === 'none' ? '' : sprintf(' · %s %.1f kHz', $filter, $params->float('cutoff', 1200.0) / 1000),
            $params->bool('loop', true) ? ' · looping' : '',
        );
    }
}
