<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Media\MediaRenderer;
use App\Random\Media\MediaUnavailable;
use App\Random\Studio\Generation;
use App\Random\Studio\Studio;
use App\Random\Studio\VersionChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The public API. The site's own pages call the same Studio these endpoints do,
 * so there is no private path with different behaviour.
 */
final class ApiController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly EntropyPool $pool,
        private readonly Studio $studio,
        private readonly MediaRenderer $media,
    ) {}

    public function generators(): JsonResponse
    {
        return response()->json(['generators' => $this->registry->toArray()]);
    }

    public function sources(): JsonResponse
    {
        return response()->json(['sources' => $this->pool->status()]);
    }

    public function generate(Request $request): JsonResponse|Response|BinaryFileResponse
    {
        $key = (string) $request->input('generator', $request->route('key', ''));

        if (! $this->registry->has($key)) {
            return response()->json([
                'error' => 'unknown_generator',
                'message' => "No generator registered as [{$key}].",
                'available' => $this->registry->all()->keys()->values(),
            ], 404);
        }

        $generator = $this->registry->findOrFail($key);
        $input = (array) $request->input('params', $request->query());

        // Validation comes from the generator's own schema — the same declaration
        // that renders the studio's controls. There is nothing to keep in sync.
        $validator = validator($input, $generator->schema()->rules());

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_params',
                'message' => 'Some parameters were outside what this generator accepts.',
                'fields' => $validator->errors()->toArray(),
            ], 400);
        }

        $generation = $this->studio->generate($key, $input, $request->input('source', $request->query('source')));

        return $this->respond($request, $generation);
    }

    public function replay(string $token, Request $request): JsonResponse|Response|BinaryFileResponse
    {
        $key = (string) $request->query('g', '');

        if (! $this->registry->has($key)) {
            return response()->json(['error' => 'unknown_generator', 'message' => "No generator registered as [{$key}]."], 404);
        }

        if ($this->registry->findOrFail($key)->isSensitive()) {
            return response()->json([
                'error' => 'not_replayable',
                'message' => "[{$key}] produces secrets, so it is never given a replayable link.",
            ], 404);
        }

        try {
            $generation = $this->studio->replay(
                token: $token,
                generatorKey: $key,
                version: (int) $request->query('v', 1),
                input: Generation::decodeParams($request->query('p')),
                sourceKey: $request->query('s'),
                reference: $request->query('r'),
            );
        } catch (VersionChanged $e) {
            return response()->json([
                'error' => 'version_changed',
                'message' => $e->getMessage(),
                'generated_with' => $e->generatedWith,
                'current' => $e->current,
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token', 'message' => $e->getMessage()], 400);
        }

        return $this->respond($request, $generation);
    }

    /**
     * Content negotiation, so every generator is fully usable over HTTP.
     *
     * A canvas or audio generator returns a spec by design — that is what keeps the
     * studio's responses small and its sliders instant — but an API caller holding a
     * description of an image has not been given an image. `image/png` and
     * `audio/wav` render server-side through the same JavaScript the browser runs.
     *
     * `?format=` is honoured alongside `Accept:` because the one-liner in the docs is
     * part of the pitch, and `curl -o out.png '...&format=png'` is a great deal easier
     * to type and to remember than an Accept header.
     */
    private function respond(Request $request, Generation $generation): JsonResponse|Response|BinaryFileResponse
    {
        $format = $this->negotiate($request);

        if ($format === 'text') {
            return response($generation->result->display."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if ($format === 'png' || $format === 'wav') {
            return $this->file($generation, $format);
        }

        return response()->json($generation->toArray());
    }

    private function negotiate(Request $request): string
    {
        $requested = strtolower((string) $request->query('format', ''));

        if (in_array($requested, ['png', 'wav', 'text', 'json'], true)) {
            return $requested;
        }

        $accept = strtolower((string) $request->header('Accept'));

        return match (true) {
            str_contains($accept, 'image/png') => 'png',
            str_contains($accept, 'audio/wav'), str_contains($accept, 'audio/x-wav') => 'wav',
            str_contains($accept, 'text/plain') => 'text',
            default => 'json',
        };
    }

    private function file(Generation $generation, string $format): JsonResponse|BinaryFileResponse
    {
        try {
            $path = $this->media->render($generation, $format);
        } catch (MediaUnavailable $e) {
            // 406 rather than 500 or a silent JSON fallback: the caller asked for a
            // representation that does not exist for this generator, and saying which
            // ones do is more useful than any of the alternatives.
            return response()->json([
                'error' => 'unsupported_format',
                'message' => $e->getMessage(),
                'available' => $this->formatsFor($generation),
            ], 406);
        }

        $filename = sprintf('randomly-%s-%s.%s',
            str_replace('.', '-', $generation->generator->key()),
            strtolower($generation->seed->token()),
            $format,
        );

        return response()->file($path, [
            'Content-Type' => $format === 'png' ? 'image/png' : 'audio/wav',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** @return list<string> */
    private function formatsFor(Generation $generation): array
    {
        return array_values(array_filter([
            'application/json',
            'text/plain',
            $this->media->supports($generation, 'png') ? 'image/png' : null,
            $this->media->supports($generation, 'wav') ? 'audio/wav' : null,
        ]));
    }
}
