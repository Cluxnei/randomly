<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Euclid;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Euclidean rhythms — docs/09 §5.
 *
 * Bjorklund's algorithm spreads k onsets over n steps as evenly as the integers
 * allow, and the startling result is that its answers are not new: E(3,8) is the
 * Cuban tresillo, E(5,8) the cinquillo, E(7,12) the West African bell, E(2,5)
 * Korean. Centuries of independent musical tradition, and a greedy pairing
 * algorithm lands on the same patterns.
 *
 * Which makes this the safest random generator in the library. Draw k, n and a
 * rotation and the result is a rhythm somebody somewhere already plays; it is
 * essentially impossible to produce an ugly one. Four layers with different n
 * give polyrhythm for free, because each layer's steps divide the bar its own
 * way.
 */
final class RhythmGenerator extends AudioGenerator
{
    public const KITS = [
        'acoustic' => 'Acoustic (skins and metal)',
        'electronic' => 'Electronic (long-tailed 808)',
        'wood' => 'Wood (claves and blocks)',
    ];

    /**
     * What each layer plays, in the order layers are added.
     *
     * Roles rather than a random instrument per layer: a kit with two kicks and
     * no hat is not a drum part. The first layer is the one the user's own k and
     * n control, so it gets the kick — the part a listener reads the metre from.
     */
    private const ROLES = ['kick', 'hat', 'snare', 'perc'];

    public function key(): string
    {
        return 'audio.rhythm';
    }

    public function name(): string
    {
        return 'Euclidean Rhythm';
    }

    public function tagline(): string
    {
        return 'k onsets spread as evenly as possible across n steps — which is most of the world’s rhythms.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->int('k', 'Onsets (k)', default: 3, min: 1, max: 16, help: 'How many hits. k=3, n=8 is the tresillo; the panel names the pattern whenever it recognises one.')
            ->int('n', 'Steps (n)', default: 8, min: 2, max: 16)
            ->int('rotation', 'Rotation', default: 0, min: 0, max: 15, help: 'Which step becomes the downbeat. Same onsets, different rhythm — this is what separates the cinquillo from the son clave.')
            ->int('layers', 'Layers', default: 3, min: 1, max: 4, help: 'Layer one is yours. The rest draw their own k and n, which is where the polyrhythm comes from.')
            ->float('tempo', 'Tempo (BPM)', default: 110.0, min: 50.0, max: 200.0, step: 1.0)
            ->int('bars', 'Bars', default: 4, min: 1, max: 16)
            ->float('swing', 'Swing', default: 0.0, min: 0.0, max: 0.6, step: 0.05, help: 'Delays every second step. At 0.33 the steps land in triplet time, which is where swing comes from.')
            ->enum('kit', 'Kit', self::KITS, default: 'acoustic');

        return $this->volumeParam($schema);
    }

    /**
     * Step counts the drawn layers choose from.
     *
     * Not a uniform draw over 2..16. A layer at n=13 against a layer at n=8 is a
     * polyrhythm that never lines up inside the piece and reads as a mistake;
     * these all share factors with 8 or 12, so the layers meet often enough for
     * the ear to hear one bar rather than several.
     */
    private const LAYER_STEPS = [8, 12, 16];

    public function generate(Rng $rng, Params $params): Result
    {
        $tempo = $params->float('tempo', 110.0);
        $bars = $params->int('bars', 4);
        $bar = $this->barSeconds($tempo);
        $count = $params->int('layers', 3);

        $layers = [];

        for ($i = 0; $i < $count; $i++) {
            $layers[] = $this->layer($rng, $i, $params, $bar);
        }

        // A tail so the last hit is heard rather than truncated. Half a second
        // covers every decay in the kits below; an 808 kick is the long one.
        $duration = $this->quantise($bars * $bar + 0.5);

        $score = [
            'algorithm' => 'rhythm',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 2,
            'tempo' => round($tempo, 2),
            'bars' => $bars,
            'bar_seconds' => round($bar, 6),
            'swing' => round($params->float('swing', 0.0), 3),
            'kit' => $params->string('kit', 'acoustic'),
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'layers' => $layers,
        ];

        return new Result(
            value: $score,
            display: $this->describe($params, $layers, $tempo, $bars),
            meta: [
                'patterns' => array_map(
                    fn (array $l): string => sprintf('%s %s', $l['instrument'], Euclid::notation($l['pattern'])),
                    $layers,
                ),
                'tempo' => sprintf('%.0f BPM', $tempo),
                'duration' => sprintf('%.1f s', $duration),
                'onsets' => array_sum(array_map(fn (array $l): int => array_sum($l['pattern']) * $bars, $layers)),
                'note' => $this->noteFor($layers[0]),
            ],
        );
    }

    /**
     * One layer: a pattern, an instrument, and how fast its steps go by.
     *
     * `step_seconds` is computed here rather than in the browser because it is
     * the contract between the score and the sound. A test can assert that the
     * rendered transients land on round($step * $step_seconds * 44100) and
     * catch a tempo that is wrong by a factor no listener would name.
     */
    private function layer(Rng $rng, int $index, Params $params, float $bar): array
    {
        if ($index === 0) {
            $n = $params->int('n', 8);

            // Clamped against each other rather than left as the panel sent them.
            // The pattern itself is clamped either way — E(16,8) is eight onsets —
            // but a score reading "k: 16, n: 8" describes something that did not
            // happen, and the display line would announce a rhythm nobody plays.
            $k = min($params->int('k', 3), $n);
            $rotation = $params->int('rotation', 0) % $n;
        } else {
            $n = $rng->pick(self::LAYER_STEPS);

            // Hats want density and everything else wants space. A hat at k=2 is
            // inaudible as a part; a snare at k=12 is a buzz roll.
            $k = $index === 1
                ? $rng->intBetween((int) ceil($n / 2), $n - 1)
                : $rng->intBetween(2, max(2, intdiv($n, 3)));

            $rotation = $rng->intBetween(0, $n - 1);
        }

        $pattern = Euclid::pattern($k, $n, $rotation);

        // Velocity per step, not per hit: the same step index in every bar gets
        // the same weight, so the accents are a property of the pattern and the
        // bars sound like repetitions of one thing rather than four takes.
        $accent = [];
        for ($step = 0; $step < count($pattern); $step++) {
            $accent[] = round(0.62 + $rng->float() * 0.38, 3);
        }

        return [
            'instrument' => self::ROLES[$index],
            'k' => $k,
            'n' => count($pattern),
            'rotation' => $rotation,
            'pattern' => $pattern,
            'accent' => $accent,
            'step_seconds' => round($bar / max(1, count($pattern)), 6),
            'gain' => $index === 0 ? 1.0 : round(0.55 + $rng->float() * 0.25, 3),
            // The kick stays centred — bass has no business moving across the
            // image — and everything else takes a side.
            'pan' => $index === 0 ? 0.0 : round(($rng->float() * 2 - 1) * 0.55, 3),
        ];
    }

    private function noteFor(array $first): string
    {
        $named = Euclid::name($first['k'], $first['n']);

        return $named === null
            ? sprintf('E(%d,%d) = %s. Bjorklund spreads the onsets as evenly as the integers allow.', $first['k'], $first['n'], Euclid::notation($first['pattern']))
            : sprintf('E(%d,%d) is the %s — the same pattern the algorithm finds and a tradition settled on.', $first['k'], $first['n'], $named);
    }

    private function describe(Params $params, array $layers, float $tempo, int $bars): string
    {
        $first = $layers[0];
        $named = Euclid::name($first['k'], $first['n']);

        return sprintf(
            'E(%d,%d) %s%s · %d bar%s at %.0f BPM · %d layer%s · %s kit',
            $first['k'],
            $first['n'],
            Euclid::notation($first['pattern']),
            $named === null ? '' : ' · '.$named,
            $bars,
            $bars === 1 ? '' : 's',
            $tempo,
            count($layers),
            count($layers) === 1 ? '' : 's',
            $params->string('kit', 'acoustic'),
        );
    }
}
