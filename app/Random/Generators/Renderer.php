<?php

declare(strict_types=1);

namespace App\Random\Generators;

/**
 * How the studio should present a result.
 *
 * Canvas and Audio results carry a *spec* rather than finished bytes: the browser
 * re-derives the pixels or samples from the same seed, which keeps responses tiny
 * and makes dragging a slider instant instead of a round trip.
 */
enum Renderer: string
{
    case Text = 'text';
    case Chart = 'chart';
    case Math = 'math';
    case Canvas = 'canvas';
    case Audio = 'audio';
    case Gallery = 'gallery';
}
