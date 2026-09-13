<?php

declare(strict_types=1);

use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Receipt;
use App\Random\Entropy\Seed;
use App\Random\Rng\Rng;

function stubReceipt(): Receipt
{
    return new Receipt(
        sourceKey: 'csprng',
        sourceLabel: 'Operating System CSPRNG',
        class: EntropyClass::Cryptographic,
        narrative: 'Drawn from the kernel entropy pool.',
        proofUrl: null,
        reference: null,
        observedAt: new DateTimeImmutable('2026-09-12T00:00:00+00:00'),
        degraded: false,
        mixedWithCsprng: false,
        latencyMs: 0,
    );
}

function draws(Rng $rng, int $n = 32): array
{
    return array_map(fn (): int => $rng->uint32(), range(1, $n));
}

it('insists on exactly sixteen bytes', function (): void {
    expect(fn () => new Seed(random_bytes(15), stubReceipt()))->toThrow(InvalidArgumentException::class);
});

it('carries the whole seed in the token, so a permalink needs no database', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());

    $replayed = Seed::fromToken($seed->token(), stubReceipt());

    expect($replayed->bytes)->toBe($seed->bytes)
        ->and($replayed->token())->toBe($seed->token());
});

it('replays an identical stream for the same generator, version and params', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());

    expect(draws($seed->rng('numbers.integers', 1, ['count' => 6])))
        ->toBe(draws($seed->rng('numbers.integers', 1, ['count' => 6])));
});

it('gives two generators uncorrelated streams from one seed', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());

    expect(draws($seed->rng('numbers.integers', 1, ['count' => 6])))
        ->not->toBe(draws($seed->rng('words.passphrase', 1, ['count' => 6])));
});

it('separates streams by generator version', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());

    expect(draws($seed->rng('numbers.integers', 1)))
        ->not->toBe(draws($seed->rng('numbers.integers', 2)));
});

it('separates streams by parameters', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());

    expect(draws($seed->rng('numbers.integers', 1, ['count' => 6])))
        ->not->toBe(draws($seed->rng('numbers.integers', 1, ['count' => 7])));
});

it('reproduces a stream from a token alone', function (): void {
    $seed = new Seed(random_bytes(Seed::BYTES), stubReceipt());
    $token = $seed->token();

    expect(draws(Seed::fromToken($token, stubReceipt())->rng('numbers.integers', 1, ['count' => 6])))
        ->toBe(draws($seed->rng('numbers.integers', 1, ['count' => 6])));
});
