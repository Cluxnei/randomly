<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * Where the International Space Station is, right now.
 *
 * The weakest entropy source on the site by a wide margin, and included
 * deliberately. An orbit is the most predictable thing in this catalogue —
 * anyone with the TLE can compute this position for next Tuesday — so the useful
 * bits are only the low digits of a position anybody could derive. Two or three
 * bits, generously counted.
 *
 * It earns its place by being a good demonstration of the honesty machinery:
 * /entropy shows it as Class C and says plainly that its contribution is
 * decorative. A source we describe accurately is worth more than one we oversell.
 */
final class IssSource implements EntropySource
{
    public function key(): string
    {
        return 'iss';
    }

    public function label(): string
    {
        return 'ISS Position';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Observational;
    }

    public function origin(): string
    {
        return 'The ground track of the International Space Station, 408 km up and moving at 27,600 km/h.';
    }

    public function refreshSeconds(): int
    {
        return 5;
    }

    public function collect(int $bytes): Material
    {
        $response = Http::timeout(1.5)->get('http://api.open-notify.org/iss-now.json')->throw()->json();

        $latitude = (float) ($response['iss_position']['latitude'] ?? 0);
        $longitude = (float) ($response['iss_position']['longitude'] ?? 0);
        $timestamp = (int) ($response['timestamp'] ?? time());

        return new Material(
            bytes: substr(str_repeat(hash('sha256', json_encode($response, JSON_THROW_ON_ERROR), true), (int) ceil($bytes / 32)), 0, $bytes),
            narrative: sprintf(
                'The ISS was over %.2f° %s, %.2f° %s — %s — when this was drawn.',
                abs($latitude), $latitude >= 0 ? 'N' : 'S',
                abs($longitude), $longitude >= 0 ? 'E' : 'W',
                $this->region($latitude, $longitude),
            ),
            proofUrl: sprintf('https://www.openstreetmap.org/#map=3/%.2f/%.2f', $latitude, $longitude),
            reference: (string) $timestamp,
            observedAt: (new \DateTimeImmutable)->setTimestamp($timestamp),
        );
    }

    /**
     * Bearing and distance to the nearest well-known place.
     *
     * The first version drew rectangles — "over Africa", "over the Pacific" — and
     * they were confidently wrong wherever a box swallowed sea. A pass at
     * 4.40°N 3.92°W came out "over Africa or the Mediterranean" while the station
     * was over open water in the Gulf of Guinea. On a site whose whole argument is
     * that claims are checkable, a plausible-sounding location that is simply
     * false is the worst kind of copy.
     *
     * Distance and bearing to a known city is a smaller claim and a true one, and
     * "1,240 km south-west of Accra" is a better sentence than "over Africa"
     * anyway. Below 400 km it reads as "near", which at orbital altitude is about
     * as precise as the phrasing deserves.
     */
    private function region(float $lat, float $lon): string
    {
        $nearest = null;
        $shortest = INF;

        foreach (self::LANDMARKS as [$name, $placeLat, $placeLon]) {
            $distance = $this->haversine($lat, $lon, $placeLat, $placeLon);

            if ($distance < $shortest) {
                $shortest = $distance;
                $nearest = [$name, $placeLat, $placeLon];
            }
        }

        if ($nearest === null) {
            return 'somewhere over Earth';
        }

        if ($shortest < 400) {
            return "near {$nearest[0]}";
        }

        return sprintf(
            '%s km %s of %s',
            number_format(round($shortest, -1)),
            $this->bearing($nearest[1], $nearest[2], $lat, $lon),
            $nearest[0],
        );
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function bearing(float $fromLat, float $fromLon, float $toLat, float $toLon): string
    {
        $dLon = deg2rad($toLon - $fromLon);
        $y = sin($dLon) * cos(deg2rad($toLat));
        $x = cos(deg2rad($fromLat)) * sin(deg2rad($toLat))
            - sin(deg2rad($fromLat)) * cos(deg2rad($toLat)) * cos($dLon);

        $degrees = fmod(rad2deg(atan2($y, $x)) + 360, 360);
        $compass = ['north', 'north-east', 'east', 'south-east', 'south', 'south-west', 'west', 'north-west'];

        return $compass[(int) round($degrees / 45) % 8];
    }

    /** Spread to keep the nearest landmark meaningful anywhere on the ground track. */
    private const LANDMARKS = [
        ['Reykjavík', 64.15, -21.94], ['London', 51.51, -0.13], ['Lisbon', 38.72, -9.13],
        ['Accra', 5.60, -0.19], ['Cape Town', -33.92, 18.42], ['Nairobi', -1.29, 36.82],
        ['Cairo', 30.04, 31.24], ['Moscow', 55.76, 37.62], ['Dubai', 25.20, 55.27],
        ['Mumbai', 19.08, 72.88], ['Jakarta', -6.21, 106.85], ['Perth', -31.95, 115.86],
        ['Sydney', -33.87, 151.21], ['Tokyo', 35.68, 139.65], ['Beijing', 39.90, 116.41],
        ['Vladivostok', 43.12, 131.89], ['Anchorage', 61.22, -149.90], ['Honolulu', 21.31, -157.86],
        ['Los Angeles', 34.05, -118.24], ['Mexico City', 19.43, -99.13], ['New York', 40.71, -74.01],
        ['Bogotá', 4.71, -74.07], ['Lima', -12.05, -77.04], ['São Paulo', -23.55, -46.63],
        ['Buenos Aires', -34.60, -58.38], ['Ushuaia', -54.80, -68.30], ['Auckland', -36.85, 174.76],
        ['Suva', -18.14, 178.44], ['Papeete', -17.54, -149.57], ['Nuuk', 64.18, -51.69],
        ['Dakar', 14.72, -17.47], ['Madagascar', -18.88, 47.51],
    ];
}
