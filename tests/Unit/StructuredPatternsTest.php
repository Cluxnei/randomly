<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Patterns\DlaGenerator;
use App\Random\Generators\Patterns\LifeGenerator;
use App\Random\Generators\Patterns\LsystemGenerator;
use App\Random\Generators\Patterns\MazeGenerator;
use App\Random\Generators\Patterns\PoissonGenerator;
use App\Random\Generators\Patterns\SpectralGenerator;
use App\Random\Generators\Patterns\VoronoiGenerator;
use App\Random\Generators\Patterns\WalkGenerator;
use App\Random\Generators\Patterns\WfcGenerator;
use Symfony\Component\Process\Process;

/**
 * The pattern generators that produce *structure* rather than a field.
 *
 * These four are unusually testable for canvas generators, and the reason is that
 * each one makes a claim a machine can check. A maze is a spanning tree — every
 * cell reachable, exactly cells−1 passages — and that either holds or it does not.
 * Blue noise promises a minimum separation. Voronoi promises neighbouring cells
 * get different colours. None of those is a matter of taste, so none of them is
 * left to the eye.
 *
 * The maze carves on the PHP side and is checked directly. The blue-noise sampler
 * runs in the browser, because a dense field is tens of thousands of points and
 * far too many to ship — so its guarantee is checked by running the real renderer
 * through node and asserting on what it reports.
 */
function structuredSpec(Generator $generator, array $input = [], string $bytes = 'randomly-canvas!'): array
{
    $params = $generator->schema()->coerce($input);

    return $generator->generate(
        patternSeed($bytes)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    )->value;
}

/**
 * Unpack a maze panel back into one integer per cell.
 *
 * Deliberately a second implementation of the encoding rather than a shared one:
 * a packer and an unpacker that agree because they are the same code agree about
 * nothing at all.
 *
 * @return list<int> bit 0 is a passage east, bit 1 a passage south
 */
function unpackMaze(string $encoded, int $count): array
{
    $raw = base64_decode($encoded, true);
    $cells = [];

    for ($i = 0; $i < $count; $i++) {
        $cells[] = (ord($raw[intdiv($i, 4)]) >> (6 - 2 * ($i % 4))) & 3;
    }

    return $cells;
}

/**
 * Every cell reachable from cell 0, by flood fill across open passages only.
 *
 * @return array{int, int} cells reached, passages found
 */
function floodMaze(array $cells, int $cols, int $rows): array
{
    $passages = 0;
    foreach ($cells as $index => $cell) {
        $x = $index % $cols;
        $y = intdiv($index, $cols);

        // A passage recorded on the last column or the bottom row would point off
        // the grid, which would be a corrupt maze rather than an extra edge.
        expect($cell & 1)->toBe($x + 1 < $cols ? $cell & 1 : 0);
        expect($cell & 2)->toBe($y + 1 < $rows ? $cell & 2 : 0);

        $passages += ($cell & 1 ? 1 : 0) + ($cell & 2 ? 1 : 0);
    }

    $seen = [0 => true];
    $stack = [0];

    while ($stack !== []) {
        $i = array_pop($stack);
        $x = $i % $cols;
        $y = intdiv($i, $cols);

        $moves = [];
        if ($cells[$i] & 1) {
            $moves[] = $i + 1;
        }
        if ($cells[$i] & 2) {
            $moves[] = $i + $cols;
        }
        if ($x > 0 && ($cells[$i - 1] & 1)) {
            $moves[] = $i - 1;
        }
        if ($y > 0 && ($cells[$i - $cols] & 2)) {
            $moves[] = $i - $cols;
        }

        foreach ($moves as $next) {
            if (! isset($seen[$next])) {
                $seen[$next] = true;
                $stack[] = $next;
            }
        }
    }

    return [count($seen), $passages];
}

function nodeIsAvailable(): bool
{
    exec('node --version 2>/dev/null', $out, $code);

    return $code === 0;
}

// ── patterns.maze ────────────────────────────────────────────────────────────

it('carves a spanning tree with every algorithm it offers', function (string $algorithm): void {
    /*
     * The one property all three share, and the one worth testing.
     *
     * A spanning tree over n cells has exactly n−1 edges and connects all of them.
     * Get either half wrong and the picture still looks like a maze: too few edges
     * leaves an unreachable pocket, too many leaves a loop, and neither is visible
     * at a glance in a thousand-cell grid. Both halves are checked here, because a
     * count alone would pass a graph with one loop and one island.
     */
    $spec = structuredSpec(new MazeGenerator, ['algorithm' => $algorithm, 'cell' => 22]);

    expect($spec['panels'])->toHaveCount(1);

    $panel = $spec['panels'][0];
    $count = $panel['cols'] * $panel['rows'];
    $cells = unpackMaze($panel['cells'], $count);

    [$reached, $passages] = floodMaze($cells, $panel['cols'], $panel['rows']);

    expect($panel['algorithm'])->toBe($algorithm)
        ->and($reached)->toBe($count)
        ->and($passages)->toBe($count - 1);
})->with(['dfs', 'kruskal', 'wilson']);

it('carves three independent spanning trees when comparing', function (): void {
    $spec = structuredSpec(new MazeGenerator);

    expect($spec['panels'])->toHaveCount(3);

    $encodings = [];

    foreach ($spec['panels'] as $panel) {
        $count = $panel['cols'] * $panel['rows'];
        [$reached, $passages] = floodMaze(unpackMaze($panel['cells'], $count), $panel['cols'], $panel['rows']);

        expect($reached)->toBe($count)->and($passages)->toBe($count - 1);
        $encodings[] = $panel['cells'];
    }

    // Three mazes from one stream, not the same maze three times.
    expect(array_unique($encodings))->toHaveCount(3);
});

it('separates the algorithms by the shape of what they carve, not just by name', function (): void {
    /*
     * The generator's entire argument is that these three are not the same
     * distribution. If they ever start producing similar trees, the side-by-side
     * comparison is decoration rather than evidence — so the difference is asserted
     * rather than asserted-about-in-prose.
     *
     * Randomised DFS backtracks only when stuck, which is why its dead-end rate
     * sits far below the roughly 29% both Kruskal and Wilson's converge on, and why
     * its longest route is several times longer.
     */
    $spec = structuredSpec(new MazeGenerator, ['cell' => 16]);
    $stats = [];

    foreach ($spec['panels'] as $panel) {
        $count = $panel['cols'] * $panel['rows'];
        $cells = unpackMaze($panel['cells'], $count);
        $stats[$panel['algorithm']] = deadEndShare($cells, $panel['cols'], $panel['rows']);
    }

    expect($stats['dfs'])->toBeLessThan(0.2)
        ->and($stats['kruskal'])->toBeGreaterThan(0.22)
        ->and($stats['wilson'])->toBeGreaterThan(0.22);
});

function deadEndShare(array $cells, int $cols, int $rows): float
{
    $ends = 0;

    foreach ($cells as $i => $cell) {
        $x = $i % $cols;
        $y = intdiv($i, $cols);

        $degree = ($cell & 1 ? 1 : 0) + ($cell & 2 ? 1 : 0)
            + ($x > 0 && ($cells[$i - 1] & 1) ? 1 : 0)
            + ($y > 0 && ($cells[$i - $cols] & 2) ? 1 : 0);

        if ($degree === 1) {
            $ends++;
        }
    }

    return $ends / count($cells);
}

it('keeps the grid inside the budget however small the cell is dragged', function (): void {
    // Wilson's cost grows fast enough that an unbounded grid is a hung request
    // rather than a slow one. The cell size is grown to fit instead.
    $spec = structuredSpec(new MazeGenerator, ['width' => 2048, 'height' => 2048, 'cell' => 8]);

    foreach ($spec['panels'] as $panel) {
        expect($panel['cols'] * $panel['rows'])->toBeLessThanOrEqual(2000);
    }
});

// ── patterns.poisson ─────────────────────────────────────────────────────────

it('never lets two blue-noise points come closer than r, while uniform points do', function (): void {
    /*
     * The separation guarantee, measured by brute force over every pair — not
     * through the background grid the sampler itself uses to decide, which would
     * make the test agree with the bug it is looking for.
     *
     * The second half matters as much as the first. If the uniform comparison set
     * did not contain pairs closer than r, the two panels would be making the same
     * promise and the whole generator would have nothing to show.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the blue-noise check needs it.');
    }

    $process = Process::fromShellCommandline(
        'node scripts/check-blue-noise.mjs 420 480 18 30 123456789',
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $measured = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($measured['points'])->toBeGreaterThan(100)
        ->and($measured['closest_blue'])->toBeGreaterThanOrEqual(18.0)
        ->and($measured['closest_uniform'])->toBeLessThan(18.0);
});

it('emits a spec small enough to be worth emitting, for a field of any density', function (): void {
    // The sampler stays in the browser precisely so this stays true: at r = 5 over
    // a full-size canvas there are tens of thousands of points, and shipping them
    // would be megabytes.
    $spec = structuredSpec(new PoissonGenerator, ['width' => 2048, 'height' => 2048, 'radius' => 5.0]);

    expect(strlen(json_encode($spec)))->toBeLessThan(1024)
        ->and($spec['seed'])->toBeInt();
});

// ── patterns.voronoi ─────────────────────────────────────────────────────────

it('gives no cell the same colour as the site nearest to it', function (): void {
    /*
     * The reason this generator uses Palette::build() rather than Palette::ramp():
     * cells are discrete objects that have to be told apart. Assigning colours at
     * random would leave about one border in eight invisible, which is the failure
     * the greedy pass exists to prevent — so the nearest neighbour of every site
     * must differ from it.
     */
    $spec = structuredSpec(new VoronoiGenerator, ['sites' => 120, 'colours' => 8]);

    expect($spec['sites'])->toHaveCount(120);

    $collisions = 0;

    foreach ($spec['sites'] as $i => [$x, $y, $colour]) {
        $nearest = null;
        $best = PHP_INT_MAX;

        foreach ($spec['sites'] as $j => [$ox, $oy]) {
            if ($i === $j) {
                continue;
            }

            $d = ($ox - $x) ** 2 + ($oy - $y) ** 2;
            if ($d < $best) {
                $best = $d;
                $nearest = $j;
            }
        }

        expect($colour)->toBeGreaterThanOrEqual(0)->toBeLessThan(8);

        if ($spec['sites'][$nearest][2] === $colour) {
            $collisions++;
        }
    }

    // Greedy and backwards-looking, so a site coloured before its eventual nearest
    // neighbour existed can still collide. Random assignment over eight colours
    // would give around fifteen of these; the pass has to do far better than that.
    expect($collisions)->toBeLessThan(4);
});

it('keeps every site on the canvas', function (): void {
    $spec = structuredSpec(new VoronoiGenerator, ['width' => 640, 'height' => 400, 'sites' => 200]);

    foreach ($spec['sites'] as [$x, $y]) {
        expect($x)->toBeGreaterThanOrEqual(0)->toBeLessThan(640)
            ->and($y)->toBeGreaterThanOrEqual(0)->toBeLessThan(400);
    }
});

// ── patterns.lsystem ─────────────────────────────────────────────────────────

it('ships the four presets with rules that rewrite into themselves', function (string $preset, bool $stochastic): void {
    $spec = structuredSpec(new LsystemGenerator, ['preset' => $preset]);

    expect($spec['axiom'])->not->toBeEmpty()
        ->and($spec['iterations'])->toBeGreaterThan(0)
        ->and($spec['angle'])->toBeGreaterThan(0.0);

    $alternatives = 0;

    foreach ($spec['rules'] as $symbol => $productions) {
        expect(strlen((string) $symbol))->toBe(1)
            ->and($productions)->not->toBeEmpty();

        $alternatives += count($productions) > 1 ? 1 : 0;

        foreach ($productions as [$weight, $replacement]) {
            expect($weight)->toBeGreaterThan(0)
                ->and($replacement)->toBeString()->not->toBeEmpty();
        }
    }

    // Only the plant has alternative productions. The other three are the textbook
    // figures and are deterministic on purpose — a Koch snowflake with a stochastic
    // rule is not a Koch snowflake.
    expect($alternatives > 0)->toBe($stochastic);
})->with([
    ['plant', true],
    ['koch', false],
    ['dragon', false],
    ['sierpinski', false],
]);

it('lets the sliders override the preset and reports what it settled on', function (): void {
    $spec = structuredSpec(new LsystemGenerator, ['preset' => 'koch', 'iterations' => 3, 'angle' => 45.5]);

    expect($spec['iterations'])->toBe(3)
        ->and($spec['angle'])->toBe(45.5);

    // Zero on either slider means "whatever the preset says", so the defaults have
    // to come back as the preset's own numbers rather than as zero.
    $preset = structuredSpec(new LsystemGenerator, ['preset' => 'koch']);

    expect($preset['iterations'])->toBe(4)
        ->and($preset['angle'])->toBe(60.0);
});

it('warns how big the expansion gets before anyone drags the slider', function (): void {
    $params = (new LsystemGenerator)->schema()->coerce(['preset' => 'plant', 'iterations' => 7]);
    $result = (new LsystemGenerator)->generate(
        patternSeed()->rng('patterns.lsystem', 1, $params->fingerprint()),
        $params,
    );

    // Geometric growth: one more iteration of the plant is several times the work,
    // and the estimate is what keeps that from being a surprise.
    $smaller = (new LsystemGenerator)->schema()->coerce(['preset' => 'plant', 'iterations' => 5]);
    $before = (new LsystemGenerator)->generate(
        patternSeed()->rng('patterns.lsystem', 1, $smaller->fingerprint()),
        $smaller,
    );

    expect($result->meta['estimated_symbols'])->toBeGreaterThan($before->meta['estimated_symbols'] * 3);
});

// ── patterns.life ────────────────────────────────────────────────────────────

/** Run a rule over a known starting position through the real renderer. */
function lifeRun(string $birth, string $survive, string $shape, int $generations): array
{
    $process = Process::fromShellCommandline(
        sprintf('node scripts/check-life.mjs %s %s %s %d', $birth, $survive, $shape, $generations),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

it('parses B/S notation into the two tables it names', function (string $notation, array $birth, array $survive): void {
    /*
     * The one part of this generator with a known answer, which is why it lives in
     * PHP: B3/S23 is birth on exactly three and survival on two or three, whatever
     * the seed or the canvas says.
     *
     * Birth on zero is forced off in every rule. It is legal notation and on a
     * torus it lights every empty cell in the world at once, forever — a rule
     * that can only ever draw one rectangle.
     */
    [$parsedBirth, $parsedSurvive] = LifeGenerator::parseRule($notation);

    expect(array_keys($parsedBirth, 1, true))->toBe($birth)
        ->and(array_keys($parsedSurvive, 1, true))->toBe($survive)
        ->and($parsedBirth[0])->toBe(0);
})->with([
    ['B3/S23', [3], [2, 3]],
    ['b36/s23', [3, 6], [2, 3]],
    ['B1357/S1357', [1, 3, 5, 7], [1, 3, 5, 7]],
    ['B234/S', [2, 3, 4], []],
    // No letters at all: the convention is birth first.
    ['3/23', [3], [2, 3]],
    // Unparseable input falls back to Conway rather than rendering a dead canvas.
    ['nonsense', [3], [2, 3]],
    ['B0123/S', [1, 2, 3], []],
]);

it('leaves a still life still and gives a blinker period two', function (): void {
    /*
     * The known answers of the whole subject. A block is four cells each with
     * three neighbours, so every one survives and no empty cell has exactly
     * three — it cannot change. A blinker is three in a row, which becomes three
     * in a column, which becomes three in a row.
     *
     * This runs the renderer's own `step` through node, because that is where the
     * evolution lives. A second implementation written for the test would agree
     * with itself and prove nothing.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the Life check needs it.');
    }

    $block = lifeRun('3', '23', 'block', 4);
    expect($block['returned'])->toBe([true, true, true, true])
        ->and($block['population'])->toBe(4);

    $beehive = lifeRun('3', '23', 'beehive', 3);
    expect($beehive['returned'])->toBe([true, true, true])
        ->and($beehive['population'])->toBe(6);

    $blinker = lifeRun('3', '23', 'blinker', 4);
    expect($blinker['period'])->toBe(2)
        ->and($blinker['returned'])->toBe([false, true, false, true])
        ->and($blinker['population'])->toBe(3);
});

it('moves a glider one cell diagonally every four generations', function (): void {
    /*
     * The one position of the three that tests the rule's asymmetry rather than
     * its arithmetic. A glider is not symmetric, so a transposed neighbour count
     * or a swapped birth and survival table still leaves a block a block and a
     * blinker a blinker — and sends the glider somewhere else, or nowhere.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the Life check needs it.');
    }

    $glider = lifeRun('3', '23', 'glider', 4);

    expect($glider['population'])->toBe(5)
        ->and($glider['end_corner'])->toBe([
            $glider['start_corner'][0] + 1,
            $glider['start_corner'][1] + 1,
        ]);
});

it('caps the world and the generation count so a slider cannot hang the tab', function (): void {
    // Cells × generations is the real cost and both sliders multiply, so the
    // budget has to be enforced on the product rather than on either one.
    $spec = structuredSpec(new LifeGenerator, ['width' => 2048, 'height' => 2048, 'cell' => 1, 'generations' => 900]);

    expect($spec['cols'] * $spec['rows'])->toBeLessThanOrEqual(240000)
        ->and($spec['cols'] * $spec['rows'] * $spec['generations'])->toBeLessThanOrEqual(40000000);
});

// ── patterns.spectral ────────────────────────────────────────────────────────

it('produces a field whose measured spectral slope is the β it was asked for', function (float $beta): void {
    /*
     * The only falsifiable claim this generator makes, and the whole reason it is
     * built in the frequency domain rather than by stacking octaves: the spectrum
     * is a power law with an exponent you set, exactly, rather than approximately.
     *
     * The checker's forward transform is a separate implementation from the
     * renderer's inverse one on purpose — see the script. A field synthesised
     * wrongly and measured with the same wrongness can still read as a perfect
     * power law.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the spectral check needs it.');
    }

    $process = Process::fromShellCommandline(
        sprintf('node scripts/check-spectral-slope.mjs %s 256 99', $beta),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $measured = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    // A quarter of an exponent of tolerance. The measurement is a least-squares
    // fit over twenty-four bins of one realisation, so it has real scatter; the
    // point is that β = 2 never measures as 1 or as 3.
    expect($measured['slope'])->toBeGreaterThan(-$beta - 0.25)
        ->toBeLessThan(-$beta + 0.25);
})->with([[-1.0], [0.0], [1.0], [2.0], [3.0]]);

it('resolves a named colour of noise to its exponent, and a custom one to the slider', function (): void {
    expect(structuredSpec(new SpectralGenerator, ['preset' => 'white'])['beta'])->toBe(0.0)
        ->and(structuredSpec(new SpectralGenerator, ['preset' => 'pink'])['beta'])->toBe(1.0)
        ->and(structuredSpec(new SpectralGenerator, ['preset' => 'brown'])['beta'])->toBe(2.0)
        ->and(structuredSpec(new SpectralGenerator, ['preset' => 'blue'])['beta'])->toBe(-1.0)
        ->and(structuredSpec(new SpectralGenerator, ['preset' => 'custom', 'beta' => 1.45])['beta'])->toBe(1.45);
});

// ── patterns.wfc ─────────────────────────────────────────────────────────────

it('never places a window the sample does not contain', function (string $sample, int $n, int $symmetry): void {
    /*
     * The guarantee the overlapping model exists to deliver, stated precisely:
     * every N×N window of the output appears somewhere in the input, under the
     * symmetries allowed. That is stronger than "no two neighbours disagree" and
     * it is exactly checkable.
     *
     * The checker rebuilds the legal window set independently of the renderer's
     * extractor — see the script. Importing the extractor would test the solver
     * against the extractor's idea of the rules, which is the one pair of things
     * that could plausibly be wrong together.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the WFC check needs it.');
    }

    $process = Process::fromShellCommandline(
        sprintf('node scripts/check-wfc.mjs %s %d %d 60 40 4242', escapeshellarg($sample), $n, $symmetry),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result['violations'])->toBe(0)
        ->and($result['solved'])->toBeTrue()
        // A solve that only ever used two or three patterns would satisfy the
        // constraint and produce a flat wash, so the variety is asserted too.
        ->and($result['distinct_windows'])->toBeGreaterThan(10)
        ->and($result['windows'])->toBeGreaterThan(2000);
})->with([
    ['000121000,000121000,000121000,111111111,222222222,111111111,000121000,000121000,000121000', 3, 8],
    ['0000000000,0111111110,0100000010,0101110010,0101010010,0101011110,0100010000,0111110000,0000000000', 3, 8],
    ['0022000,0011000,2211122,0011000,0011000,2211122,0011000', 2, 4],
    ['00000000000,01111100000,01000100000,01000100000,01111111110,00000100010,00000100010,00000111110,00000000000', 3, 1],
]);

it('ships every bundled sample with a palette long enough to draw it', function (): void {
    // The sample's colour indices address the palette directly, so a palette
    // shorter than the sample's highest index would clamp two materials onto one
    // colour and the tiling would lose exactly the structure it is about.
    foreach (array_keys((new WfcGenerator)->schema()->params()['sample']->options) as $sample) {
        $spec = structuredSpec(new WfcGenerator, ['sample' => $sample]);

        $highest = 0;
        foreach ($spec['sample']['rows'] as $row) {
            $highest = max($highest, max(array_map('intval', str_split($row))));
        }

        expect(count($spec['palette']))->toBe($highest + 1)
            // Every sample must tile: the extractor reads it periodically, so a
            // ragged one would silently generate patterns that are half sample
            // and half wrap.
            ->and(array_unique(array_map('strlen', $spec['sample']['rows'])))->toHaveCount(1);
    }
});

it('coarsens the grid rather than cropping it when the cell count runs over', function (): void {
    // The solve is cells × patterns × adjacency. Cropping would silently render a
    // corner of the picture the parameters asked for; coarsening renders all of
    // it, less finely.
    $spec = structuredSpec(new WfcGenerator, ['width' => 2048, 'height' => 2048, 'tiles' => 140]);

    expect($spec['cols'] * $spec['rows'])->toBeLessThanOrEqual(3400)
        ->and($spec['cols'])->toBeGreaterThan(8);
});

// ── patterns.walk ────────────────────────────────────────────────────────────

it('scales each walk so all three cover the same ground from the same step count', function (): void {
    /*
     * The comparison is the entire point of this generator, and it only means
     * anything if the three panels are drawing walks of the same *size*. Each
     * mode's step is solved backwards from its own exponent — √n for Brownian,
     * n^(3/4) for self-avoiding, n^(1/α) for Lévy — so the reach is equal and
     * shape is the only thing left to differ.
     */
    $spec = structuredSpec(new WalkGenerator, ['steps' => 2500, 'tail' => 1.4]);

    expect($spec['panels'])->toHaveCount(3);

    $reach = [];

    foreach ($spec['panels'] as $panel) {
        $exponent = match ($panel['mode']) {
            'brownian' => 0.5,
            'saw' => 0.75,
            default => 1 / 1.4,
        };

        // A self-avoiding walk is scaled to the length it will actually reach
        // before trapping itself, not to the length it was asked for.
        $length = $panel['mode'] === 'saw' ? 71 : $spec['steps'];
        $reach[$panel['mode']] = $panel['step'] * $length ** $exponent;
    }

    expect($reach['brownian'])->toBeGreaterThan(0.0);

    foreach ($reach as $mode => $value) {
        // Brownian carries an extra √2 because its step is per axis; the rest
        // land on the same number.
        expect($value)->toBeGreaterThan($reach['levy'] * 0.6)
            ->toBeLessThan($reach['levy'] * 1.4, "[{$mode}] is scaled differently from the others");
    }
});

it('gives the self-avoiding panel many short walks rather than a few long ones', function (): void {
    // A growing self-avoiding walk traps itself after about seventy steps on the
    // square lattice and cannot be asked for more, so its panel has to spend the
    // budget differently from the other two.
    $spec = structuredSpec(new WalkGenerator);

    $panels = collect($spec['panels'])->keyBy('mode');

    expect($panels['saw']['walkers'])->toBeGreaterThan($panels['brownian']['walkers'])
        ->and($panels['saw']['cell'])->toBeGreaterThanOrEqual(2.0)
        ->and($panels['saw']['cols'] * $panels['saw']['rows'])->toBeGreaterThan(100);
});

// ── patterns.dla ─────────────────────────────────────────────────────────────

it('keeps the lattice inside its budget however fine the cell is dragged', function (): void {
    // A particle's cost is how far it has to walk, which grows with the launch
    // circle and therefore with the lattice — halving the cell roughly triples
    // the render.
    $spec = structuredSpec(new DlaGenerator, ['width' => 2048, 'height' => 2048, 'cell' => 1]);

    expect($spec['cols'] * $spec['rows'])->toBeLessThanOrEqual(260000)
        ->and($spec['particles'])->toBeLessThanOrEqual(intdiv($spec['cols'] * $spec['rows'], 2));
});
