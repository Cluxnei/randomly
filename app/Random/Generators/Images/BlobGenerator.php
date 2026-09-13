<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Palette\Oklch;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Gielis' superformula — one equation, an absurd number of shapes.
 *
 *     r(φ) = ( |cos(mφ/4) / a|^n₂ + |sin(mφ/4) / b|^n₃ )^(−1/n₁)
 *
 * Sample φ across a full turn, convert to Cartesian, and the same four numbers
 * give you starfish, gears, leaves, flowers and sea urchins depending only on
 * where the dice landed. docs/08 §2 calls the interaction a "surprise me" button,
 * and that is exactly right: the parameters are drawn, not dialled.
 *
 * The composition is a specimen plate: one organism large enough to run off the
 * edges of the frame, and a few smaller ones settled against its flank. Each is
 * drawn as a handful of concentric bands walking the palette from its dark end
 * inwards, so a shape reads as a solid body with a lit core rather than as a
 * stack of hoops. That is the whole reason the band count is small and the fill
 * is nearly opaque — see `rings` below.
 *
 * Unlike the flow field, every shape is shipped in the spec. A couple of dozen
 * shapes of a dozen numbers is a kilobyte or two, and having the actual
 * parameters visible in the API response is worth more than the bytes: a reader
 * can plug them into the equation above and get the same curve.
 */
final class BlobGenerator extends ImageGenerator
{
    /** Resolution of the φ sweep used to measure a shape before it is drawn. */
    private const SAMPLES = 1440;

    /**
     * Largest max/min radius ratio a draw may have.
     *
     * The exponent is −1/n₁, so at the low end of n₁ the radius between two
     * spikes can be hundreds of times the radius everywhere else. Normalised to
     * fit its box, a shape like that is a few hairlines around an invisible
     * body. Eight is roughly a deep starfish: every arm is still attached to
     * something. Anything beyond it is spikes with no animal in the middle.
     */
    private const MAX_RATIO = 8.0;

    /**
     * Smallest max/min radius ratio a draw may have.
     *
     * The other end of the same filter. Large stretches of the parameter space
     * are a circle with a ripple in it, and a surprise-me button that hands back
     * a circle has not surprised anybody. 1.15 is about the point where the
     * lobes are unmistakably lobes at thumbnail size.
     */
    private const MIN_RATIO = 1.15;

    public function key(): string
    {
        return 'images.blob';
    }

    public function name(): string
    {
        return 'Superformula';
    }

    public function tagline(): string
    {
        return 'One equation: starfish, gears, leaves, sea urchins.';
    }

    public function schema(): ParamSchema
    {
        return $this->canvasSize(ParamSchema::make(), 900, 900)
            ->int('clusters', 'Specimens', default: 5, min: 1, max: 9, help: 'The first one is the subject and runs off the edges of the frame; the rest are companions settled around it, each an independent draw of m, n₁, n₂ and n₃.')
            ->int('rings', 'Bands', default: 5, min: 1, max: 14, help: 'Concentric copies of the same shape, shrinking inwards and walking the palette as they go. Few and opaque reads as a body; many and thin reads as a scribble.')
            ->int('symmetry', 'Symmetry (m)', default: 0, min: 0, max: 16, help: 'Below 3, the seed draws m per shape — the surprise-me setting. Above it, every shape is pinned to the same fold count.')
            ->float('morph', 'Morph', default: 0.30, min: 0.0, max: 1.5, step: 0.02, help: 'How far n₁, n₂ and n₃ drift between the outer band and the innermost one. At zero the bands are concentric copies.')
            ->float('twist', 'Twist', default: 0.0, min: 0.0, max: 2.0, step: 0.01, help: 'Rotation accumulated from band to band, in turns. A little offsets the lobes; past a tenth or so the bands stop lining up and the shape turns into interference.')
            ->float('spread', 'Scatter', default: 0.5, min: 0.0, max: 1.0, step: 0.02, help: 'How far the companions sit from the subject. At zero they crowd into it; at one they stand clear of it.')
            ->float('fill', 'Fill opacity', default: 0.92, min: 0.0, max: 1.0, step: 0.01, help: 'Zero leaves pure outlines. Near one, each band covers the one outside it and the specimen reads as a solid.')
            ->float('line', 'Outline width', default: 1.0, min: 0.0, max: 8.0, step: 0.1, help: 'Zero leaves pure fills.')
            ->enum('background', 'Ground', self::GROUNDS, default: 'ink')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12)
            ->float('grain', 'Grain', default: 0.016, min: 0.0, max: 0.08, step: 0.002);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * ramp(), not build(): the bands of one specimen are a single
         * progression from the outside in and are read as one object.
         * Categorical colours would turn each organism into a stack of
         * unrelated hoops.
         *
         * One extra stop is drawn and the darkest dropped. A ramp starts at
         * L≈0.16 and an ink ground sits at L≈0.07; the outermost band is the
         * one carrying the silhouette, and a silhouette that close to the
         * background is a shape nobody can see.
         */
        $count = $params->int('colours', 6);
        $palette = array_slice(Palette::ramp($rng, $params->string('palette', 'golden'), $count + 1), 1);
        $background = $this->ground($params->string('background', 'ink'), $palette);

        $width = $params->int('width');
        $height = $params->int('height');
        $clusters = $params->int('clusters');
        $rings = $params->int('rings');
        $stops = count($palette);

        // Outlines and haloes both want whichever end of the ramp the ground is
        // not. On ink that is the bright end and the halo reads as light coming
        // off the specimen; on paper it is the dark end and the same code draws
        // a soft shadow instead. Picked by measured contrast rather than by
        // testing the ground's name, because 'From the palette' can be either.
        $accent = Oklch::contrastRatio($background, $palette[$stops - 1]) >= Oklch::contrastRatio($background, $palette[0])
            ? $palette[$stops - 1]
            : $palette[0];

        $shapes = [];
        $drawn = [];
        $placed = [];

        for ($c = 0; $c < $clusters; $c++) {
            $base = $this->drawShape($rng, $params->int('symmetry'));
            $drawn[] = $base;

            [$cx, $cy, $radius] = $c === 0
                ? $this->placeSubject($rng, $width, $height)
                : $this->placeCompanion($rng, $width, $height, $placed, $params->float('spread'));

            $placed[] = [$cx, $cy, $radius];

            // A wide stretch on purpose. Circular symmetry everywhere makes
            // every specimen read as the same sunburst at a different lobe
            // count; an oval one reads as a leaf or a shell instead, and that
            // is most of the variety between one frame and the next.
            $aspect = 0.74 + $rng->float() * 0.56;
            $rotation = $rng->float() * 2 * M_PI;
            $twist = $params->float('twist') * 2 * M_PI * ($rng->bool() ? 1 : -1);

            for ($r = 0; $r < $rings; $r++) {
                $t = $rings === 1 ? 0.0 : $r / ($rings - 1);

                // Morph pulls the exponents towards rounder values on the way
                // in, so a spiky outer shell resolves into a smooth core. It
                // scales n₂ and n₃ by the same factor on purpose: equal
                // exponents are what keeps an odd-m curve closed (see
                // drawShape), and morphing them apart would reopen it.
                $morph = 1.0 + $t * $params->float('morph');
                $n1 = min(9.0, $base['n1'] * $morph);
                $n2 = max(0.3, $base['n2'] / $morph);
                $n3 = max(0.3, $base['n3'] / $morph);

                [, $max] = $this->extent($base['m'], $n1, $n2, $n3);

                // Linear shrink, leaving the innermost band at 16% — a small
                // bright core. Geometric shrink would crowd every band into the
                // outer third and leave a large flat middle.
                $scale = $radius * (1.0 - $t * 0.84);
                $next = $rings === 1 ? 0.0 : $radius * (1.0 - (($r + 1) / ($rings - 1)) * 0.84);

                // Each band is a two-stop gradient, and consecutive bands share
                // a stop, so the stack as a whole is one continuous radial ramp
                // from the palette's dark end at the silhouette to its bright
                // end at the core. A flat colour per band is what made the
                // earlier version read as hoops.
                $edge = $palette[min($stops - 1, (int) floor($t * ($stops - 1)))];
                $core = $palette[min($stops - 1, (int) floor($t * ($stops - 1)) + 1)];

                $shapes[] = [
                    'cx' => round($cx, 2),
                    'cy' => round($cy, 2),
                    // Two radii rather than one: the equation stays a=b=1 as the
                    // documentation specifies, and the stretch is applied
                    // afterwards as a plain scale. Baking it into a and b would
                    // change the curve, not the framing.
                    'sx' => round($scale, 2),
                    'sy' => round($scale * $aspect, 2),
                    'm' => $base['m'],
                    'n1' => round($n1, 4),
                    'n2' => round($n2, 4),
                    'n3' => round($n3, 4),
                    'rmax' => round($max, 6),
                    'rot' => round($rotation + $t * $twist, 5),
                    'fill' => $core,
                    'edge' => $edge,
                    // Where along the ray the core colour lands, as a fraction
                    // of the distance from centre to boundary. Set to the next
                    // band's radius so the gradient spans exactly the annulus
                    // that stays visible; anything inside it is painted over.
                    'grad' => round(max(0.0, min(0.98, $next / max(1e-6, $scale))), 4),
                    'fill_alpha' => round($params->float('fill'), 4),
                    // A halo, on the outermost band only — it is the one
                    // carrying the silhouette, and one aura per specimen is the
                    // point. Without it the shapes are die-cut out of flat
                    // black and the picture reads as clip art; with it they sit
                    // in the ground the way the flow field's trails do. Scaled
                    // with the shape so a companion is not wearing the
                    // subject's halo.
                    'glow' => $r === 0 ? round($scale * 0.16, 2) : 0.0,
                    // Faint, on every band edge. It is what keeps the outer
                    // silhouette legible once the outermost band is the end of
                    // the ramp nearest the ground.
                    'stroke' => $accent,
                    // Strongest on the outermost band, which is the one drawn
                    // in the palette's darkest colour and therefore the one at
                    // risk of dissolving into an ink ground. Inside the
                    // specimen the bands already separate by colour and the
                    // line only has to hint at the step.
                    'stroke_alpha' => round(0.46 - 0.20 * $t, 4),
                    'line' => $params->float('line'),
                ];
            }
        }

        $spec = [
            'algorithm' => 'blob',
            'width' => $width,
            'height' => $height,
            'background' => $background,
            'palette' => $palette,
            'shapes' => $shapes,
            'grain' => $params->float('grain'),
            'grain_seed' => $rng->uint32(),
        ];

        return new Result(
            value: $spec,
            display: $this->describe($drawn),
            meta: [
                'palette' => $palette,
                'background' => $background,
                'shapes_drawn' => count($shapes),
                // The actual draw, so the studio shows the equation's own numbers
                // rather than only the sliders that framed them.
                // The exponents themselves are in the description under the canvas;
                // the strip gets the fold count, which is the one number a reader
                // can check against what they are looking at.
                'symmetry' => implode(', ', array_map(fn (array $s): string => 'm='.$s['m'], $drawn)),
            ],
        );
    }

    /**
     * The subject: centred, roughly, and larger than the frame's short side.
     *
     * The radius is a fraction of the *short* side and starts above a half, so
     * the specimen always runs off two edges at least. That bleed is the whole
     * composition — a shape that fits inside the frame with room to spare reads
     * as a diagram, and the previous version of this generator was three of
     * them stacked in a pool of black.
     *
     * @return array{float, float, float}
     */
    private function placeSubject(Rng $rng, int $width, int $height): array
    {
        $short = min($width, $height);

        return [
            // A tenth of a frame off centre: enough that the composition is not
            // a bullseye, not so much that the subject stops holding the middle.
            $width / 2 + ($rng->float() - 0.5) * $width * 0.20,
            $height / 2 + ($rng->float() - 0.5) * $height * 0.20,
            // Always above half the short side, so the specimen always runs
            // off two edges even when it is the only one drawn. Not far above:
            // past about seven tenths the profile leaves the frame entirely and
            // the picture is a gradient wash with no silhouette in it at all.
            $short * (0.52 + $rng->float() * 0.16),
        ];
    }

    /**
     * A companion, settled against the flank of something already placed.
     *
     * Placement is polar about a random earlier specimen rather than uniform
     * over the frame, which is what keeps the arrangement from either piling up
     * in the middle or leaving one shape stranded in a corner. The distance is
     * set from the two radii, so companions touch rather than interpenetrate;
     * `spread` slides that contact from a 20% overlap to a clear gap.
     *
     * Candidates whose centre falls outside the frame are redrawn — a companion
     * may be cropped by an edge, which is good, but one drawn entirely outside
     * it is a wasted shape. After a few failures the best candidate so far is
     * taken anyway: on a frame the subject nearly fills there may be no room at
     * all, and refusing to place is worse than a slight overlap.
     *
     * @param  list<array{float, float, float}>  $placed
     * @return array{float, float, float}
     */
    private function placeCompanion(Rng $rng, int $width, int $height, array $placed, float $spread): array
    {
        $short = min($width, $height);
        $best = null;
        $bestScore = -INF;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $radius = $short * (0.10 + $rng->float() * 0.19);
            $anchor = $placed[$rng->intBetween(0, count($placed) - 1)];

            $angle = $rng->float() * 2 * M_PI;
            $distance = ($anchor[2] + $radius) * (0.80 + 0.42 * $spread);

            $cx = $anchor[0] + cos($angle) * $distance;
            $cy = $anchor[1] + sin($angle) * $distance;

            // Score is the worst overlap against everything already down, in
            // units of the pair's combined radius. Positive means clear.
            $score = INF;

            foreach ($placed as [$px, $py, $pr]) {
                $gap = hypot($cx - $px, $cy - $py) - ($pr + $radius);
                $score = min($score, $gap / ($pr + $radius));
            }

            $inside = $cx > 0 && $cx < $width && $cy > 0 && $cy < $height;

            if ($inside && $score > -0.08) {
                return [$cx, $cy, $radius];
            }

            // Off-frame candidates are penalised rather than discarded, so the
            // fallback still prefers a cramped placement inside the frame.
            $score -= $inside ? 0.0 : 1.0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$cx, $cy, $radius];
            }
        }

        return $best;
    }

    /**
     * Draw one set of superformula parameters.
     *
     * Two constraints on top of the ranges in docs/08 §2, both of which are the
     * difference between a specimen and a mess.
     *
     * The first is that n₂ and n₃ are tied together whenever m is odd. The sum
     * |cos x|^n₂ + |sin x|^n₃ has period π, so r(φ + 2π) evaluates the sum at
     * mπ/2 further along; for odd m that is an odd multiple of π/2, which swaps
     * cos for sin. Unless n₂ = n₃ the curve therefore does *not* close, and
     * since the renderer samples φ from atan2 ∈ (−π, π] the discontinuity lands
     * as a hard radial seam across the shape. Half of all m are odd, so this was
     * a seam on half the shapes on the page. Even m is free to draw them apart,
     * which is what gives the alternating-lobe gear forms.
     *
     * The second is the max/min radius filter — see MAX_RATIO. The published
     * range allows n₁ as low as 0.3, and the bottom of it is not a shape.
     */
    private function drawShape(Rng $rng, int $symmetry): array
    {
        $best = null;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $m = $symmetry >= 3 ? $symmetry : $rng->intBetween(3, 14);

            // n₁ biased low: squaring a uniform draw puts two thirds of the
            // mass below 2.5, which is where the lobed forms live. Straight
            // uniform over the documented [0.3, 8] spends most draws up near
            // the round end, and a generator whose surprise-me button mostly
            // returns circles is not much of a surprise.
            $n1 = 0.45 + $rng->float() ** 2 * 5.2;
            $n2 = 0.45 + $rng->float() * 9.0;

            $shape = [
                'm' => $m,
                'n1' => $n1,
                'n2' => $n2,
                // Even m may split the exponents, which alternates two
                // different lobe profiles around the turn. Odd m may not.
                'n3' => $m % 2 === 0 && $rng->bool(0.45) ? 0.45 + $rng->float() * 9.0 : $n2,
            ];

            [$min, $max] = $this->extent($shape['m'], $shape['n1'], $shape['n2'], $shape['n3']);
            $shape['ratio'] = $min > 0 ? $max / $min : INF;

            // Keep the first acceptable draw, not the most lobed of several. A
            // best-of-n would collapse the family onto whatever extreme the
            // score favours; this only discards the two ends that are not
            // shapes, and they are a modest slice of the space.
            if ($shape['ratio'] >= self::MIN_RATIO && $shape['ratio'] <= self::MAX_RATIO) {
                return $shape;
            }

            // How far outside the window, in log units so that "twice too
            // spiky" and "twice too round" are penalised the same amount.
            $miss = $shape['ratio'] > self::MAX_RATIO
                ? log($shape['ratio'] / self::MAX_RATIO)
                : log(self::MIN_RATIO / max(1.0, $shape['ratio']));

            if ($best === null || $miss < $best['miss']) {
                $best = $shape + ['miss' => $miss];
            }
        }

        return $best;
    }

    /**
     * Sweep φ across a full turn and report the smallest and largest radius.
     *
     * The maximum normalises the shape into its box; the minimum is what tells
     * drawShape() whether the thing is a shape or a set of spikes.
     *
     * @return array{float, float}
     */
    private function extent(int $m, float $n1, float $n2, float $n3): array
    {
        $min = INF;
        $max = 0.0;

        for ($i = 0; $i < self::SAMPLES; $i++) {
            $t = $m * (($i / self::SAMPLES) * 2 * M_PI) / 4;
            $term = abs(cos($t)) ** $n2 + abs(sin($t)) ** $n3;

            $r = $term > 0.0 ? $term ** (-1 / $n1) : INF;

            if (! is_finite($r)) {
                continue;
            }

            $min = min($min, $r);
            $max = max($max, $r);
        }

        // Underflow in the sum would send the radius to infinity, which would in
        // turn put an unencodable value in the JSON. It cannot happen for the
        // exponent ranges drawn here — |cos| and |sin| are never both near zero —
        // but a shape that fails to measure should fall back to a unit circle
        // rather than take the response down with it.
        return [is_finite($min) ? $min : 1.0, $max > 0.0 ? $max : 1.0];
    }

    private function describe(array $shapes): string
    {
        return implode('  ·  ', array_map(
            fn (array $s): string => sprintf('m %d, n₁ %.2f, n₂ %.2f, n₃ %.2f', $s['m'], $s['n1'], $s['n2'], $s['n3']),
            $shapes,
        ));
    }
}
