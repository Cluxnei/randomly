<?php

declare(strict_types=1);

namespace App\Random\Media;

use App\Random\Studio\Generation;
use Symfony\Component\Process\Process;

/**
 * Server-side PNG and WAV, rendered by the browser's own code.
 *
 * Canvas and audio generators ship a spec rather than finished bytes, which is
 * right for the studio — tiny responses, instant slider drags — and leaves an API
 * caller holding a description of an image instead of an image. The public API
 * promises `image/png` and `audio/wav`, so it has to deliver them.
 *
 * It delivers them by running the *same JavaScript the browser runs*, under Node.
 * A PHP reimplementation of the renderers was the obvious alternative and the
 * wrong one: two implementations of a generative algorithm drift, and they drift
 * silently, in a place nobody looks. The scripts this calls were written to verify
 * the renderers; making them serve the renderers too costs nothing and guarantees
 * the API returns what the studio shows.
 *
 * The trade is a process per cold render — 50 to 500 ms — which is why everything
 * is cached by a hash of exactly what gets drawn.
 */
final class MediaRenderer
{
    private const TIMEOUT_SECONDS = 30;

    /** A request should not be able to ask for ten minutes of synthesis. */
    private const MAX_AUDIO_SECONDS = 60;

    public function __construct(
        private readonly string $cacheDirectory,
        private readonly string $projectRoot,
    ) {}

    public function supports(Generation $generation, string $format): bool
    {
        return match ($format) {
            'png' => $generation->renderKey() !== null && ($generation->result->value['algorithm'] ?? null) !== null && $generation->generator->renderer()->value === 'canvas',
            'wav' => $generation->renderKey() !== null && $generation->generator->renderer()->value === 'audio',
            default => false,
        };
    }

    /**
     * @return string absolute path to the rendered file
     *
     * @throws MediaUnavailable when the render fails or the format does not apply
     */
    public function render(Generation $generation, string $format): string
    {
        if (! $this->supports($generation, $format)) {
            throw new MediaUnavailable(sprintf(
                '[%s] is a %s generator and has no %s representation.',
                $generation->generator->key(),
                $generation->generator->renderer()->value,
                strtoupper($format),
            ));
        }

        $path = $this->cacheDirectory.'/'.$this->fingerprint($generation, $format).'.'.$format;

        if (is_file($path)) {
            return $path;
        }

        // Plain mkdir rather than the File facade: this class is domain code and
        // reaching for the container here would make it untestable without booting
        // the framework, which is exactly the part most worth testing.
        if (! is_dir($this->cacheDirectory)) {
            @mkdir($this->cacheDirectory, 0775, recursive: true);
        }

        // The payload goes through a file rather than argv: a spec carrying a long
        // palette, or a score with a few hundred events, can exceed the argument
        // length limit, and that failure would surface as an unrelated-looking error.
        $payload = tempnam(sys_get_temp_dir(), 'randomly-payload-');
        // Only the two keys a renderer reads. The full toArray() would drag in the
        // permalink — and therefore the router — for a process that has no use for
        // either, and would put a URL inside a cache key that does not depend on one.
        file_put_contents($payload, json_encode([
            'value' => $generation->result->value,
            'render_key' => $generation->renderKey(),
        ], JSON_THROW_ON_ERROR));

        // Rendered to a temporary name and moved into place, so two requests racing
        // on the same cache key cannot serve a half-written file.
        $staging = $path.'.'.bin2hex(random_bytes(6)).'.partial';

        try {
            $this->run($format, $payload, $staging);
            rename($staging, $path);
        } finally {
            @unlink($payload);
            @unlink($staging);
        }

        return $path;
    }

    private function run(string $format, string $payload, string $destination): void
    {
        [$script, $arguments] = match ($format) {
            'png' => ['scripts/render-preview.mjs', [$payload, $destination]],
            'wav' => ['scripts/render-wav.mjs', [$payload, $destination, (string) self::MAX_AUDIO_SECONDS]],
        };

        $process = new Process(['node', $script, ...$arguments], $this->projectRoot);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new MediaUnavailable('The renderer could not be started: '.$e->getMessage(), previous: $e);
        }

        if (! $process->isSuccessful()) {
            // Node's stderr is the only useful diagnostic here and it is not
            // something to show a caller, so it is summarised rather than echoed.
            throw new MediaUnavailable(
                'The renderer failed. '.substr(trim($process->getErrorOutput()), 0, 200)
            );
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new MediaUnavailable('The renderer produced no output.');
        }
    }

    /**
     * Keyed on what is actually drawn, not on the request.
     *
     * Two callers asking for the same generation with different query-string
     * ordering get the same file, and a changed parameter gets a different one.
     */
    private function fingerprint(Generation $generation, string $format): string
    {
        return substr(hash('sha256', implode('|', [
            $format,
            $generation->generator->key(),
            (string) $generation->generator->version(),
            (string) $generation->renderKey(),
            json_encode($generation->result->value, JSON_THROW_ON_ERROR),
        ])), 0, 32);
    }
}
