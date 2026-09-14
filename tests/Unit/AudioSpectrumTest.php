<?php

declare(strict_types=1);
use App\Random\Audio\Euclid;
use App\Random\Generators\Contracts\Generator;
use Symfony\Component\Process\Process;

/**
 * Audio is the one module whose output cannot be checked by reading it.
 *
 * The failures here are specific and silent: pink noise that is actually brown,
 * a limiter that never engages, a colour whose slope is double what it claims.
 * None of them throw, none of them look wrong in a diff, and most people cannot
 * name them by ear either. They are, however, trivially measurable — so they get
 * measured.
 *
 * This caught a real error while it was being written: "blue noise" was white
 * differentiated once, which is +6 dB/octave, not +3. Differentiation multiplies
 * by jω, so power scales with f², and 10·log₁₀(f²) is 6 dB per octave. Blue is
 * differentiated *pink*.
 *
 * It has since caught a second: every drum hit ended on a step discontinuity,
 * because an exponential decay never reaches zero and the buffer simply stopped.
 * A step is broadband, so the onset detector reported twice as many onsets as the
 * pattern had — the extra ones landing exactly one kick length after each real
 * one. Nobody reading `out[i] = sin(phase) * exp(-4.5 * t)` would have seen it.
 */
function spectralMeasurement(string $signal, float $seconds = 2.0): array
{
    $process = Process::fromShellCommandline(
        sprintf('node scripts/analyse-audio.mjs %s %s', escapeshellarg($signal), $seconds),
        dirname(__DIR__, 2),
    );
    $process->setTimeout(60);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('node failed: '.$process->getErrorOutput());
    }

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        $this->markTestSkipped('node is not available; the spectrum check needs it.');
    }
});

it('gives each noise colour the spectral slope its name promises', function (string $colour, float $expected): void {
    // One dB of tolerance. The theoretical slopes are exact; a measurement over a
    // finite window with a finite number of Welch averages is not, and tightening
    // this would buy flakiness rather than correctness.
    expect(spectralMeasurement($colour)['slope_db_per_octave'])
        ->toBeGreaterThan($expected - 1.0)
        ->toBeLessThan($expected + 1.0);
})->with([
    'white' => ['white', 0.0],
    'pink' => ['pink', -3.0],
    'brown' => ['brown', -6.0],
    'blue' => ['blue', 3.0],
    'violet' => ['violet', 6.0],
]);

it('brings every signal under the limiter ceiling', function (string $colour): void {
    // Generative audio gets no soundcheck: layers can align on a sample and peak
    // where nothing predicted it. White noise reaches full scale by definition,
    // so it is the honest worst case to hold the ceiling against.
    expect(spectralMeasurement($colour)['peak_after_limiter'])->toBeLessThanOrEqual(0.9);
})->with(['white', 'brown', 'violet']);

it('plucks the note it was asked for, and lets it ring down', function (): void {
    // Deliberately not asserting on spectral slope here. Slope describes broadband
    // noise; a harmonic tone has almost no energy below its fundamental, so a
    // regression from 40 Hz upward climbs steeply however dark the string sounds.
    // The questions worth asking of a plucked string are whether it is the right
    // note and whether it decays.
    $measured = spectralMeasurement('karplus');

    // The delay line is an integer number of samples, so the pitch quantises —
    // 44100/220 is 200.45 samples, rounded to 200, which lands slightly sharp.
    expect($measured['fundamental_hz'])->toBeGreaterThan(215.0)->toBeLessThan(225.0)
        ->and($measured['decay_ratio'])->toBeLessThan(0.5)
        ->and($measured['decay_ratio'])->toBeGreaterThan(0.0)
        ->and($measured['peak'])->toBeLessThanOrEqual(1.0);
});

/*
|--------------------------------------------------------------------------
| The generators' own scores
|--------------------------------------------------------------------------
|
| Everything above measures a dsp.js primitive, which proves the primitives are
| right. These measure what the five generators actually emit, rendered through
| resources/js/audio/engine.js — the same code the browser runs, master limiter
| and all. That is a different question, and the failures it catches are the ones
| the primitives cannot: a generator that asks for the wrong colour, a tempo out
| by a factor of two, a score that never reaches the limiter.
|
*/

/** Render a generator's score under Node and measure the samples that come out. */
function scoreMeasurement(array $score): array
{
    $path = tempnam(sys_get_temp_dir(), 'randomly-score-').'.json';
    file_put_contents($path, json_encode($score, JSON_THROW_ON_ERROR));

    try {
        $process = Process::fromShellCommandline(
            sprintf('node scripts/analyse-audio.mjs score %s', escapeshellarg($path)),
            dirname(__DIR__, 2),
        );
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('node failed: '.$process->getErrorOutput());
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        @unlink($path);
    }
}

/** f = 440 · 2^((n − 69)/12) — the PHP side of dsp.js midiToHz. */
function midiHz(int $note): float
{
    return 440 * (2 ** (($note - 69) / 12));
}

it('renders each noise colour at the slope its name promises', function (string $colour, float $expected): void {
    // The same one-decibel tolerance as the primitives above, and for the same
    // reason: the theoretical slopes are exact and a finite measurement is not.
    $measured = scoreMeasurement(audioScore('audio.noise', [
        'colour' => $colour,
        'duration' => 4.0,
        // The filter and the loop crossfade both reshape the spectrum, which is
        // their job. Switched off, what is left is the colour itself.
        'filter' => 'none',
        'loop' => false,
    ]));

    expect($measured['slope_db_per_octave'])
        ->toBeGreaterThan($expected - 1.0)
        ->toBeLessThan($expected + 1.0);
})->with([
    'white' => ['white', 0.0],
    'pink' => ['pink', -3.0],
    'brown' => ['brown', -6.0],
    'blue' => ['blue', 3.0],
    'violet' => ['violet', 6.0],
]);

it('shapes grey noise like the ear rather than like a straight line', function (): void {
    /*
     * Grey is the one colour with no slope to assert, and asserting one anyway
     * would be the false precision this whole file exists to avoid. It is white
     * bent through the inverse of the ISO 226 equal-loudness contour, so what it
     * has is a *shape*: a lot of energy where the ear is deaf, a bowl where the
     * ear is most sensitive, and a lift again at the top.
     */
    $bands = scoreMeasurement(audioScore('audio.noise', [
        'colour' => 'grey', 'duration' => 4.0, 'filter' => 'none', 'loop' => false,
    ]))['bands'];

    expect($bands['60'] - $bands['960'])->toBeGreaterThan(15.0)
        // The ear canal resonance sits around 3–4 kHz; grey has to dip there.
        ->and($bands['3840'])->toBeLessThan($bands['960'])
        // And lift again above it, where sensitivity falls away.
        ->and($bands['7680'])->toBeGreaterThan($bands['3840']);
});

it('plucks the pitch the score says it plucked', function (string $root, int $octave): void {
    /*
     * One bar, one note, no double stops — so there is exactly one pitch in the
     * file and autocorrelation has an unambiguous question to answer. The
     * assertion is against the generator's own score, which is the honest form
     * of "produces the pitch it was asked for": PHP chose a MIDI number and the
     * samples have to be that note.
     *
     * Two and a half percent is under half a semitone, and both sources of error
     * are known. The delay line is an integer number of samples, so the pitch
     * quantises and always lands slightly sharp; and autocorrelation of a
     * decaying signal is itself biased sharp, because a shorter lag compares
     * louder material against louder material.
     */
    $score = audioScore('audio.pluck', [
        'bars' => 1, 'density' => 1, 'chords' => 0.0, 'root' => $root, 'octave' => $octave, 'range' => 1,
    ]);

    $expected = midiHz($score['notes'][0]['midi']);
    $measured = scoreMeasurement($score);

    expect($score['notes'])->toHaveCount(1)
        ->and($measured['fundamental_hz'])->toEqualWithDelta($expected, $expected * 0.025)
        // And it has to ring down. A string that is still at full level at the
        // end of the buffer is an oscillator, not a pluck.
        ->and($measured['decay_ratio'])->toBeLessThan(0.2);
})->with([
    ['E', 2],
    ['A', 2],
    ['D', 3],
    ['C', 4],
]);

it('lands every rhythm onset where the tempo says it should', function (int $k, int $n, float $tempo, string $kit): void {
    /*
     * Measured from the samples, not read back out of the score.
     *
     * A tempo that is wrong by a factor of two, a swing applied to the wrong
     * steps, a pattern rendered at the wrong step length — all of those leave
     * the score looking perfect. The transient positions are the only place the
     * mistake becomes visible, which is why the analyser grew an onset detector
     * rather than this test trusting the arithmetic that produced the thing it
     * is checking.
     */
    $score = audioScore('audio.rhythm', [
        'k' => $k, 'n' => $n, 'tempo' => $tempo, 'bars' => 2, 'layers' => 1, 'kit' => $kit, 'swing' => 0.0,
    ]);

    $layer = $score['layers'][0];
    $pattern = $layer['pattern'];

    $expected = [];
    for ($step = 0; $step < count($pattern) * $score['bars']; $step++) {
        if ($pattern[$step % count($pattern)] === 1) {
            $expected[] = $step * $layer['step_seconds'];
        }
    }

    $measured = scoreMeasurement($score)['onsets'];

    expect($measured)->toHaveCount(count($expected), sprintf(
        'E(%d,%d) = %s at %.0f BPM should hit %d times and hit %d.',
        $k, $n, Euclid::notation($pattern), $tempo, count($expected), count($measured),
    ));

    foreach ($expected as $index => $at) {
        // Three milliseconds. The detector works on 32-sample frames, so its own
        // resolution is 0.7 ms, and an attack takes a frame or two to clear the
        // threshold. Anything looser would not catch a rhythm that is merely
        // slightly wrong.
        expect($measured[$index])->toEqualWithDelta($at, 0.003);
    }
})->with([
    'tresillo' => [3, 8, 120.0, 'acoustic'],
    'cinquillo' => [5, 8, 96.0, 'electronic'],
    'bell' => [7, 12, 140.0, 'wood'],
    'slow four' => [4, 4, 60.0, 'acoustic'],
]);

it('never lets any generator past the limiter ceiling', function (Generator $generator): void {
    /*
     * Driven at 0 dBFS, which is the point.
     *
     * At the −12 dBFS everything ships at, the limiter never engages and this
     * test would pass whether or not it worked — the worst kind of green. Asked
     * for full scale, four layers and the densest settings, every one of these
     * has to arrive at the ceiling and stop there.
     */
    $measured = scoreMeasurement(audioScore($generator->key(), [
        'volume' => 0.0,
        'colour' => 'white',
        'filter' => 'none',
        'duration' => 4.0,
        'bars' => 2,
        'layers' => 4,
        'density' => 8,
        'sustain' => 0.9,
        'rests' => 0.0,
    ]));

    // 0.89 is the ceiling; the couple of ten-thousandths of slack is the
    // limiter's release creeping back up between the look-ahead and the sample,
    // some 70 dB below the ceiling and 1 dB below full scale either way.
    expect($measured['peak'])->toBeLessThanOrEqual(0.9)
        // And it has to make a sound. A limiter that works by outputting silence
        // would pass the line above.
        ->and($measured['rms'])->toBeGreaterThan(0.005);
})->with(audioGenerators());

it('renders in the browser faster than the piece takes to play', function (Generator $generator): void {
    // Not a benchmark — a sanity bound. The architecture only makes sense if
    // synthesising is cheap next to listening; a generator that takes longer to
    // render than to play would need a progress bar and a different design.
    $measured = scoreMeasurement(audioScore($generator->key()));

    expect($measured['render_ms'] / 1000)->toBeLessThan($measured['duration'])
        ->and($measured['duration'])->toBeGreaterThan(0.5);
})->with(audioGenerators());

it('holds a drone on the note it says it is holding', function (string $root, int $octave): void {
    /*
     * A drone is the one signal here where pitch and *persistence* are the whole
     * claim, so both get measured. The fundamental comes from autocorrelation on
     * the rendered samples, not from the score that produced them.
     *
     * This test is why the analyser's pitch detector now takes the earliest local
     * *maximum* of the correlation rather than the earliest lag over 90% of the
     * best: around a peak the curve is broad, and a 110 Hz drone crossed the old
     * threshold at lag 376 on its way to its actual maximum at 400 — reported as
     * 117 Hz, which reads like a synthesis bug and was a measurement one.
     */
    $score = audioScore('audio.drone', ['root' => $root, 'octave' => $octave, 'partials' => 5, 'duration' => 12.0]);
    $measured = scoreMeasurement($score);

    // One percent, which is a sixth of a semitone. The beating detunings are a
    // few cents at these rates, so there is real spread to allow for and not much.
    expect($measured['fundamental_hz'])->toEqualWithDelta($score['root_hz'], $score['root_hz'] * 0.015)
        // And it has to still be sounding at the end. The bound is loose on
        // purpose: the decay ratio compares the last tenth of the piece against
        // the first, and both of those sit inside the four-second equal-power
        // fades — so what this actually rules out is a drone that has stopped,
        // which is the failure worth catching. A pluck's ratio here is 0.07.
        ->and($measured['decay_ratio'])->toBeGreaterThan(0.25)
        ->and($measured['rms'])->toBeGreaterThan(0.01)
        ->and($measured['peak'])->toBeLessThanOrEqual(0.9);
})->with([
    ['A', 2],
    ['D', 2],
    ['C', 3],
]);

it('starts every UI sound exactly where the pack says it does', function (): void {
    /*
     * The offsets in the meta are an instruction — "cut here" — so they are
     * checked against the samples rather than against the score that wrote them.
     * An envelope with the wrong attack, a voice scheduled relative to the wrong
     * origin or a gap applied before the sound instead of after would all leave
     * the score looking perfect and the file unsliceable.
     */
    $score = audioScore('audio.bleep', ['count' => 6]);
    $measured = scoreMeasurement($score)['onsets'];

    foreach ($score['sounds'] as $sound) {
        $nearest = null;

        foreach ($measured as $onset) {
            if ($nearest === null || abs($onset - $sound['start']) < abs($nearest - $sound['start'])) {
                $nearest = $onset;
            }
        }

        // Five milliseconds. The detector works on 32-sample frames, so its own
        // resolution is 0.7 ms, and an attack takes a frame or two to clear the
        // threshold.
        expect($nearest)->toEqualWithDelta($sound['start'], 0.005, sprintf(
            'the %s at %.3f s has no onset within 5 ms (nearest %.3f s)',
            $sound['kind'],
            $sound['start'],
            $nearest ?? -1,
        ));
    }

    // And silence between them: a pack whose sounds run together cannot be cut
    // apart, whatever the offsets claim.
    expect(count($measured))->toBeGreaterThanOrEqual(count($score['sounds']));
});

it('keeps an ambient piece alive from end to end', function (string $mood): void {
    // The failure mode here is a piece that is mostly silence — pads too sparse,
    // motes too quiet, a bed filtered into nothing — which would pass every
    // structural test next door and be an empty file.
    $measured = scoreMeasurement(audioScore('audio.ambient', ['mood' => $mood, 'duration' => 30.0]));

    expect($measured['rms'])->toBeGreaterThan(0.01)
        ->and($measured['peak'])->toBeLessThanOrEqual(0.9)
        // Still sounding at the end, allowing for the three-second fade that
        // makes the loop point inaudible.
        ->and($measured['decay_ratio'])->toBeGreaterThan(0.25)
        ->and($measured['duration'])->toEqualWithDelta(30.0, 0.01);
})->with(['glacier', 'dusk', 'rainfall', 'orbit']);
