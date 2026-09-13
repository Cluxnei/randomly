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
 * L-systems: a string, a handful of rewrite rules, and a turtle.
 *
 * Lindenmayer invented these in 1968 to model how algae grow, and the mechanism
 * is almost insultingly simple. Start with an axiom — say "X". Replace every
 * symbol at once according to the rules. Do it again. Then read the resulting
 * string as instructions for a pen: F draws forward, + and − turn, [ and ]
 * remember and restore where you were. Out of that come ferns, snowflakes, the
 * dragon curve and the Sierpiński triangle, none of which is anywhere in the
 * rules.
 *
 * The randomness lives in *stochastic productions*: a symbol with several
 * possible replacements picks one each time it is rewritten. A deterministic
 * L-system draws the same plant every time, which is the giveaway that it is not
 * really a plant. One rule with three alternatives is the entire difference
 * between a diagram of a tree and a tree.
 *
 * The rewriting happens in the browser, not here. A plant at six iterations is a
 * few hundred thousand symbols, which is two orders of magnitude past what a spec
 * is allowed to be — so the rules and a seed go across, and the expansion and the
 * drawing happen together on the other side.
 */
final class LsystemGenerator extends BaseGenerator
{
    /**
     * The presets, as [axiom, rules, angle, iterations, heading].
     *
     * Rules map a symbol to a list of [weight, replacement] alternatives. A single
     * alternative is a deterministic rule; several make it stochastic. Heading is
     * in degrees clockwise from east, so −90 points the turtle up the page.
     */
    private const PRESETS = [
        'plant' => [
            'X',
            [
                'X' => [
                    [4, 'F+[[X]-X]-F[-FX]+X'],
                    [3, 'F-[[X]+X]+F[+FX]-X'],
                    [2, 'F[+X][-X]FX'],
                    [1, 'F[-X]F[+X]-X'],
                ],
                'F' => [[1, 'FF']],
            ],
            24.0,
            6,
            -90.0,
        ],
        'koch' => [
            'F--F--F',
            ['F' => [[1, 'F+F--F+F']]],
            60.0,
            4,
            0.0,
        ],
        'dragon' => [
            'FX',
            ['X' => [[1, 'X+YF+']], 'Y' => [[1, '-FX-Y']]],
            90.0,
            12,
            0.0,
        ],
        'sierpinski' => [
            'F-G-G',
            ['F' => [[1, 'F-G+F+G-F']], 'G' => [[1, 'GG']]],
            120.0,
            6,
            0.0,
        ],
    ];

    public function key(): string
    {
        return 'patterns.lsystem';
    }

    public function name(): string
    {
        return 'L-Systems';
    }

    public function tagline(): string
    {
        return 'Stochastic rewrite rules: plants, snowflakes, dragon curves.';
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
            ->enum('preset', 'System', [
                'plant' => 'Plant (stochastic)',
                'koch' => 'Koch snowflake',
                'dragon' => 'Dragon curve',
                'sierpinski' => 'Sierpiński triangle',
            ], default: 'plant')
            ->int('iterations', 'Iterations', default: 0, min: 0, max: 14, help: 'Zero uses the preset\'s own depth. Each iteration rewrites every symbol at once, so the string grows geometrically.')
            ->float('angle', 'Turn angle', default: 0.0, min: 0.0, max: 180.0, step: 0.5, help: 'Zero uses the preset\'s own angle. The single most dramatic control here — a plant at 90° is a lattice.')
            ->float('stochastic', 'Randomness', default: 1.0, min: 0.0, max: 1.0, step: 0.05, help: 'How freely the branching rules choose between their alternatives, and how much the turns and internodes wander. Only the plant has alternatives; the other three are textbook figures and ignore this.')
            ->float('thickness', 'Line weight', default: 2.0, min: 0.4, max: 6.0, step: 0.1)
            ->enum('palette', 'Colour', Palette::STRATEGIES, default: 'viridis')
            ->int('colours', 'Palette size', default: 7, min: 2, max: 12);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // ramp(), not build(): branches are coloured by how deep into the tree
        // they are, which is a continuous quantity running from trunk to tip.
        $palette = Palette::ramp($rng, $params->string('palette', 'viridis'), $params->int('colours', 7));

        $preset = $params->string('preset', 'plant');
        [$axiom, $rules, $angle, $iterations, $heading] = self::PRESETS[$preset] ?? self::PRESETS['plant'];

        // Zero means "use the preset's own value" rather than a separate toggle:
        // neither a zero-iteration L-system nor a zero-degree turn draws anything
        // worth looking at, so the bottom of each slider was free to mean this.
        $iterations = $params->int('iterations') ?: $iterations;
        $angle = $params->float('angle') ?: $angle;

        // The rewrite draws one number per stochastic symbol, and a plant at six
        // iterations has hundreds of thousands of them — far past the render
        // stream's 8 KB. One uint32 crosses instead; see resources/js/patterns/prng.js.
        $seed = $rng->uint32();

        $spec = [
            'algorithm' => 'lsystem',
            'width' => $params->int('width'),
            'height' => $params->int('height'),
            'axiom' => $axiom,
            'rules' => $rules,
            'angle' => $angle,
            'iterations' => $iterations,
            'heading' => $heading,
            'stochastic' => $params->float('stochastic'),
            'thickness' => $params->float('thickness'),
            'palette' => $palette,
            'seed' => $seed,
        ];

        return new Result(
            value: $spec,
            display: sprintf(
                '%s · %d iterations · %.1f° · %s',
                ucfirst($preset),
                $iterations,
                $angle,
                $this->stochasticRules($rules) > 0 && $params->float('stochastic') > 0
                    ? sprintf('%d stochastic rule%s', $this->stochasticRules($rules), $this->stochasticRules($rules) === 1 ? '' : 's')
                    : 'deterministic',
            ),
            meta: [
                'palette' => $palette,
                'axiom' => $axiom,
                'rules' => $this->readableRules($rules),
                'iterations' => $iterations,
                'angle' => $angle,
                // How many symbols the expansion will reach, ignoring stochastic
                // choice — the growth factor of a rule set is its dominant
                // eigenvalue, but for these four the plain per-symbol expansion is
                // within a few percent and is one loop rather than a matrix.
                'estimated_symbols' => $this->estimate($axiom, $rules, $iterations),
                'note' => $this->stochasticRules($rules) > 0 && $params->float('stochastic') > 0
                    ? 'The rule for X has four possible replacements and picks one every time it fires — hundreds of thousands of independent choices in a single plant. That is the whole difference between a diagram of a tree and a tree: a deterministic L-system draws the same specimen every time, and nothing in nature does.'
                    : 'Every rule here has exactly one replacement, so the figure is fully determined by the axiom and the iteration count — the same curve every time, from any seed. The randomness in this generator is entirely in the palette until the plant preset is selected or the randomness control is raised.',
            ],
        );
    }

    private function stochasticRules(array $rules): int
    {
        return count(array_filter($rules, fn (array $alternatives): bool => count($alternatives) > 1));
    }

    /** @return array<string, list<string>> symbol => its possible replacements */
    private function readableRules(array $rules): array
    {
        return array_map(
            fn (array $alternatives): array => array_map(fn (array $a): string => $a[1], $alternatives),
            $rules,
        );
    }

    /**
     * Roughly how long the expanded string gets.
     *
     * Counts symbols per rewrite using the mean replacement length of each rule,
     * which is exact for the deterministic presets and close for the stochastic
     * one. Reported so the iteration slider comes with a warning attached — the
     * growth is geometric, and two more iterations of the plant is a hundredfold.
     */
    private function estimate(string $axiom, array $rules, int $iterations): int
    {
        $counts = array_count_values(str_split($axiom));

        for ($i = 0; $i < $iterations; $i++) {
            $next = [];

            foreach ($counts as $symbol => $count) {
                if (! isset($rules[$symbol])) {
                    $next[$symbol] = ($next[$symbol] ?? 0) + $count;

                    continue;
                }

                $weight = array_sum(array_column($rules[$symbol], 0));

                foreach ($rules[$symbol] as [$w, $replacement]) {
                    foreach (array_count_values(str_split($replacement)) as $produced => $times) {
                        $next[$produced] = ($next[$produced] ?? 0) + $count * $times * $w / $weight;
                    }
                }
            }

            $counts = $next;

            // A runaway rule set would otherwise spend real time counting symbols
            // nobody will ever draw. The renderer stops at its own ceiling too.
            if (array_sum($counts) > 5_000_000) {
                break;
            }
        }

        return (int) round(array_sum($counts));
    }
}
