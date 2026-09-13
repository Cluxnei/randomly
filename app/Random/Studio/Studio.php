<?php

declare(strict_types=1);

namespace App\Random\Studio;

use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\EntropyPool;
use App\Random\Entropy\Receipt;
use App\Random\Entropy\Seed;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Contracts\NeedsData;
use App\Random\Generators\Params;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single path from "a request for randomness" to a finished Generation.
 *
 * The studio page and the public API both come through here, so there is exactly
 * one place where a seed is drawn and a generator is run. Nothing in the app is
 * allowed a private shortcut.
 */
final readonly class Studio
{
    public function __construct(
        private GeneratorRegistry $registry,
        private EntropyPool $pool,
    ) {}

    public function generate(string $generatorKey, array $input = [], ?string $source = null): Generation
    {
        $generator = $this->registry->findOrFail($generatorKey);

        // A generator that consumes no randomness gets no entropy draw. Reaching
        // for a live beacon here would spend up to a second of someone's time to
        // produce a receipt crediting a source that changed nothing about the
        // output — the result would be byte-identical had that pulse never been
        // published. The receipt says exactly that instead.
        if (! $generator->usesEntropy()) {
            $seed = $this->pool->seed('csprng');

            return $this->run($generator, $seed, $input, $this->deterministicReceipt($seed->receipt));
        }

        $seed = $this->pool->seed($source ?? config('randomly.entropy.default_source'));

        return $this->run($generator, $seed, $input, $seed->receipt);
    }

    private function deterministicReceipt(Receipt $receipt): Receipt
    {
        return new Receipt(
            sourceKey: 'none',
            sourceLabel: 'No entropy used',
            class: EntropyClass::Cryptographic,
            narrative: 'This generator is a pure function of what you typed. No randomness was drawn, and the same input will produce this same result anywhere, forever.',
            proofUrl: null,
            reference: null,
            observedAt: $receipt->observedAt,
            degraded: false,
            mixedWithCsprng: false,
            latencyMs: 0,
        );
    }

    /**
     * Rebuild a past result from its token.
     *
     * The token is the seed, so this needs no storage. The receipt is rebuilt from
     * the source key and reference carried in the link and marked reconstructed —
     * we will not present a freshly-invented receipt as the original.
     */
    public function replay(string $token, string $generatorKey, int $version, array $input = [], ?string $sourceKey = null, ?string $reference = null): Generation
    {
        $generator = $this->registry->findOrFail($generatorKey);

        if ($generator->isSensitive()) {
            throw new \RuntimeException("[{$generatorKey}] produces secrets and is never replayable.");
        }

        if ($generator->version() !== $version) {
            throw new VersionChanged($generatorKey, $version, $generator->version());
        }

        $seed = Seed::fromToken($token, $receipt = $this->reconstructReceipt($sourceKey, $reference));

        return $this->run($generator, $seed, $input, $receipt, replayed: true);
    }

    private function run(Generator $generator, Seed $seed, array $input, Receipt $receipt, bool $replayed = false): Generation
    {
        $params = $generator->schema()->coerce($input);

        // Derive the stream from the parameters *before* attaching fetched data.
        // Fetched material is deliberately outside the fingerprint: a Wikipedia
        // pool that shifts between two requests would otherwise derive a different
        // stream and break replay for a reason nobody could see.
        $rng = $seed->rng($generator->key(), $generator->version(), $params->fingerprint());

        if ($generator instanceof NeedsData) {
            $params = $params->withData($this->fetchFor($generator, $params));
        }

        $startedAt = hrtime(true);
        $result = $generator->generate($rng, $params);
        $durationUs = (int) round((hrtime(true) - $startedAt) / 1_000);

        return new Generation(
            generator: $generator,
            params: $params,
            result: $result->withMeta([
                'stream_bytes_used' => $rng->bytesRead(),
                'rejections' => $result->meta['rejections'] ?? $rng->rejections(),
            ]),
            seed: $seed,
            receipt: $receipt,
            durationUs: $durationUs,
            replayed: $replayed,
        );
    }

    /**
     * Fetch a generator's raw material, and never let a third party take the page down.
     *
     * Cached for as long as the generator says the material stays usable, so a slider
     * drag does not become a request to somebody else's server, and wrapped so that a
     * failure degrades to the declared fallback rather than a 500. The generator's own
     * output says which happened.
     */
    private function fetchFor(NeedsData $generator, Params $params): array
    {
        $key = 'randomly.data.'.$generator->key().'.'.substr(hash('sha256', json_encode($params->fingerprint())), 0, 16);

        try {
            return Cache::remember(
                $key,
                max(1, $generator->cacheSeconds()),
                fn (): array => $generator->fetch($params),
            );
        } catch (\Throwable $e) {
            Log::warning("Data fetch failed for [{$generator->key()}]: {$e->getMessage()}");

            return ['degraded' => true, ...$generator->fallback()];
        }
    }

    private function reconstructReceipt(?string $sourceKey, ?string $reference): Receipt
    {
        $source = $sourceKey !== null ? $this->pool->source($sourceKey) : null;

        return new Receipt(
            sourceKey: $source?->key() ?? 'unknown',
            sourceLabel: $source?->label() ?? 'Not recorded',
            class: $source?->class() ?? EntropyClass::Observational,
            narrative: $source !== null
                ? sprintf('Originally drawn from %s%s.', $source->label(), $reference !== null ? " ({$reference})" : '')
                : 'This link did not record where its randomness came from.',
            proofUrl: null,
            reference: $reference,
            observedAt: new \DateTimeImmutable,
            degraded: false,
            mixedWithCsprng: $sourceKey !== null && $sourceKey !== 'csprng',
            latencyMs: 0,
        );
    }
}
