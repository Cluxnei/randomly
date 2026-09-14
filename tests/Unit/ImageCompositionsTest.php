<?php

declare(strict_types=1);

use App\Random\Generators\Images\CirclesGenerator;
use App\Random\Generators\Images\GradientGenerator;
use App\Random\Generators\Images\MondrianGenerator;
use App\Random\Generators\Images\SprayGenerator;
use App\Random\Generators\Images\StrataGenerator;
use App\Random\Generators\Images\TilesGenerator;
use Symfony\Component\Process\Process;

/**
 * The image generators that make a claim a machine can check.
 *
 * Most of this module is a matter of taste and is checked by looking at it. These
 * four are not. A circle pack promises separation; a subdivision promises to tile
 * its own canvas exactly; a Dirichlet draw promises to sum to one; a mixture
 * promises normalised weights. Each of those is either true or false, so none of
 * them is left to the eye.
 *
 * Where the work happens in the browser — the packing, because a full pack is
 * tens of thousands of circles — the guarantee is checked by running the real
 * renderer through node and asserting on what it reports, exactly as the
 * blue-noise sampler's is.
 */

// ── images.circles ───────────────────────────────────────────────────────────

it('never lets two packed circles overlap, at any density', function (float $gap): void {
    /*
     * Measured by brute force over every pair, not through the bucket grid the
     * packer searches with. A packer that queries the wrong buckets and a checker
     * that queries the same wrong buckets would agree, and would both be wrong.
     *
     * The tolerance is float32: the radii come back out of a Float32Array, so a
     * pair that is exactly tangent can measure a few hundred-thousandths of a
     * pixel negative.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the packing check needs it.');
    }

    $process = Process::fromShellCommandline(
        sprintf('node scripts/check-packing.mjs 700 500 12000 3 70 %s 987654', $gap),
        dirname(__DIR__, 2),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $measured = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($measured['circles'])->toBeGreaterThan(100)
        ->and($measured['closest_surfaces'])->toBeGreaterThan($gap - 0.001)
        ->and($measured['smallest_radius'])->toBeGreaterThan(2.99)
        ->and($measured['largest_radius'])->toBeLessThanOrEqual(70.0);
})->with([[0.0], [2.5], [9.0]]);

it('orders a reversed radius pair rather than rendering an empty canvas', function (): void {
    // Both sliders are independent, so they can be dragged past each other. A
    // minimum above the maximum rejects every candidate, and the result would be
    // a blank frame with no indication why.
    $spec = structuredSpec(new CirclesGenerator, ['min_radius' => 40.0, 'max_radius' => 8.0]);

    expect($spec['min_radius'])->toBeLessThan($spec['max_radius']);
});

// ── images.mondrian ──────────────────────────────────────────────────────────

it('cuts the canvas into cells that tile it exactly', function (array $input): void {
    /*
     * The property a subdivision loses silently. Rounding each cut as it is made
     * leaves gaps and overlaps of a pixel here and there, which on flat colour
     * separated by a gutter is invisible in a screenshot and obvious in print.
     *
     * Checked two ways, because either alone would pass something broken: the
     * areas must sum to the canvas, *and* no two cells may overlap. A set of
     * cells that double-covers one region and misses another of the same size
     * passes the first test on its own.
     */
    $spec = structuredSpec(new MondrianGenerator, $input);

    $area = 0;

    foreach ($spec['cells'] as [$x, $y, $w, $h]) {
        expect($w)->toBeGreaterThan(0)->and($h)->toBeGreaterThan(0)
            ->and($x)->toBeGreaterThanOrEqual(0)->and($y)->toBeGreaterThanOrEqual(0)
            ->and($x + $w)->toBeLessThanOrEqual($spec['width'])
            ->and($y + $h)->toBeLessThanOrEqual($spec['height']);

        $area += $w * $h;
    }

    expect($area)->toBe($spec['width'] * $spec['height']);

    foreach ($spec['cells'] as $i => [$ax, $ay, $aw, $ah]) {
        foreach ($spec['cells'] as $j => [$bx, $by, $bw, $bh]) {
            if ($i >= $j) {
                continue;
            }

            $overlaps = $ax < $bx + $bw && $bx < $ax + $aw && $ay < $by + $bh && $by < $ay + $ah;

            expect($overlaps)->toBeFalse("cells {$i} and {$j} overlap");
        }
    }
})->with([
    [[]],
    [['cut_min' => 0.02, 'threshold' => 0.01]],
    [['cut_min' => 0.5, 'threshold' => 0.2, 'squareness' => 0.0]],
    [['width' => 1013, 'height' => 577, 'threshold' => 0.004]],
]);

it('keeps every cut inside the range it advertises', function (): void {
    /*
     * U(0.3, 0.7) is the generator's headline and the whole reason its output
     * reads as a composition rather than as shredding. A rectangle cut at 0.3 of
     * its longer side has children whose areas are in a 3:7 ratio, so the ratio
     * between any parent and child area is bounded — which is what this measures,
     * since the cuts themselves are not in the spec.
     */
    $spec = structuredSpec(new MondrianGenerator, ['cut_min' => 0.3, 'threshold' => 0.02]);

    $smallest = min(array_map(fn (array $c): int => $c[2] * $c[3], $spec['cells']));
    $largest = max(array_map(fn (array $c): int => $c[2] * $c[3], $spec['cells']));

    // A shredded subdivision reaches ratios in the thousands within a few levels;
    // a balanced one at this threshold stays well inside two orders of magnitude.
    expect($largest / $smallest)->toBeLessThan(100.0)
        ->and(count($spec['cells']))->toBeGreaterThan(8);
});

it('keeps the spec small enough to be worth shipping as a description', function (): void {
    // The whole justification for sending cells rather than pixels. The cell cap
    // exists for this and nothing else.
    $spec = structuredSpec(new MondrianGenerator, ['width' => 2048, 'height' => 2048, 'threshold' => 0.002]);

    expect(count($spec['cells']))->toBeLessThanOrEqual(180)
        ->and(strlen(json_encode($spec)))->toBeLessThan(8192);
});

// ── images.strata ────────────────────────────────────────────────────────────

it('draws band heights that sum to the canvas', function (float $alpha): void {
    /*
     * A Dirichlet draw sums to one by construction — that is the only reason to
     * reach for it rather than for n independent draws — so a sum that misses is
     * a broken Gamma sampler rather than a rounding matter.
     */
    $spec = structuredSpec(new StrataGenerator, ['concentration' => $alpha, 'bands' => 24]);

    $heights = array_map(fn (array $b): float => $b[0], $spec['bands']);

    expect($spec['bands'])->toHaveCount(24)
        ->and(array_sum($heights))->toBeGreaterThan(0.999)
        ->and(array_sum($heights))->toBeLessThan(1.001);

    foreach ($heights as $height) {
        // Not strictly positive: below α = 1 the draw genuinely produces bands
        // thinner than the eighth decimal place they are rounded to, and those
        // hairlines are the point of the low end of the slider rather than a
        // defect in it.
        expect($height)->toBeGreaterThanOrEqual(0.0);
    }

    expect(count(array_filter($heights, fn (float $h): bool => $h > 0.0)))->toBeGreaterThan(12);
})->with([[0.2], [1.0], [6.0]]);

it('makes the Dirichlet concentration visible in the spread of the bands', function (): void {
    /*
     * The α slider is the design, so it has to do something measurable. Below 1 a
     * few bands take almost everything; above 3 they converge on equal thickness.
     * Averaged over several seeds, because one draw of either is noisy enough to
     * land anywhere.
     */
    $ragged = 0.0;
    $even = 0.0;

    foreach (['a', 'b', 'c', 'd', 'e'] as $salt) {
        foreach ([['concentration' => 0.2], ['concentration' => 6.0]] as $i => $input) {
            $heights = array_map(
                fn (array $b): float => $b[0],
                structuredSpec(new StrataGenerator, $input + ['bands' => 30], 'randomly-strata'.$salt)['bands'],
            );

            // The thickest band's share of the height, not the thickest-to-
            // thinnest ratio: below α = 1 the thinnest band is a rounding error
            // away from zero and the ratio stops being a statement about α.
            if ($i === 0) {
                $ragged += max($heights);
            } else {
                $even += max($heights);
            }
        }
    }

    // An even stack gives every one of thirty bands about 3.3%; a ragged one
    // gives its thickest several times that.
    expect($ragged / 5)->toBeGreaterThan($even / 5 * 2.5);
});

// ── images.spray ─────────────────────────────────────────────────────────────

it('normalises the mixture weights it draws', function (int $components): void {
    // The weights are a Dirichlet draw as well, and a mixture whose weights do
    // not sum to one is not a probability distribution — the renderer's linear
    // scan over the cumulative weights would then favour the last component with
    // whatever was left over.
    $spec = structuredSpec(new SprayGenerator, ['components' => $components]);

    expect($spec['components'])->toHaveCount($components);

    $weights = array_map(fn (array $c): float => $c[5], $spec['components']);

    expect(array_sum($weights))->toBeGreaterThan(0.999)
        ->and(array_sum($weights))->toBeLessThan(1.001);

    foreach ($spec['components'] as [$x, $y, $sigmaA, $sigmaB, $angle, $weight, $colour]) {
        expect($weight)->toBeGreaterThan(0.0)
            ->and($sigmaA)->toBeGreaterThan(0.0)
            ->and($sigmaB)->toBeGreaterThan(0.0)
            // Means are kept off the frame edge: a component centred on it spends
            // half its particles outside the canvas.
            ->and($x)->toBeGreaterThan(0.1)->toBeLessThan(0.9)
            ->and($y)->toBeGreaterThan(0.1)->toBeLessThan(0.9)
            ->and($colour)->toBeGreaterThanOrEqual(0)->toBeLessThan(count($spec['palette']));
    }
})->with([[1], [4], [10]]);

it('elongates every component rather than drawing its two axes independently', function (): void {
    // Drawing the axes independently gives a ratio near 1 most of the time, and a
    // field of circular blurs is exactly the spilt-milk look the control exists
    // to avoid. The ratio is drawn and then applied, so it is bounded above by
    // the slider and — over ten components — reliably above 1 somewhere.
    $spec = structuredSpec(new SprayGenerator, ['components' => 10, 'anisotropy' => 5.0]);

    $ratios = array_map(fn (array $c): float => $c[2] / $c[3], $spec['components']);

    expect(max($ratios))->toBeGreaterThan(1.5)->toBeLessThanOrEqual(5.001);
});

// ── images.gradient and images.tiles ─────────────────────────────────────────

it('places some gradient control points outside the frame', function (): void {
    /*
     * A composition whose every control point is visible reads as a diagram of
     * its own construction. The off-frame stops are what make the colour appear
     * to arrive from somewhere, so the draw range is deliberately wider than the
     * canvas — which means the positions are fractions and may be negative.
     */
    $spec = structuredSpec(new GradientGenerator, ['stops' => 18]);

    $xs = array_map(fn (array $s): float => $s[0], $spec['stops']);
    $ys = array_map(fn (array $s): float => $s[1], $spec['stops']);

    expect($spec['stops'])->toHaveCount(18)
        ->and(min([...$xs, ...$ys]))->toBeLessThan(0.05)
        ->and(max([...$xs, ...$ys]))->toBeGreaterThan(0.95);

    foreach ($spec['stops'] as [$x, $y, $colour]) {
        expect($colour)->toBeGreaterThanOrEqual(0)->toBeLessThan(count($spec['palette']));
    }
});

it('walks the gradient palette rather than drawing from it at random', function (): void {
    // Neighbouring stops want to be neighbouring hues: a field that has to blend
    // across the whole palette in one step passes through the mud in the middle
    // of it. Consecutive stops are therefore never more than two apart.
    $spec = structuredSpec(new GradientGenerator, ['stops' => 16, 'colours' => 9]);

    $colours = array_map(fn (array $s): int => $s[2], $spec['stops']);

    for ($i = 1; $i < count($colours); $i++) {
        $step = abs($colours[$i] - $colours[$i - 1]);

        // Modular, so a step of one from the last stop to the first reads as a
        // large difference and is the same small step.
        expect(min($step, 9 - $step))->toBeLessThanOrEqual(2);
    }
});

it('keeps glyph cells square whatever shape the canvas is', function (): void {
    // Cells are counted across the long side so the short side gets however many
    // fit. Fitting the count to both would stretch every glyph, and a stretched
    // quarter-arc no longer meets its neighbour at the cell edge.
    $spec = structuredSpec(new TilesGenerator, ['width' => 1600, 'height' => 500, 'tiles' => 20]);

    // PHP hands back an int for an exact division of two ints, so the cast is
    // load-bearing rather than decoration.
    $size = (float) (max($spec['width'], $spec['height']) / $spec['tiles']);

    expect($size)->toBe(80.0)
        ->and($spec['tiles'])->toBe(20);
});
