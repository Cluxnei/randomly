<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Module;
use App\Random\Studio\Studio;
use Illuminate\Contracts\View\View;

final class PageController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly EntropyPool $pool,
        private readonly Studio $studio,
    ) {}

    public function landing(): View
    {
        // The hero draws a real seed from a real source on every page load, which
        // is why the sub-headline can name where this particular visit came from.
        // It also warms the entropy cache that the ticker reads.
        $hero = $this->studio->generate('numbers.integers', ['count' => 6, 'min' => 1, 'max' => 60, 'unique' => true, 'sort' => 'asc']);

        return view('pages.landing', [
            'hero' => $hero,
            'counts' => [
                'generators' => $this->registry->all()->count(),
                'modules' => count(Module::cases()),
                'sources' => count($this->pool->sources()),
                'keys' => 0,
            ],
            'modules' => Module::cases(),
            'byModule' => $this->registry->byModule(),
            'sources' => $this->pool->status(),
        ]);
    }

    public function library(): View
    {
        return view('pages.library', [
            'generators' => $this->registry->all(),
            'byModule' => $this->registry->byModule(),
            'modules' => Module::cases(),
        ]);
    }

    public function entropy(): View
    {
        return view('pages.entropy', [
            'sources' => $this->pool->status(),
        ]);
    }
}
