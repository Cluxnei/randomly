<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Three pattern generators whose claim is a *number*.
 *
 * `patterns.simplex` says its lattice has no preferred direction where Perlin's
 * has four. `patterns.walk` says its three panels are three different processes
 * rather than one process at three scales. `patterns.dla` says its cluster is
 * connected and fractal. None of those is a matter of taste, and none of them is
 * visible in the spec PHP ships — all three live in the renderer, because a
 * field is half a million samples, a walk is a few hundred thousand steps and a
 * cluster is thousands of particles, and none of that would fit in an 8 KB
 * render stream.
 *
 * So each one is measured by running the real renderer under node and asserting
 * on what it reports, exactly as the blue-noise separation, the spectral slope
 * and the Life positions already are. The scripts under scripts/ carry the
 * argument for each measurement; what is here is the thresholds and why they sit
 * where they do.
 *
 * Every threshold below is deliberately loose. These are measurements of one
 * realisation and they have scatter; the point is never that β is 2.03 rather
 * than 2.00, it is that a correct implementation cannot land anywhere near the
 * other side of the line. Where a measured value is quoted in a comment it was
 * taken across several seeds, and the bound is set well outside that spread.
 */
function measuredCheck(string $script, string $arguments): array
{
    $process = Process::fromShellCommandline(
        sprintf('node scripts/%s %s', $script, $arguments),
        dirname(__DIR__, 2),
    );

    // The DLA runs grow six thousand particles between them; the default 60
    // seconds is a coin flip on a loaded machine rather than a real limit.
    $process->setTimeout(300);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

// ── patterns.simplex ─────────────────────────────────────────────────────────

it('keeps the noise field inside the range its shaping variants assume', function (): void {
    /*
     * OpenSimplex2's kernel radius and its normalising constant are a matched
     * pair, and mismatching them is the easiest mistake in the file to make and
     * the hardest to see. A plain fBm hides it completely — paintField stretches
     * whatever range it is handed — but `turbulence`, `ridged` and `billow` all
     * fold the field around fixed constants (|n|, 1 − |n|, 2|n| − 1), so a field
     * several times too large turns those three into flat washes.
     *
     * Both halves matter. A field that never leaves [−0.2, 0.2] satisfies an
     * upper bound on its own and is just as broken: the same three variants then
     * fold around constants the field never reaches, and the shape control stops
     * changing the shape.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the simplex check needs it.');
    }

    $measured = measuredCheck('check-simplex.mjs', 'range 20250914 400');

    expect($measured['max'])->toBeLessThanOrEqual(1.0)
        ->and($measured['min'])->toBeGreaterThanOrEqual(-1.0)
        ->and($measured['max'])->toBeGreaterThan(0.9)
        ->and($measured['min'])->toBeLessThan(-0.9);
});

it('leaves no crease along the simplex lattice', function (): void {
    /*
     * The four branches in `simplex2` decide which of the surrounding lattice
     * points are still within kernel range. Get one wrong and a contribution is
     * counted on one side of a triangle edge and dropped on the other, which is
     * a step discontinuity — a hard crease running along the lattice, obvious in
     * a render and invisible to every other test here.
     *
     * Checked by scale rather than by size, because "how large a jump is too
     * large" has no principled answer and "how should the jump change when the
     * samples move closer together" has an exact one. A continuous field's
     * largest sample-to-sample difference is proportional to the spacing, so
     * quartering the spacing quarters it. A crease is the same height however
     * finely it is approached, so the ratio collapses towards 1.
     *
     * Measured at 3.997 across seeds. The window is wide enough to cover any
     * realisation and nowhere near wide enough to admit a discontinuity.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the simplex check needs it.');
    }

    $measured = measuredCheck('check-simplex.mjs', 'continuity 20250914 400');

    expect($measured['jump_coarse'] / $measured['jump_fine'])
        ->toBeGreaterThan(3.2)
        ->toBeLessThan(4.8);
});

it('carries none of the square lattice Perlin cannot shake off', function (): void {
    /*
     * The generator's headline, measured: the gradient directions of the field,
     * decomposed into angular harmonics.
     *
     * A perfectly isotropic field puts no energy into any of them. Perlin puts a
     * great deal into the 4-fold term — its lattice repeats every 90° — and into
     * the 8-fold one, because its eight gradients sit every 45°. Those two
     * numbers are what "square" means here, and they are exactly what an
     * equilateral lattice with twenty-four gradients has no reason to carry.
     *
     * Perlin is measured alongside rather than quoted from memory, because a
     * near-zero reading from the simplex side is equally consistent with an
     * isotropic field and with a probe that has quietly stopped working. The
     * 6-fold assertion is the other half of the same precaution: that is the
     * triangular lattice's own symmetry and it must be *present*. An instrument
     * has to be shown finding something before its not finding something means
     * anything.
     *
     * Measured across seeds at 0.133-0.137 and 0.109-0.117 for Perlin against
     * 0.003-0.006 and 0.001-0.004 for simplex. The thresholds are an order of
     * magnitude apart because the effect is.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the simplex check needs it.');
    }

    $measured = measuredCheck('check-simplex.mjs', 'isotropy 20250914 400');

    expect($measured['perlin']['4'])->toBeGreaterThan(0.08)
        ->and($measured['perlin']['8'])->toBeGreaterThan(0.07)
        ->and($measured['simplex']['4'])->toBeLessThan(0.03)
        ->and($measured['simplex']['8'])->toBeLessThan(0.03);

    expect($measured['simplex']['6'])->toBeGreaterThan(0.02)
        ->and($measured['perlin']['6'])->toBeLessThan($measured['perlin']['4']);
});

it('draws both halves of comparison mode at the same feature size', function (): void {
    /*
     * Comparison mode is the evidence for the test above, and it only works as
     * evidence if the two halves differ in one thing. An OpenSimplex2 field is
     * about 45% finer than a Perlin field at the same frequency, so without a
     * correction the eye reads the picture as "the left side is busier" and
     * stops there — a fact about two normalising constants, presented as a fact
     * about two lattices.
     *
     * The correction is a single constant inside the renderer, which is why this
     * is measured off the rendered picture rather than by applying a copy of the
     * constant here: a check with its own copy would keep passing after the
     * renderer's had been changed.
     *
     * Both numbers are asserted. The corrected ratio lands at 0.99-1.05 across
     * seeds; the uncorrected one — the same two fields at the same frequency —
     * at 1.44-1.45, which is what the correction is there to remove and what
     * would come back if it were dropped.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the simplex check needs it.');
    }

    $measured = measuredCheck('check-simplex.mjs', 'match 20250914');

    expect($measured['ratio'])->toBeGreaterThan(0.85)->toBeLessThan(1.15)
        ->and($measured['uncorrected_ratio'])->toBeGreaterThan(1.3);
});

// ── patterns.walk ────────────────────────────────────────────────────────────

it('gives a Lévy flight a heavy tail and a Brownian walk none', function (): void {
    /*
     * The difference the generator exists to show, as a number.
     *
     * A Gaussian step length has a finite variance, so the longest of twenty
     * thousand steps is a small multiple of the typical one — √(2 ln n) of them,
     * about four, and it cannot be otherwise. A Pareto tail with α below 2 has
     * infinite variance and routinely produces a single step a thousand times
     * the median: that one segment crosses the frame while the rest mill about
     * in a corner, which is the shape the panel is there to show.
     *
     * Measured at 3.9-4.1 for Brownian against 1800-5900 for Lévy at α = 1.35.
     * The two thresholds are an order of magnitude clear of both, and of each
     * other: nothing that could reasonably be called a Gaussian step reaches 10,
     * and nothing with a genuine heavy tail fails to reach 100.
     *
     * `breaks` is the measurement's own audit. A segment clipped at the panel
     * edge would be recorded shorter than it was, and the segments clipping
     * would take are precisely the long ones — so a non-zero count means the
     * numbers above are not the walk's and the comparison is worthless.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the walk check needs it.');
    }

    $brownian = measuredCheck('check-walk.mjs', 'brownian 4 20000 1.35 4242');
    $levy = measuredCheck('check-walk.mjs', 'levy 4 20000 1.35 4242');

    expect($brownian['breaks'])->toBe(0)
        ->and($levy['breaks'])->toBe(0)
        ->and($brownian['segments'])->toBe(80000)
        ->and($levy['segments'])->toBe(80000);

    expect($brownian['longest_over_median'])->toBeLessThan(10.0)
        ->and($levy['longest_over_median'])->toBeGreaterThan(100.0);

    // The same statement from the other side: what share of everything the walk
    // covered was covered in its single longest step. A Gaussian walk spreads it
    // evenly over twenty thousand steps and gives each about 1/20000 of the
    // total; the Lévy flight puts a full percent of its whole journey into one.
    expect($brownian['longest_share'])->toBeLessThan(0.001)
        ->and($levy['longest_share'])->toBeGreaterThan(0.005);
});

it('draws Lévy step lengths with the tail exponent the slider asks for', function (float $alpha): void {
    /*
     * The α slider's only falsifiable claim, and the same move the spectral
     * generator's slope test makes: the parameter is not merely stored and
     * echoed back in the caption, it is recoverable from the output.
     *
     * Hill's estimator over the two thousand longest steps — the maximum
     * likelihood estimate of a Pareto exponent, and a measurement that touches
     * only the tail, which is the part the slider is about. It is worth pinning
     * across the range rather than at one value: an inverse transform with the
     * exponent the wrong way up still produces a heavy tail and would pass the
     * test above, while measuring here as 1/α instead of α.
     *
     * Recovered to within 0.06 of the requested exponent at every setting
     * tested, so a quarter of an exponent of tolerance is generous by four
     * times over and still nowhere near enough to confuse 1.1 with 3.0.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the walk check needs it.');
    }

    $measured = measuredCheck('check-walk.mjs', sprintf('levy 4 20000 %s 4242', $alpha));

    expect($measured['breaks'])->toBe(0)
        ->and($measured['tail_exponent'])
        ->toBeGreaterThan($alpha - 0.25)
        ->toBeLessThan($alpha + 0.25);
})->with([[1.05], [1.35], [2.0], [3.0]]);

it('measures a Gaussian walk as having no tail to speak of', function (): void {
    // The control for the test above. Applied to a Brownian panel the same
    // estimator returns something near 9 — a "tail exponent" for a distribution
    // that has no power-law tail at all, which is the right answer in the only
    // sense the number can have: nothing like a power law is there.
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the walk check needs it.');
    }

    $measured = measuredCheck('check-walk.mjs', 'brownian 4 20000 1.35 4242');

    expect($measured['tail_exponent'])->toBeGreaterThan(5.0);
});

it('never lets a self-avoiding walk step onto a cell it has already used', function (): void {
    /*
     * The definition of the process, checked against the cells the renderer
     * actually drew rather than against the `visited` grid the walk consults to
     * decide — which is the one array that must not be asked whether the rule it
     * enforces held.
     *
     * A single revisit is a failure, not a statistic. The walk's whole character
     * comes from the constraint: a ribbon that may cross itself once may cross
     * itself anywhere, and what it draws is a Brownian tangle.
     *
     * `walks` is checked against the walker count because the stream is split
     * back into walks on discontinuity, and two walkers that happened to start
     * where the last one ended would be spliced into one — which could then look
     * like a revisit that never happened. A mismatch there means the *test* is
     * wrong and should say so rather than failing somewhere else.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the walk check needs it.');
    }

    $measured = measuredCheck('check-walk.mjs', 'saw 400 4000 1.35 4242');

    expect($measured['walks'])->toBe(400)
        ->and($measured['revisits'])->toBe(0)
        ->and($measured['off_lattice'])->toBe(0)
        // Every step is exactly one lattice cell. A step of any other length is
        // a walk that left its lattice or a segment the clipper trimmed.
        ->and($measured['off_pitch'])->toBe(0);

    /*
     * And the fact the panel is designed around: a growing self-avoiding walk on
     * the square lattice traps itself after about seventy steps. The generator
     * hard-codes 71 — it scales the lattice and divides the segment budget by it
     * — so if the true figure ever drifted, every SAW panel would be laid out
     * for a walk length that does not happen.
     *
     * Measured at 70.3 over four hundred walks. The window is wide because four
     * hundred samples of a heavily skewed distribution are noisy, and narrow
     * enough that "the walks now run to four hundred steps" cannot hide in it.
     */
    expect($measured['mean_length'])->toBeGreaterThan(40.0)->toBeLessThan(140.0)
        // The long tail is part of what the panel shows, so a run that never
        // beats the average would mean the walks were being cut off rather than
        // trapping.
        ->and($measured['longest_walk'])->toBeGreaterThan(150);
});

// ── patterns.dla ─────────────────────────────────────────────────────────────

it('grows a cluster that is connected and fractal', function (): void {
    /*
     * Two claims, one run, because growing the clusters is the expensive part.
     *
     * **Connected.** A particle freezes only where it touches something already
     * frozen, so the aggregate is one eight-connected piece by construction and
     * a stray cell means a particle stuck to nothing. That is not a cosmetic
     * defect: the contact test and the "is this cell already occupied" guard are
     * the two things standing between this and a cloud of dots, and a flood fill
     * over what was drawn is the only check that notices when one of them stops
     * working.
     *
     * **Fractal.** The radius of gyration grows as N^(1/D). Planar DLA's
     * dimension is about 1.71, which means eight times the particles make a
     * cluster a little over three times as wide — so the ratio below is the
     * measurement, and the implied dimension is what it means.
     *
     * That second one guards a failure the generator's own comments record:
     * launching particles from half the *long* side of a landscape lattice grew
     * two stringy diagonal arms at D ≈ 1.54. It still looked like a dendrite. It
     * was the wrong dendrite, and nothing short of measuring it would have said
     * so.
     *
     * Both clusters must be complete. A run that stopped early because the
     * cluster reached the edge of the board says nothing about how a cluster of
     * the requested size scales, and its radius would be the board's rather than
     * its own.
     */
    if (! nodeIsAvailable()) {
        $this->markTestSkipped('node is not available; the DLA check needs it.');
    }

    [$small, $large] = measuredCheck('check-dla.mjs', '460 20250914 500,4000');

    foreach ([$small, $large] as $cluster) {
        expect($cluster['placed'])->toBe($cluster['asked'])
            ->and($cluster['cells'])->toBe($cluster['asked'])
            ->and($cluster['reached'])->toBe($cluster['cells']);
    }

    // Eight times the particles. Linear growth would be a factor of eight and a
    // dimension of 1 — a cluster that grows outward without ever branching.
    $growth = $large['radius_of_gyration'] / $small['radius_of_gyration'];

    expect($growth)->toBeLessThan(8.0);

    // Measured at 3.23-3.40 across seeds, which is a dimension of 1.70-1.77
    // against the 1.71 the literature gives and the generator's meta quotes. The
    // window admits anything from a sparse 1.4 to a nearly solid 2.1 and still
    // excludes both a non-branching cluster and a blob.
    $dimension = log(8.0) / log($growth);

    expect($dimension)->toBeGreaterThan(1.4)->toBeLessThan(2.1);
});
