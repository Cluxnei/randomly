<?php

declare(strict_types=1);

namespace App\Random\Generators;

enum Module: string
{
    case Numbers = 'numbers';
    case Words = 'words';
    case Equations = 'equations';
    case Patterns = 'patterns';
    case Images = 'images';
    case Audio = 'audio';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tagline(): string
    {
        return match ($this) {
            self::Numbers => 'Integers, distributions, dice and the points on a sphere everyone gets wrong.',
            self::Words => 'Passphrases with real entropy, and words that never existed.',
            self::Equations => 'Problems with clean answers, built backwards from the solution.',
            self::Patterns => 'Noise, growth and decay — Perlin, Gray-Scott, cellular automata.',
            self::Images => 'Flow fields, superformula blobs, perceptual palettes, real art.',
            self::Audio => 'Noise colours, Euclidean rhythms and strings plucked out of static.',
        };
    }
}
