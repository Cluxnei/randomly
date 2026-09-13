<?php

declare(strict_types=1);

namespace App\Random\Studio;

use App\Random\Entropy\Receipt;
use App\Random\Entropy\Seed;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Params;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;

/**
 * One finished generation: what was made, how, and how to get back to it.
 */
final readonly class Generation
{
    public function __construct(
        public Generator $generator,
        public Params $params,
        public Result $result,
        public Seed $seed,
        public Receipt $receipt,
        public int $durationUs,
        public bool $replayed = false,
    ) {}

    /**
     * A link that reproduces this exactly — or null when reproducing it would be
     * a security bug. See Generator::isSensitive().
     */
    public function permalink(): ?string
    {
        // No link for a secret, and none for a result built on live external
        // material — that link would resolve to something different tomorrow,
        // which is a worse failure than having no link at all.
        if ($this->generator->isSensitive() || ! $this->generator->isReproducible()) {
            return null;
        }

        return route('replay', array_filter([
            'token' => $this->seed->token(),
            'g' => $this->generator->key(),
            'v' => $this->generator->version(),
            'p' => $this->encodedParams(),
            's' => $this->receipt->sourceKey,
            'r' => $this->receipt->reference,
        ]));
    }

    /**
     * The preview image for this exact result.
     *
     * Carries the live receipt narrative, because a replayed receipt can only be
     * reconstructed from a source key and a reference — "Originally drawn from
     * USGS Seismic Feed (us7000th33)" where the real sentence read "A magnitude
     * 3.6 earthquake, 67 km N of Culebra, Puerto Rico, 39.6 km down". That
     * sentence is the product, and the shared card is where it reaches the most
     * people, so it travels in the link.
     *
     * It travels **signed**. A card bearing the Randomly wordmark with text an
     * attacker chose is a forgery generator, and "it is only a preview image" is
     * not a defence — the preview is what most people ever see of a link.
     */
    public function ogImageUrl(): ?string
    {
        if ($this->generator->isSensitive() || ! $this->generator->isReproducible()) {
            return null;
        }

        $narrative = $this->receipt->narrative;

        return route('og.result', array_filter([
            'token' => $this->seed->token(),
            'g' => $this->generator->key(),
            'v' => $this->generator->version(),
            'p' => $this->encodedParams(),
            's' => $this->receipt->sourceKey,
            'r' => $this->receipt->reference,
            'n' => $narrative,
            'ns' => self::signNarrative($narrative),
        ]));
    }

    /**
     * The key is a parameter with a default rather than a hidden config() call.
     *
     * Unit tests here never boot the framework — that is what keeps the suite
     * instant — so reaching for the container inside a pure function makes the one
     * thing most worth testing untestable. Passing it also means the tests pin a
     * fixed key instead of inheriting whatever APP_KEY the machine happens to have.
     */
    public static function signNarrative(string $narrative, ?string $key = null): string
    {
        $key ??= (string) config('app.key');

        // Truncated to 16 bytes: this authenticates a caption, not a transaction,
        // and a 128-bit tag is far beyond forging while keeping the URL from
        // growing another 40 characters.
        return substr(hash_hmac('sha256', $narrative, $key), 0, 32);
    }

    public static function verifyNarrative(?string $narrative, ?string $signature, ?string $key = null): ?string
    {
        if ($narrative === null || $signature === null || $signature === '') {
            return null;
        }

        return hash_equals(self::signNarrative($narrative, $key), $signature) ? $narrative : null;
    }

    public function encodedParams(): string
    {
        return rtrim(strtr(base64_encode(
            json_encode($this->params->fingerprint(), JSON_THROW_ON_ERROR)
        ), '+/', '-_'), '=');
    }

    public static function decodeParams(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($json === false) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The key a canvas or audio graph re-derives its stream from.
     *
     * Only handed out for renderers that draw client-side, and never for a
     * sensitive generator — a render key is a seed by another name.
     */
    public function renderKey(): ?string
    {
        if ($this->generator->isSensitive()) {
            return null;
        }

        return in_array($this->generator->renderer(), [Renderer::Canvas, Renderer::Audio], true)
            ? $this->seed->renderKey($this->generator->key(), $this->generator->version())
            : null;
    }

    public function toArray(): array
    {
        return array_filter([
            'generator' => $this->generator->key(),
            'version' => $this->generator->version(),
            'value' => $this->result->value,
            'display' => $this->result->display,
            'meta' => [
                ...$this->result->meta,
                'entropy_in_bytes' => $this->seed::BYTES,
                'stream_bytes_used' => $this->result->meta['stream_bytes_used'] ?? null,
                'duration_us' => $this->durationUs,
            ],
            'receipt' => $this->receipt->toArray(),
            'seed' => $this->generator->isSensitive() ? null : [
                'token' => $this->seed->token(),
                'permalink' => $this->permalink(),
            ],
            'render_key' => $this->renderKey(),
            'uses_entropy' => $this->generator->usesEntropy(),
            'reproducible' => $this->generator->isReproducible(),
            'replayed' => $this->replayed ?: null,
        ], fn ($v) => $v !== null);
    }
}
