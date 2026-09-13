<?php

declare(strict_types=1);

use App\Random\Entropy\Base32;
use App\Random\Rng\HkdfStream;
use App\Random\Rng\Rng;
use Symfony\Component\Process\Process;

/**
 * PHP and JavaScript must produce the identical stream.
 *
 * The site makes a real correctness claim — that a canvas drawn in your browser
 * is the same draw the server would have made — and pattern generators ship a
 * render key instead of pixels on the strength of it. Two implementations of the
 * same algorithm in two languages is exactly the kind of promise that rots
 * silently, so it is pinned here rather than trusted.
 */
function jsVector(string $prkHex, string $info, int $length): array
{
    $process = Process::fromShellCommandline(
        sprintf('node scripts/rng-vector.mjs %s %s %d', escapeshellarg($prkHex), escapeshellarg($info), $length),
        dirname(__DIR__, 2),
    );
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('node failed: '.$process->getErrorOutput());
    }

    return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    exec('node --version 2>/dev/null', $out, $code);

    if ($code !== 0) {
        $this->markTestSkipped('node is not available; the parity check needs it.');
    }
});

it('expands the identical byte stream in both languages', function (): void {
    $prkHex = str_repeat('a7', 32);
    $info = 'randomly/render|patterns.perlin|v1';

    $php = bin2hex((new HkdfStream(hex2bin($prkHex), $info))->read(512));

    expect(jsVector($prkHex, $info, 512)['stream'])->toBe($php);
});

it('crosses the RFC 5869 block boundary identically', function (): void {
    // The 32-bit counter deviation only shows up past 8160 bytes. If someone
    // "fixes" one side back to the RFC's 8-bit counter, this is what catches it.
    $prkHex = str_repeat('3c', 32);
    $info = 'boundary';

    $php = bin2hex((new HkdfStream(hex2bin($prkHex), $info))->read(9000));

    expect(jsVector($prkHex, $info, 9000)['stream'])->toBe($php);
});

it('draws the identical unbiased integers, rejections included', function (): void {
    $prkHex = str_repeat('5e', 32);
    $info = 'ints';
    $js = jsVector($prkHex, $info, 4096);

    $rng = new Rng(new HkdfStream(hex2bin($prkHex), $info));
    $php = array_map(fn (): int => $rng->intBetween(1, 100), range(1, 20));

    expect($php)->toBe($js['ints'])
        ->and($rng->rejections())->toBe($js['rejections']);
});

it('produces the identical doubles', function (): void {
    $prkHex = str_repeat('11', 32);
    $info = 'floats';
    $js = jsVector($prkHex, $info, 4096);

    $rng = new Rng(new HkdfStream(hex2bin($prkHex), $info));
    // Skip the 20 ints the JS vector draws first so both sides are at the same offset.
    foreach (range(1, 20) as $ignored) {
        $rng->intBetween(1, 100);
    }

    $php = array_map(fn (): string => number_format($rng->float(), 15, '.', ''), range(1, 5));

    expect($php)->toBe($js['floats']);
});

it('shuffles into the identical permutation', function (): void {
    // Fisher-Yates consumes the stream in a particular order; a subtle difference
    // in loop direction would still produce a valid permutation, just not the same
    // one, and the two canvases would diverge while both looking correct.
    $prkHex = str_repeat('9b', 32);
    $info = 'shuffle';
    $js = jsVector($prkHex, $info, 4096);

    $rng = new Rng(new HkdfStream(hex2bin($prkHex), $info));

    expect($rng->shuffle(range(0, 15)))->toBe($js['shuffle']);
});

it('decodes a token to the identical bytes', function (): void {
    $js = jsVector(str_repeat('00', 32), 'token', 64);

    expect(bin2hex(Base32::decode('QGZPPD2MX955XFVBX6GC7KWX00')))->toBe($js['token']);
});
