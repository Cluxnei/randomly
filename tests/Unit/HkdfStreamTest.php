<?php

declare(strict_types=1);

use App\Random\Rng\HkdfStream;

it('produces the same bytes for the same key and info', function (): void {
    $a = new HkdfStream('prk-material', 'randomly/v1|numbers.integers');
    $b = new HkdfStream('prk-material', 'randomly/v1|numbers.integers');

    expect($a->read(256))->toBe($b->read(256));
});

it('produces different bytes when only the info differs', function (): void {
    $a = new HkdfStream('prk-material', 'randomly/v1|numbers.integers');
    $b = new HkdfStream('prk-material', 'randomly/v1|words.passphrase');

    expect($a->read(256))->not->toBe($b->read(256));
});

it('is continuous across reads, however they are chunked', function (): void {
    $whole = (new HkdfStream('prk-material', 'info'))->read(64);

    $stream = new HkdfStream('prk-material', 'info');
    $byByte = '';
    for ($i = 0; $i < 64; $i++) {
        $byByte .= $stream->read(1);
    }

    expect($byByte)->toBe($whole);
});

it('keeps going past the 255-block limit of RFC 5869', function (): void {
    // 255 x 32 = 8160 bytes is where an 8-bit counter would wrap or refuse.
    $out = (new HkdfStream('prk-material', 'info'))->read(9000);

    expect(strlen($out))->toBe(9000)
        ->and(substr($out, 8160, 32))->not->toBe(substr($out, 0, 32));

    // Every block past the limit must still be fresh, not a repeat of an earlier one.
    $blocks = str_split($out, 32);
    expect(count(array_unique($blocks)))->toBe(count($blocks));
});

it('reports how much of the stream was consumed', function (): void {
    $stream = new HkdfStream('prk-material', 'info');
    $stream->read(10);
    $stream->read(7);

    expect($stream->bytesRead())->toBe(17);
});
