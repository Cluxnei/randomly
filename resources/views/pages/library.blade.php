@php
    use App\Random\Entropy\EntropyPool;
    use App\Random\Generators\Renderer;

    $roadmap = require resource_path('roadmap.php');
    $plannedCount = collect($roadmap)->flatten(1)->count();

    /*
     * Thumbnails.
     *
     * One seed, drawn from the local CSPRNG — no beacon is asked for anything to
     * decorate a card, and the page still costs exactly one entropy draw. Each
     * generator expands it along its own info string, so the previews are
     * uncorrelated with each other and change on every visit.
     *
     * The parameters below are a *budget*, not a design: a card is 400px wide and
     * a visitor may be looking at eight of them. Keys a generator never declared
     * are dropped by coerce(), so this one list can lower the cost of any of them
     * without knowing which is which — `grid` and `steps` only mean anything to
     * the reaction-diffusion generator, whose defaults are a hundred million cell
     * updates and no business running in a thumbnail.
     */
    $previewSeed = app(EntropyPool::class)->seed('csprng');

    $preview = function ($generator) use ($previewSeed): ?array {
        if ($generator->renderer() !== Renderer::Canvas) {
            return null;
        }

        $params = $generator->schema()->coerce([
            'width' => 400, 'height' => 256, 'size' => 256,
            'particles' => 420, 'trail' => 140, 'clusters' => 2, 'rings' => 6,
            'grid' => 96, 'steps' => 900,
        ]);

        $result = $generator->generate(
            $previewSeed->rng($generator->key(), $generator->version(), $params->fingerprint()),
            $params,
        );

        return [
            'value' => $result->value,
            'render_key' => $previewSeed->renderKey($generator->key(), $generator->version()),
        ];
    };

    // Two lists, never one. Everything in $generators is registered and runnable;
    // everything in $roadmap is a specification. They are rendered differently on
    // purpose — a catalogue that counts unwritten code is a wishlist.
    $live = $generators->values()->map(fn ($g) => [
        'key' => $g->key(),
        'name' => $g->name(),
        'tagline' => $g->tagline(),
        'module' => $g->module()->value,
        'renderer' => $g->renderer()->value,
        'version' => $g->version(),
        'sensitive' => $g->isSensitive(),
        'params' => count($g->schema()->params()),
        'preview' => $preview($g),
        'hay' => Str::lower($g->name().' '.$g->key().' '.$g->tagline()),
    ]);
@endphp

<x-layout :breadcrumbs="[
              ['name' => 'Randomly', 'url' => route('home')],
              ['name' => 'Library', 'url' => route('library')],
          ]"
          :og="[
    'title' => 'Library · Randomly',
    'description' => 'All ' . $generators->count() . ' generators in one catalogue — numbers, words, equations, patterns, images and audio — filterable by module. Each one is free to call over HTTP with no key.',
]">
    <x-slot:title>Library</x-slot:title>

    <div x-data="{
            q: '',
            module: 'all',
            matches(module, hay) {
                return (this.module === 'all' || this.module === module)
                    && (this.q === '' || hay.includes(this.q.trim().toLowerCase()));
            },
            count(items) {
                return items.filter((i) => this.matches(i.module, i.hay)).length;
            },
            live: @js($live->map(fn ($g) => ['module' => $g['module'], 'hay' => $g['hay']])->values()),
            planned: @js(collect($roadmap)->flatMap(fn ($items, $module) => collect($items)->map(fn ($i) => [
                'module' => $module,
                'hay' => Str::lower($i['name'].' '.$i['key'].' '.$i['tagline']),
            ]))->values()),
         }"
         @keydown.window.slash="if (! ['input', 'textarea', 'select'].includes((document.activeElement?.tagName ?? '').toLowerCase())) { $event.preventDefault(); $refs.search.focus(); }">

        <section class="border-b border-line">
            <div class="mx-auto max-w-7xl px-6 pb-10 pt-16">
                <h1 class="text-4xl font-semibold tracking-tighter sm:text-5xl">The library.</h1>
                <p class="mt-4 max-w-2xl leading-relaxed text-muted">
                    A generator is one class and one line of config; the studio panel, the API
                    validation and this page are all rendered from its own
                    <span class="font-mono text-sm text-text">schema()</span>. Which is why what
                    ships and what is merely specified are easy to tell apart — and shown apart.
                </p>

                <p class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 font-mono text-xs">
                    <span class="flex items-center gap-2">
                        <span class="inline-block size-1.5 rounded-full bg-signal" aria-hidden="true"></span>
                        <span class="text-text num">{{ $live->count() }}</span>
                        <span class="text-muted">built and runnable</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="inline-block size-1.5 rounded-full border border-line" aria-hidden="true"></span>
                        <span class="text-muted num">{{ $plannedCount }}</span>
                        <span class="text-muted">specified, not yet written</span>
                    </span>
                </p>

                <div class="mt-8 flex flex-col gap-4 lg:flex-row lg:items-center">
                    <div class="relative lg:w-80">
                        <label for="library-search" class="sr-only">Search generators</label>
                        <input id="library-search" type="search" x-model="q" x-ref="search"
                               placeholder="Search generators" autocomplete="off"
                               class="tap w-full border border-line bg-surface/50 px-3 py-2.5 pr-12 text-sm text-text placeholder:text-muted focus:border-signal focus:outline-none">
                        <kbd class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 border border-line px-1.5 py-0.5 font-mono text-[0.65rem] text-muted">/</kbd>
                    </div>

                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filter by module">
                        <button type="button" @click="module = 'all'"
                                :class="module === 'all' ? 'border-signal text-signal' : 'border-line text-muted hover:text-text'"
                                class="tap inline-flex items-center border px-3 py-2 text-xs uppercase tracking-[0.12em] transition-colors">
                            All
                        </button>
                        @foreach ($modules as $moduleCase)
                            <button type="button" @click="module = '{{ $moduleCase->value }}'"
                                    :class="module === '{{ $moduleCase->value }}' ? 'border-signal text-signal' : 'border-line text-muted hover:text-text'"
                                    class="tap inline-flex items-center border px-3 py-2 text-xs uppercase tracking-[0.12em] transition-colors">
                                {{ $moduleCase->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- ── Built ──────────────────────────────────────────────────────── --}}
        <section class="mx-auto max-w-7xl px-6 pt-12" aria-labelledby="live-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="live-heading" class="text-xl font-medium tracking-tight">Built</h2>
                <p class="font-mono text-xs text-muted num">
                    <span x-text="count(live)">{{ $live->count() }}</span> of {{ $live->count() }} shown
                </p>
            </div>

            <ul class="mt-5 grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($live as $generator)
                    @php [$m, $g] = explode('.', $generator['key'], 2); @endphp
                    <li x-show="matches('{{ $generator['module'] }}', @js($generator['hay']))" class="min-w-0 bg-ground">
                        <a href="{{ route('studio', ['module' => $m, 'generator' => $g]) }}"
                           class="group flex h-full flex-col transition-colors hover:bg-surface/60">
                            @if ($generator['preview'])
                                {{-- A still, and only a still. One canvas moves on this site and
                                     it is on the landing page; a grid of animations is a jank
                                     budget nobody can afford and a page nobody can read. Each of
                                     these draws only once it is scrolled into view, and they
                                     queue rather than race. --}}
                                <div x-data="canvasPreview(@js($generator['preview']))"
                                     class="border-b border-line bg-surface/30">
                                    <canvas x-ref="canvas"
                                            width="{{ $generator['preview']['value']['width'] }}"
                                            height="{{ $generator['preview']['value']['height'] }}"
                                            class="block h-28 w-full object-cover"
                                            aria-hidden="true"></canvas>
                                </div>
                            @endif

                        <div class="flex min-w-0 flex-1 flex-col gap-3 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="text-base font-medium tracking-tight">{{ $generator['name'] }}</h3>
                                <span class="shrink-0 border border-line px-1.5 py-0.5 text-[0.62rem] uppercase tracking-[0.12em] text-muted">
                                    {{ $generator['module'] }}
                                </span>
                            </div>

                            <p class="text-sm leading-relaxed text-muted">{{ $generator['tagline'] }}</p>

                            {{-- SLOT: live animated preview per renderer — numbers tick digits,
                                 canvas generators animate at a low frame rate. --}}
                            <div class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[0.65rem] text-muted num">
                                <span class="text-signal">{{ $generator['key'] }}</span>
                                <span>v{{ $generator['version'] }}</span>
                                <span>{{ $generator['params'] }} controls</span>
                                <span>{{ $generator['renderer'] }}</span>
                                @if ($generator['sensitive'])
                                    <span class="border border-warn/40 px-1.5 text-warn">no permalink</span>
                                @endif
                            </div>
                        </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p x-show="count(live) === 0" x-cloak class="mt-5 border border-line px-6 py-10 text-center text-sm text-muted">
                Nothing matches that yet.
            </p>
        </section>

        {{-- Hidden entirely once the roadmap empties: a "Planned" heading over an
             empty grid reads as a rendering fault, not as an achievement. --}}
        @if ($plannedCount > 0)
        {{-- ── Planned ────────────────────────────────────────────────────────
             Deliberately dimmed, chipped and unlinked. Nothing here exists. --}}
        <section class="mx-auto max-w-7xl px-6 py-14" aria-labelledby="planned-heading">
            <div class="mt-6 flex flex-wrap items-baseline justify-between gap-3 border-t border-line pt-10">
                <div>
                    <h2 id="planned-heading" class="text-xl font-medium tracking-tight text-muted">Planned</h2>
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-muted">
                        Specified in the design documents, not written. These cards do not link anywhere,
                        do not appear in <span class="font-mono text-xs">/api/v1/generators</span>, and are
                        not counted as generators anywhere on this site.
                    </p>
                </div>
                <p class="font-mono text-xs text-muted num">
                    <span x-text="count(planned)">{{ $plannedCount }}</span> of {{ $plannedCount }} shown
                </p>
            </div>

            <ul class="mt-6 grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($roadmap as $moduleKey => $items)
                    @foreach ($items as $item)
                        <li x-show="matches('{{ $moduleKey }}', @js(Str::lower($item['name'].' '.$item['key'].' '.$item['tagline'])))"
                            class="flex min-w-0 flex-col gap-2 bg-ground p-4 opacity-55">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-medium tracking-tight">{{ $item['name'] }}</h3>
                                <span class="shrink-0 border border-line px-1.5 py-0.5 font-mono text-[0.58rem] uppercase tracking-[0.1em] text-muted">
                                    planned
                                </span>
                            </div>
                            <p class="text-xs leading-relaxed text-muted">{{ $item['tagline'] }}</p>
                            <p class="mt-auto font-mono text-[0.6rem] text-muted num">{{ $item['key'] }}</p>
                        </li>
                    @endforeach
                @endforeach
            </ul>

            <p x-show="count(planned) === 0" x-cloak class="mt-5 border border-line px-6 py-10 text-center text-sm text-muted">
                Nothing planned matches <span class="font-mono text-text" x-text="q"></span> either.
            </p>
        </section>
        @endif
    </div>
</x-layout>
