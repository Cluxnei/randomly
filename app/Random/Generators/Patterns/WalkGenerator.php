<?php

declare(strict_types=1);

namespace App\Random\Generators\Patterns;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Palette\Oklch;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Brownian motion, Lévy flight and the self-avoiding walk, drawn side by side.
 *
 *   Brownian      step ~ N(0, σ) per axis           ⟨r⟩ ∝ √n
 *   Lévy flight   ℓ ~ Pareto(α), direction uniform  ⟨r⟩ ∝ n^(1/α)
 *   Self-avoiding uniform over unvisited neighbours ⟨r⟩ ∝ n^(3/4)
 *
 * All three are "a random walk". All three have a different exponent, and the
 * exponent is visible: a Brownian cloud is an even fuzz, a Lévy flight is a
 * cluster joined to another cluster by one long straight line, and a
 * self-avoiding walk is a ribbon that fills a region without ever touching
 * itself. Comparison mode is therefore the default, because the contrast is the
 * whole content and a single panel makes a claim nobody can check.
 *
 * **Step length is derived, not set.** The control is how much of the frame the
 * walk should cover, and each mode's step is solved backwards from its own
 * scaling exponent to reach it. Exposing a pixel step instead would mean that
 * changing the step count also changes how much of the canvas is used, and that
 * every mode needs a different step to be worth looking at — which makes the one
 * comparison this generator exists for impossible to set up.
 *
 * A Lévy flight with α < 2 has infinite variance, so ⟨r⟩ above is a typical
 * displacement rather than an RMS one. That is not a footnote: it is the reason
 * the tail exponent is a slider, and the reason the picture changes character
 * rather than merely scale as it crosses 2.
 */
final class WalkGenerator extends BaseGenerator
{
    private const MODES = [
        'compare' => 'All three, side by side',
        'brownian' => 'Brownian only',
        'levy' => 'Lévy flight only',
        'saw' => 'Self-avoiding only',
    ];

    /** The most segments a render may lay down, across every panel. */
    private const MAX_SEGMENTS = 900000;

    /**
     * How long a self-avoiding walk gets before it traps itself, on average.
     *
     * About seventy steps on the square lattice — a measured constant of the
     * "growing" self-avoiding walk, not a parameter — and the single most
     * surprising fact in this generator. The naive process cannot be asked for a
     * walk of a thousand steps: it will not reach one, from any start, however
     * long you wait.
     *
     * Two things follow. The lattice for a SAW panel is scaled to seventy steps
     * rather than to the requested step count, or every ribbon would be a speck.
     * And the panel spends its segment budget on many short walks instead of a
     * few long ones, because that is the only shape those segments can take.
     */
    private const TRAPPING_LENGTH = 71;

    public function key(): string
    {
        return 'patterns.walk';
    }

    public function name(): string
    {
        return 'Random Walks';
    }

    public function tagline(): string
    {
        return 'Brownian, self-avoiding and Lévy — the fat tail is visible.';
    }

    public function module(): Module
    {
        return Module::Patterns;
    }

    public function renderer(): Renderer
    {
        return Renderer::Canvas;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('width', 'Width', default: 1100, min: 200, max: 2048)
            ->int('height', 'Height', default: 620, min: 200, max: 2048)
            ->enum('mode', 'Walk', self::MODES, default: 'compare')
            ->int('walkers', 'Walkers', default: 22, min: 1, max: 160, help: 'Each one is an independent walk and is never reused.')
            ->int('steps', 'Steps each', default: 2600, min: 20, max: 20000)
            ->float('tail', 'Lévy tail α', default: 1.35, min: 1.05, max: 3.0, step: 0.05, help: 'The Pareto exponent. Below 2 the variance is infinite and the walk is dominated by its rare huge jumps; above 2 the central limit theorem takes back over and it becomes Brownian again.')
            ->float('spread', 'Coverage', default: 0.85, min: 0.2, max: 1.6, step: 0.05, help: 'How much of the frame a typical walk should reach. Step length is solved backwards from this and each mode\'s own scaling exponent, so all three fill the frame the same amount however many steps you ask for.')
            ->enum('start', 'Start from', [
                'centre' => 'The centre of the panel',
                'scattered' => 'Anywhere',
            ], default: 'centre')
            ->float('alpha', 'Line alpha', default: 0.11, min: 0.01, max: 0.9, step: 0.005, help: 'Per-segment opacity. A walk that crosses its own path a thousand times is drawn out of the overlap, so this wants to stay low.')
            ->float('line', 'Line width', default: 1.2, min: 0.4, max: 4.0, step: 0.1)
            ->bool('glow', 'Additive blending', default: true)
            ->enum('background', 'Ground', ['ink' => 'Ink', 'paper' => 'Paper', 'palette' => 'From the palette'], default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.016, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('colours', 6);

        /*
         * ramp(), not build(): a trail travels along the palette as it ages, so
         * the renderer interpolates between neighbouring stops on every segment.
         * The two darkest stops are dropped for the same reason flowfield drops
         * them — a ramp starts at L≈0.16 and an ink ground sits at L≈0.07, so
         * the first stops are trails nobody can see.
         */
        $palette = array_slice(Palette::ramp($rng, $params->string('palette', 'golden'), $count + 2), 2);

        $background = match ($params->string('background', 'ink')) {
            'paper' => Oklch::toHex(0.965, 0.004, 90),
            'palette' => $palette[0],
            default => Oklch::toHex(0.07, 0.012, 265),
        };

        $mode = $params->string('mode', 'compare');
        $modes = $mode === 'compare' ? ['brownian', 'levy', 'saw'] : [$mode];

        [$walkers, $steps] = $this->budget($params, count($modes));

        $spec = [
            'algorithm' => 'walk',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'walkers' => $walkers,
            'steps' => $steps,
            'tail' => $params->float('tail'),
            'start' => $params->string('start', 'centre'),
            'alpha' => $params->float('alpha'),
            'line' => $params->float('line'),
            'glow' => $params->bool('glow'),
            'background' => $background,
            // The hairline between panels. Mixed from the ground towards the
            // palette rather than being a fixed grey, so it stays visible on ink
            // and on paper without ever being the loudest thing on the canvas.
            'rule' => self::mix($background, $palette[count($palette) - 1], 0.3),
            'palette' => $palette,
            // How far along the ramp a walk travels over its own lifetime. Fixed
            // rather than exposed: it is the difference between a path and a
            // coloured line, and nobody would want it at zero.
            'drift' => 0.42,
            'panels' => $this->panels($params, $modes, $walkers, $steps),
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // The walks themselves are expanded from this in the browser. Two
            // hundred thousand steps at two draws each is far past what the 8 KB
            // render stream holds; see patterns/prng.js for the argument.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $modes, $walkers, $steps),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'segments' => $walkers * $steps * count($modes),
                'exponents' => implode(', ', array_map(
                    fn (string $m): string => $m.' r∝n^'.self::EXPONENT_LABELS[$m],
                    $modes,
                )),
                'levy_variance' => $params->float('tail') < 2.0 ? 'infinite (α < 2)' : 'finite (α ≥ 2)',
                'note' => $this->note($params, $modes),
            ],
        );
    }

    /** Blend two hex colours in sRGB. Good enough for a one-pixel rule. */
    private static function mix(string $a, string $b, float $t): string
    {
        $channels = [];

        for ($i = 1; $i <= 5; $i += 2) {
            $channels[] = (int) round(hexdec(substr($a, $i, 2)) * (1 - $t) + hexdec(substr($b, $i, 2)) * $t);
        }

        return sprintf('#%02x%02x%02x', ...$channels);
    }

    /**
     * How a mode's typical displacement grows with the number of steps.
     *
     * Brownian is the central limit theorem: independent increments, variance
     * adding, so √n. The self-avoiding exponent 3/4 is Flory's, exact in two
     * dimensions and one of the few exactly known critical exponents there is. A
     * Lévy flight's is 1/α, which is where the whole character of the thing comes
     * from — at α = 1.2 that is n^0.83, far faster than diffusion.
     */
    private const EXPONENT_LABELS = [
        'brownian' => '(1/2)',
        'saw' => '(3/4)',
        'levy' => '(1/α)',
    ];

    /**
     * Trim the walk count and length to fit the drawing budget.
     *
     * Both sliders multiply, and comparison mode multiplies again by three. A
     * million short segments through the software rasteriser is around a second;
     * the walkers are trimmed before the steps because a shorter walk is a
     * different picture while fewer walks is the same picture, thinner.
     *
     * @return array{int, int} walkers, steps
     */
    private function budget(Params $params, int $panels): array
    {
        $walkers = $params->int('walkers', 22);
        $steps = $params->int('steps', 2600);

        $allowance = intdiv(self::MAX_SEGMENTS, max(1, $panels));

        if ($walkers * $steps > $allowance) {
            $walkers = max(1, intdiv($allowance, $steps));
        }

        if ($walkers * $steps > $allowance) {
            $steps = max(20, intdiv($allowance, $walkers));
        }

        return [$walkers, $steps];
    }

    /**
     * One panel per mode, each with the step length its own exponent needs.
     *
     * Solving `reach = step · n^ν` for the step is what makes the three
     * comparable: they are then drawing walks of the same *size* out of the same
     * number of steps, so the only thing left to differ is shape — which is the
     * claim.
     *
     * @param  list<string>  $modes
     * @return list<array<string, mixed>>
     */
    private function panels(Params $params, array $modes, int $walkers, int $steps): array
    {
        $width = $params->int('width');
        $height = $params->int('height');

        $panelWidth = $width / count($modes);
        $reach = $params->float('spread') * min($panelWidth, $height) / 2;

        $panels = [];

        foreach ($modes as $i => $mode) {
            $exponent = match ($mode) {
                'brownian' => 0.5,
                'saw' => 0.75,
                default => 1.0 / max(1.05, $params->float('tail')),
            };

            // A self-avoiding walk will not reach the requested step count — see
            // TRAPPING_LENGTH — so its lattice is scaled to the length it will
            // actually achieve. Scaling it to the ask would put every ribbon
            // inside a twenty-pixel square.
            $length = $mode === 'saw' ? self::TRAPPING_LENGTH : $steps;
            $step = $reach / max(1.0, $length ** $exponent);

            // √2, because a Brownian step of σ per axis covers σ√2 in total and
            // the reach above is a distance rather than a per-axis one.
            if ($mode === 'brownian') {
                $step /= M_SQRT2;
            }

            $panel = [
                'mode' => $mode,
                'x' => (int) round($i * $panelWidth),
                'width' => (int) round($panelWidth),
                'step' => round($step, 5),
                'walkers' => $walkers,
                'steps' => $steps,
                /*
                 * Per panel, because the low default alpha is tuned for paths
                 * that cross themselves a thousand times. A self-avoiding walk
                 * by definition never does, so at the shared value its panel
                 * came out three stops darker than the other two and read as
                 * empty next to them.
                 */
                'alpha' => round(min(1.0, $params->float('alpha') * ($mode === 'saw' ? 3.2 : 1.0)), 4),
            ];

            if ($mode === 'saw') {
                /*
                 * The same segment budget, spent on many short walks.
                 *
                 * `steps` stays as a ceiling rather than a target: a lucky walk
                 * that gets past seventy should not be cut off at the average,
                 * and the long tail of walk lengths is part of what the panel
                 * shows.
                 */
                // Below two pixels the ribbon is narrower than the line drawn
                // along it and the lattice stops being visible as a lattice.
                $cell = max(2.0, $step);
                $cols = max(2, (int) ceil($panel['width'] / $cell) + 1);
                $rows = max(2, (int) ceil($height / $cell) + 1);

                /*
                 * Whichever runs out first: the segment budget, or the lattice.
                 *
                 * Spending the whole budget on short walks is what the first
                 * version did, and it covered every cell of the panel ten times
                 * over — a solid red grid rather than a set of ribbons. A SAW
                 * segment is a whole lattice cell long where a Brownian one is a
                 * couple of pixels, so equal segment counts are nothing like
                 * equal ink. Capping at a fraction of the cells leaves the
                 * ribbons legible as individual objects, which is the only way
                 * the self-avoidance is visible at all.
                 */
                $panel['walkers'] = max(1, (int) round(min(
                    $walkers * $steps / self::TRAPPING_LENGTH,
                    0.62 * $cols * $rows / self::TRAPPING_LENGTH,
                )));

                $panel['cell'] = round($cell, 4);
                $panel['cols'] = $cols;
                $panel['rows'] = $rows;
            }

            $panels[] = $panel;
        }

        return $panels;
    }

    /** @param list<string> $modes */
    private function describe(Params $params, array $modes, int $walkers, int $steps): string
    {
        return sprintf(
            '%s · %d walkers × %s steps%s · %s start',
            implode(' | ', array_map(fn (string $m): string => ucfirst($m), $modes)),
            $walkers,
            number_format($steps),
            in_array('levy', $modes, true) ? sprintf(' · α = %.2f', $params->float('tail')) : '',
            $params->string('start', 'centre') === 'centre' ? 'centred' : 'scattered',
        );
    }

    /** @param list<string> $modes */
    private function note(Params $params, array $modes): string
    {
        if (count($modes) === 1) {
            return match ($modes[0]) {
                'levy' => sprintf('Step lengths drawn from a Pareto with α = %.2f, by inverse transform: ℓ = σ·U^(−1/α). Below α = 2 the variance is infinite, so the walk is ruled by its rare enormous jumps — it mills in one place, crosses the frame in a single segment, and mills again. That is how an albatross searches an ocean.', $params->float('tail')),
                'saw' => 'Each step is uniform over the lattice neighbours not already visited. A walk that runs out of unvisited neighbours is trapped and stops there, which is why a self-avoiding walk of a requested length may simply not exist. End-to-end distance grows as n^(3/4) — Flory\'s exponent, exact in two dimensions.',
                default => 'Independent Gaussian increments. Variance adds, so the typical displacement grows as √n and no direction is ever preferred: the result is an even fuzz whose density falls off as a Gaussian in every direction.',
            };
        }

        return sprintf(
            'Three walks from the same number of steps, each scaled to cover the same fraction of its panel. Brownian reaches √n, the self-avoiding walk n^(3/4), and the Lévy flight n^(1/α) — here n^%.2f. Same step count, three different exponents, three shapes you would never mistake for one another.',
            1 / max(1.05, $params->float('tail')),
        );
    }
}
