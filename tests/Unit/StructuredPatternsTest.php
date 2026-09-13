<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Patterns\LsystemGenerator;
use App\Random\Generators\Patterns\MazeGenerator;
use App\Random\Generators\Patterns\PoissonGenerator;
use App\Random\Generators\Patterns\VoronoiGenerator;
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
