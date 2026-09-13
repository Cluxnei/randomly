<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Studio\Generation;
use App\Random\Studio\Studio;
use App\Random\Studio\VersionChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    ) {}

    public function generators(): JsonResponse
    {
        return response()->json(['generators' => $this->registry->toArray()]);
    }

    public function sources(): JsonResponse
    {
        return response()->json(['sources' => $this->pool->status()]);
    }

    public function generate(Request $request): JsonResponse|Response
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

    public function replay(string $token, Request $request): JsonResponse|Response
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
     * `text/plain` exists so the one-liner in the docs actually works:
     * being curl-able without a key is part of the pitch.
     */
    private function respond(Request $request, Generation $generation): JsonResponse|Response
    {
        if (str_contains((string) $request->header('Accept'), 'text/plain')) {
            return response($generation->result->display."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response()->json($generation->toArray());
    }
}
