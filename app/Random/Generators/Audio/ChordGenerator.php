<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Harmony;
use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Chord progressions from a functional-harmony transition matrix — docs/09 §8.
 *
 * The generator that should not sound generated. Seven chords drawn uniformly
 * sound like a mistake being repeated; the same seven drawn from this table sound
 * like somebody wrote them, because the table encodes what tonal music actually
 * does — chords move down a fifth far more often than anywhere else, and V is
 * followed by I more than half the time.
 *
 * Two details do most of the work. The chain starts on I, so there is a key from
 * the first beat. And the last two bars are not drawn at all: V → I, or V → vi at
 * p = 0.2 for the deceptive cadence. A progression that stops wherever the chain
 * happened to be is the single thing that gives generative harmony away.
 *
 * The matrix travels in the score rather than staying on the server, so the
 * studio can show it and light up each transition as it fires — the module's best
 * visual, and it costs about four hundred bytes.
 */
final class ChordGenerator extends AudioGenerator
{
    public const MODES = ['major' => 'Major', 'minor' => 'Minor'];

    public const INSTRUMENTS = [
        'pad' => 'Pad (slow, glassy)',
        'piano' => 'Electric piano (FM)',
        'organ' => 'Organ (additive)',
        'strings' => 'Strings (bowed, detuned)',
    ];

    public function key(): string
    {
        return 'audio.chord';
    }

    public function name(): string
    {
        return 'Chord Progressions';
    }

    public function tagline(): string
    {
        return 'A functional-harmony transition matrix, not a random draw — so it sounds written.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('root', 'Key', Scales::ROOT_LABELS, default: 'C')
            ->enum('mode', 'Mode', self::MODES, default: 'major')
            ->int('octave', 'Octave', default: 3, min: 2, max: 4)
            ->int('bars', 'Bars', default: 8, min: 4, max: 16, help: 'One chord per bar. The last two are the cadence and are not drawn from the chain.')
            ->float('tempo', 'Tempo (BPM)', default: 84.0, min: 40.0, max: 140.0, step: 1.0)
            ->enum('voicing', 'Voicing', Harmony::VOICINGS, default: 'close')
            ->enum('instrument', 'Instrument', self::INSTRUMENTS, default: 'pad')
            ->bool('sevenths', 'Sevenths', default: false, help: 'Adds the fourth chord tone. Turns a hymn into something closer to jazz without changing a single root.')
            ->bool('bass', 'Bass note', default: true);

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $mode = $params->string('mode', 'major');
        $root = Scales::rootMidi($params->string('root', 'C'), $params->int('octave', 3));
        $bars = $params->int('bars', 8);
        $tempo = $params->float('tempo', 84.0);
        $bar = $this->barSeconds($tempo);
        $voicing = $params->string('voicing', 'close');
        $seventh = $params->bool('sevenths', false) || $voicing === 'seventh';

        $degrees = Harmony::walk($rng, $bars);

        $chords = [];
        $previousTop = null;

        foreach ($degrees as $index => $degree) {
            $triad = Harmony::triad($root, $mode, $degree, $seventh);
            $midi = Harmony::voice($triad, $voicing, $previousTop);
            $previousTop = end($midi);

            $chords[] = [
                'numeral' => Harmony::numeral($degree, $mode),
                'degree' => $degree,
                'midi' => array_values($midi),
                // The bass plays the chord's own root, not the voicing's lowest
                // note. An inversion chosen for voice leading would otherwise
                // rewrite the harmony underneath it.
                'bass' => $params->bool('bass', true) ? $triad[0] - 12 : null,
                'start' => round($index * $bar, 6),
                // Just short of the bar, so one chord releases into the next
                // rather than the two overlapping into a seven-note cluster.
                'length' => round($bar * 0.96, 6),
                'velocity' => round(0.62 + $rng->float() * 0.3, 3),
            ];
        }

        $duration = $this->quantise($bars * $bar + 2.5);
        $cadence = end($degrees) === 'vi' ? 'V → vi (deceptive)' : 'V → I (authentic)';

        $score = [
            'algorithm' => 'chord',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 2,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'tempo' => round($tempo, 2),
            'mode' => $mode,
            'root' => $root,
            'voicing' => $voicing,
            'instrument' => $params->string('instrument', 'pad'),
            'chords' => $chords,
            // Shipped with the score so the UI can draw the chain and highlight
            // each transition as it plays, rather than the page having its own
            // copy of the weights to drift out of step with.
            'matrix' => Harmony::MATRIX,
        ];

        return new Result(
            value: $score,
            display: $this->describe($params, $chords, $tempo, $root, $mode),
            meta: [
                'progression' => implode(' → ', array_column($chords, 'numeral')),
                'key' => Scales::noteName($root).' '.$mode,
                'cadence' => $cadence,
                'voicing' => Harmony::VOICINGS[$voicing] ?? $voicing,
                'duration' => sprintf('%.1f s', $duration),
                'note' => 'Drawn from the transition matrix in docs/09 §8: chords move down a fifth far more often than anywhere else, and the final two bars are a cadence rather than a draw. That is the whole difference between this and a random chord picker.',
            ],
        );
    }

    private function describe(Params $params, array $chords, float $tempo, int $root, string $mode): string
    {
        return sprintf(
            '%s %s · %s · %d bars at %.0f BPM · %s',
            Scales::noteName($root),
            $mode,
            implode(' ', array_column($chords, 'numeral')),
            count($chords),
            $tempo,
            self::INSTRUMENTS[$params->string('instrument', 'pad')],
        );
    }
}
