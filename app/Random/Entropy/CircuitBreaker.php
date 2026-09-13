<?php

declare(strict_types=1);

namespace App\Random\Entropy;

use Illuminate\Support\Facades\Cache;

/**
 * Keeps one flaky third party from becoming our outage.
 *
 * Three consecutive failures open the circuit for a minute; the next attempt
 * after that is a half-open probe that either restores the source or re-opens it.
 */
final class CircuitBreaker
{
    private const THRESHOLD = 3;

    private const OPEN_SECONDS = 60;

    public function __construct(private readonly string $key) {}

    public function isOpen(): bool
    {
        return Cache::get($this->openKey(), false) === true;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failureKey());
        Cache::forget($this->openKey());
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get($this->failureKey(), 0) + 1;
        Cache::put($this->failureKey(), $failures, now()->addMinutes(5));

        if ($failures >= self::THRESHOLD) {
            Cache::put($this->openKey(), true, now()->addSeconds(self::OPEN_SECONDS));
        }
    }

    public function failures(): int
    {
        return (int) Cache::get($this->failureKey(), 0);
    }

    private function failureKey(): string
    {
        return "randomly.breaker.{$this->key}.failures";
    }

    private function openKey(): string
    {
        return "randomly.breaker.{$this->key}.open";
    }
}
