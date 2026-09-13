<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * The planetary K-index: how disturbed Earth's magnetic field is right now.
 *
 * Driven by the solar wind, so the ultimate source is nuclear fusion 150 million
 * kilometres away. Beautiful provenance, almost no entropy — Kp is a single digit
 * from 0 to 9 that changes every three hours, and quiet days sit at 0 or 1 for
 * weeks. The minute-by-minute estimates carry a little more.
 */
final class SpaceWeatherSource implements EntropySource
{
    public function key(): string
    {
        return 'space-weather';
    }

    public function label(): string
    {
        return 'NOAA Space Weather';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Observational;
    }

    public function origin(): string
    {
        return "The planetary K-index — how much the solar wind is currently disturbing Earth's magnetic field, measured by NOAA.";
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function collect(int $bytes): Material
    {
        $readings = Http::timeout(1.5)
            ->get('https://services.swpc.noaa.gov/json/planetary_k_index_1m.json')
            ->throw()
            ->json();

        if (! is_array($readings) || $readings === []) {
            throw new \RuntimeException('NOAA returned no K-index readings.');
        }

        $latest = end($readings);
        $kp = (float) ($latest['estimated_kp'] ?? $latest['kp_index'] ?? 0);

        // Hash the recent history rather than the single current value: one digit
        // that changes every few hours is not entropy by any definition.
        $window = array_slice($readings, -720);

        return new Material(
            bytes: substr(str_repeat(hash('sha256', json_encode($window, JSON_THROW_ON_ERROR), true), (int) ceil($bytes / 32)), 0, $bytes),
            narrative: sprintf('Planetary K-index %.2f — %s when this was made.', $kp, $this->describe($kp)),
            proofUrl: 'https://www.swpc.noaa.gov/products/planetary-k-index',
            reference: $latest['time_tag'] ?? null,
            observedAt: isset($latest['time_tag']) ? new \DateTimeImmutable($latest['time_tag'].' UTC') : new \DateTimeImmutable,
        );
    }

    private function describe(float $kp): string
    {
        return match (true) {
            $kp < 2 => 'the geomagnetic field was quiet',
            $kp < 4 => 'the field was unsettled',
            $kp < 5 => 'the field was active',
            $kp < 6 => 'a minor geomagnetic storm was under way',
            $kp < 8 => 'a strong geomagnetic storm was under way',
            default => 'a severe geomagnetic storm was under way',
        };
    }
}
