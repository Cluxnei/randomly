<?php

declare(strict_types=1);

namespace App\Random\Generators\Audio;

use App\Random\Audio\Scales;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Generative ambient — docs/09 §7.
 *
 * Three layers whose cycles do not divide each other: a noise bed under a slowly
 * moving filter, pads long enough that one chord is always arriving while another
 * leaves, and single notes falling at irregular intervals over the top. That is
 * the Eno trick, and it is arithmetic rather than taste — put several slow things
 * together whose periods share no common factor and the combination takes far
 * longer to come round than any part of it does.
 *
 * docs/09 §7 says this one "runs indefinitely", and it does not. It cannot: the
 * studio renders synchronously, the browser has to hold every sample in memory,
 * and a piece with no end has no progress bar and no WAV. So the length is
 * bounded and the loop does the rest — the fades at each end are equal power, so
 * the seam is inaudible, and a two-minute buffer that loops is indistinguishable
 * from an endless piece until you have listened for two minutes.
 *
 * The chords are stacked from scale positions rather than walked through
 * functional harmony the way audio.chord does. Ambient is not functional music:
 * a V→I cadence is a sentence ending, and this is meant to be weather. Stacking
 * thirds on a wandering scale degree gives chords that are related without any of
 * them pulling anywhere.
 */
final class AmbientGenerator extends AudioGenerator
{
    /**
     * Four moods, as four sets of numbers.
     *
     * Every one is the same three layers and the same synthesis; what differs is
     * the scale, how bright the pad is, how much floor there is under it and how
     * high the motes sit. No mood takes a code path of its own — the same
     * argument as the drum kits in docs/09 §7.
     */
    public const MOODS = [
        'glacier' => [
            'label' => 'Glacier — high, cold, slow',
            'scale' => 'pentatonic_minor',
            'octave' => 3,
            'partials' => [1, 0.32, 0.5, 0.14, 0.22, 0.08, 0.1],
            'bed' => ['colour' => 'pink', 'level' => 0.16, 'cutoff' => 900.0],
            'pad_seconds' => [11.0, 16.0],
            'mote_octaves' => 2,
            'ratio' => [3.53, 4.76],
            'detune' => 9.0,
            'note' => 'Minor pentatonic in a high register with a thin pink floor under it. Nothing resolves, because a pentatonic scale has no semitone to resolve with.',
        ],
        'dusk' => [
            'label' => 'Dusk — warm, low, close',
            'scale' => 'dorian',
            'octave' => 2,
            'partials' => [1, 0.62, 0.3, 0.2, 0.12, 0.06],
            'bed' => ['colour' => 'brown', 'level' => 0.22, 'cutoff' => 420.0],
            'pad_seconds' => [13.0, 19.0],
            'mote_octaves' => 2,
            'ratio' => [2.0, 2.76],
            'detune' => 14.0,
            'note' => 'Dorian — a minor scale with a raised sixth, which is the interval that keeps it from being sad. Brown noise underneath, rolled off low, so it reads as a room rather than as hiss.',
        ],
        'rainfall' => [
            'label' => 'Rainfall — a floor you can hear through',
            'scale' => 'minor',
            'octave' => 2,
            'partials' => [1, 0.4, 0.22, 0.16, 0.08],
            'bed' => ['colour' => 'pink', 'level' => 0.42, 'cutoff' => 2400.0],
            'pad_seconds' => [14.0, 20.0],
            'mote_octaves' => 3,
            'ratio' => [1.41, 3.53],
            'detune' => 7.0,
            'note' => 'The bed is the piece here and the chords are underneath it — pink noise opened up to a couple of kilohertz, which is as close to rain as a filter gets without a sample.',
        ],
        'orbit' => [
            'label' => 'Orbit — floating, unresolved',
            'scale' => 'whole_tone',
            'octave' => 3,
            'partials' => [1, 0.0, 0.42, 0.0, 0.18, 0.0, 0.08],
            'bed' => ['colour' => 'pink', 'level' => 0.12, 'cutoff' => 1400.0],
            'pad_seconds' => [10.0, 15.0],
            'mote_octaves' => 2,
            'ratio' => [1.73, 2.41],
            'detune' => 5.0,
            'note' => 'Whole tone: six equal steps, no semitone anywhere, so no note is more home than any other. The pad keeps only its odd partials, which is what a clarinet does and why it sounds hollow.',
        ],
    ];

    /**
     * How far apart consecutive pads start, as a fraction of the pad before.
     *
     * Well under 1, so chords overlap by design — at any moment two are usually
     * sounding. At 1 they would queue instead, and a queue of chords is a
     * progression rather than a wash.
     */
    private const PAD_OVERLAP = 0.55;

    public function key(): string
    {
        return 'audio.ambient';
    }

    public function name(): string
    {
        return 'Generative Ambient';
    }

    public function tagline(): string
    {
        return 'A mood preset that evolves and never repeats.';
    }

    public function schema(): ParamSchema
    {
        $schema = ParamSchema::make()
            ->enum('mood', 'Mood', array_map(fn (array $m): string => $m['label'], self::MOODS), default: 'glacier')
            ->enum('root', 'Root', Scales::ROOT_LABELS, default: 'D')
            ->float('duration', 'Length (seconds)', default: 60.0, min: 20.0, max: 150.0, step: 5.0, help: 'Bounded on purpose: the browser renders every sample before it plays anything. Loop it and the fades hide the seam.')
            ->float('density', 'Notes per minute', default: 14.0, min: 0.0, max: 60.0, step: 1.0, help: 'How often a single note falls over the pads. Zero leaves the chords and the floor alone, which is the version to work to.')
            ->float('bed', 'Floor', default: 1.0, min: 0.0, max: 2.0, step: 0.05, help: 'Scales the noise layer. Past 1 the piece becomes weather with chords in it rather than the other way round.')
            ->float('spread', 'Stereo spread', default: 0.7, min: 0.0, max: 1.0, step: 0.05)
            ->float('movement', 'Movement', default: 0.5, min: 0.0, max: 1.0, step: 0.05, help: 'How far the filter over the floor drifts across the piece. It is most of what makes the thing sound alive rather than held.');

        return $this->volumeParam($schema);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $mood = self::MOODS[$params->string('mood', 'glacier')] ?? self::MOODS['glacier'];
        $root = Scales::rootMidi($params->string('root', 'D'), $mood['octave']);
        $duration = $this->quantise($params->float('duration', 60.0));

        $notes = Scales::notes($root, $mood['scale'], $root, $root + 12 * 3);
        $pads = $this->pads($rng, $notes, $mood, $duration, $params->float('spread', 0.7));
        $motes = $this->motes($rng, $notes, $mood, $duration, $params->float('density', 14.0), $params->float('spread', 0.7));

        $score = [
            'algorithm' => 'ambient',
            'sample_rate' => self::SAMPLE_RATE,
            'duration' => $duration,
            'channels' => 2,
            'gain' => $this->gain($params->float('volume', self::DEFAULT_VOLUME_DB)),
            'mood' => $params->string('mood', 'glacier'),
            'bed' => [
                'colour' => $mood['bed']['colour'],
                'level' => round($mood['bed']['level'] * $params->float('bed', 1.0), 4),
                'filter' => [
                    'cutoff' => $mood['bed']['cutoff'],
                    'q' => 0.7,
                    'sweep' => $this->sweep($rng, $params->float('movement', 0.5)),
                ],
            ],
            'pad' => [
                'partials' => $mood['partials'],
                // A long attack and a longer release. Nothing here is struck: a
                // chord should arrive rather than start, which is entirely a
                // matter of the first two seconds of its envelope.
                'envelope' => ['attack' => 2.6, 'decay' => 2.0, 'sustain' => 0.72, 'release' => 5.0],
                'unison' => 2,
                'detune' => $mood['detune'],
            ],
            'mote' => [
                'envelope' => ['attack' => 0.012, 'decay' => 0.9, 'sustain' => 0.18, 'release' => 2.6],
            ],
            'pads' => $pads,
            'motes' => $motes,
            // Three seconds each end, equal power, so the buffer loops without a
            // seam — which is how a bounded piece stands in for an endless one.
            'fade' => 3.0,
        ];

        return new Result(
            value: $score,
            display: sprintf(
                '%s · %s on %s · %d chords · %d notes · %.0f s',
                $mood['label'],
                Scales::label($mood['scale']),
                Scales::noteName($root),
                count($pads),
                count($motes),
                $duration,
            ),
            meta: [
                'mood' => $mood['label'],
                'scale' => Scales::label($mood['scale']).' on '.Scales::noteName($root),
                'chords' => count($pads),
                'notes' => count($motes),
                'bed' => sprintf('%s noise at %.0f%%', $mood['bed']['colour'], $mood['bed']['level'] * $params->float('bed', 1.0) * 100),
                'duration' => sprintf('%.0f s', $duration),
                'overlap' => sprintf('each chord starts %.0f%% of the way through the one before', self::PAD_OVERLAP * 100),
                'note' => $mood['note'].' Bounded and looped rather than endless: the browser has to hold every sample before it can play one, and equal-power fades make the seam inaudible.',
            ],
        );
    }

    /**
     * Overlapping chords, stacked on a wandering scale degree.
     *
     * The degree moves by a step or two between chords rather than jumping, which
     * is voice leading done the cheap way: consecutive chords share notes, so the
     * change is heard as movement rather than as a cut.
     *
     * @param  list<int>  $notes
     * @return list<array<string, mixed>>
     */
    private function pads(Rng $rng, array $notes, array $mood, float $duration, float $spread): array
    {
        $pads = [];
        $at = 0.0;
        $degree = $rng->intBetween(0, max(0, count($notes) - 5));

        while ($at < $duration) {
            $length = $mood['pad_seconds'][0] + $rng->float() * ($mood['pad_seconds'][1] - $mood['pad_seconds'][0]);

            $chord = [];
            foreach ([0, 2, 4] as $offset) {
                $chord[] = $notes[min(count($notes) - 1, $degree + $offset)];
            }

            // A ninth on top now and then. It is the one added tone that cannot
            // sound wrong on any of these scales, and the chord stops being a
            // plain triad for a moment.
            if ($rng->bool(0.35)) {
                $chord[] = $notes[min(count($notes) - 1, $degree + 6)];
            }

            $pads[] = [
                'midi' => array_values(array_unique($chord)),
                'start' => round($at, 6),
                'length' => round($length, 6),
                'velocity' => round(0.5 + $rng->float() * 0.3, 3),
                'spread' => round($spread, 3),
            ];

            $at += $length * self::PAD_OVERLAP;
            $degree = max(0, min(count($notes) - 5, $degree + $rng->pick([-2, -1, -1, 1, 1, 2])));
        }

        return $pads;
    }

    /**
     * Single notes, at exponentially distributed gaps.
     *
     * A Poisson process rather than a grid: notes on a grid are a sequence, and
     * the ear finds the pulse within about four of them. Exponential gaps have no
     * pulse to find — sometimes two land almost together and sometimes nothing
     * happens for twenty seconds, which is what makes the piece feel unplanned
     * rather than sparse.
     *
     * @param  list<int>  $notes
     * @return list<array<string, mixed>>
     */
    private function motes(Rng $rng, array $notes, array $mood, float $duration, float $density, float $spread): array
    {
        if ($density <= 0) {
            return [];
        }

        $rate = $density / 60;
        $motes = [];
        $at = $rng->exponential($rate);

        // The motes sit above the pads rather than among them: same scale, upper
        // register, so they read as a separate voice instead of thickening the
        // chord.
        $floor = max(0, count($notes) - 12 * $mood['mote_octaves'] / 3);

        while ($at < $duration && count($motes) < 400) {
            $motes[] = [
                'midi' => $notes[$rng->intBetween((int) $floor, count($notes) - 1)] + 12,
                'start' => round($at, 6),
                'length' => round(1.2 + $rng->float() * 2.4, 6),
                'pan' => round(($rng->float() * 2 - 1) * $spread, 3),
                'velocity' => round(0.25 + $rng->float() * 0.35, 3),
                'ratio' => $mood['ratio'][$rng->intBetween(0, 1)],
                'index' => round(1.4 + $rng->float() * 2.2, 3),
            ];

            $at += $rng->exponential($rate);
        }

        return $motes;
    }

    /**
     * Breakpoints for the filter over the noise bed.
     *
     * Octave multipliers rather than linear ones, for the same reason the noise
     * generator's sweep uses them: the ear hears cutoff logarithmically, so ±1
     * octave is a symmetrical drift and ±1000 Hz is not.
     *
     * @return list<float>
     */
    private function sweep(Rng $rng, float $movement): array
    {
        $points = [];

        for ($i = 0; $i < 6; $i++) {
            $points[] = round(2 ** (($rng->float() * 2 - 1) * $movement), 4);
        }

        return $points;
    }
}
