<?php

declare(strict_types=1);

namespace App\Random\Entropy;

/**
 * How much a source can actually be trusted to carry entropy.
 *
 * This classification is shown to users verbatim. It is the honest core of the
 * product: an earthquake is a wonderful story and a poor cipher.
 */
enum EntropyClass: string
{
    /** Full-entropy, unpredictable bytes. Safe to stand alone. */
    case Cryptographic = 'A';

    /** High entropy at publication, but globally public afterwards. */
    case Public = 'B';

    /** Low-rate, biased, structured. Flavour, not entropy. */
    case Observational = 'C';

    public function label(): string
    {
        return match ($this) {
            self::Cryptographic => 'Cryptographic',
            self::Public => 'Public beacon',
            self::Observational => 'Observational',
        };
    }

    public function caveat(): ?string
    {
        return match ($this) {
            self::Cryptographic => null,
            self::Public => 'Published openly — anyone can look this value up after the fact.',
            self::Observational => 'Carries only tens of bits of genuine surprise. Always mixed with the OS CSPRNG.',
        };
    }

    /** Class A can stand alone; everything else is mixed with local entropy. */
    public function standsAlone(): bool
    {
        return $this === self::Cryptographic;
    }
}
