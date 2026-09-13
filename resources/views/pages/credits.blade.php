@php
    /*
     * Terms, keyed by the pool's own source keys.
     *
     * The page loops over the live sources rather than over this array, so a
     * source that gets wired without a credit shows up as an unmissable gap in
     * the list rather than quietly going uncredited.
     */
    $terms = [
        'csprng' => [
            'operator' => 'The kernel of the machine that served this page',
            'licence' => 'No third party involved',
            'note' => 'PHP’s random_bytes(), which is getrandom(2) on Linux. Nobody to credit and nobody to ask.',
            'url' => 'https://www.php.net/manual/en/function.random-bytes.php',
        ],
        'anu-qrng' => [
            'operator' => 'Australian National University, Canberra',
            'licence' => 'Free public API · attribution requested',
            'note' => 'Vacuum field fluctuations measured by homodyne detection, published as bytes for anyone to use.',
            'url' => 'https://qrng.anu.edu.au/',
        ],
        'random-org' => [
            'operator' => 'Randomness and Integrity Services Ltd., Dublin',
            'licence' => 'Free tier · daily quota, no key',
            'note' => 'Atmospheric radio noise, running since 1998. We watch the quota headers and stop when told to.',
            'url' => 'https://www.random.org/',
        ],
        'nist-beacon' => [
            'operator' => 'US National Institute of Standards and Technology',
            'licence' => 'US Government work · public domain',
            'note' => 'NIST publishes the beacon; it does not endorse this or any other use of it.',
            'url' => 'https://beacon.nist.gov/home',
        ],
        'drand' => [
            'operator' => 'The League of Entropy — Protocol Labs, Cloudflare, EPFL, Kudelski and others',
            'licence' => 'Public endpoint · open network',
            'note' => 'A threshold BLS signature nobody in the network could have produced alone.',
            'url' => 'https://drand.love/',
        ],
        'bitcoin' => [
            'operator' => 'mempool.space, reading the Bitcoin chain',
            'licence' => 'Open-source explorer · free public API',
            'note' => 'The work is the network’s; the explorer is the part that answers our HTTP request, and it is the part that gets credited.',
            'url' => 'https://mempool.space/',
        ],
        'seismic' => [
            'operator' => 'USGS Earthquake Hazards Program',
            'licence' => 'US Government work · public domain',
            'note' => 'The whole hour of microquakes, not only the one the receipt names.',
            'url' => 'https://earthquake.usgs.gov/earthquakes/feed/',
        ],
        'space-weather' => [
            'operator' => 'NOAA Space Weather Prediction Center',
            'licence' => 'US Government work · public domain',
            'note' => 'The planetary K-index, once a minute, from a service that exists to warn power grids.',
            'url' => 'https://www.swpc.noaa.gov/products/planetary-k-index',
        ],
        'atmosphere' => [
            'operator' => 'Open-Meteo',
            'licence' => 'Weather data CC BY 4.0 · free without a key',
            'note' => 'Live conditions at a city picked at random. Their free tier is generous and we cache hard so as not to abuse it.',
            'url' => 'https://open-meteo.com/',
        ],
        'iss' => [
            'operator' => 'Open Notify, by Nathan Bergey, from NASA tracking data',
            'licence' => 'Free public API',
            'note' => 'The station’s ground track. Worth two or three bits, and worth every one of them as a sentence.',
            'url' => 'http://open-notify.org/',
        ],
    ];

    $corpora = [
        [
            'name' => 'EFF Diceware wordlists',
            'holder' => 'Electronic Frontier Foundation',
            'licence' => 'CC BY 3.0 US',
            'url' => 'https://www.eff.org/dice',
            'note' => '7,776 and 1,296 words — 12.925 and 10.340 bits each. Bundled, not fetched, so the entropy accounting is verifiable offline.',
        ],
        [
            'name' => 'Wikipedia article summaries',
            'holder' => 'Wikipedia contributors, via the Wikimedia Foundation',
            'licence' => 'CC BY-SA 4.0',
            'url' => 'https://en.wikipedia.org/api/rest_v1/',
            'note' => 'Every article the words module quotes is linked back to its page, which is both the licence condition and the interesting part.',
        ],
        [
            'name' => 'GBIF species records',
            'holder' => 'GBIF Secretariat and the publishing institutions',
            'licence' => 'Per dataset — CC0 or CC BY',
            'url' => 'https://www.gbif.org/',
            'note' => 'Scientific names come with their naming authority attached, because in taxonomy the citation is part of the name.',
        ],
        [
            'name' => 'Syllable grammars and lorem vocabularies',
            'holder' => 'Hand-built for this project',
            'licence' => 'No third-party rights',
            'url' => null,
            'note' => 'The classical lorem text descends from Cicero by way of a 15th-century typesetter, and is long out of copyright.',
        ],
    ];

    $software = [
        ['name' => 'Laravel', 'licence' => 'MIT', 'url' => 'https://laravel.com', 'note' => 'The framework.'],
        ['name' => 'Alpine.js', 'licence' => 'MIT', 'url' => 'https://alpinejs.dev', 'note' => 'Every interaction on this site, and no more than that.'],
        ['name' => 'Tailwind CSS', 'licence' => 'MIT', 'url' => 'https://tailwindcss.com', 'note' => 'The palette in §2 of the brand doc, as OKLCH tokens.'],
        ['name' => 'Vite', 'licence' => 'MIT', 'url' => 'https://vite.dev', 'note' => 'The build.'],
        ['name' => 'Pest', 'licence' => 'MIT', 'url' => 'https://pestphp.com', 'note' => 'Including the tests that fail when this page tells a lie about a count.'],
        ['name' => 'KaTeX', 'licence' => 'MIT', 'url' => 'https://katex.org', 'note' => 'Typesets the equations module, in the browser.'],
        ['name' => 'Inter', 'licence' => 'SIL OFL 1.1', 'url' => 'https://rsms.me/inter/', 'note' => 'Rasmus Andersson. Everything you are reading that is not a number.'],
        ['name' => 'JetBrains Mono', 'licence' => 'SIL OFL 1.1', 'url' => 'https://www.jetbrains.com/lp/mono/', 'note' => 'Every number, seed and hash — and every share card, which is drawn from the bundled TTF.'],
        ['name' => 'Bunny Fonts', 'licence' => 'Service', 'url' => 'https://fonts.bunny.net', 'note' => 'Where the two faces above are fetched from at build time. They ship from this origin, not a tracker’s.'],
        ['name' => 'OpenStreetMap', 'licence' => 'ODbL · © OpenStreetMap contributors', 'url' => 'https://www.openstreetmap.org/copyright', 'note' => 'The map every ISS receipt links to as its proof.'],
    ];

    /*
     * Specified in docs/11-api.md for generators that are not built.
     *
     * Kept visibly separate for the same reason the roadmap is kept out of the
     * generator count: crediting an API we have never called would be flattering
     * to us and false about them.
     */
    $notYet = [
        ['name' => 'Lorem Picsum / Unsplash', 'note' => 'photographer credit per image'],
        ['name' => 'The Met Collection API', 'note' => 'CC0'],
        ['name' => 'Art Institute of Chicago API', 'note' => 'public domain'],
        ['name' => 'Datamuse', 'note' => 'free, keyless'],
        ['name' => 'Open Library', 'note' => 'free, keyless'],
    ];

    $credited = count($sources) + count($corpora) + count($software);
@endphp

<x-layout :og="['title' => 'Credits · Randomly', 'description' => 'Every entropy source, corpus, library and typeface this site stands on, with its licence. Free does not mean uncredited.']">
    <x-slot:title>Credits</x-slot:title>

    <section class="border-b border-line">
        <div class="mx-auto max-w-7xl px-6 pb-12 pt-16">
            <h1 class="text-4xl font-semibold tracking-tighter sm:text-5xl">Standing on other people's work.</h1>

            <p class="mt-6 max-w-3xl text-lg leading-relaxed text-muted">
                This site draws randomness from {{ count($sources) }} sources and builds words out of
                corpora it did not compile. Not one of them charges, not one of them asked for a key,
                and every one of them could have.
            </p>

            <dl class="mt-10 grid max-w-3xl grid-cols-2 gap-px border border-line bg-line sm:grid-cols-4">
                @foreach ([
                    ['value' => $credited, 'label' => 'credits owed'],
                    ['value' => count($sources), 'label' => 'entropy sources'],
                    ['value' => 0, 'label' => 'API keys held'],
                    ['value' => 0, 'label' => 'invoices paid'],
                ] as $stat)
                    <div class="bg-ground px-4 py-4">
                        <dt class="font-mono text-3xl text-text num">{{ $stat['value'] }}</dt>
                        <dd class="mt-1 text-xs uppercase tracking-[0.12em] text-muted">{{ $stat['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- ── Entropy ────────────────────────────────────────────────────────────
         Iterated from the live pool, so this list cannot fall behind the one on
         /entropy or the one in the config. --}}
    <section class="mx-auto max-w-7xl px-6 py-16">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">Where the randomness comes from</h2>
            <a href="{{ route('entropy') }}" class="tap inline-flex items-center text-sm text-signal hover:underline">See them live →</a>
        </div>

        <ul class="mt-8 grid gap-px border border-line bg-line lg:grid-cols-2">
            @foreach ($sources as $source)
                @php $term = $terms[$source['key']] ?? null; @endphp
                <li class="min-w-0 bg-ground p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <h3 class="text-base font-medium tracking-tight">{{ $source['label'] }}</h3>
                        <span class="shrink-0 border px-2 py-0.5 font-mono text-[0.65rem] {{ $source['class'] === 'A' ? 'border-signal/40 text-signal' : 'border-line text-muted' }}">
                            Class {{ $source['class'] }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm leading-relaxed text-muted">
                        {{ $term['operator'] ?? $source['origin'] }}
                    </p>

                    @if ($term)
                        <p class="mt-3 text-sm leading-relaxed text-muted">{{ $term['note'] }}</p>

                        <p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[0.68rem] text-muted num">
                            <span class="text-text">{{ $term['licence'] }}</span>
                            <a href="{{ $term['url'] }}" target="_blank" rel="noopener noreferrer"
                               class="tap ml-auto inline-flex items-center text-signal hover:underline">{{ parse_url($term['url'], PHP_URL_HOST) }} ↗</a>
                        </p>
                    @else
                        {{-- A source wired without a credit. Saying so is better than
                             printing nothing and hoping nobody counts the cards. --}}
                        <p class="mt-4 font-mono text-[0.68rem] text-warn">
                            Wired, and not yet credited here. That is a bug — please open an issue.
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ── Corpora ──────────────────────────────────────────────────────────── --}}
    <section class="border-y border-line bg-surface/30">
        <div class="mx-auto max-w-7xl px-6 py-16">
            <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">Words, names and the data behind them</h2>

            <ul class="mt-8 divide-y divide-line border-y border-line">
                @foreach ($corpora as $corpus)
                    <li class="grid gap-2 py-5 md:grid-cols-[1.1fr_1.4fr_auto] md:items-baseline md:gap-8">
                        <div>
                            <p class="font-medium tracking-tight">{{ $corpus['name'] }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $corpus['holder'] }}</p>
                        </div>
                        <p class="text-sm leading-relaxed text-muted">{{ $corpus['note'] }}</p>
                        <p class="font-mono text-xs text-text num md:text-right">
                            {{ $corpus['licence'] }}
                            @if ($corpus['url'])
                                <a href="{{ $corpus['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="tap ml-2 inline-flex items-center px-1 text-signal hover:underline"><span class="sr-only">{{ $corpus['name'] }} — </span>↗</a>
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ── Software and type ────────────────────────────────────────────────── --}}
    <section class="mx-auto max-w-7xl px-6 py-16">
        <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">The stack, and the letterforms</h2>

        <ul class="mt-8 grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($software as $item)
                <li class="min-w-0 bg-ground p-5">
                    {{-- A licence string can be as long as "ODbL · © OpenStreetMap
                         contributors", which is wider than a phone. It wraps under the
                         name rather than pushing the card past the viewport. --}}
                    <p class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer"
                           class="tap inline-flex items-center font-medium tracking-tight hover:text-signal">{{ $item['name'] }}</a>
                        <span class="min-w-0 font-mono text-[0.65rem] text-muted num">{{ $item['licence'] }}</span>
                    </p>
                    <p class="mt-2 text-sm leading-relaxed text-muted">{{ $item['note'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ── Not yet ──────────────────────────────────────────────────────────── --}}
    <section class="border-t border-line bg-surface/30">
        <div class="mx-auto grid max-w-7xl gap-12 px-6 py-16 lg:grid-cols-[1.1fr_1fr]">
            <div>
                <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">Not credited, because not yet used</h2>
                <p class="mt-4 max-w-2xl text-sm leading-relaxed text-muted">
                    These are named in the specification for generators that have not been built.
                    This site has never sent any of them a request. They are listed here so that the
                    list above can be read as exactly what it is — everything currently in use — and
                    so that the day one of them starts answering, it moves up a section rather than
                    appearing out of nowhere.
                </p>

                <ul class="mt-6 flex flex-wrap gap-x-3 gap-y-2 font-mono text-xs text-muted">
                    @foreach ($notYet as $item)
                        <li class="border border-line px-2 py-1">
                            {{ $item['name'] }} <span class="text-muted/70">· {{ $item['note'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">Free does not mean uncredited.</h2>
                <p class="mt-4 text-sm leading-relaxed text-muted">
                    Every service on this page could have put a login in front of itself and did not.
                    A beacon run by a government laboratory, a quantum optics bench in Canberra, an
                    earthquake feed maintained so that buildings do not fall down — all of it answers
                    an anonymous HTTP request in under a second, for nothing.
                </p>
                <p class="mt-4 text-sm leading-relaxed text-muted">
                    The least this project can do in return is cache politely, time out quickly, name
                    them on every result they touch, and keep this page honest about which of them it
                    has actually asked for anything.
                </p>

                <p class="mt-6 font-mono text-[0.68rem] leading-relaxed text-muted">
                    Machine-readable, with live status:
                    <a href="{{ url('/api/v1/sources') }}" class="text-signal hover:underline">/api/v1/sources</a>
                </p>
            </div>
        </div>
    </section>
</x-layout>
