@php
    /*
     * Grouped with the original indices intact, because those indices are how the
     * Alpine ticker addresses the array it polls from /api/v1/sources. Regrouping
     * without preserveKeys would silently point every card at the wrong source —
     * a page that reported the wrong narrative under the wrong name, which is the
     * worst bug this particular page could have.
     */
    $byClass = collect($sources)->groupBy('class', preserveKeys: true);
    $drawn = collect($sources)->filter(fn ($s) => $s['current'] !== null)->count();

    /*
     * What each feed is honestly worth, in its own words.
     *
     * One line per source rather than the class caveat repeated ten times: the
     * class is already stated once in the grid at the top, and "Class C" is not
     * an answer to "so how much is the ISS actually contributing?". Deliberately
     * conservative and deliberately specific — "tens of bits" is a hedge, "two or
     * three bits" is a claim someone can argue with, and being arguable is the
     * point. These count the external contribution only; the 256 bits of local
     * CSPRNG mixed in beside them are not in these numbers.
     */
    $worth = [
        'csprng' => 'The floor. Every other source on this page is mixed into this one, never used instead of it.',
        'anu-qrng' => 'Unpredictable as a matter of physics rather than of difficulty — but it still reaches us over the public internet, which is exactly why it is mixed with local bytes anyway.',
        'random-org' => 'Full-entropy bytes from a service that has been running since 1998, on a daily quota we are a guest of. We watch the quota headers and stop when told to.',
        'nist-beacon' => 'A hardware source, SHA-512 hash-chained and signed — and published to the entire world sixty seconds later. Wonderful to point at; never a secret.',
        'drand' => 'A signature no single operator in the network could have produced alone, and that every one of them can read three seconds later.',
        'bitcoin' => 'Ten minutes of the whole network\'s hashrate, and the answer is on every block explorer on Earth. The leading zeros are the proof and carry no information, so we read the hash from the far end.',
        'seismic' => 'Perhaps 20–40 bits of genuine surprise an hour, and it is in the tail of the feed rather than in the headline quake.',
        'space-weather' => 'One number on a 28-step scale, often the same number for hours. Under five bits, at best.',
        'atmosphere' => 'A temperature and a wind speed to one decimal place — a handful of bits. The choice of city is ours, and comes from the CSPRNG.',
        'iss' => 'A published orbit puts the station within metres; anyone with the TLE can compute this for next Tuesday. What is left is the jitter in when we asked: two or three bits, and we are rounding up.',
    ];
@endphp

{{-- The count is read from the pool rather than typed, like every other number
     on this site. A hardcoded "ten" in a share description is exactly the kind of
     number that is right the day it is written and wrong a month later. --}}
<x-layout :og="[
    'title' => 'Entropy · Randomly',
    'description' => count($sources) . ' live entropy sources, classified A to C, each with what it is honestly worth.',
]">
    <x-slot:title>Entropy</x-slot:title>

    <div x-data="entropyTicker(@js($sources))">

        <section class="border-b border-line">
            <div class="mx-auto max-w-7xl px-6 pb-12 pt-16">
                <h1 class="text-4xl font-semibold tracking-tighter sm:text-5xl">Where it comes from.</h1>

                <p class="mt-6 max-w-3xl text-lg leading-relaxed text-muted">
                    An earthquake contributes maybe <span class="font-mono text-text num">30</span> bits of
                    genuine surprise. We mix it with <span class="font-mono text-text num">256</span> bits
                    from your OS. Both facts are on the receipt.
                </p>

                <p class="mt-4 max-w-3xl text-sm leading-relaxed text-muted">
                    {{ count($sources) }} sources are wired, and none of them is trusted alone. Each has a
                    timeout and a circuit breaker; when one misses, the pool falls back to the kernel and the
                    receipt says <span class="font-mono text-warn">degraded</span> rather than pretending
                    otherwise. This dashboard reads the cache only — opening it never makes an outbound
                    request, which is why a source shows
                    <span class="font-mono text-text">—</span> until something has actually drawn from it.
                </p>

                {{-- Classes come from the enum the pool itself uses, so the wording here
                     is the same wording that goes onto a receipt. A class with nothing
                     wired says so rather than borrowing another class's description. --}}
                <ul class="mt-10 grid gap-px border border-line bg-line sm:grid-cols-3">
                    @foreach (\App\Random\Entropy\EntropyClass::cases() as $class)
                        @php $members = $byClass->get($class->value, collect()); @endphp
                        <li class="bg-ground p-5 {{ $members->isEmpty() ? 'opacity-60' : '' }}">
                            <p class="flex items-baseline justify-between gap-2 font-mono text-xs">
                                <span class="{{ $class->standsAlone() ? 'text-signal' : 'text-muted' }}">
                                    Class {{ $class->value }} · {{ $class->label() }}
                                </span>
                                <span class="text-muted num">
                                    {{ $members->isEmpty() ? 'none wired yet' : $members->count().' wired' }}
                                </span>
                            </p>
                            <p class="mt-2 text-sm leading-relaxed text-muted">
                                {{ $class->caveat() ?? 'Full-entropy bytes, safe to stand alone — and the floor every other class rests on.' }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-6 py-14">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-xl font-medium tracking-tight">Sources</h2>
                <p class="font-mono text-xs text-muted num">
                    {{ $drawn }} of {{ count($sources) }} currently hold cached material · refreshed live every 5s
                </p>
            </div>

            {{-- Grouped by class, strongest first, so the page reads top to bottom as
                 "trust this" down to "enjoy this". Rows are server-rendered from the
                 pool's own status(), then kept current by polling GET /api/v1/sources —
                 the same endpoint, cache only. --}}
            @foreach (\App\Random\Entropy\EntropyClass::cases() as $class)
                @php $group = $byClass->get($class->value, collect()); @endphp
                @continue($group->isEmpty())

                <div class="mt-10">
                    {{-- The class's meaning is stated once, in the grid above. Repeating it
                         over every group would push the sources themselves below the fold. --}}
                    <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1 border-b border-line pb-3">
                        <h3 class="font-mono text-sm {{ $class->standsAlone() ? 'text-signal' : 'text-text' }}">
                            Class {{ $class->value }} · {{ $class->label() }}
                        </h3>
                        <p class="font-mono text-xs text-muted num">
                            {{ $class->standsAlone() ? 'safe alone' : 'never used alone' }}
                        </p>
                        <p class="ml-auto font-mono text-xs text-muted num">{{ $group->count() }} wired</p>
                    </div>

                    <ul class="mt-px grid gap-px border border-line bg-line lg:grid-cols-2">
                        @foreach ($group as $i => $source)
                            <li class="bg-ground p-6">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <h3 class="flex items-center gap-2.5 text-base font-medium tracking-tight">
                                            <span class="animate-blip inline-block size-1.5 shrink-0 rounded-full {{ $source['status'] === 'up' ? 'bg-signal' : 'bg-warn' }}"
                                                  :class="isUp({{ $i }}) ? 'bg-signal' : 'bg-warn'"
                                                  aria-hidden="true"></span>
                                            {{ $source['label'] }}
                                            <span class="sr-only">— status {{ $source['status'] }}</span>
                                        </h3>
                                        <p class="mt-2 text-sm leading-relaxed text-muted">{{ $source['origin'] }}</p>
                                    </div>

                                    <span class="shrink-0 border px-2 py-0.5 font-mono text-[0.65rem] {{ $source['class'] === 'A' ? 'border-signal/40 text-signal' : 'border-line text-muted' }}">
                                        Class {{ $source['class'] }}
                                    </span>
                                </div>

                                @if ($source['key'] === 'csprng')
                                    {{-- Nothing is ever cached here, so there is nothing to poll. --}}
                                    <p class="mt-5 border border-line bg-surface/50 px-3 py-2.5 font-mono text-xs text-muted num">
                                        drawn fresh per request · never cached
                                    </p>
                                @else
                                    <p class="mt-5 border border-line bg-surface/50 px-3 py-2.5 font-mono text-xs num
                                              {{ $source['current'] === null ? 'text-muted' : 'text-signal' }}"
                                       :class="current({{ $i }}) === '—' ? 'text-muted' : 'text-signal'"
                                       x-text="current({{ $i }})">{{ $source['current'] ?? '—' }}</p>
                                @endif

                                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 font-mono text-[0.68rem] text-muted num">
                                    <span>
                                        refresh
                                        <span class="text-text">{{ $source['refresh_seconds'] === 0 ? 'instant' : $source['refresh_seconds'].'s' }}</span>
                                    </span>
                                    <span>
                                        observed
                                        <span class="text-text">{{ $source['observed_at'] ?? '—' }}</span>
                                    </span>
                                    @if ($source['failures'] > 0)
                                        <span class="text-warn">{{ $source['failures'] }} recent failures</span>
                                    @endif

                                    @if ($source['proof_url'])
                                        <a href="{{ $source['proof_url'] }}" target="_blank" rel="noopener noreferrer"
                                           class="ml-auto text-signal hover:underline">proof ↗</a>
                                    @else
                                        <span class="ml-auto">{{ $source['class'] === 'A' && $source['key'] === 'csprng' ? 'no third party involved' : 'no per-draw proof' }}</span>
                                    @endif
                                </div>

                                {{-- The class caveat is stated once in the grid at the top of the
                                     page, so a card spends its last line on what *this* feed is
                                     worth instead. Under-claiming on purpose: these are the external
                                     contribution only, and the 256 bits of CSPRNG mixed in beside
                                     them are not counted in them. --}}
                                @if (isset($worth[$source['key']]))
                                    <p class="mt-4 border-t border-line pt-3 text-xs leading-relaxed text-muted">{{ $worth[$source['key']] }}</p>
                                @elseif ($source['caveat'])
                                    <p class="mt-4 border-t border-line pt-3 text-xs leading-relaxed text-muted">{{ $source['caveat'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>

        <section class="border-t border-line bg-surface/30">
            <div class="mx-auto grid max-w-7xl gap-12 px-6 py-16 lg:grid-cols-2">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">Freshness without hammering</h2>
                    <p class="mt-4 text-sm leading-relaxed text-muted">
                        Collected material is cached for as long as it stays fresh at the origin — a NIST
                        pulse lives 60 seconds, and re-fetching it six times a minute would be rude and
                        would tell us nothing new. A cache hit must never mean a repeated seed, so fresh
                        local bytes, the nanosecond clock and a monotonic counter are folded in every time
                        before HKDF runs.
                    </p>

                    <pre class="mt-6 overflow-x-auto border border-line bg-ground p-5 font-mono text-[0.72rem] leading-relaxed text-text"><code>ikm  = material ‖ random_bytes(32) ‖ hrtime() ‖ counter
seed = hash_hkdf('sha256', ikm, 16, 'randomly.seed', salt)</code></pre>

                    <p class="mt-6 text-sm leading-relaxed text-muted">
                        The receipt credits the interesting source while the pool guarantees the
                        cryptographic floor. Both are true, and both are printed — including
                        <span class="font-mono text-xs text-text">cached</span>, so a beacon that answered
                        in 0 ms is never passed off as a beacon that is simply fast.
                    </p>
                </div>

                <div>
                    <h2 class="text-2xl font-semibold tracking-tighter sm:text-3xl">What we do not claim</h2>

                    <ul class="mt-5 divide-y divide-line border-y border-line text-sm">
                        <li class="flex gap-3 py-3">
                            <span class="mt-1.5 size-1 shrink-0 rounded-full bg-muted" aria-hidden="true"></span>
                            <span class="leading-relaxed text-muted">
                                A class B beacon is public. Anyone can look up the pulse or round afterwards,
                                so it is never the only thing in a seed — and it is never presented as a secret.
                            </span>
                        </li>
                        <li class="flex gap-3 py-3">
                            <span class="mt-1.5 size-1 shrink-0 rounded-full bg-muted" aria-hidden="true"></span>
                            <span class="leading-relaxed text-muted">
                                Observational sources are flavour. They make a better sentence than they make
                                a cipher, and the receipt says which one you got.
                            </span>
                        </li>
                        <li class="flex gap-3 py-3">
                            <span class="mt-1.5 size-1 shrink-0 rounded-full bg-muted" aria-hidden="true"></span>
                            <span class="leading-relaxed text-muted">
                                Von Neumann debiasing for observational feeds is specified but not yet built.
                                It is not running, so this page does not animate it.
                            </span>
                        </li>
                        <li class="flex gap-3 py-3">
                            <span class="mt-1.5 size-1 shrink-0 rounded-full bg-muted" aria-hidden="true"></span>
                            <span class="leading-relaxed text-muted">
                                Latency is not charted here, because the pool records it per draw on the
                                receipt rather than as a time series. An invented sparkline would be
                                decoration pretending to be instrumentation.
                            </span>
                        </li>
                    </ul>

                    <p class="mt-6 font-mono text-[0.68rem] leading-relaxed text-muted">
                        Everything on this page is also at
                        <a href="{{ url('/api/v1/sources') }}" class="text-signal hover:underline">/api/v1/sources</a>.
                    </p>
                </div>
            </div>
        </section>
    </div>
</x-layout>
