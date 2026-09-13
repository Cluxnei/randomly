<?php

declare(strict_types=1);

namespace App\Random\Entropy;

use App\Random\Rng\HkdfStream;
use App\Random\Rng\Rng;

/**
 * A shareable 16-byte seed plus its provenance.
 *
 * 16 bytes (128 bits) is the whole reproducibility story: the token IS the seed,
 * so a permalink needs no database to replay. Generators that produce secrets are
 * marked sensitive and never expose one — see Generator::isSensitive().
 */
final readonly class Seed
{
    public const BYTES = 16;

    public function __construct(
        public string $bytes,
        public Receipt $receipt,
    ) {
        if (strlen($bytes) !== self::BYTES) {
            throw new \InvalidArgumentException('A seed is exactly '.self::BYTES.' bytes.');
        }
    }

    public function token(): string
    {
        return Base32::encode($this->bytes);
    }

    public static function fromToken(string $token, Receipt $receipt): self
    {
        return new self(Base32::decode($token), $receipt);
    }

    /**
     * Derive an independent byte stream for one generator.
     *
     * `info` binds the stream to the generator and its parameters, so two
     * generators handed the same seed never produce correlated output.
     */
    public function rng(string $generatorKey, int $version, array $params = []): Rng
    {
        $info = sprintf(
            'randomly/v1|%s|v%d|%s',
            $generatorKey,
            $version,
            hash('sha256', json_encode(self::canonical($params), JSON_THROW_ON_ERROR), true),
        );

        return new Rng(new HkdfStream($this->bytes, $info));
    }

    /**
     * A 32-byte key the browser can re-derive a stream from, as hex.
     *
     * The main rng() info string embeds a hash of json_encode($params), and PHP
     * escapes forward slashes where JSON.stringify does not — so a client trying
     * to rebuild that exact info would silently diverge on any parameter
     * containing a slash. Handing the browser an already-derived key removes the
     * shared-canonicalisation problem entirely: both sides then expand from the
     * same PRK with a plain ASCII info, which is trivially identical in both
     * languages. See resources/js/rng.js.
     */
    public function renderKey(string $generatorKey, int $version): string
    {
        return bin2hex(hash_hkdf(
            'sha256',
            $this->bytes,
            32,
            sprintf('randomly/render|%s|v%d', $generatorKey, $version),
            'randomly.render.v1',
        ));
    }

    /**
     * Sort keys before hashing, at every depth.
     *
     * Without this, ['a'=>1,'b'=>2] and ['b'=>2,'a'=>1] derive different streams and
     * replay the same permalink differently. Parameters happen to arrive in schema
     * order today, but a link rebuilt from a decoded query string carries whatever
     * order it was written in, and reproducibility cannot rest on that.
     */
    private static function canonical(array $params): array
    {
        ksort($params);

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = self::canonical($value);
            }
        }

        return $params;
    }
}
