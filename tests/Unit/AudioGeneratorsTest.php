<?php

declare(strict_types=1);

use App\Random\Audio\Euclid;
use App\Random\Audio\Harmony;
use App\Random\Audio\Scales;
use App\Random\Entropy\Seed;
use App\Random\Generators\Audio\ChordGenerator;
use App\Random\Generators\Audio\MelodyGenerator;
use App\Random\Generators\Audio\NoiseGenerator;
use App\Random\Generators\Audio\PluckGenerator;
use App\Random\Generators\Audio\RhythmGenerator;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Module;
use App\Random\Generators\Renderer;
use Symfony\Component\Process\Process;

/**
 * The PHP half of the audio module.
 *
 * Nothing here listens to anything. What PHP owns is the *score* — which notes,
 * which onsets, which chord follows which — and that is what gets asserted:
 * patterns against their documented answers, melodies against the scale they
 * claim, progressions against the cadence they promise. The samples are measured
 * next door in AudioSpectrumTest.php, because they are the half no amount of
 * reading can check.
 *
 * @return array<string, Generator>
 */
function audioGenerators(): array
{
    // Read from the registry rather than from a list kept here, so a sixth audio
    // generator inherits all of this the moment it ships. Instantiated directly
    // rather than filtered out of allGenerators(): a dataset is evaluated as the
    // file is parsed, and at that moment the helpers in the other test files do
    // not exist yet.
    $generators = [];

    foreach ((require __DIR__.'/../../config/randomly.php')['generators'] as $class) {
        $generator = new $class;

        if ($generator->module() === Module::Audio) {
            $generators[$generator->key()] = $generator;
        }
    }

    return $generators;
}

function audioSeed(string $bytes = 'randomly-audio!!'): Seed
{
    return new Seed($bytes, stubReceipt());
}

/** A generator's score, exactly as the API would ship it. */
function audioScore(string $key, array $input = [], string $bytes = 'randomly-audio!!'): array
{
    $generator = audioGenerators()[$key] ?? throw new RuntimeException("No audio generator [{$key}].");
    $params = $generator->schema()->coerce($input);

    return $generator->generate(
        audioSeed($bytes)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    )->value;
}

it('answers the three structural questions the same way', function (Generator $generator): void {
    expect($generator->renderer())->toBe(Renderer::Audio)
        ->and($generator->key())->toStartWith('audio.')
        ->and($generator->isSensitive())->toBeFalse()
        ->and($generator->isReproducible())->toBeTrue();
})->with(audioGenerators());

it('ships a score small enough to justify not sending audio at all', function (Generator $generator): void {
    // The entire architecture rests on a description being tiny next to the thing
    // it describes. A 25-second render is four megabytes of samples; if the score
    // for it is not measured in kilobytes, the generator is shipping data it
    // should be deriving.
    $score = audioScore($generator->key());

    expect(strlen(json_encode($score)))->toBeLessThan(8192)
        ->and($score)->toHaveKeys(['algorithm', 'sample_rate', 'duration', 'gain']);
})->with(audioGenerators());

it('registers a browser engine for every audio generator', function (): void {
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        $this->markTestSkipped('node is not available; the engine check needs it.');
    }

    $algorithms = array_map(fn (Generator $g): string => audioScore($g->key())['algorithm'], audioGenerators());

    $process = Process::fromShellCommandline(
        'node scripts/list-audio-renderers.mjs '.implode(' ', array_map('escapeshellarg', $algorithms)),
        dirname(__DIR__, 2),
    );
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('node failed: '.$process->getErrorOutput());
    }

    $support = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    foreach ($algorithms as $key => $algorithm) {
        expect($support[$algorithm] ?? false)
            ->toBeTrue("[{$key}] emits algorithm [{$algorithm}], which no browser engine is registered for.");
    }
});

/*
|--------------------------------------------------------------------------
| Euclidean rhythm
|--------------------------------------------------------------------------
*/

it('produces the Euclidean patterns the world already plays', function (int $k, int $n, string $expected): void {
    // Known answers, straight out of docs/09 §5. Bjorklund's algorithm is the
    // one piece of this module with a right answer written down in advance, and
    // every one of these patterns is a rhythm a tradition arrived at
    // independently — the tresillo, the cinquillo, the West African bell.
    expect(Euclid::notation(Euclid::pattern($k, $n)))->toBe($expected);
})->with([
    'tresillo E(3,8)' => [3, 8, 'x..x..x.'],
    'cinquillo E(5,8)' => [5, 8, 'x.xx.xx.'],
    'Korean E(2,5)' => [2, 5, 'x.x..'],
    'West African bell E(7,12)' => [7, 12, 'x.xx.x.xx.x.'],
    'samba E(9,16)' => [9, 16, 'x.xx.x.x.xx.x.x.'],
]);

it('puts exactly k onsets in n steps, however odd the pair', function (int $k, int $n): void {
    $pattern = Euclid::pattern($k, $n);

    expect($pattern)->toHaveCount($n)
        ->and(array_sum($pattern))->toBe($k);
})->with(function () {
    foreach ([[1, 2], [3, 8], [4, 9], [5, 12], [7, 16], [11, 13], [13, 16], [16, 16], [0, 8]] as $pair) {
        yield "E({$pair[0]},{$pair[1]})" => $pair;
    }
});

it('rotates the downbeat without changing the onsets', function (): void {
    // Rotation is not cosmetic: E(5,8) rotated is the difference between the
    // cinquillo and the son clave. Same interval set, different rhythm.
    $base = Euclid::pattern(5, 8);
    $turned = Euclid::pattern(5, 8, 3);

    expect(array_sum($turned))->toBe(array_sum($base))
        ->and(Euclid::notation($turned))->not->toBe(Euclid::notation($base))
        ->and(Euclid::notation(Euclid::rotate($turned, -3)))->toBe(Euclid::notation($base));
});

it('names a pattern only when the pattern has a name', function (): void {
    expect(Euclid::name(3, 8))->toBe('Cuban tresillo')
        ->and(Euclid::name(7, 12))->toBe('West African bell')
        ->and(Euclid::name(6, 11))->toBeNull();
});

it('gives the first rhythm layer exactly the pattern the panel asked for', function (): void {
    $score = audioScore('audio.rhythm', ['k' => 7, 'n' => 12, 'rotation' => 2, 'layers' => 4, 'tempo' => 120]);
    $first = $score['layers'][0];

    expect($first['pattern'])->toBe(Euclid::pattern(7, 12, 2))
        ->and($score['layers'])->toHaveCount(4);

    foreach ($score['layers'] as $layer) {
        // Every drawn layer is still a real Euclidean pattern, not a random
        // bitmask that happens to have the right number of ones in it.
        expect($layer['pattern'])->toBe(Euclid::pattern($layer['k'], $layer['n'], $layer['rotation']))
            ->and($layer['accent'])->toHaveCount(count($layer['pattern']));
    }
});

it('divides the bar into steps the tempo actually implies', function (float $tempo, int $n): void {
    $score = audioScore('audio.rhythm', ['tempo' => $tempo, 'n' => $n, 'k' => 3, 'layers' => 1, 'bars' => 4]);
    $bar = 4 * 60 / $tempo;

    expect($score['bar_seconds'])->toBe(round($bar, 6))
        ->and($score['layers'][0]['step_seconds'])->toBe(round($bar / $n, 6))
        // The tail is what lets the last hit ring rather than being cut off, so
        // the render is always longer than the bars it contains.
        ->and($score['duration'])->toBeGreaterThan(4 * $bar);
})->with([[120.0, 8], [90.0, 16], [140.0, 12], [50.0, 5]]);

/*
|--------------------------------------------------------------------------
| Pitch
|--------------------------------------------------------------------------
*/

it('keeps every melody inside the scale it declares', function (string $scale): void {
    // The promise the whole module rests on. A generator that quantises to a
    // scale and then plays a note outside it has no defence: the scale is the
    // only reason random notes sound like music.
    foreach (['uniform', 'walk', 'markov', 'voss'] as $method) {
        $score = audioScore('audio.melody', ['scale' => $scale, 'method' => $method, 'bars' => 8, 'range' => 3]);

        expect($score['notes'])->not->toBeEmpty();

        foreach ($score['notes'] as $note) {
            expect(Scales::contains($note['midi'], $score['root'], $scale))
                ->toBeTrue("[{$method}] played MIDI {$note['midi']}, which is not in {$scale}.");
        }
    }
})->with(array_keys(Scales::INTERVALS));

it('keeps every plucked note inside the scale and the register it declares', function (string $root, int $octave, int $range): void {
    $score = audioScore('audio.pluck', ['root' => $root, 'octave' => $octave, 'range' => $range, 'bars' => 4]);
    $lowest = Scales::rootMidi($root, $octave);

    foreach ($score['notes'] as $note) {
        expect(Scales::contains($note['midi'], $score['root'], $score['scale']))->toBeTrue()
            ->and($note['midi'])->toBeGreaterThanOrEqual($lowest)
            ->toBeLessThanOrEqual($lowest + 12 * $range)
            // A feedback coefficient at or above 1 is not a string, it is an
            // oscillator that never stops and eventually overflows the buffer.
            ->and($note['decay'])->toBeLessThan(1.0)
            ->and($note['pan'])->toBeGreaterThanOrEqual(-1.0)->toBeLessThanOrEqual(1.0);
    }
})->with([
    ['A', 2, 2],
    ['C', 3, 1],
    ['F#', 4, 3],
]);

it('spreads the strings only as far as the spread control allows', function (): void {
    $narrow = audioScore('audio.pluck', ['spread' => 0.0, 'bars' => 4]);

    foreach ($narrow['notes'] as $note) {
        expect(abs($note['pan']))->toBeLessThanOrEqual(0.01);
    }
});

it('places the notes on the grid the tempo implies', function (): void {
    $score = audioScore('audio.melody', ['tempo' => 120, 'rate' => '2', 'bars' => 4, 'rests' => 0.0]);

    // Eighth notes at 120 BPM: a beat is half a second, so a step is a quarter.
    foreach ($score['notes'] as $index => $note) {
        expect($note['start'])->toBe(round($index * 0.25, 6));
    }

    expect($score['notes'])->toHaveCount(4 * 4 * 2);
});

/*
|--------------------------------------------------------------------------
| Harmony
|--------------------------------------------------------------------------
*/

it('weights every row of the progression matrix to one', function (string $degree, array $row): void {
    // A row that does not sum to 1 still works — weighted() normalises — but it
    // means the table has drifted from the one in docs/09 §8, and the table is
    // the entire claim this generator makes.
    expect(array_sum($row))->toEqualWithDelta(1.0, 1e-9);

    foreach (array_keys($row) as $target) {
        expect($target)->toBeIn(array_keys(Harmony::MATRIX));
    }
})->with(array_map(fn ($k, $v) => [$k, $v], array_keys(Harmony::MATRIX), Harmony::MATRIX));

it('starts on the tonic and finishes on a cadence', function (int $bars): void {
    // Twenty seeds, because the cadence is the one thing in the progression that
    // is not allowed to be random: docs/09 §8 forces V → I, or V → vi at p = 0.2.
    // A chain left to stop wherever it happened to be is what makes generative
    // harmony sound generative.
    for ($i = 0; $i < 20; $i++) {
        $score = audioScore('audio.chord', ['bars' => $bars], str_pad("seed-{$i}", 16, '!'));
        $numerals = array_column($score['chords'], 'degree');

        expect($numerals)->toHaveCount($bars)
            ->and($numerals[0])->toBe('I')
            ->and($numerals[$bars - 2])->toBe('V')
            ->and($numerals[$bars - 1])->toBeIn(['I', 'vi']);

        foreach ($numerals as $degree) {
            expect($degree)->toBeIn(array_keys(Harmony::MATRIX));
        }
    }
})->with([4, 8, 16]);

it('voices every chord as a real chord in the key', function (string $mode, string $voicing): void {
    $score = audioScore('audio.chord', ['mode' => $mode, 'voicing' => $voicing, 'root' => 'D', 'octave' => 3]);

    foreach ($score['chords'] as $chord) {
        $midi = $chord['midi'];

        expect($midi)->toHaveCount($voicing === 'spread' ? 4 : ($voicing === 'seventh' ? 4 : 3));

        // Ascending, always: a "voicing" whose notes are out of order is a list
        // of pitches, and the drop-2 spacing below depends on knowing which one
        // is on top.
        for ($i = 1; $i < count($midi); $i++) {
            expect($midi[$i])->toBeGreaterThan($midi[$i - 1]);
        }

        // Nothing may wander outside the range a keyboard has keys for.
        expect(min($midi))->toBeGreaterThan(20)->and(max($midi))->toBeLessThan(100);
    }
})->with([
    ['major', 'close'],
    ['major', 'open'],
    ['major', 'seventh'],
    ['minor', 'close'],
    ['minor', 'spread'],
]);

it('raises the seventh on a minor dominant, because that is what minor keys do', function (): void {
    // The natural minor's v is a minor chord with no leading tone and no pull
    // home; every idiom that uses minor sharpens it. Harmonic minor exists for
    // exactly this one chord.
    $root = Scales::rootMidi('A', 3);

    expect(Harmony::triad($root, 'minor', 'V'))->toBe([$root + 7, $root + 11, $root + 14])
        ->and(Harmony::triad($root, 'minor', 'i'))->toBe([$root, $root + 3, $root + 7]);
});

it('ships the transition matrix so the page can draw it', function (): void {
    // docs/09 §8 calls the matrix the module's best visual. It travels in the
    // score rather than being duplicated in the page, so there is no second copy
    // to drift out of step with the weights the music was actually drawn from.
    $score = audioScore('audio.chord');

    expect($score['matrix'])->toBe(Harmony::MATRIX);
});

/*
|--------------------------------------------------------------------------
| Noise
|--------------------------------------------------------------------------
*/

it('draws a filter sweep whatever the filter is set to', function (string $filter): void {
    // Drawing the breakpoints conditionally would make the number of bytes read
    // depend on a checkbox, and two otherwise identical seeds would then diverge
    // for a reason no receipt could explain.
    $score = audioScore('audio.noise', ['filter' => $filter]);

    expect($score['filter']['sweep'])->toHaveCount(8);

    foreach ($score['filter']['sweep'] as $point) {
        expect($point)->toBeGreaterThanOrEqual(0.5)->toBeLessThanOrEqual(2.0);
    }
})->with(array_keys(NoiseGenerator::FILTERS));

it('starts twelve decibels below full scale', function (Generator $generator): void {
    // docs/09 §9: every generator starts at −12 dBFS. 10^(−12/20) = 0.2512.
    expect(audioScore($generator->key())['gain'])->toEqualWithDelta(0.25119, 1e-4);
})->with(audioGenerators());

it('names every colour the docs name', function (): void {
    expect(array_keys(NoiseGenerator::COLOURS))
        ->toBe(['white', 'pink', 'brown', 'blue', 'violet', 'grey']);
});

it('carries a version so a permalink cannot quietly change what it plays', function (): void {
    foreach ([new NoiseGenerator, new RhythmGenerator, new MelodyGenerator, new PluckGenerator, new ChordGenerator] as $generator) {
        expect($generator->version())->toBeGreaterThanOrEqual(1);
    }
});

// ── audio.drone ──────────────────────────────────────────────────────────────

it('derives each detuning from the beat rate rather than the other way round', function (float $beating): void {
    /*
     * The one thing a listener can hear in a drone is the pulse, and the pulse is
     * the *difference* between two frequencies. A fixed detuning in cents would
     * make that difference scale with pitch — twelve cents beats twice as fast at
     * 440 Hz as at 220 — so the control is the rate and the cents are solved for.
     *
     * Asserted as the arithmetic identity it is: partner − frequency is the beat
     * rate, exactly, at every partial.
     */
    $score = audioScore('audio.drone', ['beating' => $beating, 'partials' => 6]);

    foreach ($score['partials'] as $partial) {
        expect($partial['partner'] - $partial['frequency'])
            ->toEqualWithDelta($partial['beat_hz'], 0.0005);

        // And the cents figure printed next to it has to describe the same two
        // frequencies: 1200·log2(partner/frequency).
        expect(1200 * log($partial['partner'] / $partial['frequency'], 2))
            ->toEqualWithDelta($partial['cents'], 0.01);

        // Spread around the requested rate, never a multiple of it: identical
        // rates across every partial phase-lock into one big tremolo.
        expect($partial['beat_hz'])->toBeGreaterThan($beating * 0.5)
            ->and($partial['beat_hz'])->toBeLessThan($beating * 3.0);
    }
})->with([0.1, 0.4, 2.0]);

it('stacks a drone as a harmonic series on the root it names', function (): void {
    // Whole multiples of the root, not ratios like 3/2 — a fifth would put the
    // ear's perceived fundamental an octave *below* anything present, which is a
    // fine effect and a confusing thing for the tuning display to report.
    $score = audioScore('audio.drone', ['root' => 'A', 'octave' => 2, 'partials' => 6]);

    expect($score['root_hz'])->toEqualWithDelta(110.0, 0.01);

    foreach ($score['partials'] as $n => $partial) {
        expect($partial['frequency'])->toEqualWithDelta($score['root_hz'] * ($n + 1), 0.01)
            // Amplitude falling monotonically: an upper partial louder than the
            // fundamental stops being a drone on a note and becomes two notes.
            ->and($partial['amplitude'])->toBeLessThanOrEqual($n === 0 ? 1.0 : $score['partials'][$n - 1]['amplitude']);
    }
});

// ── audio.bleep ──────────────────────────────────────────────────────────────

it('lays a pack out with enough silence to cut it apart', function (int $count): void {
    /*
     * The pack is one buffer because a zip would need a server and could not be
     * auditioned before downloading — so the gaps are the file format. Each sound
     * has to finish well before the next begins, or a splitter cutting on silence
     * gets the boundaries wrong and the offsets in the meta are a lie.
     */
    $score = audioScore('audio.bleep', ['count' => $count]);

    expect($score['sounds'])->toHaveCount($count);

    foreach ($score['sounds'] as $i => $sound) {
        expect($sound['length'])->toBeLessThan(0.75);

        if ($i === 0) {
            continue;
        }

        $previous = $score['sounds'][$i - 1];
        expect($sound['start'] - ($previous['start'] + $previous['length']))
            ->toEqualWithDelta($score['gap'], 0.001);
    }

    // And the buffer has to hold the last sound plus its tail.
    $last = end($score['sounds']);
    expect($score['duration'])->toBeGreaterThan($last['start'] + $last['length']);
})->with([1, 4, 9]);

it('gives a mixed pack one of each kind before it repeats', function (): void {
    // Drawing at random would routinely give three coins and no error, which is
    // the wrong answer to "a pack of UI sounds". A mixed pack cycles.
    $kinds = array_column(audioScore('audio.bleep', ['category' => 'mixed', 'count' => 8])['sounds'], 'kind');

    expect(array_slice($kinds, 0, 4))->toBe(['success', 'error', 'notify', 'coin'])
        ->and(array_unique($kinds))->toHaveCount(4);
});

it('sends every UI sound up or down the way its category promises', function (string $category, int $direction): void {
    /*
     * What survives a phone speaker at arm's length is pitch direction and
     * length, not timbre. Rising reads as success in every interface anyone has
     * used and falling reads as failure, so the pitches in the score have to
     * actually go that way — this is the one property of these sounds that is
     * worth asserting rather than listening to.
     */
    $score = audioScore('audio.bleep', ['category' => $category, 'count' => 3]);

    foreach ($score['sounds'] as $sound) {
        $pitched = array_values(array_filter(
            $sound['voices'],
            fn (array $v): bool => $v['wave'] !== 'noise',
        ));

        $first = $pitched[0]['midi'];
        $last = end($pitched)['midi'];

        expect(($last - $first) * $direction)->toBeGreaterThan(0);
    }
})->with([
    'success rises' => ['success', 1],
    'error falls' => ['error', -1],
    'coin flicks up' => ['coin', 1],
]);

// ── audio.ambient ────────────────────────────────────────────────────────────

it('bounds an endless piece so the studio can render it', function (float $asked): void {
    /*
     * docs/09 §7 says this one runs indefinitely, and it cannot: the browser has
     * to hold every sample before it plays one, there is no progress bar for a
     * piece with no end, and no WAV either. Bounded and looped is the honest
     * substitute, and the bound has to be real.
     */
    $score = audioScore('audio.ambient', ['duration' => $asked]);

    expect($score['duration'])->toBeLessThanOrEqual(150.0)
        ->and($score['duration'])->toEqualWithDelta(max(20.0, min(150.0, $asked)), 0.001)
        // Long fades at both ends, because the loop point has to be inaudible.
        ->and($score['fade'])->toBeGreaterThanOrEqual(2.0);
})->with([20.0, 60.0, 150.0, 1000.0]);

it('overlaps its chords instead of queueing them', function (): void {
    // A chord that starts when the last one ended is a progression; ambient wants
    // a wash, which means one chord always arriving while another leaves.
    $score = audioScore('audio.ambient', ['duration' => 90.0]);

    expect(count($score['pads']))->toBeGreaterThan(2);

    foreach ($score['pads'] as $i => $pad) {
        if ($i === 0) {
            continue;
        }

        $previous = $score['pads'][$i - 1];
        expect($pad['start'])->toBeLessThan($previous['start'] + $previous['length'])
            ->and(count($pad['midi']))->toBeGreaterThanOrEqual(3);
    }
});

it('drops its single notes at exponential gaps rather than on a grid', function (): void {
    /*
     * Notes on a grid are a sequence, and the ear finds the pulse within about
     * four of them. A Poisson process has no pulse to find: sometimes two land
     * almost together and sometimes nothing happens for twenty seconds, which is
     * what makes the piece sound unplanned rather than merely sparse.
     *
     * Checked through the gaps themselves — for an exponential, the standard
     * deviation equals the mean, and for a grid it would be zero.
     */
    $starts = array_column(audioScore('audio.ambient', ['duration' => 150.0, 'density' => 30.0])['motes'], 'start');

    expect(count($starts))->toBeGreaterThan(30);

    $gaps = [];
    for ($i = 1; $i < count($starts); $i++) {
        $gaps[] = $starts[$i] - $starts[$i - 1];
    }

    $mean = array_sum($gaps) / count($gaps);
    $variance = array_sum(array_map(fn (float $g): float => ($g - $mean) ** 2, $gaps)) / count($gaps);

    expect($mean)->toEqualWithDelta(2.0, 0.6)
        ->and(sqrt($variance) / $mean)->toBeGreaterThan(0.6);
});

it('plays nothing at all when the note density is turned off', function (): void {
    // The version to actually work to, and a generator that quietly ignored the
    // zero would be worse than one without the control.
    $score = audioScore('audio.ambient', ['density' => 0.0]);

    expect($score['motes'])->toBe([])
        ->and($score['pads'])->not->toBeEmpty();
});
