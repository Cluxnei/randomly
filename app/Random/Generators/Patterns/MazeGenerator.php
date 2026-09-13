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
 * Three maze algorithms, drawn side by side, because "random maze" is not one
 * thing.
 *
 * All three produce a spanning tree of the grid — every cell reachable from every
 * other, by exactly one route, with exactly cells−1 passages. All three are
 * driven by the same unbiased Rng. And they do not produce the same distribution
 * of mazes at all:
 *
 *   Randomised DFS  carves as deep as it can before backtracking, so it produces
 *                   long winding corridors and very few dead ends. Heavily biased
 *                   towards mazes with one enormous path through them.
 *   Kruskal         shuffles every possible passage and keeps the ones that join
 *                   two separate regions. Short, bushy, dead ends everywhere.
 *                   Uniform over *edge orderings*, which is not the same thing as
 *                   uniform over mazes.
 *   Wilson's        loop-erased random walk, and the only one of the three that
 *                   is provably uniform over all spanning trees of the grid: every
 *                   possible maze comes up with exactly equal probability.
 *
 * That is the lesson, and it is one the site can only make by showing all three at
 * once: "random" is a property of a process, not of an output, and choosing the
 * convenient process silently chooses a distribution. The meta reports the mean
 * distance from the corner for each, which is where the bias becomes a number
 * rather than an impression.
 *
 * Unlike the other canvas generators, the algorithm runs here rather than in the
 * browser. A maze is structure, not a field: the whole thing packs into two bits
 * per cell, a few hundred bytes even at the largest grid, so there is nothing to
 * be saved by re-deriving it client-side — and having it in PHP means the
 * spanning-tree property is under test instead of under assumption.
 */
final class MazeGenerator extends BaseGenerator
{
    /** Passage from this cell to the one on its right. */
    private const EAST = 1;

    /** Passage from this cell to the one below it. */
    private const SOUTH = 2;

    /**
     * Cells per panel, capped.
     *
     * Wilson's is the constraint. Its loop-erased walks wander for a long time
     * before the tree is big enough to catch them, so its cost grows much faster
     * than the other two — comfortably sub-second here, minutes at ten times this.
     * The cell size is grown to fit rather than the grid being cropped, so the
     * picture stays full-bleed whatever the sliders say.
     */
    private const MAX_CELLS_PER_PANEL = 2000;

    private const ALGORITHMS = [
        'dfs' => 'Randomised DFS',
        'kruskal' => 'Kruskal',
        'wilson' => "Wilson's",
    ];

    public function key(): string
    {
        return 'patterns.maze';
    }

    public function name(): string
    {
        return 'Mazes';
    }

    public function tagline(): string
    {
        return 'DFS, Kruskal and Wilson side by side — algorithm as bias.';
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
            ->enum('algorithm', 'Algorithm', [
                'compare' => 'All three, side by side',
                ...self::ALGORITHMS,
            ], default: 'compare')
            ->int('cell', 'Cell size', default: 18, min: 8, max: 64, help: 'In pixels. Grown automatically if the grid would otherwise be too large to carve.')
            ->float('wall', 'Wall thickness', default: 0.18, min: 0.04, max: 0.5, step: 0.02, help: 'As a fraction of a cell.')
            ->enum('colouring', 'Colour the floor by', [
                'depth' => 'Distance from the corner',
                'plain' => 'Flat',
            ], default: 'depth', help: 'Distance is where the bias becomes visible: DFS stretches one long gradient, the other two spread outwards.')
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->int('colours', 'Palette size', default: 8, min: 2, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): the floors are coloured by distance from the
        // starting corner, which is a continuous field over the grid. Categorical
        // colours would interpolate through mud between one step and the next.
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $params->int('colours', 8));

        $choice = $params->string('algorithm', 'compare');
        $algorithms = $choice === 'compare' ? array_keys(self::ALGORITHMS) : [$choice];

        [$cols, $rows, $gutter] = $this->grid($params, count($algorithms));

        $panels = [];
        $stats = [];

        foreach ($algorithms as $algorithm) {
            $cells = match ($algorithm) {
                'kruskal' => $this->kruskal($rng, $cols, $rows),
                'wilson' => $this->wilson($rng, $cols, $rows),
                default => $this->dfs($rng, $cols, $rows),
            };

            $depth = $this->depths($cells, $cols, $rows);

            $panels[] = [
                'algorithm' => $algorithm,
                'label' => self::ALGORITHMS[$algorithm],
                'cols' => $cols,
                'rows' => $rows,
                'cells' => $this->pack($cells),
            ];

            $stats[$algorithm] = [
                'dead_ends' => round($this->deadEnds($cells, $cols, $rows) / ($cols * $rows), 3),
                'mean_depth' => round(array_sum($depth) / count($depth), 1),
                'max_depth' => max($depth),
            ];
        }

        $spec = [
            'algorithm' => 'maze',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'wall' => $params->float('wall'),
            'gutter' => $gutter,
            'colouring' => $params->string('colouring', 'depth'),
            'palette' => $palette,
            'panels' => $panels,
        ];

        return new Result(
            value: $spec,
            display: $this->describe($choice, $cols, $rows, $stats),
            meta: [
                'palette' => $palette,
                'grid' => "{$cols} × {$rows}",
                'cells_each' => $cols * $rows,
                'passages_each' => $cols * $rows - 1,
                'statistics' => $stats,
                'note' => $choice === 'compare'
                    ? sprintf(
                        'Every one of these is a spanning tree: %d cells, %d passages, exactly one route between any two points. They are not drawn from the same distribution. DFS carves until it cannot and then backs up, so its longest route runs %d cells and only %.0f%% of cells are dead ends; Kruskal leaves %.0f%%; Wilson\'s leaves %.0f%% and is the only one of the three that is provably uniform over all possible mazes — the other two are biased by their own convenience.',
                        $cols * $rows,
                        $cols * $rows - 1,
                        $stats['dfs']['max_depth'],
                        $stats['dfs']['dead_ends'] * 100,
                        $stats['kruskal']['dead_ends'] * 100,
                        $stats['wilson']['dead_ends'] * 100,
                    )
                    : sprintf(
                        '%s over a %d × %d grid: %d passages joining %d cells, one route between any two. %s',
                        self::ALGORITHMS[$choice],
                        $cols,
                        $rows,
                        $cols * $rows - 1,
                        $cols * $rows,
                        match ($choice) {
                            'wilson' => "Wilson's is the only common maze algorithm that is uniform over all spanning trees — every possible maze of this grid is equally likely.",
                            'kruskal' => 'Kruskal is uniform over edge orderings, which is not the same as uniform over mazes: it favours short, bushy trees with dead ends everywhere.',
                            default => 'Randomised DFS is heavily biased towards long corridors, because it only backtracks when it has nowhere left to go.',
                        },
                    ),
            ],
        );
    }

    /**
     * Grid dimensions, shrunk to fit the cell budget rather than cropped.
     *
     * @return array{int, int, int}
     */
    private function grid(Params $params, int $panels): array
    {
        $width = $params->int('width');
        $height = $params->int('height');

        // A gutter either side of every panel, so three mazes read as three
        // objects rather than one wide one with seams.
        $gutter = max(6, (int) round($params->int('cell') * 0.6));
        $panelWidth = ($width - $gutter * ($panels + 1)) / $panels;
        $panelHeight = $height - $gutter * 2;

        $cell = (float) $params->int('cell');

        // Grow the cell until the grid fits the budget. Solving for it directly
        // would need a square root and a ceiling and would still need this loop to
        // correct the rounding, so the loop is the whole implementation.
        while (true) {
            $cols = max(2, (int) floor($panelWidth / $cell));
            $rows = max(2, (int) floor($panelHeight / $cell));

            if ($cols * $rows <= self::MAX_CELLS_PER_PANEL || $cell > 512) {
                return [$cols, $rows, $gutter];
            }

            $cell *= 1.1;
        }
    }

    /**
     * Randomised depth-first search, iteratively.
     *
     * Recursion would be the shorter spelling and would also blow the stack on a
     * 2000-cell grid, because the corridor this carves can be almost the whole
     * maze — which is precisely the bias being demonstrated.
     *
     * @return list<int> two bits per cell: EAST and SOUTH passages
     */
    private function dfs(Rng $rng, int $cols, int $rows): array
    {
        $cells = array_fill(0, $cols * $rows, 0);
        $visited = array_fill(0, $cols * $rows, false);

        $visited[0] = true;
        $stack = [0];

        while ($stack !== []) {
            $current = $stack[count($stack) - 1];

            $options = [];
            foreach ($this->neighbours($current, $cols, $rows) as $next) {
                if (! $visited[$next]) {
                    $options[] = $next;
                }
            }

            if ($options === []) {
                array_pop($stack);

                continue;
            }

            $next = $rng->pick($options);
            $this->carve($cells, $current, $next, $cols);
            $visited[$next] = true;
            $stack[] = $next;
        }

        return $cells;
    }

    /**
     * Kruskal's, over a shuffled list of every possible passage.
     *
     * No weights are needed: a uniformly shuffled edge list *is* a random weight
     * assignment, and the minimum spanning tree of random weights is what this
     * builds. Union-find with path compression keeps the whole thing effectively
     * linear.
     *
     * @return list<int>
     */
    private function kruskal(Rng $rng, int $cols, int $rows): array
    {
        $cells = array_fill(0, $cols * $rows, 0);
        $parent = range(0, $cols * $rows - 1);

        $edges = [];
        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $i = $y * $cols + $x;
                if ($x + 1 < $cols) {
                    $edges[] = [$i, $i + 1];
                }
                if ($y + 1 < $rows) {
                    $edges[] = [$i, $i + $cols];
                }
            }
        }

        foreach ($rng->shuffle($edges) as [$a, $b]) {
            $rootA = $this->find($parent, $a);
            $rootB = $this->find($parent, $b);

            if ($rootA === $rootB) {
                continue;
            }

            $parent[$rootA] = $rootB;
            $this->carve($cells, $a, $b, $cols);
        }

        return $cells;
    }

    private function find(array &$parent, int $i): int
    {
        while ($parent[$i] !== $i) {
            // Path halving: point every other node at its grandparent on the way
            // up. Same asymptotics as full compression, one pass instead of two.
            $parent[$i] = $parent[$parent[$i]];
            $i = $parent[$i];
        }

        return $i;
    }

    /**
     * Wilson's algorithm: loop-erased random walks into a growing tree.
     *
     * Pick a cell not yet in the tree and random-walk from it until it hits the
     * tree, remembering only the *last* direction taken out of each cell. That
     * single rule is the loop erasure: walk into a loop and the second visit
     * overwrites the first, so retracing at the end silently skips the whole
     * excursion. Then follow those directions from the start and carve.
     *
     * The result is a uniform spanning tree — every maze of this grid with exactly
     * equal probability — which is a genuinely surprising thing for so short an
     * algorithm to guarantee, and which neither of the other two manages.
     *
     * @return list<int>
     */
    private function wilson(Rng $rng, int $cols, int $rows): array
    {
        $count = $cols * $rows;
        $cells = array_fill(0, $count, 0);
        $inTree = array_fill(0, $count, false);

        $inTree[$rng->intBetween(0, $count - 1)] = true;

        for ($start = 0; $start < $count; $start++) {
            if ($inTree[$start]) {
                continue;
            }

            // Where the walk left each cell it passed through. Overwritten on a
            // revisit, which is the loop erasure in one assignment.
            $direction = [];
            $walk = $start;

            while (! $inTree[$walk]) {
                $direction[$walk] = $rng->pick($this->neighbours($walk, $cols, $rows));
                $walk = $direction[$walk];
            }

            // Retrace, and only now commit: the cells skipped by an erased loop
            // are simply never reached by this second pass.
            $walk = $start;
            while (! $inTree[$walk]) {
                $next = $direction[$walk];
                $this->carve($cells, $walk, $next, $cols);
                $inTree[$walk] = true;
                $walk = $next;
            }
        }

        return $cells;
    }

    /** @return list<int> */
    private function neighbours(int $i, int $cols, int $rows): array
    {
        $x = $i % $cols;
        $y = intdiv($i, $cols);

        $out = [];
        if ($x > 0) {
            $out[] = $i - 1;
        }
        if ($x + 1 < $cols) {
            $out[] = $i + 1;
        }
        if ($y > 0) {
            $out[] = $i - $cols;
        }
        if ($y + 1 < $rows) {
            $out[] = $i + $cols;
        }

        return $out;
    }

    /**
     * Open the wall between two adjacent cells.
     *
     * Every passage is stored once, on the cell to its west or north, so the
     * encoding cannot disagree with itself — the alternative, a flag on each side,
     * is two places to get the same fact wrong.
     */
    private function carve(array &$cells, int $a, int $b, int $cols): void
    {
        $lower = min($a, $b);

        $cells[$lower] |= abs($a - $b) === 1 ? self::EAST : self::SOUTH;
    }

    /** Breadth-first distance from the top-left corner, in cells. */
    private function depths(array $cells, int $cols, int $rows): array
    {
        $depth = array_fill(0, $cols * $rows, -1);
        $depth[0] = 0;
        $queue = [0];

        for ($head = 0; $head < count($queue); $head++) {
            $i = $queue[$head];

            foreach ($this->connections($cells, $i, $cols, $rows) as $next) {
                if ($depth[$next] === -1) {
                    $depth[$next] = $depth[$i] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $depth;
    }

    /**
     * The cells actually reachable from this one — neighbours with the wall down.
     *
     * @return list<int>
     */
    private function connections(array $cells, int $i, int $cols, int $rows): array
    {
        $x = $i % $cols;
        $y = intdiv($i, $cols);

        $out = [];
        if ($cells[$i] & self::EAST) {
            $out[] = $i + 1;
        }
        if ($cells[$i] & self::SOUTH) {
            $out[] = $i + $cols;
        }
        if ($x > 0 && ($cells[$i - 1] & self::EAST)) {
            $out[] = $i - 1;
        }
        if ($y > 0 && ($cells[$i - $cols] & self::SOUTH)) {
            $out[] = $i - $cols;
        }

        return $out;
    }

    private function deadEnds(array $cells, int $cols, int $rows): int
    {
        $count = 0;

        for ($i = 0; $i < $cols * $rows; $i++) {
            if (count($this->connections($cells, $i, $cols, $rows)) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Two bits per cell, base64'd.
     *
     * A 2000-cell maze is 500 bytes packed and 667 characters encoded, so three of
     * them fit inside the spec budget with room to spare — which is the only
     * reason the algorithm gets to live on this side of the wire at all.
     */
    private function pack(array $cells): string
    {
        $out = '';
        $byte = 0;
        $filled = 0;

        foreach ($cells as $value) {
            $byte = ($byte << 2) | ($value & 3);

            if (++$filled === 4) {
                $out .= chr($byte);
                $byte = 0;
                $filled = 0;
            }
        }

        if ($filled > 0) {
            $out .= chr($byte << (2 * (4 - $filled)));
        }

        return base64_encode($out);
    }

    private function describe(string $choice, int $cols, int $rows, array $stats): string
    {
        if ($choice !== 'compare') {
            return sprintf('%s · %d × %d · longest route %d cells', self::ALGORITHMS[$choice], $cols, $rows, $stats[$choice]['max_depth']);
        }

        return implode(' · ', array_map(
            fn (string $key): string => sprintf('%s: %d deep, %.0f%% dead ends', self::ALGORITHMS[$key], $stats[$key]['max_depth'], $stats[$key]['dead_ends'] * 100),
            array_keys($stats),
        ));
    }
}
