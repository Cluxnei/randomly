<?php

declare(strict_types=1);

namespace App\Random\Entropy\Sources;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyClass;
use App\Random\Entropy\Material;
use Illuminate\Support\Facades\Http;

/**
 * Quantum vacuum fluctuations, measured at the Australian National University.
 *
 * The strongest claim any source here can make. Where atmospheric noise and
 * proof-of-work are unpredictable because modelling them is infeasible, this is
 * unpredictable as a matter of physics: the vacuum is not empty, its field
 * amplitude fluctuates, and those fluctuations have no hidden cause to uncover.
 * ANU measures them by homodyne detection and publishes the digitised result.
 *
 * Class A, and the one source on this site whose randomness is a property of the
 * universe rather than of our ignorance.
 */
final class AnuQrngSource implements EntropySource
{
    public function key(): string
    {
        return 'anu-qrng';
    }

    public function label(): string
    {
        return 'ANU Quantum Vacuum';
    }

    public function class(): EntropyClass
    {
        return EntropyClass::Cryptographic;
    }

    public function origin(): string
    {
        return 'Fluctuations of the quantum vacuum field, measured by homodyne detection at the Australian National University.';
    }

    public function refreshSeconds(): int
    {
        return 0;
    }

    public function collect(int $bytes): Material
    {
        $response = Http::timeout(1.5)
            ->get('https://qrng.anu.edu.au/API/jsonI.php', ['length' => $bytes, 'type' => 'uint8'])
            ->throw()
            ->json();

        if (($response['success'] ?? false) !== true || ! is_array($response['data'] ?? null)) {
            throw new \RuntimeException('ANU QRNG did not return a successful reading.');
        }

        $data = $response['data'];

        if (count($data) < $bytes) {
            throw new \RuntimeException('ANU QRNG returned fewer bytes than asked for.');
        }

        return new Material(
            bytes: implode('', array_map(fn (int $b): string => chr($b & 0xFF), array_slice($data, 0, $bytes))),
            narrative: 'Measured fluctuations of the quantum vacuum, in a laboratory in Canberra. Not unpredictable because it is complicated — unpredictable as a matter of physics.',
            proofUrl: 'https://qrng.anu.edu.au/',
            observedAt: new \DateTimeImmutable,
        );
    }
}
