@props(['wide' => false])

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A library of random generators, seeded by verifiable real-world entropy. Every result ships with a receipt.">
    <meta name="color-scheme" content="dark">

    <title>{{ isset($title) ? $title . ' · Randomly' : 'Randomly · Randomness, sourced from reality.' }}</title>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ground font-sans text-text antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-sm focus:bg-signal focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-ground">
        Skip to content
    </a>

    <header class="sticky top-0 z-50 border-b border-line bg-ground/80 backdrop-blur-md">
        <div class="mx-auto flex max-w-7xl items-center gap-6 px-6 py-3">
            <a href="{{ route('home') }}" class="group flex min-w-0 flex-col leading-none">
                <span class="text-[0.95rem] font-semibold tracking-tight text-text">
                    Randomly<span class="text-signal">.</span>
                </span>
                <span class="mt-1 hidden text-[0.68rem] font-normal tracking-wide text-muted sm:block">
                    Randomness, sourced from reality.
                </span>
            </a>

            <nav aria-label="Primary" class="ml-auto flex items-center gap-1 text-sm">
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
                       class="rounded-sm px-3 py-1.5 transition-colors hover:text-text
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
        <div class="mx-auto max-w-7xl px-6 py-14">
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
                        <li>Lorem Picsum / Unsplash — photographer credit per image</li>
                        <li>The Met <span class="font-mono text-xs text-text">CC0</span>, Art Institute of Chicago — public domain</li>
                    </ul>
                </div>

                <div>
                    <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-muted">Sources we consume</h2>
                    <ul class="mt-4 flex flex-wrap gap-x-3 gap-y-2 font-mono text-xs text-muted">
                        @foreach (['random.org', 'ANU QRNG', 'NIST', 'drand', 'USGS', 'NOAA', 'Open-Meteo', 'mempool.space', 'Datamuse', 'GBIF', 'Open Library'] as $source)
                            <li class="border border-line px-2 py-1 text-text/80">{{ $source }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-sm text-muted">Free does not mean uncredited.</p>
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
