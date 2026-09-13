<?php

declare(strict_types=1);

namespace App\Random\Audio;

use App\Random\Rng\Rng;

/**
 * Functional harmony as a Markov chain — docs/09 §8.
 *
 * The point of the whole generator: a uniform draw over seven chords sounds like
 * a mistake, and the *same* seven chords drawn from this table sound written.
 * Nothing here is a musical opinion of ours — the weights encode what tonal music
 * empirically does, which is that chords move down a fifth far more often than
 * anywhere else, and that V is nearly always followed by I.
 */
final class Harmony
{
    /**
     * Transition weights, docs/09 §8 verbatim for I, ii, IV, V and vi.
     *
     * The doc gives no row for iii or vii°, and the chain can reach both — iii
     * from I (p=.1) and from V's deceptive branch, vii° from ii (p=.2). Rather
     * than a dead end these get rows in the same idiom:
     *
     *   iii is a weak tonic substitute whose strongest pull is down a fifth to vi.
     *   vii° is a V7 with its root missing, so it does what a dominant does: it
     *   resolves. Anything else from a diminished triad sounds like a wrong turn.
     */
    public const MATRIX = [
        'I' => ['IV' => 0.30, 'V' => 0.25, 'vi' => 0.20, 'ii' => 0.15, 'iii' => 0.10],
        'ii' => ['V' => 0.50, 'IV' => 0.20, 'vii°' => 0.20, 'I' => 0.10],
        'iii' => ['vi' => 0.40, 'IV' => 0.30, 'ii' => 0.20, 'V' => 0.10],
        'IV' => ['V' => 0.35, 'I' => 0.30, 'ii' => 0.20, 'vi' => 0.15],
        'V' => ['I' => 0.55, 'vi' => 0.25, 'IV' => 0.10, 'iii' => 0.10],
        'vi' => ['IV' => 0.35, 'ii' => 0.30, 'V' => 0.20, 'I' => 0.15],
        'vii°' => ['I' => 0.70, 'iii' => 0.20, 'vi' => 0.10],
    ];

    /** Roman numeral to its 0-based position in the seven-note mode. */
    public const DEGREES = ['I' => 0, 'ii' => 1, 'iii' => 2, 'IV' => 3, 'V' => 4, 'vi' => 5, 'vii°' => 6];

    /**
     * How a numeral is spelled in the minor mode.
     *
     * The matrix is written in major numerals because functional harmony is the
     * same set of *functions* in both modes; only the qualities change. Mapping
     * rather than duplicating the table keeps one source of truth for the weights.
     */
    public const MINOR_NUMERALS = ['I' => 'i', 'ii' => 'ii°', 'iii' => 'III', 'IV' => 'iv', 'V' => 'V', 'vi' => 'VI', 'vii°' => 'vii°'];

    public const VOICINGS = [
        'close' => 'Close (root position)',
        'open' => 'Open (drop-2)',
        'seventh' => 'Sevenths',
        'spread' => 'Spread (bass + triad)',
    ];

    /**
     * Walk the chain for `$bars` chords, starting on I and cadencing at the end.
     *
     * The last two bars are not drawn. A progression that simply stops wherever
     * the chain happened to be is the single thing that makes generative harmony
     * sound generative; a cadence is what tells a listener the phrase was
     * finished on purpose. V→I is the authentic one, and V→vi — the deceptive
     * cadence, docs/09 §8 puts it at p=0.2 — is the reason this does not sound
     * like the same four bars every time.
     *
     * @return list<string> roman numerals, major spelling
     */
    public static function walk(Rng $rng, int $bars): array
    {
        $bars = max(2, $bars);
        $chords = ['I'];

        // Two bars are reserved for the cadence, and the opening I is already
        // placed, so the chain fills what is left in between.
        while (count($chords) < $bars - 2) {
            $current = end($chords);
            $row = self::MATRIX[$current];
            $chords[] = $rng->weighted(array_keys($row), array_values($row));
        }

        $chords[] = 'V';
        $chords[] = $rng->bool(0.2) ? 'vi' : 'I';

        return array_slice($chords, 0, $bars);
    }

    /** The numeral as the mode spells it: I in major, i in minor. */
    public static function numeral(string $degree, string $mode): string
    {
        return $mode === 'minor' ? (self::MINOR_NUMERALS[$degree] ?? $degree) : $degree;
    }

    /**
     * Stack thirds on a scale degree.
     *
     * Chord tones are scale positions d, d+2, d+4 (+6 for a seventh) — "every
     * other note", which is what a third means once the scale is the ruler rather
     * than the semitone. Wrapping past the top of the scale adds an octave.
     *
     * @return list<int> MIDI notes, ascending, root position
     */
    public static function triad(int $root, string $mode, string $degree, bool $seventh = false): array
    {
        $scale = Scales::intervals($mode === 'minor' ? 'minor' : 'major');
        $position = self::DEGREES[$degree] ?? 0;
        $tones = $seventh ? [0, 2, 4, 6] : [0, 2, 4];

        $notes = [];

        foreach ($tones as $offset) {
            $index = $position + $offset;
            $semitones = $scale[$index % 7] + 12 * intdiv($index, 7);

            // Harmonic minor, and the reason it exists: the natural minor's v is
            // a minor chord with no leading tone, so it does not pull home. Every
            // idiom that uses minor raises the seventh degree on the dominant.
            if ($mode === 'minor' && ($degree === 'V' || $degree === 'vii°') && $index % 7 === 6) {
                $semitones++;
            }

            $notes[] = $root + $semitones;
        }

        return $notes;
    }

    /**
     * Choose an inversion and a spacing.
     *
     * The inversion is picked to move the top voice as little as possible from
     * the chord before. This is voice leading, and it is most of the difference
     * between a progression that sounds played and one that sounds like a list of
     * chords: the ear follows the top line, and a top line that leaps a seventh
     * every bar reads as four unrelated events.
     *
     * @param  list<int>  $chord  root-position chord tones
     * @return list<int>
     */
    public static function voice(array $chord, string $voicing, ?int $previousTop): array
    {
        $best = $chord;

        if ($previousTop !== null) {
            $distance = PHP_INT_MAX;

            // Every inversion, plus the same inversions an octave either side, so
            // the candidate set actually spans the register rather than only the
            // one octave root position happened to land in.
            foreach ([-12, 0, 12] as $shift) {
                for ($i = 0; $i < count($chord); $i++) {
                    $candidate = self::invert($chord, $i, $shift);
                    $gap = abs(end($candidate) - $previousTop);

                    if ($gap < $distance) {
                        $distance = $gap;
                        $best = $candidate;
                    }
                }
            }
        }

        return match ($voicing) {
            // Drop-2: take the second voice from the top down an octave. The gap
            // it opens in the middle is what makes a piano chord sound like a
            // piano chord rather than a block.
            'open' => self::dropTwo($best),
            'spread' => [$best[0] - 24, ...$best],
            default => $best,
        };
    }

    /** @param  list<int>  $chord */
    private static function invert(array $chord, int $times, int $shift): array
    {
        $notes = array_map(fn (int $n): int => $n + $shift, $chord);

        for ($i = 0; $i < $times; $i++) {
            $lowest = array_shift($notes);
            $notes[] = $lowest + 12;
        }

        return array_values($notes);
    }

    /** @param  list<int>  $chord */
    private static function dropTwo(array $chord): array
    {
        if (count($chord) < 3) {
            return $chord;
        }

        $notes = $chord;
        $index = count($notes) - 2;
        $notes[$index] -= 12;
        sort($notes);

        return array_values($notes);
    }
}
