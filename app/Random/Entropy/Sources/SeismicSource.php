<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * Earthquakes recorded worldwide in the last hour.
 *
 * The best narrative on the site and among the worst entropy on it, which is
 * exactly why the honesty machinery exists. A USGS feed weighs 12 KB and carries
 * perhaps thirty bits of genuine surprise — magnitude to one decimal, a location
 * to a few decimal places, a timestamp. The pool mixes it with the CSPRNG and the
 * receipt says so.
 *
 * The entropy is a rounding error. "Your numbers came from a magnitude 4.2
 * earthquake off Honshu, six minutes ago" is the product.
 */
final class SeismicSource implements EntropySource
{
    public function key(): string
    {
        return 'seismic';
    }

    public function label(): string
    {
        return 'USGS Seismic Feed';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Observational;
    }

    public function origin(): string
    {
        return 'Every earthquake above the detection threshold recorded anywhere on Earth in the past hour, published by the US Geological Survey.';
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function collect(int $bytes): Material
    {
        $feed = Http::timeout(1.5)
            ->get('https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson')
            ->throw()
            ->json();

        $quakes = $feed['features'] ?? [];

        if ($quakes === []) {
            throw new \RuntimeException('An hour with no recorded earthquakes — rare, and nothing to draw from.');
        }

        // Largest rather than most recent. A magnitude 5.1 is a better story than a
        // 0.6, and the feed is dominated by microquakes in California that are real
        // but say nothing to anyone.
        usort($quakes, fn (array $a, array $b) => ($b['properties']['mag'] ?? 0) <=> ($a['properties']['mag'] ?? 0));
        $quake = $quakes[0];

        $properties = $quake['properties'];
        $magnitude = (float) ($properties['mag'] ?? 0);
        $place = $properties['place'] ?? 'an unnamed location';
        [$longitude, $latitude, $depth] = $quake['geometry']['coordinates'];
        $when = \DateTimeImmutable::createFromFormat('U', (string) intdiv((int) $properties['time'], 1000));

        // Hash the whole feed, not just the headline quake: every microquake's
        // timing and coordinates contribute, which is most of the real entropy here.
        $material = hash('sha256', json_encode($feed, JSON_THROW_ON_ERROR), true);

        return new Material(
            bytes: substr(str_repeat($material, (int) ceil($bytes / 32)), 0, $bytes),
            narrative: sprintf(
                'A magnitude %.1f earthquake, %s, %s km down — %s.',
                $magnitude,
                $place,
                round((float) $depth, 1),
                $this->ago($when),
            ),
            proofUrl: $properties['url'] ?? null,
            reference: $quake['id'] ?? null,
            observedAt: $when ?: new \DateTimeImmutable,
        );
    }

    private function ago(?\DateTimeImmutable $when): string
    {
        if ($when === null) {
            return 'recently';
        }

        $minutes = (int) round((time() - $when->getTimestamp()) / 60);

        return match (true) {
            $minutes < 1 => 'less than a minute ago',
            $minutes === 1 => 'a minute ago',
            $minutes < 60 => "{$minutes} minutes ago",
            default => 'about an hour ago',
        };
    }
}
