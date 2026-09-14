<?php

declare(strict_types=1);

namespace App\Providers;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use App\Random\Media\MediaRenderer;
use App\Random\Og\OgImage;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * One pool for the whole request. It is a singleton because the rotation
         * counter and the per-source circuit breakers only mean anything if every
         * caller shares them — two pools would rotate independently and double the
         * load we put on the third parties.
         *
         * Sources are resolved through the container so a source may take its own
         * dependencies later without this list changing.
         */
        $this->app->singleton(EntropyPool::class, function (Application $app): EntropyPool {
            $sources = array_map(
                fn (string $source): EntropySource => $app->make($source),
                (array) config('randomly.entropy.sources', []),
            );

            return new EntropyPool($sources);
        });

        /*
         * One renderer, so the cache directory and the project root are settled in
         * a single place rather than guessed at each call site.
         */
        /*
         * The limiter keeps its counters in its own store rather than the app cache.
         * With CACHE_STORE=database, deciding whether to reject a request would mean
         * a database round trip first — the most expensive possible way to say no.
         */
        $this->app->singleton(LaravelRateLimiter::class, fn ($app) => new LaravelRateLimiter(
            $app['cache']->store((string) config('randomly.limits.store', 'file'))
        ));

        $this->app->singleton(MediaRenderer::class, fn (): MediaRenderer => new MediaRenderer(
            cacheDirectory: storage_path('app/media'),
            projectRoot: base_path(),
        ));

        /*
         * The registry is immutable once built and every page reads it, so building
         * the generator objects once per request is worth it. They hold no state:
         * generate() is pure, and all the per-request randomness lives in the Rng
         * handed to it.
         */
        $this->app->singleton(GeneratorRegistry::class, function (Application $app): GeneratorRegistry {
            $generators = array_map(
                fn (string $generator): Generator => $app->make($generator),
                (array) config('randomly.generators', []),
            );

            return new GeneratorRegistry($generators);
        });

        /*
         * The share-card renderer needs to be told where its typeface lives, and
         * that is a deployment fact rather than something the class should go
         * looking for. JetBrains Mono is bundled rather than fetched: a card is
         * drawn inside the request that serves it, and a font over the network
         * would put a third party in that path.
         */
        $this->app->singleton(OgImage::class, fn (): OgImage => new OgImage(resource_path('fonts')));
    }

    /**
     * A 429 a machine can act on.
     *
     * The API is meant to be consumed by scripts and by language models, and
     * "Too Many Requests" as bare HTML tells either of them nothing. This says how
     * long to wait and where the limits are documented.
     */
    private function limitExceeded(Request $request, array $headers): JsonResponse
    {
        return response()->json([
            'error' => 'rate_limited',
            'message' => 'Too many requests. The API is free and unauthenticated, so it is rate limited per IP instead.',
            'retry_after_seconds' => (int) ($headers['Retry-After'] ?? 60),
            'limits' => [
                'requests_per_minute' => (int) config('randomly.limits.per_minute', 60),
                'renders_per_minute' => (int) config('randomly.limits.media_per_minute', 20),
            ],
            'documentation' => url('/api.md'),
        ], 429, $headers);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Generous but bounded. The API is free and unauthenticated, so the only
         * thing standing between it and a scraper is this.
         *
         * The limiter reads from its own cache store (see config/randomly.php) so a
         * request that is about to be rejected does not first make a database round
         * trip to find that out.
         */
        RateLimiter::for('randomly', fn (Request $request) => Limit::perMinute(
            (int) config('randomly.limits.per_minute', 60)
        )->by($request->ip())->response($this->limitExceeded(...)));

        /*
         * Rendering is the expensive door. A PNG or WAV starts a Node process, so a
         * caller hammering ?format=png costs orders of magnitude more than one
         * pulling JSON — and this bucket is charged *in addition* to the one above,
         * so a media request counts against both.
         */
        RateLimiter::for('randomly-media', fn (Request $request) => Limit::perMinute(
            (int) config('randomly.limits.media_per_minute', 20)
        )->by($request->ip())->response($this->limitExceeded(...)));

        RateLimiter::for('randomly-pages', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));

    }
}
