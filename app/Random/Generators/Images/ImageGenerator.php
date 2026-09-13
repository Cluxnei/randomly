<?php

declare(strict_types=1);

namespace App\Random\Generators\Images;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Palette\Oklch;

/**
 * The shared half of an image generator.
 *
 * Every generator in this module answers the same three structural questions the
 * same way — Images module, canvas renderer, and a `background` control drawn
 * from one list of grounds — so they are answered once here rather than copied
 * three times. Everything that actually differs stays in the subclass.
 *
 * Like Patterns, these emit a *spec*, not pixels: the response is a few hundred
 * bytes of numbers and the browser re-derives the drawing from the render key.
 */
abstract class ImageGenerator extends BaseGenerator
{
    /**
     * The grounds a composition can sit on.
     *
     * Offered as a named list rather than a colour picker because three of the
     * four are relationships — the site's own background, the palette's own
     * darkest stop — and a hex field cannot express a relationship.
     */
    public const GROUNDS = [
        'ink' => 'Ink',
        'ground' => 'Site ground',
        'paper' => 'Paper',
        'palette' => 'From the palette',
    ];

    public function module(): Module
    {
        return Module::Images;
    }

    public function renderer(): Renderer
    {
        return Renderer::Canvas;
    }

    /**
     * Resolve a ground choice to the hex the renderer fills with.
     *
     * Built in OKLCH for the same reason every other colour here is: 'ink' and
     * 'paper' have to sit at a *perceptual* distance from the palette, and an
     * eyeballed hex constant does not know what perceptual means.
     *
     * @param  list<string>  $palette
     */
    protected function ground(string $choice, array $palette): string
    {
        return match ($choice) {
            'paper' => Oklch::toHex(0.965, 0.004, 90),
            'ground' => Oklch::toHex(0.15, 0.01, 260),
            'palette' => $palette[0],
            default => Oklch::toHex(0.07, 0.012, 265),
        };
    }

    /** The two controls shared by every composition: width and height in pixels. */
    protected function canvasSize(ParamSchema $schema, int $width, int $height): ParamSchema
    {
        return $schema
            ->int('width', 'Width', default: $width, min: 256, max: 2048)
            ->int('height', 'Height', default: $height, min: 256, max: 2048);
    }
}
