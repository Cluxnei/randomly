<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Melody — docs/09 §3 and §4.
 *
 * Four ways of choosing the next note, and switching between them is the whole
 * lesson. Uniform is aimless; a bounded walk has contour; a Markov chain has
 * phrasing borrowed from real tunes; 1/f sits between the two extremes, which
 * Voss found is where actual music sits.
 *
 * The scale defaults to major pentatonic, and that single default carries more
 * weight than any of the algorithms. A pentatonic scale contains no semitone and
 * no tritone, so there is no interval in it that can clash — uniformly random
 * notes on it sound musical to almost everybody. It is the difference between
 * "random noise" and "oh, that's nice", and it costs one line.
 */
final class MelodyGenerator extends AudioGenerator
{
    public const METHODS = [
        'walk' => 'Bounded random walk (contour)',
        'uniform' => 'Uniform (aimless, on purpose)',
        'markov' => 'Markov chain over folk tunes',
        'voss' => '1/f — the Voss melody',
    ];

    public const ENGINES = [
        'fm' => 'FM (bells, electric piano)',
        'additive' => 'Additive (organ, glassy)',
        'subtractive' => 'Subtractive (classic synth)',
        'sine' => 'Sine (the shape of the notes, and nothing else)',
    ];

    public const RATES = [
        '0.5' => 'Half notes',
        '1' => 'Quarter notes',
        '2' => 'Eighth notes',
        '4' => 'Sixteenth notes',
    ];

    /**
     * Melodic contour as a weight table — docs/09 §4.1.
     *
     * Small steps dominate and leaps are rare, which is not a stylistic
     * preference but a description of how melodies across essentially every
     * tradition actually move. A uniform step distribution over the same scale
     * sounds like an exercise; this sounds like a tune.
     */
    private const STEP_WEIGHTS = [-4 => 0.1, -2 => 0.1, -1 => 0.25, 0 => 0.1, 1 => 0.25, 2 => 0.1, 4 => 0.1];

    /**
     * Folk melodies reduced to scale degrees, 0 being the tonic.
     *
     * Degrees rather than notes, so one corpus trains a chain for any key and any
     * scale. On a seven-note scale these come back close to the original tunes;
     * on a pentatonic the intervals compress but the *contour* survives, which is
     * the part the chain is actually learning. Negative degrees are below the
     * tonic and index down the note list exactly as positive ones index up.
     */
    private const CORPUS = [
        // Twinkle, Twinkle
        [0, 0, 4, 4, 5, 5, 4, 3, 3, 2, 2, 1, 1, 0, 4, 4, 3, 3, 2, 2, 1, 4, 4, 3, 3, 2, 2, 1, 0, 0, 4, 4, 5, 5, 4, 3, 3, 2, 2, 1, 1, 0],
        // Ode to Joy
        [2, 2, 3, 4, 4, 3, 2, 1, 0, 0, 1, 2, 2, 1, 1, 2, 2, 3, 4, 4, 3, 2, 1, 0, 0, 1, 2, 1, 0, 0],
        // Amazing Grace
        [-3, 0, 2, 0, 2, 1, 0, -2, -3, 0, 2, 0, 2, 1, 4, 4, 2, 4, 5, 4, 2, 0, 2, 0, -3, 0, 2, 0, 2, 1, 0],
        // Scarborough Fair
        [0, 0, 4, 4, 5, 4, 2, 0, -3, 0, 2, 0, 4, 4, 3, 2, 1, 2, 0, -3, -1, 0, 2, 1, 0],
        // Drunken Sailor
        [4, 4, 4, 4, 2, 4, 5, 4, 2, 0, -3, 0, 2, 2, 2, 2, 0, 2, 3, 2, 0, -3, -1, 0],
        // Shenandoah
        [0, 2, 4, 4, 3, 2, 0, -3, 0, 2, 4, 2, 0, 4, 5, 6, 5, 4, 2, 0, -1, 0],
        // Greensleeves
        [0, 2, 3, 4, 5, 4, 2, -1, 0, 2, 0, -1, -3, 0, 2, 3, 4, 5, 4, 2, -1, 0, 2, 1, -1, 0],
        // Auld Lang Syne
        [-3, 0, 0, 0, 2, 1, 0, 1, 2, 1, 0, 0, 2, 4, 4, 4, 2, 0, 0, 2, 1, 0, 1, 2, 1, -1, -3, 0],
    ];

    public function key(): string
    {
        return 'audio.melody';
    }

    public function name(): string
    {
        return 'Melody';
    }

    public function tagline(): string
    {
        return 'Random walk, Markov, or the 1/f kind that sounds written — on a scale that cannot clash.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('scale', 'Scale', Scales::LABELS, default: 'pentatonic_major', help: 'The one control that decides whether random notes sound musical. Pentatonic has no semitone in it to clash with.')
            ->enum('root', 'Root', Scales::ROOT_LABELS, default: 'C')
            ->int('octave', 'Octave', default: 4, min: 2, max: 5, help: 'Octave 4 is middle C.')
            ->int('range', 'Range (octaves)', default: 2, min: 1, max: 3)
            ->enum('method', 'Method', self::METHODS, default: 'walk', help: 'Switch between these on the same seed. Uniform is aimless, the walk has contour, Markov has phrasing, 1/f sits where real music sits.')
            ->int('order', 'Markov order', default: 2, min: 1, max: 2, help: 'Only used by the Markov method. Order 1 is a word salad of intervals; order 2 starts quoting whole phrases.')
            ->int('bars', 'Bars', default: 8, min: 1, max: 16)
            ->float('tempo', 'Tempo (BPM)', default: 100.0, min: 50.0, max: 180.0, step: 1.0)
            ->enum('rate', 'Note length', self::RATES, default: '1')
            ->float('rests', 'Rests', default: 0.15, min: 0.0, max: 0.5, step: 0.05, help: 'How often a note is left out. A melody with no silence in it has no phrases in it.')
            ->enum('engine', 'Engine', self::ENGINES, default: 'fm');

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $scale = $params->string('scale', 'pentatonic_major');
        $root = Scales::rootMidi($params->string('root', 'C'), $params->int('octave', 4));
        $range = $params->int('range', 2);
        $notes = Scales::notes($root, $scale, $root, $root + 12 * $range);

        $tempo = $params->float('tempo', 100.0);
        $bars = $params->int('bars', 8);
        $rate = (float) $params->string('rate', '1');
        $beat = 60 / $tempo;
        $step = $beat / $rate;
        $count = (int) round($bars * 4 * $rate);

        $degrees = $this->degrees($rng, $params->string('method', 'walk'), $count, count($notes), $params->int('order', 2));

        $envelope = $this->envelope($rng, $params->string('engine', 'fm'), $step);
        $timbre = $this->timbre($rng, $params->string('engine', 'fm'));

        $events = [];
        $rests = $params->float('rests', 0.15);

        foreach ($degrees as $i => $degree) {
            // Never rest on the first note. A melody whose opening is silence
            // reads as a broken player, not as a phrase.
            if ($i > 0 && $rng->bool($rests)) {
                continue;
            }

            $events[] = [
                'midi' => $notes[max(0, min(count($notes) - 1, $degree))],
                'start' => round($i * $step, 6),
                // Slightly shorter than the grid so consecutive notes articulate
                // rather than running into one continuous tone.
                'length' => round($step * 0.9, 6),
                'velocity' => round(0.55 + $rng->float() * 0.45, 3),
            ];
        }

        $duration = $this->quantise($count * $step + $envelope['release'] + 0.1);

        $score = [
            'algorithm' => 'melody',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 1,
            'tempo' => round($tempo, 2),
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'engine' => $params->string('engine', 'fm'),
            'scale' => $scale,
            'root' => $root,
            'method' => $params->string('method', 'walk'),
            'envelope' => $envelope,
            'timbre' => $timbre,
            'notes' => $events,
        ];

        return new Result(
            value: $score,
            display: $this->describe($params, $scale, $root, count($events), $tempo),
            meta: [
                'scale' => Scales::label($scale).' on '.Scales::noteName($root),
                'method' => self::METHODS[$params->string('method', 'walk')],
                'notes' => count($events),
                'pitch_span' => sprintf('%s – %s', Scales::noteName(min(array_column($events, 'midi'))), Scales::noteName(max(array_column($events, 'midi')))),
                'duration' => sprintf('%.1f s', $duration),
                'note' => $this->noteFor($params->string('method', 'walk')),
            ],
        );
    }

    /**
     * The four ways of choosing the next scale degree.
     *
     * All of them return indices into the note list, so the scale does the
     * quantising and none of these has to know what a semitone is.
     *
     * @return list<int>
     */
    private function degrees(Rng $rng, string $method, int $count, int $available, int $order): array
    {
        return match ($method) {
            'uniform' => $this->uniform($rng, $count, $available),
            'markov' => $this->markov($rng, $count, $available, $order),
            'voss' => $this->voss($rng, $count, $available),
            default => $this->walk($rng, $count, $available),
        };
    }

    /** @return list<int> */
    private function uniform(Rng $rng, int $count, int $available): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $rng->intBetween(0, $available - 1);
        }

        return $out;
    }

    /** @return list<int> */
    private function walk(Rng $rng, int $count, int $available): array
    {
        // Start in the middle of the range so the walk has room in both
        // directions; starting on the tonic pins the first quarter of the melody
        // against the bottom of the range.
        $current = intdiv($available, 2);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $current;
            $step = $rng->weighted(array_keys(self::STEP_WEIGHTS), array_values(self::STEP_WEIGHTS));
            $current = max(0, min($available - 1, $current + (int) $step));
        }

        return $out;
    }

    /**
     * Order-n chain over the folk corpus.
     *
     * The chain is built on every pass rather than cached, because it is a few
     * hundred integers and generate() must stay pure — a memoised table on a
     * static would be shared state between two requests that are supposed to be
     * independent.
     *
     * @return list<int>
     */
    private function markov(Rng $rng, int $count, int $available, int $order): array
    {
        $table = [];
        $starts = [];

        foreach (self::CORPUS as $tune) {
            $starts[] = array_slice($tune, 0, $order);

            for ($i = $order; $i < count($tune); $i++) {
                $context = implode(',', array_slice($tune, $i - $order, $order));
                $table[$context][] = $tune[$i];

                // Every order-2 context is also recorded at order 1, so an unseen
                // pair can back off rather than dead-end. Without the fallback a
                // chain that wanders off the corpus simply stops.
                if ($order === 2) {
                    $table[(string) $tune[$i - 1]][] = $tune[$i];
                }
            }
        }

        $history = $rng->pick($starts);
        $out = $history;

        while (count($out) < $count) {
            $context = implode(',', $history);
            $options = $table[$context] ?? $table[(string) end($history)] ?? null;

            // A context the corpus never saw: start a new phrase rather than
            // stop. Real melodies change subject too, and the alternative is a
            // chain that runs out of ideas halfway through the bar.
            if ($options === null) {
                $history = $rng->pick($starts);
                $out = [...$out, ...$history];

                continue;
            }

            $next = $rng->pick($options);
            $out[] = $next;
            $history = array_slice([...$history, $next], -$order);
        }

        // Corpus degrees are centred on the tonic and run negative; the note list
        // starts at the root, so shift up an octave's worth of degrees to bring
        // the whole contour inside it.
        $offset = (int) round($available / 3);

        return array_map(fn (int $d): int => max(0, min($available - 1, $d + $offset)), array_slice($out, 0, $count));
    }

    /**
     * Voss's own application of pink noise — docs/09 §4.3.
     *
     * The same algorithm as the pink noise generator, used as a composer: a
     * handful of dice, each rerolled at half the rate of the one before, summed.
     * Voss's finding was that melodies built this way are empirically closer to
     * real music than either white (too random) or brown (too meandering), and
     * that the same 1/f statistic shows up in the loudness and pitch of actual
     * recordings.
     *
     * @return list<int>
     */
    private function voss(Rng $rng, int $count, int $available): array
    {
        $rows = 5;
        $dice = [];
        for ($k = 0; $k < $rows; $k++) {
            $dice[$k] = $rng->float();
        }

        $out = [];

        for ($i = 0; $i < $count; $i++) {
            // Reroll row k when bit k of the counter has just flipped — which is
            // exactly the "half the rate of the last" schedule, without keeping a
            // counter per row.
            for ($k = 0; $k < $rows; $k++) {
                if ($i % (1 << $k) === 0) {
                    $dice[$k] = $rng->float();
                }
            }

            $sum = array_sum($dice) / $rows;
            $out[] = max(0, min($available - 1, (int) round($sum * ($available - 1))));
        }

        return $out;
    }

    /**
     * An envelope drawn inside musical bounds — docs/09 §6.
     *
     * The bounds are what keep a random draw usable: A ∈ [1 ms, 400 ms],
     * D ∈ [20 ms, 800 ms], S ∈ [0, 0.8], R ∈ [50 ms, 2 s]. A percussive draw and
     * a pad draw out of the same generator feel like different instruments, which
     * is most of why the engine list is shorter than it could be.
     */
    private function envelope(Rng $rng, string $engine, float $step): array
    {
        $pad = $engine === 'additive';

        $attack = $pad ? 0.05 + $rng->float() * 0.35 : 0.001 + $rng->float() * 0.03;
        $decay = 0.02 + $rng->float() * (0.78 * ($pad ? 1.0 : 0.5));
        $sustain = $pad ? 0.4 + $rng->float() * 0.4 : $rng->float() * 0.5;

        // Never let the release outlast the gap to the next note by so much that
        // the melody turns into a chord. Bounded above by the doc's 2 s either way.
        $release = min(2.0, 0.05 + $rng->float() * max(0.15, $step * 2));

        return [
            'attack' => round($attack, 5),
            'decay' => round($decay, 5),
            'sustain' => round($sustain, 4),
            'release' => round($release, 5),
        ];
    }

    private function timbre(Rng $rng, string $engine): array
    {
        return match ($engine) {
            // An integer ratio puts the sidebands on harmonics and sounds like a
            // bell or an electric piano; anything else is clangorous. The ratio is
            // drawn from the small integers for that reason — docs/09 §6.
            'fm' => [
                'ratio' => (float) $rng->pick([1.0, 2.0, 3.0, 4.0, 1.5, 2.5]),
                'index' => round(1.0 + $rng->float() * 5.0, 3),
            ],
            // Amplitudes falling as 1/k with a random tilt: a flat series is a
            // buzz, and 1/k is where an organ pipe lands.
            'additive' => [
                'partials' => array_map(
                    fn (int $k): float => round((1 / ($k + 1)) * (0.6 + $rng->float() * 0.8), 4),
                    range(0, 6),
                ),
            ],
            'subtractive' => [
                'wave' => $rng->pick(['saw', 'square', 'triangle']),
                'cutoff' => round(600 + $rng->float() * 4000, 1),
                'q' => round(0.7 + $rng->float() * 4.0, 3),
            ],
            default => [],
        };
    }

    private function noteFor(string $method): string
    {
        return match ($method) {
            'uniform' => 'Uniform notes, aimless by design — and still not unpleasant, because the scale has no interval in it that can clash.',
            'markov' => 'Degrees drawn from a chain trained on eight folk melodies. Order 1 is a salad of intervals; order 2 quotes phrases.',
            'voss' => 'Pitch from summed dice rerolled at halving rates — the same 1/f algorithm as the pink noise generator, used as a composer.',
            default => 'A bounded walk: small steps common, occasional leaps. That weight table is the melodic contour rule music theory describes.',
        };
    }

    private function describe(Params $params, string $scale, int $root, int $notes, float $tempo): string
    {
        return sprintf(
            '%s · %s · %d notes · %d bars at %.0f BPM · %s',
            Scales::label($scale).' on '.Scales::noteName($root),
            self::METHODS[$params->string('method', 'walk')],
            $notes,
            $params->int('bars', 8),
            $tempo,
            self::ENGINES[$params->string('engine', 'fm')],
        );
    }
}
