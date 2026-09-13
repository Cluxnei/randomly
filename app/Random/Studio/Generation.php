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
