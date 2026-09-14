@props([
    'wide' => false,
    'og' => [],
    // Where this page's markdown twin lives, and what the crumbs above it are.
    // Both default to the site-wide answers so a page has to opt in rather than
    // remember, and neither is ever a hand-written string: a studio page passes
    // the route, and the route is generated from the generator's own key.
    'markdown' => null,
    'canonical' => null,
    'breadcrumbs' => [],
])

@php
    $pageTitle = isset($title) ? trim($title) . ' · Randomly' : 'Randomly · Randomness, sourced from reality.';

    /*
     * The share card.
     *
     * Pages that are one specific result hand in their own image — drawn from
     * that result's display string and its real receipt — and everything else
     * falls back to the site card, which carries the two live counts. Nothing
     * here is a stock photograph of a concept.
     */
    // One answer for the canonical link and for og:url, so a share and a crawl
    // agree on what this page's address is.
    $canonicalUrl = $canonical ?? url()->current();

    $ogImage = $og['image'] ?? route('og.site');
    $ogDescription = $og['description']
        ?? 'A library of random generators, seeded by verifiable real-world entropy. Every result ships with a receipt.';
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $ogDescription }}">
    <meta name="color-scheme" content="dark">

    <title>{{ $pageTitle }}</title>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Randomly">
    <meta property="og:title" content="{{ $og['title'] ?? $pageTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $og['alt'] ?? 'A dark card reading “Randomness, sourced from reality.”' }}">
    <meta name="twitter:card" content="summary_large_image">

    <x-seo :canonical="$canonicalUrl" :markdown="$markdown" :breadcrumbs="$breadcrumbs" />

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ground font-sans text-text antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-sm focus:bg-signal focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-ground">
        Skip to content
    </a>

    <header class="sticky top-0 z-50 border-b border-line bg-ground/80 backdrop-blur-md">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2 sm:gap-6 sm:px-6 sm:py-3">
            <a href="{{ route('home') }}" class="tap group flex min-w-0 flex-col justify-center leading-none">
                <span class="text-[0.95rem] font-semibold tracking-tight text-text">
                    Randomly<span class="text-signal">.</span>
                </span>
                <span class="mt-1 hidden text-[0.68rem] font-normal tracking-wide text-muted sm:block">
                    Randomness, sourced from reality.
                </span>
            </a>

            <nav aria-label="Primary" class="ml-auto flex items-center gap-0.5 text-sm sm:gap-1">
                @php
                    $navLinks = [
                        ['label' => 'Library', 'href' => route('library'), 'active' => request()->routeIs('library')],
                        ['label' => 'Entropy', 'href' => route('entropy'), 'active' => request()->routeIs('entropy')],
                        ['label' => 'API', 'href' => route('home') . '#api', 'active' => false],
                    ];
                @endphp

                @foreach ($navLinks as $link)
                    <a href="{{ $link['href'] }}"
                       @if ($link['active']) aria-current="page" @endif
                       class="tap inline-flex items-center rounded-sm px-2.5 py-2 transition-colors hover:text-text sm:px-3
                              {{ $link['active'] ? 'text-signal' : 'text-muted' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="main" class="{{ $wide ? '' : 'pb-24' }}">
        {{ $slot }}
    </main>

    <footer class="border-t border-line bg-ground">
        <div class="mx-auto max-w-7xl px-6 py-12 sm:py-14">
            <div class="grid gap-10 md:grid-cols-[1.1fr_1fr_1fr]">
                <div>
                    <p class="text-sm font-semibold tracking-tight">Randomly<span class="text-signal">.</span></p>
                    <p class="mt-2 max-w-xs text-sm leading-relaxed text-muted">
                        Randomness, sourced from reality. Every result has a receipt, and every
                        result replays bit-for-bit from its URL.
                    </p>
                    <p class="mt-4 font-mono text-xs text-muted num">
                        No accounts · No keys · No paid APIs
                    </p>
                </div>

                <div>
                    <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-muted">Licences we honour</h2>
                    <ul class="mt-4 space-y-2 text-sm text-muted">
                        <li>EFF wordlists — <span class="font-mono text-xs text-text">CC BY 3.0 US</span></li>
                        <li>Wikipedia summaries — <span class="font-mono text-xs text-text">CC BY-SA 4.0</span></li>
                        <li>GBIF occurrence data — per-dataset, credited on the result</li>
                        <li>JetBrains Mono — <span class="font-mono text-xs text-text">OFL 1.1</span></li>
                    </ul>
                </div>

                <div>
                    {{-- Only what is actually wired and actually called. The catalogue of
                         sources a planned generator will need lives on /credits, in its own
                         list, marked as not yet in use. A footer that lists an API we have
                         never called is exactly the kind of small lie this site is arguing
                         against. --}}
                    <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-muted">Sources we consume</h2>
                    <ul class="mt-4 flex flex-wrap gap-x-3 gap-y-2 font-mono text-xs text-muted">
                        @foreach (['random.org', 'ANU QRNG', 'NIST', 'drand', 'mempool.space', 'USGS', 'NOAA SWPC', 'Open-Meteo', 'Open Notify', 'Wikipedia', 'GBIF'] as $source)
                            <li class="border border-line px-2 py-1 text-text/80">{{ $source }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-sm text-muted">
                        <a href="{{ route('credits') }}" class="tap inline-flex items-center text-signal hover:underline">Every source, every licence →</a>
                    </p>
                    <p class="mt-2 text-sm text-muted">Free does not mean uncredited.</p>
                </div>
            </div>

            <div class="mt-12 flex flex-col gap-2 border-t border-line pt-6 text-xs text-muted sm:flex-row sm:items-center sm:justify-between">
                <p class="font-mono num">&copy; {{ date('Y') }} Randomly</p>
                <p class="font-mono num">Rate limited to 60 requests/minute per IP. No authentication, no key, no signup.</p>
            </div>
        </div>
    </footer>
</body>
</html>
