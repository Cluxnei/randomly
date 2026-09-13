<?php

declare(strict_types=1);

namespace App\Providers;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use App\Random\Og\OgImage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Generous but bounded. The API is free and unauthenticated, so the only
         * thing standing between it and a scraper is this.
         */
        RateLimiter::for('randomly', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
