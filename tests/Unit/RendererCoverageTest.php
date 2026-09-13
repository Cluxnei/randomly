<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Renderer;
use Symfony\Component\Process\Process;

/**
 * Every canvas generator must have a browser half.
 *
 * A canvas generator ships a description instead of an image, so the drawing code
 * lives in JavaScript and nothing on the PHP side can tell whether it exists. Ship
 * one without its renderer and the studio serves a blank rectangle — the test
 * suite stays green, the API returns 200, and only a human opening the page finds
 * out. The two halves are registered in different files by different people, which
 * is exactly the seam where this goes wrong.
 */
function projectPath(string $relative): string
{
    // Unit tests deliberately never boot the framework — that is what keeps the
    // suite instant — so base_path() is not available here.
    return dirname(__DIR__, 2).'/'.$relative;
}

/**
 * Ask the JavaScript which algorithms it can draw.
 *
 * The first version of this parsed the registry maps out of the source with a
 * regex and silently under-reported: the two registries use different value
 * shapes, and a non-greedy brace match stopped at the first nested object. A
 * coverage check that quietly passes by finding nothing is worse than no check,
 * so it asks the modules themselves.
 *
 * @param  list<string>  $algorithms
 * @return array<string, bool>
 */
function rendererSupport(array $algorithms): array
{
    $process = Process::fromShellCommandline(
        'node scripts/list-renderers.mjs '.implode(' ', array_map('escapeshellarg', $algorithms)),
        dirname(__DIR__, 2),
    );
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('node failed: '.$process->getErrorOutput());
    }

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

function canvasGenerators(): array
{
    return array_filter(
        allGenerators(),
        fn (Generator $g): bool => $g->renderer() === Renderer::Canvas,
    );
}

beforeEach(function (): void {
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        $this->markTestSkipped('node is not available; the renderer check needs it.');
    }
});

it('finds at least one canvas generator to check', function (): void {
    // Guards the guard: a broken filter would make every assertion below vacuous.
    expect(canvasGenerators())->not->toBeEmpty();
});

it('registers a browser renderer for every canvas generator', function (): void {
    $algorithms = [];

    foreach (canvasGenerators() as $generator) {
        $params = $generator->schema()->coerce([]);
        $spec = $generator->generate(
            seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint()),
            $params,
        )->value;

        expect($spec)->toHaveKey('algorithm');
        $algorithms[$generator->key()] = $spec['algorithm'];
    }

    $support = rendererSupport(array_values($algorithms));

    foreach ($algorithms as $key => $algorithm) {
        expect($support[$algorithm] ?? false)
            ->toBeTrue("[{$key}] emits algorithm [{$algorithm}], which no browser renderer is registered for.");
    }
});

it('points every registered renderer at a file that exists', function (): void {
    foreach (['resources/js/canvas.js', 'resources/js/renderers.js'] as $file) {
        preg_match_all("/from '(\.\/[^']+\.js)'/", file_get_contents(projectPath($file)), $imports);

        foreach ($imports[1] as $import) {
            expect(projectPath('resources/js/'.ltrim($import, './')))->toBeFile();
        }
    }
});

it('keeps a canvas generator small enough to be worth shipping as a spec', function (): void {
    // The whole justification for client-side rendering is that a description is
    // tiny next to pixels. A spec that bloats past a few KB means a generator is
    // shipping data it should be deriving.
    foreach (canvasGenerators() as $generator) {
        $params = $generator->schema()->coerce([]);
        $spec = $generator->generate(
            seedFor()->rng($generator->key(), $generator->version(), $params->fingerprint()),
            $params,
        )->value;

        expect(strlen(json_encode($spec)))->toBeLessThan(8192);
    }
});
