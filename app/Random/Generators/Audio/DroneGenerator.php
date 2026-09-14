<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * A drone — docs/09 §7.
 *
 * One note, held, and nothing happens. What makes it worth listening to is
 * *beating*: two sine waves a fraction of a hertz apart drift in and out of phase
 * with each other, and their sum swells and fades at exactly the difference
 * between them. Two partials at 220.0 and 220.3 Hz pulse three times every ten
 * seconds — a rhythm nobody played, arriving purely from arithmetic.
 *
 * So the beat rate is the control, and the detuning is derived from it rather
 * than the other way round. Asking for "12 cents of detune" gives a beat that
 * speeds up with pitch — twelve cents at 440 Hz beats twice as fast as twelve
 * cents at 220 — which means the one thing the listener can actually hear changes
 * every time they move the root. Asking for "0.4 Hz" and solving for the cents
 * keeps the effect fixed where the ear is, and the solved figure is printed so
 * the relationship is visible.
 *
 * Partials are the harmonic series — f, 2f, 3f — because that is what a bowed
 * string or a pipe produces, and because it keeps the perceived pitch at the
 * root. Ratios like 3/2 would sound just as pleasant and would put the ear's
 * fundamental an octave *below* anything actually present, which is a fine effect
 * and a confusing thing for a tuning display to report.
 */
final class DroneGenerator extends AudioGenerator
{
    /**
     * How many amplitude breakpoints the slow movement is drawn from.
     *
     * Six over the whole piece, whatever its length, so "movement" means the same
     * thing at 20 seconds as at 90 — slow swells rather than a tremolo that gets
     * faster as the drone gets shorter.
     */
    private const MOVEMENT_POINTS = 6;

    public function key(): string
    {
        return 'audio.drone';
    }

    public function name(): string
    {
        return 'Drone';
    }

    public function tagline(): string
    {
        return 'Detuned partials, beating slowly against each other.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('root', 'Root', Scales::ROOT_LABELS, default: 'A')
            ->int('octave', 'Octave', default: 2, min: 1, max: 4, help: 'Low is the point. A drone an octave up stops being a floor and starts being a note.')
            ->int('partials', 'Partials', default: 5, min: 2, max: 10, help: 'Harmonics above the root: f, 2f, 3f and so on. Each one is a pair of sines, slightly apart.')
            ->float('beating', 'Beat rate (Hz)', default: 0.4, min: 0.05, max: 8.0, step: 0.05, help: 'How often each pair swells, in beats per second. Under 1 Hz it breathes; past 4 it turns into a rattle.')
            ->float('movement', 'Movement', default: 0.4, min: 0.0, max: 1.0, step: 0.05, help: 'How far the partials drift in level and the filter in cutoff over the piece. At zero it is completely static, which is its own kind of useful.')
            ->float('tilt', 'Brightness', default: 0.45, min: 0.0, max: 1.0, step: 0.05, help: 'How much of the upper harmonics survives. Dark is a cello section; bright is an organ with every stop out.')
            ->float('spread', 'Stereo spread', default: 0.8, min: 0.0, max: 1.0, step: 0.05, help: 'The two halves of each beating pair are placed on opposite sides, so the swell moves across the image instead of sitting in the middle.')
            ->float('duration', 'Length (seconds)', default: 30.0, min: 8.0, max: 90.0, step: 1.0);

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $root = Scales::rootMidi($params->string('root', 'A'), $params->int('octave', 2));
        $rootHz = $this->frequency($root);

        $count = $params->int('partials', 5);
        $beating = $params->float('beating', 0.4);
        $tilt = $params->float('tilt', 0.45);
        $spread = $params->float('spread', 0.8);
        $movement = $params->float('movement', 0.4);

        $partials = [];

        for ($n = 1; $n <= $count; $n++) {
            $frequency = $rootHz * $n;

            /*
             * Amplitude falling as 1/n^k, with k from the brightness control.
             *
             * A sawtooth is exactly 1/n and sounds like a buzz; 1/n² is a soft
             * triangle-ish tone with almost no top. The control moves between
             * them, which covers the whole useful range with one number and
             * never produces the thing that goes wrong here — an upper partial
             * louder than the fundamental, which stops being a drone on a note
             * and becomes two notes.
             */
            $falloff = 2.2 - 1.2 * $tilt;
            $amplitude = 1 / ($n ** $falloff);

            // Each pair beats a little faster than the last. Identical rates
            // across every partial phase-lock into one big pulse, which is a
            // tremolo; spreading them by a few percent is what makes the
            // movement feel like several things happening at once.
            $rate = $beating * (0.75 + $rng->float() * 0.6) * $n ** 0.25;

            $partials[] = [
                'frequency' => round($frequency, 4),
                // The partner, exactly `rate` hertz above. The beat frequency of
                // two sines is their difference, so this is not an approximation
                // of the requested rate — it is the rate.
                'partner' => round($frequency + $rate, 4),
                'beat_hz' => round($rate, 4),
                'cents' => round(1200 * log(1 + $rate / $frequency, 2), 3),
                'amplitude' => round($amplitude, 5),
                // Opposite sides, alternating by partial, so neighbouring
                // harmonics do not stack up on the same side of the image.
                'pan' => round(($n % 2 === 0 ? -1 : 1) * $spread * (0.4 + $rng->float() * 0.6), 3),
                'levels' => $this->movementCurve($rng, $movement),
                // A random start phase per partial. Every sine starting at zero
                // sums to one large transient on sample zero — a thump at the
                // start of a piece that is supposed to fade in from nothing.
                'phase' => round($rng->float(), 5),
            ];
        }

        $duration = $this->quantise($params->float('duration', 30.0));

        $score = [
            'algorithm' => 'drone',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 2,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'root' => $root,
            'root_hz' => round($rootHz, 3),
            'partials' => $partials,
            'filter' => [
                // A gentle low pass over everything, moving slowly. Without it the
                // top partials sit at a fixed level for a minute and the ear stops
                // hearing them entirely; with it they come and go.
                'cutoff' => round($rootHz * (4 + 24 * $tilt), 2),
                'q' => 0.7,
                'sweep' => $this->movementCurve($rng, $movement),
            ],
            // Four seconds in and out. A drone is a thing that was always there —
            // it should not start, it should be noticed.
            'fade' => 4.0,
        ];

        return new Result(
            value: $score,
            display: sprintf(
                'Drone on %s (%.2f Hz) · %d harmonics · beating at %.2f Hz · %.0f s',
                Scales::noteName($root),
                $rootHz,
                $count,
                $beating,
                $duration,
            ),
            meta: [
                'root' => sprintf('%s · %.2f Hz', Scales::noteName($root), $rootHz),
                'partials' => $count,
                'beat_rates' => array_map(fn (array $p): float => $p['beat_hz'], $partials),
                'detune_cents' => array_map(fn (array $p): float => $p['cents'], $partials),
                'slowest_beat' => sprintf('one swell every %.1f s', 1 / max(0.001, min(array_map(fn (array $p): float => $p['beat_hz'], $partials)))),
                'duration' => sprintf('%.0f s', $duration),
                'note' => 'Each partial is two sines a fraction of a hertz apart. Their sum swells and fades at exactly their difference — the beat rate is not a modulator anyone applied, it is what two close frequencies do. The cents figure is derived from it, not the other way round, so the pulse stays where the ear is whatever note you pick.',
            ],
        );
    }

    /**
     * A slow level curve, as multipliers around 1.
     *
     * Drawn whether or not movement is switched on, so the number of bytes this
     * generator consumes does not depend on a slider — two otherwise identical
     * seeds must not diverge for a reason the receipt cannot show.
     *
     * @return list<float>
     */
    private function movementCurve(Rng $rng, float $movement): array
    {
        $points = [];

        for ($i = 0; $i < self::MOVEMENT_POINTS; $i++) {
            $points[] = round(1 + ($rng->float() * 2 - 1) * $movement * 0.6, 4);
        }

        return $points;
    }

    /** f = 440 · 2^((n − 69)/12) — the PHP side of dsp.js midiToHz. */
    private function frequency(int $midi): float
    {
        return 440 * (2 ** (($midi - 69) / 12));
    }
}
