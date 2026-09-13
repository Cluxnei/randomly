<?php

declare(strict_types=1);

namespace App\Random\Entropy;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\Sources\CsprngSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one impure step in the whole system: turn the physical world into 16 bytes.
 *
 * Two promises are kept here at once, and the receipt states both:
 *
 *   1. The exotic source really did contribute, and we can point at the proof.
 *   2. The result is unique and unpredictable regardless, because fresh local
 *      CSPRNG bytes and a monotonic counter are always folded in before HKDF.
 *
 * That second promise is what makes caching safe. We may reuse a NIST pulse for
 * its full 60-second life without ever reusing a seed.
 */
final class EntropyPool
{
    private static int $counter = 0;

    /** Set by collect() so the receipt can distinguish a cache hit from a fast source. */
    private bool $servedFromCache = false;

    /** @var array<string, EntropySource> */
    private array $sources = [];

    /** @param iterable<EntropySource> $sources */
    public function __construct(iterable $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->key()] = $source;
        }
    }

    /** @return array<string, EntropySource> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function source(string $key): ?EntropySource
    {
        return $this->sources[$key] ?? null;
    }

    public function seed(?string $prefer = null): Seed
    {
        $source = $this->resolve($prefer);
        $startedAt = hrtime(true);

        $this->servedFromCache = false;

        try {
            $material = $this->collect($source);
            $degraded = false;
        } catch (\Throwable $e) {
            Log::warning("Entropy source [{$source->key()}] failed: {$e->getMessage()}");
            (new CircuitBreaker($source->key()))->recordFailure();

            $source = new CsprngSource;
            $material = $source->collect(32);
            $degraded = true;
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $isCsprng = $source instanceof CsprngSource;

        // The exotic material supplies the story and a real contribution; the local
        // CSPRNG, the nanosecond clock and the counter supply uniqueness.
        $ikm = $material->bytes
            .random_bytes(32)
            .pack('J', hrtime(true))
            .pack('J', ++self::$counter);

        $bytes = hash_hkdf('sha256', $ikm, Seed::BYTES, 'randomly.seed', 'randomly.seed.v1');

        return new Seed($bytes, new Receipt(
            sourceKey: $source->key(),
            sourceLabel: $source->label(),
            class: $source->class(),
            narrative: $degraded
                ? $material->narrative.' (The source you picked was unreachable, so we fell back to the kernel.)'
                : $material->narrative,
            proofUrl: $material->proofUrl,
            reference: $material->reference,
            observedAt: $material->observedAt ?? new \DateTimeImmutable,
            degraded: $degraded,
            mixedWithCsprng: ! $isCsprng,
            latencyMs: $latencyMs,
            cached: $this->servedFromCache,
        ));
    }

    /**
     * Collected material is cached for as long as it stays fresh at the origin.
     * A NIST pulse lives 60 seconds; re-fetching it six times a minute would be
     * rude and would tell us nothing new.
     */
    private function collect(EntropySource $source): Material
    {
        if ($source instanceof CsprngSource) {
            return $source->collect(32);
        }

        if ((new CircuitBreaker($source->key()))->isOpen()) {
            throw new \RuntimeException("Circuit open for [{$source->key()}].");
        }

        $ttl = max(1, min($source->refreshSeconds() ?: 10, 60));
        $key = "randomly.entropy.{$source->key()}";

        // Deliberately not Cache::remember: we need to know whether this was a hit.
        // A cached NIST pulse answers in 0 ms, and reporting that as the source's
        // latency would quietly overstate how fast the beacon is.
        $cached = Cache::get($key);
        $this->servedFromCache = $cached !== null;

        if ($cached === null) {
            $material = $source->collect(32);
            (new CircuitBreaker($source->key()))->recordSuccess();

            $cached = [
                'bytes' => base64_encode($material->bytes),
                'narrative' => $material->narrative,
                'proof_url' => $material->proofUrl,
                'reference' => $material->reference,
                'observed_at' => ($material->observedAt ?? new \DateTimeImmutable)->format(DATE_ATOM),
            ];

            Cache::put($key, $cached, $ttl);
        }

        return new Material(
            bytes: base64_decode($cached['bytes']),
            narrative: $cached['narrative'],
            proofUrl: $cached['proof_url'],
            reference: $cached['reference'],
            observedAt: new \DateTimeImmutable($cached['observed_at']),
        );
    }

    /**
     * Resolve the requested source, or rotate through the healthy ones.
     *
     * 'auto' deliberately spreads load rather than favouring the fastest source —
     * variety in the receipts is part of the product.
     */
    private function resolve(?string $prefer): EntropySource
    {
        if ($prefer !== null && $prefer !== 'auto' && isset($this->sources[$prefer])) {
            return $this->sources[$prefer];
        }

        $healthy = array_values(array_filter(
            $this->sources,
            fn (EntropySource $s) => ! (new CircuitBreaker($s->key()))->isOpen() && ! $s instanceof CsprngSource,
        ));

        if ($healthy === []) {
            return $this->sources['csprng'] ?? new CsprngSource;
        }

        // Picked at random rather than round-robin. A counter only rotates within
        // one process, and PHP gives no promise about how requests map onto
        // processes — under the built-in server every request is a fresh one, so a
        // static counter is permanently zero and 'auto' silently means 'always the
        // first source'. Variety in the receipts is part of the product, so it
        // cannot depend on the process model.
        return $healthy[random_int(0, count($healthy) - 1)];
    }

    /** Live status for the /entropy dashboard, read from cache only. */
    public function status(): array
    {
        return array_map(function (EntropySource $source): array {
            $breaker = new CircuitBreaker($source->key());
            $cached = Cache::get("randomly.entropy.{$source->key()}");

            return [
                'key' => $source->key(),
                'label' => $source->label(),
                'class' => $source->class()->value,
                'class_label' => $source->class()->label(),
                'caveat' => $source->class()->caveat(),
                'origin' => $source->origin(),
                'refresh_seconds' => $source->refreshSeconds(),
                'status' => $breaker->isOpen() ? 'down' : 'up',
                'failures' => $breaker->failures(),
                'current' => $cached['narrative'] ?? null,
                'proof_url' => $cached['proof_url'] ?? null,
                'observed_at' => $cached['observed_at'] ?? null,
            ];
        }, array_values($this->sources));
    }
}
