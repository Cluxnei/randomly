<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Interface sounds, as a pack — docs/09 §7.
 *
 * The most directly useful generator in the module: somebody building an app
 * needs a success chime and an error buzz, and the alternatives are a freesound
 * search with a licence to read or a synthesiser they do not own. A seed, four
 * categories and a WAV download is a shorter path.
 *
 * The pack is one buffer with silence between the sounds rather than several
 * files, because several files would need a server to zip them and could not be
 * auditioned before downloading. The offsets and lengths are in the meta, so
 * cutting them apart is a known operation rather than a guess — and the gap is
 * wide enough that an automatic splitter on silence gets it right too.
 *
 * The categories are not arbitrary. What survives a phone speaker at arm's length
 * is pitch *direction* and length, not timbre: rising and short reads as success
 * in every interface anyone has used, falling and buzzy reads as error, and a
 * single struck bell reads as "look at me" without claiming either. So those are
 * the three shapes, plus the arcade coin, which is a rising pair so fast it
 * registers as one event.
 *
 * Nothing autoplays. Web Audio would not allow it and docs/09 §10 would not
 * either — the first sound anyone hears from this page is one they clicked for.
 */
final class BleepGenerator extends AudioGenerator
{
    public const CATEGORIES = [
        'mixed' => 'Mixed pack (one of each)',
        'success' => 'Success — rising, short, bright',
        'error' => 'Error — falling, buzzy, blunt',
        'notify' => 'Notify — a single struck bell',
        'coin' => 'Coin — the arcade pickup',
    ];

    /** The four sound shapes a mixed pack cycles through. */
    private const KINDS = ['success', 'error', 'notify', 'coin'];

    /**
     * Silence between sounds.
     *
     * Long enough that the tail of a bell has died before the next thing starts,
     * so a splitter cutting on silence finds the right boundaries — and short
     * enough that auditioning eight of them is not a wait.
     */
    private const GAP = 0.45;

    public function key(): string
    {
        return 'audio.bleep';
    }

    public function name(): string
    {
        return 'UI Sounds';
    }

    public function tagline(): string
    {
        return 'Success, error, notify, coin — as a downloadable pack.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('category', 'Category', self::CATEGORIES, default: 'mixed')
            ->int('count', 'Sounds in the pack', default: 6, min: 1, max: 16)
            ->enum('root', 'Key', Scales::ROOT_LABELS, default: 'C', help: 'Interface sounds share a room with each other. Pinning them to one key is why a set feels like a set.')
            ->int('octave', 'Octave', default: 5, min: 3, max: 6, help: 'High is the point: small speakers roll off below about 500 Hz, so a bleep an octave down is a bleep nobody hears.')
            ->float('brightness', 'Brightness', default: 0.5, min: 0.0, max: 1.0, step: 0.05, help: 'Moves the waveform from a pure sine towards a square. Sine is soft and modern; square is a games console.')
            ->float('length', 'Length', default: 0.5, min: 0.2, max: 1.0, step: 0.05, help: 'Scales every sound. Short is confident — an interface sound that outlasts the action it confirms feels slow.');

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $category = $params->string('category', 'mixed');
        $count = $params->int('count', 6);
        $root = Scales::rootMidi($params->string('root', 'C'), $params->int('octave', 5));
        $scale = $params->float('length', 0.5) * 2;

        $sounds = [];
        $at = 0.05;

        for ($i = 0; $i < $count; $i++) {
            // A mixed pack cycles rather than draws, so a pack of four contains
            // one of each. Drawing would routinely give three coins and no error,
            // which is the wrong answer to "a pack of UI sounds".
            $kind = $category === 'mixed' ? self::KINDS[$i % count(self::KINDS)] : $category;

            $sound = $this->sound($rng, $kind, $root, $params->float('brightness', 0.5), $scale);
            $sound['start'] = round($at, 6);
            $sound['index'] = $i + 1;

            $sounds[] = $sound;
            $at += $sound['length'] + self::GAP;
        }

        $duration = $this->quantise($at + 0.2);

        $score = [
            'algorithm' => 'bleep',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 1,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'category' => $category,
            'gap' => self::GAP,
            'sounds' => $sounds,
        ];

        return new Result(
            value: $score,
            display: implode(PHP_EOL, array_map(
                fn (array $s): string => sprintf(
                    '%2d.  %-8s  starts %6.2f s  ·  %4.0f ms  ·  %s',
                    $s['index'],
                    $s['kind'],
                    $s['start'],
                    $s['length'] * 1000,
                    $s['description'],
                ),
                $sounds,
            )),
            meta: [
                'pack' => self::CATEGORIES[$category],
                'sounds' => count($sounds),
                // The cut points, so the one WAV can become n files. A list of
                // scalars, because the meta strip joins those for display and a
                // nested structure would arrive as "Array".
                'starts_seconds' => array_map(fn (array $s): float => $s['start'], $sounds),
                'lengths_ms' => array_map(fn (array $s): int => (int) round($s['length'] * 1000), $sounds),
                'gap' => sprintf('%.2f s of silence between sounds', self::GAP),
                'duration' => sprintf('%.2f s', $duration),
                'key' => Scales::noteName($root),
                'note' => 'One buffer, several sounds, silence in between — download it once and cut at the offsets above. Nothing here plays on its own: the first sound you hear is one you asked for.',
            ],
        );
    }

    /**
     * One sound, as a list of voices.
     *
     * Every category is the same three primitives — a swept oscillator, an FM
     * partial, a band-passed noise burst — with different numbers. There is no
     * code path that only one category takes, which is what keeps the set
     * sounding like a set.
     *
     * @return array<string, mixed>
     */
    private function sound(Rng $rng, string $kind, int $root, float $brightness, float $scale): array
    {
        // Sine below a third of the way, triangle in the middle, square at the
        // top. Three waveforms rather than a continuous morph, because the ear
        // hears these as three instruments and anything in between as one of them
        // slightly wrong.
        $wave = $brightness < 0.34 ? 'sine' : ($brightness < 0.67 ? 'triangle' : 'square');

        return match ($kind) {
            'error' => $this->error($rng, $root, $wave, $scale),
            'notify' => $this->notify($rng, $root, $scale),
            'coin' => $this->coin($rng, $root, $scale),
            default => $this->success($rng, $root, $wave, $scale),
        };
    }

    /** @return array<string, mixed> */
    private function success(Rng $rng, int $root, string $wave, float $scale): array
    {
        // Two or three steps up a major triad. Ascending is the whole message;
        // which intervals barely matter as long as none of them is a semitone.
        $steps = $rng->bool(0.5) ? [0, 4, 7] : [0, 7, 12];
        $steps = $rng->bool(0.35) ? array_slice($steps, 0, 2) : $steps;
        $each = 0.075 * $scale;

        $voices = [];

        foreach ($steps as $i => $semitones) {
            $voices[] = [
                'wave' => $wave,
                'midi' => $root + $semitones,
                'glide' => 0,
                'start' => round($i * $each * 0.8, 6),
                'length' => round($each * ($i === count($steps) - 1 ? 2.2 : 1.0), 6),
                'attack' => 0.002,
                'curve' => 5.0,
                'level' => round(0.55 + $rng->float() * 0.2, 3),
            ];
        }

        return [
            'kind' => 'success',
            'length' => round($each * 0.8 * (count($steps) - 1) + $each * 2.2, 6),
            'description' => count($steps) === 2 ? 'two steps up, the second held' : 'three steps up a triad',
            'voices' => $voices,
        ];
    }

    /** @return array<string, mixed> */
    private function error(Rng $rng, int $root, string $wave, float $scale): array
    {
        $drop = $rng->pick([3, 4, 5]);
        $first = 0.09 * $scale;
        $second = 0.2 * $scale;

        return [
            'kind' => 'error',
            'length' => round($first * 0.9 + $second, 6),
            'description' => sprintf('two notes down %d semitones, with a detuned second', $drop),
            'voices' => [
                [
                    'wave' => $wave,
                    'midi' => $root - 12,
                    'glide' => 0,
                    'start' => 0.0,
                    'length' => round($first, 6),
                    'attack' => 0.002,
                    'curve' => 4.0,
                    'level' => 0.6,
                ],
                [
                    'wave' => $wave,
                    'midi' => $root - 12 - $drop,
                    // A downward glide of a quarter tone under the second note.
                    // Sagging pitch is what makes a sound read as a failure
                    // rather than as a low note.
                    'glide' => -0.5,
                    'start' => round($first * 0.9, 6),
                    'length' => round($second, 6),
                    'attack' => 0.003,
                    'curve' => 3.2,
                    'level' => 0.65,
                ],
                [
                    // A short noise edge under the attack, band-passed low. It is
                    // what separates "a sad note" from "something went wrong".
                    'wave' => 'noise',
                    'midi' => $root - 24,
                    'q' => 1.4,
                    'start' => 0.0,
                    'length' => round($first * 0.6, 6),
                    'attack' => 0.001,
                    'curve' => 9.0,
                    'level' => round(0.2 + $rng->float() * 0.15, 3),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function notify(Rng $rng, int $root, float $scale): array
    {
        $length = 0.45 * $scale;

        // An irrational-ish ratio, because a bell is a struck plate and struck
        // plates are inharmonic — the partials are not whole multiples of the
        // fundamental, which is exactly why a bell sounds like a bell.
        $ratio = $rng->pick([1.41, 2.76, 3.53]);

        return [
            'kind' => 'notify',
            'length' => round($length * 1.15, 6),
            'description' => sprintf('FM bell, ratio %.2f, with a fifth above', $ratio),
            'voices' => [
                [
                    'wave' => 'fm',
                    'midi' => $root,
                    'ratio' => $ratio,
                    'index' => round(2.5 + $rng->float() * 2.5, 3),
                    'start' => 0.0,
                    'length' => round($length, 6),
                    'attack' => 0.003,
                    'curve' => 4.5,
                    'level' => 0.7,
                ],
                [
                    'wave' => 'fm',
                    'midi' => $root + 7,
                    'ratio' => $ratio,
                    'index' => round(1.5 + $rng->float(), 3),
                    // A few milliseconds late and quieter: two bells struck at
                    // exactly the same instant sum into one thicker bell, and the
                    // small offset is what keeps them two.
                    'start' => round(0.012 + $rng->float() * 0.02, 6),
                    'length' => round($length * 1.1, 6),
                    'attack' => 0.004,
                    'curve' => 5.0,
                    'level' => 0.4,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function coin(Rng $rng, int $root, float $scale): array
    {
        // The arcade coin, which is one short note and one held note a fifth or a
        // sixth above, played faster than the ear separates them.
        $leap = $rng->pick([7, 9]);
        $first = 0.05 * $scale;
        $second = 0.28 * $scale;

        return [
            'kind' => 'coin',
            'length' => round($first + $second, 6),
            'description' => sprintf('flick up %d semitones, square, held', $leap),
            'voices' => [
                [
                    'wave' => 'square',
                    'midi' => $root + 5,
                    'glide' => 0,
                    'start' => 0.0,
                    'length' => round($first, 6),
                    'attack' => 0.001,
                    'curve' => 3.0,
                    'level' => 0.5,
                ],
                [
                    'wave' => 'square',
                    'midi' => $root + 5 + $leap,
                    'glide' => 0,
                    'start' => round($first, 6),
                    'length' => round($second, 6),
                    'attack' => 0.001,
                    'curve' => 4.5,
                    'level' => 0.55,
                ],
            ],
        ];
    }
}
