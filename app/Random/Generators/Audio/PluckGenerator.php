<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Karplus-Strong — docs/09 §6.
 *
 * The best demonstration on this site of structure emerging from randomness, and
 * it is not a metaphor: the string is *made of* noise. Fill a delay line of
 * fs/f samples with white noise, then feed it back through a two-point average —
 *
 *     y[n] = decay · ½ · (y[n−N] + y[n−N−1])
 *
 * — and what comes out is a plucked string. The delay length picks the pitch out
 * of the noise, because only the frequencies whose period divides the loop
 * survive a lap. The averaging is a one-pole low pass, so each lap loses a little
 * more high end, which is exactly what a real string does as its higher partials
 * radiate away first. Two lines of arithmetic, no wavetable, no sample, no model
 * of a string anywhere — and the ear hears a guitar.
 *
 * The random seed of the delay line is why no two notes here are identical, the
 * same way no two plucks of a real string are.
 */
final class PluckGenerator extends AudioGenerator
{
    public function key(): string
    {
        return 'audio.pluck';
    }

    public function name(): string
    {
        return 'Plucked Strings';
    }

    public function tagline(): string
    {
        return 'Karplus–Strong: fill a delay line with pure noise and a guitar comes out.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('scale', 'Scale', Scales::LABELS, default: 'pentatonic_minor')
            ->enum('root', 'Root', Scales::ROOT_LABELS, default: 'A')
            ->int('octave', 'Octave', default: 3, min: 1, max: 5)
            ->int('range', 'Range (octaves)', default: 2, min: 1, max: 3)
            ->int('density', 'Notes per bar', default: 4, min: 1, max: 12)
            ->int('bars', 'Bars', default: 8, min: 1, max: 16)
            ->float('tempo', 'Tempo (BPM)', default: 92.0, min: 50.0, max: 180.0, step: 1.0)
            ->float('sustain', 'Sustain', default: 0.55, min: 0.0, max: 1.0, step: 0.05, help: 'How much of the loop survives each lap. Past about 0.9 the string stops being a string and becomes a drone.')
            ->float('brightness', 'Brightness', default: 0.6, min: 0.0, max: 1.0, step: 0.05, help: 'Where the string is plucked. Near the bridge is bright and thin; over the fretboard is round and dark.')
            ->float('spread', 'Stereo spread', default: 0.7, min: 0.0, max: 1.0, step: 0.05, help: 'How far across the image the notes are placed. Strings recorded close are never all in the same spot.')
            ->float('chords', 'Double stops', default: 0.18, min: 0.0, max: 0.6, step: 0.02, help: 'How often two strings are struck at once.');

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $scale = $params->string('scale', 'pentatonic_minor');
        $root = Scales::rootMidi($params->string('root', 'A'), $params->int('octave', 3));
        $notes = Scales::notes($root, $scale, $root, $root + 12 * $params->int('range', 2));

        $tempo = $params->float('tempo', 92.0);
        $bars = $params->int('bars', 8);
        $bar = $this->barSeconds($tempo);
        $density = $params->int('density', 4);
        $step = $bar / $density;

        /*
         * Feedback per lap, from the friendly 0..1 control.
         *
         * The usable window is narrow and very non-linear: 0.98 is a muted thud,
         * 0.999 rings for the best part of a minute, and everything interesting
         * is in between. Exposing the coefficient itself would be a slider where
         * the last two percent of travel is the entire range of the instrument.
         */
        $decay = 0.985 + $params->float('sustain', 0.55) * 0.0145;

        $spread = $params->float('spread', 0.7);
        $chordChance = $params->float('chords', 0.18);

        $events = [];

        for ($i = 0; $i < $bars * $density; $i++) {
            $index = $rng->intBetween(0, count($notes) - 1);
            $start = round($i * $step, 6);
            $pan = round(($rng->float() * 2 - 1) * $spread, 3);

            $events[] = $this->note($rng, $notes[$index], $start, $decay, $pan);

            // A double stop is a second string struck a scale step or two away
            // and a few milliseconds later — nobody's fingers land simultaneously,
            // and the small offset is most of what makes it sound played.
            if ($rng->bool($chordChance)) {
                $other = max(0, min(count($notes) - 1, $index + $rng->pick([-3, -2, 2, 3])));
                $events[] = $this->note($rng, $notes[$other], round($start + 0.012 + $rng->float() * 0.02, 6), $decay, round(-$pan * 0.6, 3));
            }
        }

        // Long enough for the last note to ring out rather than being cut off.
        // Held at four seconds because that is where the slowest decay here is
        // inaudible, and a longer tail is silence in the WAV.
        $duration = $this->quantise($bars * $bar + 4.0);

        $score = [
            'algorithm' => 'pluck',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 2,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'scale' => $scale,
            'root' => $root,
            'tempo' => round($tempo, 2),
            'brightness' => round($params->float('brightness', 0.6), 3),
            'notes' => $events,
        ];

        return new Result(
            value: $score,
            display: $this->describe($params, $scale, $root, count($events), $tempo),
            meta: [
                'scale' => Scales::label($scale).' on '.Scales::noteName($root),
                'notes' => count($events),
                'decay_per_lap' => round($decay, 5),
                'pitch_span' => sprintf('%s – %s', Scales::noteName(min(array_column($events, 'midi'))), Scales::noteName(max(array_column($events, 'midi')))),
                'duration' => sprintf('%.1f s', $duration),
                'note' => 'Every note starts as a delay line full of white noise. The length of the line picks the pitch out of it, and a two-point average takes the brightness off a little more on each lap — which is what a real string does.',
            ],
        );
    }

    private function note(Rng $rng, int $midi, float $start, float $decay, float $pan): array
    {
        return [
            'midi' => $midi,
            'start' => $start,
            // Per-note variation in the feedback, because no two plucks of the
            // same string decay identically and a fixed coefficient makes a
            // sequence sound sequenced.
            'decay' => round(min(0.9998, $decay * (0.997 + $rng->float() * 0.004)), 6),
            'velocity' => round(0.5 + $rng->float() * 0.5, 3),
            'pan' => $pan,
        ];
    }

    private function describe(Params $params, string $scale, int $root, int $notes, float $tempo): string
    {
        return sprintf(
            'Karplus–Strong · %s · %d notes over %d bars at %.0f BPM · sustain %.2f · spread %.2f',
            Scales::label($scale).' on '.Scales::noteName($root),
            $notes,
            $params->int('bars', 8),
            $tempo,
            $params->float('sustain', 0.55),
            $params->float('spread', 0.7),
        );
    }
}
