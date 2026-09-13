<?php

declare(strict_types=1);

use App\Random\Entropy\Seed;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Renderer;
use App\Random\Media\MediaRenderer;
use App\Random\Media\MediaUnavailable;
use App\Random\Studio\Generation;

/**
 * The seam where the API stops being JSON.
 *
 * `supports()` decides whether a caller gets a file or a 406, so getting it wrong
 * means either a confusing error on a generator that should render, or a process
 * spawned to render something that never could.
 */
function generationFor(Generator $generator): Generation
{
    $params = $generator->schema()->coerce([]);
    $seed = new Seed(str_repeat('m', 16), stubReceipt());
    $rng = $seed->rng($generator->key(), $generator->version(), $params->fingerprint());

    return new Generation(
        generator: $generator,
        params: $params,
        result: $generator->generate($rng, $params),
        seed: $seed,
        receipt: stubReceipt(),
        durationUs: 1,
    );
}

function renderer(): MediaRenderer
{
    return new MediaRenderer(
        cacheDirectory: sys_get_temp_dir().'/randomly-media-test',
        projectRoot: dirname(__DIR__, 2),
    );
}

it('offers a file only for the renderers that can produce one', function (Generator $generator): void {
    $generation = generationFor($generator);
    $media = renderer();

    expect($media->supports($generation, 'png'))->toBe($generator->renderer() === Renderer::Canvas)
        ->and($media->supports($generation, 'wav'))->toBe($generator->renderer() === Renderer::Audio);
})->with(allGenerators());

it('never offers a file for a format that does not exist', function (Generator $generator): void {
    expect(renderer()->supports(generationFor($generator), 'gif'))->toBeFalse();
})->with(allGenerators());

it('refuses a representation it cannot produce, rather than trying', function (): void {
    // Spawning a Node process to render a passphrase as a PNG would fail slowly and
    // confusingly. This fails immediately and says what is actually available.
    $generation = generationFor(allGenerators()['words.passphrase']);

    expect(fn () => renderer()->render($generation, 'png'))
        ->toThrow(MediaUnavailable::class, 'has no PNG representation');
});

it('keys the cache on what is drawn, not on the request', function (): void {
    $generator = allGenerators()['patterns.perlin'];
    $media = renderer();

    $narrow = new Generation(
        generator: $generator,
        params: $p1 = $generator->schema()->coerce(['width' => 300, 'height' => 200]),
        result: $generator->generate((new Seed(str_repeat('m', 16), stubReceipt()))->rng($generator->key(), 1, $p1->fingerprint()), $p1),
        seed: new Seed(str_repeat('m', 16), stubReceipt()),
        receipt: stubReceipt(),
        durationUs: 1,
    );

    $wide = new Generation(
        generator: $generator,
        params: $p2 = $generator->schema()->coerce(['width' => 900, 'height' => 200]),
        result: $generator->generate((new Seed(str_repeat('m', 16), stubReceipt()))->rng($generator->key(), 1, $p2->fingerprint()), $p2),
        seed: new Seed(str_repeat('m', 16), stubReceipt()),
        receipt: stubReceipt(),
        durationUs: 1,
    );

    $fingerprint = function (Generation $generation): string {
        $method = new ReflectionMethod(MediaRenderer::class, 'fingerprint');

        return $method->invoke(renderer(), $generation, 'png');
    };

    expect($fingerprint($narrow))->not->toBe($fingerprint($wide))
        ->and($fingerprint($narrow))->toBe($fingerprint($narrow));
});

it('renders a real PNG through the browser’s own renderer', function (): void {
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        $this->markTestSkipped('node is not available.');
    }

    $generator = allGenerators()['patterns.perlin'];
    // 200 is the schema's declared minimum; asking for less gets clamped up to it,
    // which is the coercion working and not something to render around.
    $params = $generator->schema()->coerce(['width' => 200, 'height' => 200, 'octaves' => 2]);
    $seed = new Seed(str_repeat('m', 16), stubReceipt());

    $generation = new Generation(
        generator: $generator,
        params: $params,
        result: $generator->generate($seed->rng($generator->key(), 1, $params->fingerprint()), $params),
        seed: $seed,
        receipt: stubReceipt(),
        durationUs: 1,
    );

    $path = renderer()->render($generation, 'png');

    expect($path)->toBeFile();

    // Check the magic bytes and the IHDR dimensions rather than just the file size:
    // a renderer that wrote a valid but empty image would pass a size check.
    $header = file_get_contents($path, length: 24);

    expect(substr($header, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(unpack('N', substr($header, 16, 4))[1])->toBe(200)
        ->and(unpack('N', substr($header, 20, 4))[1])->toBe(200);

    @unlink($path);
});
