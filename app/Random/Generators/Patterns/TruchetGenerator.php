<?php

declare(strict_types=1);

namespace App\Random\Generators\Patterns;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Palette\Palette;
use App\Random\Rng\Rng;

/**
 * Truchet tiles: one square motif, dropped at a random orientation.
 *
 * The cheapest beautiful thing in the module, and worth having precisely because
 * it is cheap. There is no noise function, no simulation and no field — a tile
 * gets one of two or four rotations and the eye assembles the rest, following
 * arcs across tile boundaries into loops nobody placed. It is the clearest
 * demonstration on the site that structure does not require a complicated
 * generator, only a constraint that randomness has to respect.
 *
 * Sébastien Truchet published the diagonal set in 1704; the quarter-arc version
 * is Cyril Smith's, from 1987, and is the one everybody has seen.
 */
final class TruchetGenerator extends BaseGenerator
{
    private const SETS = [
        'arcs' => 'Quarter arcs',
        'diagonals' => 'Diagonals',
        'triangles' => 'Triangles',
        'mixed' => 'Mixed (a set per tile)',
    ];

    private const COLOURINGS = [
        'flat' => 'One ink',
        'diagonal' => 'Graded across the canvas',
        'tile' => 'A colour per tile',
    ];

    public function key(): string
    {
        return 'patterns.truchet';
    }

    public function name(): string
    {
        return 'Truchet Tiles';
    }

    public function tagline(): string
    {
        return 'Random tile orientation. Instant beauty, ten lines of code.';
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
            ->int('width', 'Width', default: 900, min: 200, max: 2048)
            ->int('height', 'Height', default: 600, min: 200, max: 2048)
            ->int('tiles', 'Tiles across', default: 14, min: 3, max: 72)
            ->enum('set', 'Tile set', self::SETS, default: 'arcs')
            ->float('weight', 'Line weight', default: 0.2, min: 0.02, max: 0.7, step: 0.02, help: 'As a fraction of a tile. Past about 0.5 the lines meet and the negative space becomes the pattern — worth pushing.')
            ->enum('colouring', 'Ink', self::COLOURINGS, default: 'diagonal')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'golden')
            ->int('colours', 'Palette size', default: 6, min: 2, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        /*
         * ramp() even though the ink is nowhere interpolated across a field.
         *
         * The reason is the same one as the automaton's: the first colour becomes
         * the ground and the rest become ink laid on top of it, so what the
         * palette has to guarantee is that ink and ground are not the same
         * lightness. ramp() pins its ends apart; build() would happily return six
         * colours that all read as the same grey in a photograph.
         */
        $palette = Palette::ramp($rng, $params->string('palette', 'golden'), $params->int('colours', 6));

        // Orientations are hashed from tile coordinates in the browser rather than
        // drawn here: a 72-across grid is over three thousand tiles, and shipping
        // three thousand integers would undo the whole point of sending a spec.
        $seed = $rng->uint32();

        $spec = [
            'algorithm' => 'truchet',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'tiles' => $params->int('tiles'),
            'set' => $params->string('set', 'arcs'),
            'weight' => $params->float('weight'),
            'colouring' => $params->string('colouring', 'diagonal'),
            'palette' => $palette,
            'seed' => $seed,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($params, $palette),
            meta: [
                'palette' => $palette,
                'tile_count' => $this->tileCount($params),
                // The size of the space this one image was drawn from. Four
                // orientations per tile over a few thousand tiles is a number with
                // more digits than there are atoms in anything, which is the point
                // worth making about a pattern this simple.
                'arrangements' => $this->arrangements($params),
            ],
        );
    }

    private function describe(Params $params, array $palette): string
    {
        return sprintf(
            '%s · %d across · weight %.2f · %d colours',
            self::SETS[$params->string('set', 'arcs')] ?? 'Quarter arcs',
            $params->int('tiles'),
            $params->float('weight'),
            count($palette),
        );
    }

    private function tileCount(Params $params): int
    {
        $size = max($params->int('width'), $params->int('height')) / max(1, $params->int('tiles'));

        return (int) ceil($params->int('width') / $size) * (int) ceil($params->int('height') / $size);
    }

    /**
     * Rendered as a power of ten rather than an integer: PHP would overflow to a
     * float and print 1.0E+1900 anyway, and "10^1900" is the readable form of a
     * number whose only job is to be absurd.
     */
    private function arrangements(Params $params): string
    {
        // Arcs and diagonals have two distinct orientations, triangles four; the
        // mixed set is the union of all three, so eight.
        $orientations = match ($params->string('set', 'arcs')) {
            'triangles' => 4,
            'mixed' => 8,
            default => 2,
        };

        return sprintf('10^%d', (int) round($this->tileCount($params) * log10($orientations)));
    }
}
