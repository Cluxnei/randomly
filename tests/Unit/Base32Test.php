<?php

declare(strict_types=1);

use App\Random\Entropy\Base32;

it('round-trips arbitrary byte strings', function (): void {
    foreach (range(1, 16) as $length) {
        $bytes = random_bytes($length);

        expect(Base32::decode(Base32::encode($bytes)))->toBe($bytes);
    }
});

it('encodes a 16-byte seed as a 26-character token', function (): void {
    // 128 bits / 5 bits per character, rounded up — the length every token shares.
    expect(strlen(Base32::encode(random_bytes(16))))->toBe(26);
});

it('reads Crockford aliases the way a human would write them', function (): void {
    $bytes = hex2bin('00112233445566778899aabbccddeeff');
    $token = Base32::encode($bytes);

    expect(Base32::decode(strtr($token, ['0' => 'O', '1' => 'I'])))->toBe($bytes)
        ->and(Base32::decode(strtr($token, ['0' => 'o', '1' => 'l'])))->toBe($bytes);
});

it('ignores dashes and surrounding whitespace', function (): void {
    $bytes = random_bytes(16);
    $token = Base32::encode($bytes);

    expect(Base32::decode('  '.substr($token, 0, 13).'-'.substr($token, 13).'  '))->toBe($bytes);
});

it('rejects characters that are not in the alphabet', function (): void {
    // U is deliberately absent from Crockford base32, so it can never be a typo we accept.
    expect(fn () => Base32::decode('ABCDU'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Base32::decode('ABCD$'))->toThrow(InvalidArgumentException::class);
});
