<?php

declare(strict_types=1);

/*
 * The roadmap — generators that are specified but not built.
 *
 * This file exists so the site can show the shape of the finished library without
 * ever presenting a planned generator as a shipped one. Nothing here is in the
 * registry; every entry renders dimmed, chipped `planned`, and unlinked. When a
 * generator ships it is deleted from this list and appears in config/randomly.php
 * instead, so the two can never both claim it.
 *
 * Sourced from docs/04-module-numbers.md through docs/09-module-audio.md.
 */

return [
    'numbers' => [
        ['key' => 'numbers.decimals', 'name' => 'Decimals', 'tagline' => 'Floats in a range, at a precision you choose.'],
        ['key' => 'numbers.gaussian', 'name' => 'Bell Curve', 'tagline' => 'The normal distribution by Box–Muller, with a live histogram.'],
        ['key' => 'numbers.distribution', 'name' => 'Distribution Lab', 'tagline' => 'Poisson, Pareto, Zipf, Beta, Cauchy — sampled and plotted.'],
        ['key' => 'numbers.coin', 'name' => 'Coin Flips', 'tagline' => 'Biased or fair, showing the longest run against the expected log₂ n.'],
        ['key' => 'numbers.lottery', 'name' => 'Lottery', 'tagline' => 'Mega-Sena, Powerball and EuroMillions, bonus pool included.'],
        ['key' => 'numbers.uuid', 'name' => 'Identifiers', 'tagline' => 'UUID v4 and v7, ULID, NanoID — v7 shows its timestamp prefix.'],
        ['key' => 'numbers.bytes', 'name' => 'Raw Bytes', 'tagline' => 'The entropy itself, in hex, base64 or binary.'],
        ['key' => 'numbers.prime', 'name' => 'Primes', 'tagline' => 'Miller–Rabin, forty rounds, at the bit width you ask for.'],
        ['key' => 'numbers.coordinates', 'name' => 'Earth Points', 'tagline' => 'Uniform on a sphere — not the latitude bug everyone ships.'],
        ['key' => 'numbers.timestamp', 'name' => 'Moments', 'tagline' => 'A random instant inside a window you define.'],
    ],

    'words' => [
        ['key' => 'words.related', 'name' => 'Word Web', 'tagline' => 'Means-like, rhymes-with and sounds-like, via Datamuse.'],
        ['key' => 'words.identity', 'name' => 'Fictional People', 'tagline' => 'Names that hold together across a nationality.'],
    ],

    'patterns' => [
        ['key' => 'patterns.simplex', 'name' => 'Simplex Noise', 'tagline' => 'Fewer directional artefacts, cheaper in higher dimensions.'],
        ['key' => 'patterns.spectral', 'name' => 'Spectral Noise', 'tagline' => '1/f^β synthesised in the frequency domain.'],
        ['key' => 'patterns.life', 'name' => 'Life', 'tagline' => 'Conway from a random soup, with a density control.'],
        ['key' => 'patterns.poisson', 'name' => 'Blue Noise', 'tagline' => 'Poisson-disk beside uniform random — the difference is the lesson.'],
        ['key' => 'patterns.voronoi', 'name' => 'Voronoi', 'tagline' => 'Cells coloured by area or by a noise field.'],
        ['key' => 'patterns.maze', 'name' => 'Mazes', 'tagline' => 'DFS, Kruskal and Wilson side by side — algorithm as bias.'],
        ['key' => 'patterns.wfc', 'name' => 'Wave Function Collapse', 'tagline' => 'Overlapping model, min-entropy heuristic, backtracking.'],
        ['key' => 'patterns.lsystem', 'name' => 'L-Systems', 'tagline' => 'Stochastic rewrite rules: trees, ferns, dragon curves.'],
        ['key' => 'patterns.walk', 'name' => 'Random Walks', 'tagline' => 'Brownian, self-avoiding and Lévy — the fat tail is visible.'],
        ['key' => 'patterns.dla', 'name' => 'Diffusion-Limited Aggregation', 'tagline' => 'Dendritic crystals, grown one sticky particle at a time.'],
    ],

    'images' => [
        ['key' => 'images.circles', 'name' => 'Circle Packing', 'tagline' => 'Grow until collision, keep what fits.'],
        ['key' => 'images.mondrian', 'name' => 'Mondrian', 'tagline' => 'Recursive subdivision, split at U(0.3, 0.7).'],
        ['key' => 'images.gradient', 'name' => 'Mesh Gradient', 'tagline' => 'Random control points, dithered to kill the banding.'],
        ['key' => 'images.spray', 'name' => 'Particle Spray', 'tagline' => 'A Gaussian mixture with k random components.'],
        ['key' => 'images.tiles', 'name' => 'Glyph Grid', 'tagline' => 'A random glyph and rotation per cell.'],
        ['key' => 'images.strata', 'name' => 'Strata', 'tagline' => 'Band heights from a Dirichlet draw, colours walking a palette.'],
    ],

    'audio' => [
        ['key' => 'audio.drone', 'name' => 'Drone', 'tagline' => 'Detuned partials, beating slowly against each other.'],
        ['key' => 'audio.bleep', 'name' => 'UI Sounds', 'tagline' => 'Success, error, notify, coin — as a downloadable pack.'],
        ['key' => 'audio.ambient', 'name' => 'Generative Ambient', 'tagline' => 'A mood preset that evolves and never repeats.'],
    ],
];
