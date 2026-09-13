@php
    $roadmap = require resource_path('roadmap.php');
    $plannedCount = collect($roadmap)->flatten(1)->count();
    $liveModules = $byModule->count();

    $heroReceipt = $hero->receipt;
    [$heroModule, $heroGenerator] = explode('.', $hero->generator->key(), 2);

    /*
     * The background behind the headline.
     *
     * Drawn from the *same sixteen bytes* as the numbers in the card below it,
     * expanded along a different info string — so the hero costs no second
     * entropy draw and no second network call, and the sentence under the card
     * claiming both came from one seed is literally true.
     *
     * Null when the generator is not registered, in which case the gradient
     * behind it stands on its own and nothing on the page misses it.
     */
    $flowField = $byModule->get('images')?->get('images.flowfield');
    $heroCanvas = null;

    if ($flowField !== null) {
        $heroParams = $flowField->schema()->coerce([
            'width' => 1280, 'height' => 720,
            'particles' => 2000, 'trail' => 230, 'scale' => 2.0, 'alpha' => 0.26,
        ]);

        $heroCanvas = [
            'value' => $flowField->generate(
                $hero->seed->rng($flowField->key(), $flowField->version(), $heroParams->fingerprint()),
                $heroParams,
            )->value,
            'render_key' => $hero->seed->renderKey($flowField->key(), $flowField->version()),
        ];
    }
@endphp

<x-layout>
    {{-- ── Hero ───────────────────────────────────────────────────────────────
         Not a mockup: $hero is a real Generation, drawn through the same Studio
         the API uses, on this request. The sentence below names the source it
         actually came from. --}}
    <section class="relative isolate overflow-hidden border-b border-line">
        {{-- images.flowfield, grown a step at a time over a couple of seconds so the
             page paints first and the picture arrives after it — then it stops. The
             one moving canvas on the site; under prefers-reduced-motion it is
             completed in a single pass and simply appears. --}}
        <canvas id="hero-canvas"
                x-data="heroFlowField(@js($heroCanvas))"
                width="{{ $heroCanvas['value']['width'] ?? 1280 }}"
                height="{{ $heroCanvas['value']['height'] ?? 720 }}"
                class="pointer-events-none absolute inset-0 size-full object-cover opacity-60"
                aria-hidden="true"></canvas>
        <div class="absolute inset-0 bg-gradient-to-b from-ground/30 via-ground/70 to-ground" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-7xl px-6 pb-16 pt-24 sm:pt-32">
            <p class="flex items-center gap-2 font-mono text-xs uppercase tracking-[0.2em] text-muted">
                <span class="animate-blip inline-block size-1.5 rounded-full bg-signal" aria-hidden="true"></span>
                A library of random generators
            </p>

            <h1 class="mt-6 max-w-4xl text-5xl font-semibold leading-[0.95] tracking-tighter sm:text-6xl lg:text-7xl">
                Randomness,<br>sourced from reality.
            </h1>

            <div class="mt-10 max-w-2xl border border-line bg-surface/40">
                <p class="border-b border-line px-5 py-2 font-mono text-[0.65rem] uppercase tracking-[0.18em] text-muted">
                    Drawn for this page load · {{ $hero->generator->name() }}
                </p>
                <p class="animate-rise px-5 py-6 font-mono text-2xl text-signal num sm:text-3xl">
                    {{ $hero->result->display }}
                </p>
                <p class="border-t border-line px-5 py-3 text-sm leading-relaxed text-muted">
                    {{ $heroReceipt->narrative }}
                    @if ($heroReceipt->proofUrl)
                        <a href="{{ $heroReceipt->proofUrl }}" target="_blank" rel="noopener noreferrer"
                           class="whitespace-nowrap font-mono text-xs text-signal hover:underline">Check it yourself ↗</a>
                    @endif
                </p>

                {{-- The studio for *this* generator, kept as the small affordance it is.
                     The big button goes to the catalogue, because dropping a first-time
                     visitor into one arbitrary generator hides the other thirty-one. --}}
                <p class="border-t border-line px-5 py-2.5">
                    <a href="{{ route('studio', ['module' => $heroModule, 'generator' => $heroGenerator]) }}"
                       class="inline-flex min-h-11 items-center font-mono text-xs text-signal hover:underline">
                        See how this one was made →
                    </a>
                </p>
            </div>

            <p class="mt-6 max-w-2xl leading-relaxed text-muted">
                Every result carries a receipt like that one: the source, the moment, the proof.
                Reload this page and the numbers change, because the world did.
                @if ($heroCanvas)
                    So does the field drawn behind this headline — it is the same sixteen bytes,
                    expanded down a different path and handed to your browser to draw.
                @endif
            </p>

            <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <a href="{{ route('library') }}"
                   class="inline-flex min-h-12 items-center justify-center gap-2 bg-signal px-5 py-3 text-sm font-medium text-ground transition-opacity hover:opacity-90 sm:justify-start">
                    Browse {{ $counts['generators'] }} generators
                    <span aria-hidden="true">→</span>
                </a>
                <a href="{{ route('entropy') }}"
                   class="inline-flex min-h-12 items-center justify-center gap-2 border border-line px-5 py-3 text-sm font-medium text-text transition-colors hover:border-muted sm:justify-start">
                    See where it comes from
                </a>
            </div>

            {{-- Live counts, with the roadmap kept visibly separate from what ships. --}}
            <dl class="mt-14 grid max-w-4xl grid-cols-2 gap-px border border-line bg-line sm:grid-cols-4">
                @php
                    $stats = [
                        // Counts here are small and will stay small for a while, so they
                        // get pluralised properly. "1 modules shipping" on a page that
                        // sells itself on careful language is not a small mistake.
                        ['value' => $counts['generators'], 'label' => Str::plural('generator', $counts['generators']).' live', 'sub' => $plannedCount.' more specified'],
                        ['value' => $liveModules, 'label' => Str::plural('module', $liveModules).' shipping', 'sub' => 'of '.$counts['modules'].' planned'],
                        ['value' => $counts['sources'], 'label' => 'entropy '.Str::plural('source', $counts['sources']), 'sub' => 'wired and probed'],
                        ['value' => $counts['keys'], 'label' => Str::plural('API key', $counts['keys']), 'sub' => 'no signup, ever'],
                    ];
                @endphp
                @foreach ($stats as $stat)
                    <div class="bg-ground px-4 py-4">
                        <dt class="font-mono text-3xl text-text num">{{ $stat['value'] }}</dt>
                        <dd class="mt-1 text-xs uppercase tracking-[0.12em] text-muted">{{ $stat['label'] }}</dd>
                        <dd class="mt-1 font-mono text-[0.65rem] text-muted num">{{ $stat['sub'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ── Live entropy ticker ────────────────────────────────────────────────
         Polls GET /api/v1/sources every 5s. That endpoint reads cache only, so a
         tab left open here never costs a beacon a single request. --}}
    <section aria-label="Live entropy sources" class="border-b border-line bg-surface/40"
             x-data="entropyTicker(@js($sources))">
        {{-- Eleven mono cells never fit a phone, and squashing them would cost the
             one thing the strip is for: a real value you can read. So it scrolls
             under a finger — momentum, no scrollbar chrome — and the right edge is
             masked so a half-shown cell reads as "there is more" rather than as a
             clipped layout. --}}
        <div class="scroll-x mx-auto max-w-7xl px-6 edge-fade lg:[mask-image:none]">
            <ul class="flex min-w-max items-stretch divide-x divide-line font-mono text-xs">
                @foreach ($sources as $i => $source)
                    <li class="flex items-center gap-3 px-4 py-3.5 sm:px-5">
                        <span class="animate-blip inline-block size-1.5 shrink-0 rounded-full {{ $source['status'] === 'up' ? 'bg-signal' : 'bg-warn' }}"
                              :class="isUp({{ $i }}) ? 'bg-signal' : 'bg-warn'"
                              aria-hidden="true"></span>
                        <span class="whitespace-nowrap uppercase tracking-[0.14em] text-muted">{{ $source['label'] }}</span>
                        @if ($source['key'] === 'csprng')
                            {{-- Never cached, so there is nothing for the poll to report. --}}
                            <span class="whitespace-nowrap text-muted num">drawn fresh per request</span>
                        @else
                            <span class="whitespace-nowrap text-text num"
                                  x-text="current({{ $i }})">{{ $source['current'] ?? '—' }}</span>
                        @endif
                        <span class="whitespace-nowrap text-muted num">class {{ $source['class'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
        <p class="mx-auto max-w-7xl border-t border-line px-6 py-2 font-mono text-[0.65rem] text-muted">
            Values appear as each source gets drawn from. Nothing is fetched merely to fill this strip ·
            <a href="{{ route('entropy') }}" class="text-signal hover:underline">what each one is worth →</a>
            <span x-show="failed" x-cloak class="text-warn">· the ticker lost its connection and is showing its last known values</span>
        </p>
    </section>

    {{-- ── The catalogue ──────────────────────────────────────────────────── --}}
    <section class="mx-auto max-w-7xl px-6 py-20">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-3xl font-semibold tracking-tighter sm:text-4xl">Pick what you want to be random.</h2>
                <p class="mt-3 max-w-xl text-muted">
                    {{ $liveModules }} of {{ $counts['modules'] }} modules have something live in them:
                    {{ $counts['generators'] }} generators built, {{ $plannedCount }} more specified and not
                    written. The cards below keep the two apart, because a catalogue that counts unwritten
                    code is just a wishlist.
                </p>
            </div>
            <a href="{{ route('library') }}" class="tap inline-flex items-center text-sm text-signal hover:underline">
                Browse the library →
            </a>
        </div>

        <ul class="mt-10 grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($modules as $module)
                @php
                    $live = $byModule->get($module->value, collect());
                    $planned = collect($roadmap[$module->value] ?? []);
                @endphp
                <li class="flex flex-col gap-4 bg-ground p-6 {{ $live->isEmpty() ? 'opacity-60' : '' }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-lg font-medium tracking-tight">{{ $module->label() }}</h3>
                        @if ($live->isNotEmpty())
                            <span class="shrink-0 border border-signal/40 px-1.5 py-0.5 font-mono text-[0.62rem] text-signal num">
                                {{ $live->count() }} live
                            </span>
                        @else
                            <span class="shrink-0 border border-line px-1.5 py-0.5 font-mono text-[0.62rem] uppercase tracking-[0.1em] text-muted">
                                planned
                            </span>
                        @endif
                    </div>

                    <p class="text-sm leading-relaxed text-muted">{{ $module->tagline() }}</p>

                    @if ($live->isNotEmpty())
                        <ul class="flex flex-wrap gap-px bg-line">
                            @foreach ($live as $generator)
                                @php [$m, $g] = explode('.', $generator->key(), 2); @endphp
                                <li>
                                    <a href="{{ route('studio', ['module' => $m, 'generator' => $g]) }}"
                                       class="tap flex items-center bg-surface/60 px-3 py-2 font-mono text-xs text-signal transition-colors hover:bg-surface">
                                        {{ $generator->name() }} →
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Equations has nothing left on the roadmap, and "0 planned ·" followed
                         by an empty list reads as a rendering bug rather than as the good news
                         it is. --}}
                    <p class="mt-auto font-mono text-[0.65rem] leading-relaxed text-muted num">
                        @if ($planned->isEmpty())
                            everything specified for this module is built
                        @else
                            {{ $planned->count() }} planned · {{ $planned->take(3)->pluck('name')->implode(' · ') }}@if ($planned->count() > 3) · …@endif
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ── How it works ───────────────────────────────────────────────────── --}}
    <section class="border-y border-line bg-surface/30">
        <div class="mx-auto max-w-7xl px-6 py-20">
            <h2 class="text-3xl font-semibold tracking-tighter sm:text-4xl">Collect, condition, generate.</h2>
            <p class="mt-3 max-w-2xl text-muted">
                Three steps, one of which is a single line of PHP. The honesty is in the middle one.
            </p>

            <ol class="mt-12 grid gap-px border border-line bg-line lg:grid-cols-3">
                <li class="min-w-0 bg-ground p-6 sm:p-7">
                    <p class="font-mono text-xs text-signal num">01</p>
                    <h3 class="mt-3 text-lg font-medium tracking-tight">Collect</h3>
                    <p class="mt-3 text-sm leading-relaxed text-muted">
                        {{ $counts['sources'] }} free, keyless sources: the kernel's own pool, a quantum
                        optics bench in Canberra, atmospheric radio noise, a signed government beacon, a
                        threshold signature network, ten minutes of global Bitcoin hashrate, and every
                        earthquake on Earth in the last hour. Every call has a timeout and a circuit breaker;
                        when one misses we fall back to the OS CSPRNG and mark the receipt
                        <span class="font-mono text-warn">degraded</span> rather than pretending.
                    </p>
                </li>

                <li class="min-w-0 bg-ground p-6 sm:p-7">
                    <p class="font-mono text-xs text-signal num">02</p>
                    <h3 class="mt-3 text-lg font-medium tracking-tight">Condition</h3>
                    <p class="mt-3 text-sm leading-relaxed text-muted">
                        The exotic material supplies the story and a real contribution. Fresh CSPRNG bytes,
                        the nanosecond clock and a counter supply uniqueness — which is what makes caching a
                        60-second beacon pulse safe without ever reusing a seed.
                    </p>
                    <pre class="mt-4 overflow-x-auto border border-line bg-ground px-3 py-3 font-mono text-[0.68rem] leading-relaxed text-text"><code>ikm  = source ‖ random_bytes(32) ‖ hrtime() ‖ counter
seed = hash_hkdf('sha256', ikm, 16, 'randomly.seed')</code></pre>

                    <svg viewBox="0 0 340 70" class="mt-5 w-full text-line" role="img"
                         aria-label="Diagram: source bytes and OS entropy are extracted into a pseudorandom key, then expanded into a sixteen byte seed.">
                        <g fill="none" stroke="currentColor" stroke-width="1">
                            <rect x="1" y="8" width="76" height="22" />
                            <rect x="1" y="38" width="76" height="22" />
                            <rect x="120" y="23" width="76" height="22" />
                            <rect x="248" y="23" width="90" height="22" />
                            <path d="M77 19 H104 M77 49 H104 M104 19 V34 M104 49 V34 M104 34 H120" />
                            <path d="M196 34 H248" />
                        </g>
                        <g fill="currentColor" stroke="none">
                            <polygon points="120,34 114,31 114,37" />
                            <polygon points="248,34 242,31 242,37" />
                        </g>
                        <g font-family="ui-monospace, monospace" font-size="9" fill="currentColor">
                            <text x="10" y="23">source bytes</text>
                            <text x="10" y="53">OS csprng</text>
                            <text x="130" y="38">extract → PRK</text>
                            <text x="258" y="38">expand → seed</text>
                        </g>
                    </svg>
                </li>

                <li class="min-w-0 bg-ground p-6 sm:p-7">
                    <p class="font-mono text-xs text-signal num">03</p>
                    <h3 class="mt-3 text-lg font-medium tracking-tight">Generate</h3>
                    <p class="mt-3 text-sm leading-relaxed text-muted">
                        16 bytes and a token. The token <em>is</em> the seed, so a permalink recomputes the
                        result instead of looking it up — which is why this site has no database. Change a
                        parameter and you get a different answer from the same randomness; press Generate and
                        you get new randomness. Two buttons, one lesson.
                    </p>
                    <p class="mt-4 overflow-x-auto font-mono text-[0.68rem] text-muted num">
                        info = randomly/v1|{generator}|v{n}|sha256(params)
                    </p>
                </li>
            </ol>
        </div>
    </section>

    {{-- ── Receipt + API ──────────────────────────────────────────────────── --}}
    <section id="api" class="mx-auto max-w-7xl scroll-mt-20 px-6 py-20">
        <div class="grid gap-12 lg:grid-cols-2">
            <div class="min-w-0">
                <h2 class="text-3xl font-semibold tracking-tighter sm:text-4xl">Every result has a receipt.</h2>
                <p class="mt-3 text-muted">
                    This is the receipt for the numbers at the top of this page — not an example,
                    the actual one, from this request.
                </p>

                <figure class="mt-8 border border-line bg-surface/50">
                    <figcaption class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
                        <span class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">Seed receipt</span>
                        <span class="flex items-center gap-2 font-mono text-[0.68rem] {{ $heroReceipt->degraded ? 'text-warn' : 'text-signal' }}">
                            <span class="inline-block size-1.5 rounded-full {{ $heroReceipt->degraded ? 'bg-warn' : 'bg-signal' }}" aria-hidden="true"></span>
                            Class {{ $heroReceipt->class->value }} · {{ $heroReceipt->class->label() }}{{ $heroReceipt->degraded ? ' · degraded' : '' }}{{ $heroReceipt->cached ? ' · cached' : '' }}
                        </span>
                    </figcaption>
                    <dl class="divide-y divide-line font-mono text-xs">
                        @php
                            $receiptRows = [
                                ['Source', $heroReceipt->sourceLabel],
                                ['Narrative', $heroReceipt->narrative],
                                ['Observed at', $heroReceipt->observedAt->format(DATE_ATOM)],
                                ['Mixed with', $heroReceipt->mixedWithCsprng ? 'csprng — always' : 'nothing — this source stands alone'],
                                ['Latency', $heroReceipt->latencyMs.' ms'],
                                ['Entropy in', \App\Random\Entropy\Seed::BYTES.' bytes'],
                                ['Duration', number_format($hero->durationUs).' µs'],
                                ['Token', $hero->seed->token()],
                            ];
                        @endphp
                        @foreach ($receiptRows as [$key, $value])
                            <div class="flex gap-4 px-5 py-2.5">
                                <dt class="w-24 shrink-0 text-muted">{{ $key }}</dt>
                                <dd class="min-w-0 break-words text-text num">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($hero->permalink())
                        <div class="border-t border-line px-5 py-3">
                            <a href="{{ $hero->permalink() }}" class="break-all font-mono text-xs text-signal hover:underline">
                                Replay this exact result ↗
                            </a>
                        </div>
                    @endif
                </figure>

                @if ($heroReceipt->class->caveat())
                    <p class="mt-4 text-sm leading-relaxed text-muted">{{ $heroReceipt->class->caveat() }}</p>
                @endif
            </div>

            <div class="min-w-0">
                <h2 class="text-3xl font-semibold tracking-tighter sm:text-4xl">The same API we use.</h2>
                <p class="mt-3 text-muted">
                    There is no private API. This page's own hero went through the endpoints below.
                    No authentication, no key, no signup — matching every source we draw from.
                    Rate limited to <span class="font-mono text-text num">60</span> requests a minute per IP.
                </p>

                <pre class="mt-8 overflow-x-auto border border-line bg-surface/50 p-5 font-mono text-xs leading-relaxed text-text"><code>curl -s '{{ url('/api/v1/g/numbers.integers') }}?count=6&min=1&max=60&unique=1' \
  -H 'Accept: text/plain'
<span class="text-signal">{{ $hero->result->display }}</span></code></pre>

                <ul class="mt-6 divide-y divide-line border-y border-line font-mono text-xs">
                    @foreach ([['GET', '/api/v1/generators', 'the catalogue, machine-readable'], ['GET', '/api/v1/sources', 'every source, status and class'], ['POST', '/api/v1/generate', 'one result plus its receipt'], ['GET', '/api/v1/replay/{token}', 'recompute a past result, bit-for-bit']] as [$verb, $path, $note])
                        <li class="flex flex-wrap items-baseline gap-3 py-2.5">
                            <span class="w-11 shrink-0 text-signal">{{ $verb }}</span>
                            <span class="text-text">{{ $path }}</span>
                            <span class="text-muted">{{ $note }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-6 text-sm leading-relaxed text-muted">
                    A replay against a generator whose version has moved on answers
                    <span class="font-mono text-xs text-text">409 version_changed</span> rather than quietly
                    returning something different. Honest failure beats a silently different answer.
                </p>
            </div>
        </div>
    </section>
</x-layout>
