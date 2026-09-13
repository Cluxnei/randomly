<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * The weather, somewhere, right now.
 *
 * Temperature to a tenth of a degree, wind speed, pressure — perhaps twenty bits
 * between them, and heavily autocorrelated with the reading a minute ago. Pure
 * flavour, and lovely flavour: the number you drew depends on the wind in
 * Ushuaia.
 *
 * The location is picked locally rather than by the weather service, so the
 * *choice* is genuine randomness even when the reading barely moves.
 */
final class AtmosphereSource implements EntropySource
{
    /** Places chosen to sound like somewhere, and to span climates that actually differ. */
    private const PLACES = [
        ['Reykjavík, Iceland', 64.146, -21.942],
        ['Ushuaia, Argentina', -54.801, -68.303],
        ['Longyearbyen, Svalbard', 78.223, 15.627],
        ['Timbuktu, Mali', 16.775, -3.009],
        ['Kathmandu, Nepal', 27.717, 85.324],
        ['Hobart, Tasmania', -42.883, 147.331],
        ['Nuuk, Greenland', 64.181, -51.694],
        ['Manaus, Brazil', -3.119, -60.022],
        ['Yakutsk, Russia', 62.035, 129.675],
        ['Perth, Australia', -31.953, 115.857],
        ['Anchorage, Alaska', 61.218, -149.900],
        ['Suva, Fiji', -18.141, 178.442],
    ];

    public function key(): string
    {
        return 'atmosphere';
    }

    public function label(): string
    {
        return 'Live Weather';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Observational;
    }

    public function origin(): string
    {
        return 'Temperature, wind and pressure read from a weather station somewhere on Earth, chosen at random for each draw.';
    }

    public function refreshSeconds(): int
    {
        return 300;
    }

    public function collect(int $bytes): Material
    {
        [$place, $latitude, $longitude] = self::PLACES[random_int(0, count(self::PLACES) - 1)];

        $current = Http::timeout(1.5)
            ->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'current' => 'temperature_2m,wind_speed_10m,pressure_msl',
            ])
            ->throw()
            ->json('current');

        if (! is_array($current)) {
            throw new \RuntimeException('Open-Meteo returned no current conditions.');
        }

        $temperature = (float) ($current['temperature_2m'] ?? 0);
        $wind = (float) ($current['wind_speed_10m'] ?? 0);

        return new Material(
            bytes: substr(str_repeat(hash('sha256', $place.json_encode($current, JSON_THROW_ON_ERROR), true), (int) ceil($bytes / 32)), 0, $bytes),
            narrative: sprintf(
                'It was %.1f°C in %s, with a %.0f km/h wind, when this was made.',
                $temperature,
                $place,
                $wind,
            ),
            proofUrl: sprintf('https://open-meteo.com/en/docs#latitude=%s&longitude=%s', $latitude, $longitude),
            reference: $place,
            observedAt: new \DateTimeImmutable,
        );
    }
}
