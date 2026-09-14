<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Hundreds of thousands of particles, sprayed from a Gaussian mixture.
 *
 *   pick component i with probability wᵢ
 *   z ~ N(0, I),  p = μᵢ + Rᵢ Sᵢ z
 *
 * A mixture rather than one Gaussian, because a single Gaussian is a fuzzy dot.
 * Several overlapping anisotropic ones read as depth: the dense cores resolve as
 * objects and the overlapping fringes as the space between them, and nobody drew
 * either.
 *
 * The picture is made of *density*, not of marks. Every particle is laid down at
 * an opacity of a percent or two and added rather than painted, so the brightness
 * at any point is the mixture's own probability density — which is the honest way
 * to draw a distribution, and also the reason the opacity control wants to stay
 * low. Turn it up and the image collapses into flat paint with a hard edge where
 * the samples run out.
 *
 * Only the components travel in the spec: a few numbers each, where the particles
 * they generate would be several megabytes. The browser expands them from one
 * seed word, exactly as the flow field expands its trails.
 */
final class SprayGenerator extends ImageGenerator
{
    public function key(): string
    {
        return 'images.spray';
    }

    public function name(): string
    {
        return 'Particle Spray';
    }

    public function tagline(): string
    {
        return 'A Gaussian mixture with k random components.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 1200, 800)
            ->int('components', 'Components', default: 5, min: 1, max: 10, help: 'How many Gaussians the mixture is made of. One is a fuzzy dot; the interest is entirely in how several overlap.')
            ->int('particles', 'Particles', default: 450000, min: 5000, max: 900000, help: 'Samples drawn from the mixture. The image is their density, so this is a grain control as much as a quantity one.')
            ->float('spread', 'Component size', default: 0.12, min: 0.02, max: 0.6, step: 0.01, help: 'The typical standard deviation, as a fraction of the short side.')
            ->float('anisotropy', 'Elongation', default: 3.4, min: 1.0, max: 9.0, step: 0.1, help: 'The most a component\'s two axes may differ. At 1 every component is a circular blur; above 3 they become strokes with a direction, which is what stops the result looking like spilt milk.')
            ->float('alpha', 'Particle alpha', default: 0.11, min: 0.004, max: 0.3, step: 0.002, help: 'Per-particle opacity. The picture is the accumulation, so this wants to stay low.')
            ->float('radius', 'Particle size', default: 1.0, min: 0.3, max: 4.0, step: 0.05)
            ->bool('glow', 'Additive blending', default: true, help: 'Particles add their light together instead of painting over each other. On a dark ground this is the whole look.')
            ->float('drift', 'Halo drift', default: 0.16, min: 0.0, max: 0.5, step: 0.01, help: 'How far along the palette a particle travels as it lands further from its component\'s centre — so a core is one colour and its halo another.')
            ->enum('background', 'Ground', self::GROUNDS, default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'magma')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.012, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $count = $params->int('colours', 7);

        /*
         * ramp(), not build(): a particle's colour is sampled continuously
         * between stops as it lands further from its component's centre, so the
         * palette has to be something that can be interpolated. The two darkest
         * stops go, for the reason flowfield drops them — against an ink ground
         * they are particles nobody can see.
         */
        $palette = array_slice(Palette::ramp($rng, $params->string('palette', 'magma'), $count + 2), 2);

        $background = $this->ground($params->string('background', 'ink'), $palette);
        $components = $this->components($rng, $params, count($palette));

        $spec = [
            'algorithm' => 'spray',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'components' => $components,
            'particles' => $params->int('particles'),
            'alpha' => $params->float('alpha'),
            'radius' => $params->float('radius'),
            'glow' => $params->bool('glow'),
            'drift' => $params->float('drift'),
            'background' => $background,
            'palette' => $palette,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
            // A quarter of a million particles at four draws each is 14 MB of
            // stream for something one word determines. See patterns/prng.js.
            'seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s particles · %d components · σ ≈ %.2f · alpha %.3f',
                number_format($params->int('particles')),
                count($components),
                $params->float('spread'),
                $params->float('alpha'),
            ),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'components' => count($components),
                'particles' => $params->int('particles'),
                'mixture_weights' => array_map(fn (array $c): float => round($c[5], 3), $components),
                'note' => 'Every particle picks a component by weight, draws two standard normals, and is placed by that component\'s own rotation and scale. Nothing is drawn twice and nothing is corrected afterwards — the structure on screen is the mixture\'s probability density, sampled a few hundred thousand times and accumulated in floating point so that marks far below one output level still add up to something visible.',
            ],
        );
    }

    /**
     * Draw the mixture.
     *
     * Three decisions here, all about composition rather than statistics.
     *
     * Means are drawn from the middle 76% of the canvas, not from all of it: a
     * component centred on the edge spends half its particles outside the frame,
     * which costs the render and gives a lopsided picture.
     *
     * Every component is elongated — the axis ratio is drawn from [1, anisotropy]
     * and then *applied*, rather than the two axes being drawn independently.
     * Independent draws give a ratio near 1 most of the time, and a field of
     * circular blurs is exactly the spilt-milk look the elongation exists to
     * avoid.
     *
     * Weights are drawn from a symmetric Dirichlet with α = 1.6 rather than
     * uniformly. Uniform weights make every component the same, which wastes the
     * mixture; α below 1 would give one component almost everything, which wastes
     * it the other way.
     *
     * @return list<array{float, float, float, float, float, float, int}> x, y, σa, σb, angle, weight, colour
     */
    private function components(Rng $rng, Params $params, int $colours): array
    {
        $count = $params->int('components', 5);
        $spread = $params->float('spread');
        $anisotropy = $params->float('anisotropy');

        $out = [];
        $weights = [];
        $index = $rng->intBetween(0, $colours - 1);

        for ($i = 0; $i < $count; $i++) {
            // Gamma(1.6, 1) per component, normalised at the end: the standard
            // construction of a Dirichlet draw.
            $weights[] = self::gamma($rng, 1.6);

            $ratio = 1.0 + $rng->float() * ($anisotropy - 1.0);
            $scale = $spread * (0.55 + $rng->float() * 0.9);

            $out[] = [
                round(0.12 + $rng->float() * 0.76, 5),
                round(0.12 + $rng->float() * 0.76, 5),
                round($scale * sqrt($ratio), 5),
                round($scale / sqrt($ratio), 5),
                round($rng->float() * M_PI, 5),
                0.0,
                $index,
            ];

            // Walk the palette rather than drawing independently, so adjacent
            // components are usually adjacent colours and the overlaps blend
            // instead of fighting.
            $index = ($index + ($rng->bool(0.7) ? 1 : 2)) % $colours;
        }

        $total = array_sum($weights) ?: 1.0;

        foreach ($out as $i => $component) {
            $out[$i][5] = round($weights[$i] / $total, 5);
        }

        return $out;
    }

    /**
     * One Gamma(shape, 1) draw, by Marsaglia–Tsang.
     *
     * The squeeze test in the middle is what makes this a two-line loop that
     * almost always exits on its first pass: for shape ≥ 1 the acceptance rate is
     * above 95%, and the cheap logarithm-free test catches most of that before
     * the expensive one is reached.
     */
    private static function gamma(Rng $rng, float $shape): float
    {
        // Boost a shape below 1 into the valid range and scale back afterwards —
        // the method needs d = shape − 1/3 to be positive.
        if ($shape < 1.0) {
            return self::gamma($rng, $shape + 1.0) * $rng->float() ** (1.0 / $shape);
        }

        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);

        while (true) {
            $z = $rng->gaussian();
            $v = (1.0 + $c * $z) ** 3;

            if ($v <= 0) {
                continue;
            }

            $u = $rng->float();

            if ($u < 1.0 - 0.0331 * $z ** 4) {
                return $d * $v;
            }

            if (log($u) < 0.5 * $z ** 2 + $d * (1.0 - $v + log($v))) {
                return $d * $v;
            }
        }
    }
}
