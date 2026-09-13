<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Studio\Generation;
use App\Random\Studio\Studio;
use App\Random\Studio\VersionChanged;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StudioController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly EntropyPool $pool,
        private readonly Studio $studio,
    ) {}

    public function show(string $module, string $generator, Request $request): View
    {
        $key = "{$module}.{$generator}";

        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("No generator at [{$key}].");
        }

        // Generate once server-side so the studio is never an empty shell waiting
        // on JavaScript — the page arrives with something already made.
        $generation = $this->studio->generate($key, $request->query(), $request->query('source'));

        return view('pages.studio', [
            'generator' => $generation->generator,
            'generation' => $generation,
            'sources' => $this->pool->status(),
        ]);
    }

    public function replay(string $token, Request $request): View
    {
        $key = (string) $request->query('g', '');
        $version = (int) $request->query('v', 1);

        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("No generator at [{$key}].");
        }

        try {
            $generation = $this->studio->replay(
                token: $token,
                generatorKey: $key,
                version: $version,
                input: Generation::decodeParams($request->query('p')),
                sourceKey: $request->query('s'),
                reference: $request->query('r'),
            );
        } catch (VersionChanged $e) {
            return view('pages.version-changed', [
                'exception' => $e,
                'generator' => $this->registry->findOrFail($key),
            ]);
        }

        return view('pages.studio', [
            'generator' => $generation->generator,
            'generation' => $generation,
            'sources' => $this->pool->status(),
        ]);
    }
}
