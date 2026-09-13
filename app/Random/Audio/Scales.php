<?php

declare(strict_types=1);

namespace App\Random\Audio;

/**
 * Pitch material — docs/09 §3.
 *
 * A scale is the cheapest musical constraint there is: five or seven integers
 * that turn a uniform draw into something people call a tune. Every generator in
 * this module quantises through here rather than picking a frequency, which is
 * why none of them can play a wrong note.
 */
final class Scales
{
    /** Semitone offsets from the root. Straight from the docs/09 §3 table. */
    public const INTERVALS = [
        'pentatonic_major' => [0, 2, 4, 7, 9],
        'pentatonic_minor' => [0, 3, 5, 7, 10],
        'major' => [0, 2, 4, 5, 7, 9, 11],
        'minor' => [0, 2, 3, 5, 7, 8, 10],
        'dorian' => [0, 2, 3, 5, 7, 9, 10],
        'phrygian' => [0, 1, 3, 5, 7, 8, 10],
        'lydian' => [0, 2, 4, 6, 7, 9, 11],
        'mixolydian' => [0, 2, 4, 5, 7, 9, 10],
        'blues' => [0, 3, 5, 6, 7, 10],
        'whole_tone' => [0, 2, 4, 6, 8, 10],
        'hirajoshi' => [0, 2, 3, 7, 8],
    ];

    /**
     * Ordered so the two pentatonics come first.
     *
     * The default matters more than any other choice in this module: on a
     * pentatonic scale a uniformly random note cannot clash, because the scale
     * contains no semitone and no tritone to clash with. Putting it at the top of
     * the list is the same argument as making it the default — docs/09 §3.
     */
    public const LABELS = [
        'pentatonic_major' => 'Major pentatonic (can’t sound wrong)',
        'pentatonic_minor' => 'Minor pentatonic (blues)',
        'major' => 'Major (Ionian)',
        'minor' => 'Natural minor (Aeolian)',
        'dorian' => 'Dorian (jazzy minor)',
        'phrygian' => 'Phrygian (dark, Spanish)',
        'lydian' => 'Lydian (dreamy)',
        'mixolydian' => 'Mixolydian (bluesy)',
        'blues' => 'Blues',
        'whole_tone' => 'Whole tone (unresolved)',
        'hirajoshi' => 'Hirajoshi (Japanese)',
    ];

    /** Pitch classes, named. A dropdown of note names beats a MIDI number nobody can read. */
    public const ROOTS = [
        'C' => 0, 'C#' => 1, 'D' => 2, 'D#' => 3, 'E' => 4, 'F' => 5,
        'F#' => 6, 'G' => 7, 'G#' => 8, 'A' => 9, 'A#' => 10, 'B' => 11,
    ];

    public const ROOT_LABELS = [
        'C' => 'C', 'C#' => 'C♯ / D♭', 'D' => 'D', 'D#' => 'D♯ / E♭', 'E' => 'E', 'F' => 'F',
        'F#' => 'F♯ / G♭', 'G' => 'G', 'G#' => 'G♯ / A♭', 'A' => 'A', 'A#' => 'A♯ / B♭', 'B' => 'B',
    ];

    /** @return list<int> */
    public static function intervals(string $scale): array
    {
        return self::INTERVALS[$scale] ?? self::INTERVALS['pentatonic_major'];
    }

    public static function label(string $scale): string
    {
        return self::LABELS[$scale] ?? $scale;
    }

    /** MIDI note number for a named root in a given octave. C4 = 60, the usual convention. */
    public static function rootMidi(string $note, int $octave): int
    {
        return 12 * ($octave + 1) + (self::ROOTS[$note] ?? 0);
    }

    /**
     * Every note of the scale between two MIDI bounds, ascending.
     *
     * Generators index into this list rather than doing their own octave
     * arithmetic: a "scale degree" that walks past the top of the octave should
     * carry on into the next one, and a flat array is the representation where
     * that is simply `$notes[$i + 1]`.
     *
     * @return list<int>
     */
    public static function notes(int $root, string $scale, int $lowest, int $highest): array
    {
        $intervals = self::intervals($scale);
        $notes = [];

        // Start a couple of octaves below the root so a low bound still fills,
        // whatever octave the root itself sits in.
        for ($octave = -3; $octave <= 6; $octave++) {
            foreach ($intervals as $step) {
                $midi = $root + $step + 12 * $octave;

                if ($midi >= $lowest && $midi <= $highest) {
                    $notes[] = $midi;
                }
            }
        }

        sort($notes);

        // An empty window would leave a generator with nothing to play; hand back
        // the root rather than letting a clamped slider produce silence.
        return $notes === [] ? [max(0, min(127, $root))] : array_values(array_unique($notes));
    }

    /** Does this note belong to the scale? The assertion every melody test makes. */
    public static function contains(int $midi, int $root, string $scale): bool
    {
        $degree = (($midi - $root) % 12 + 12) % 12;

        return in_array($degree, self::intervals($scale), true);
    }

    /** "C♯4" — for a display line, where a MIDI number would say nothing. */
    public static function noteName(int $midi): string
    {
        $names = array_keys(self::ROOTS);

        return $names[(($midi % 12) + 12) % 12].(intdiv($midi, 12) - 1);
    }
}
