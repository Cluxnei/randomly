@php
    use App\Random\Generators\Renderer;

    $params = $generation->params->all();
    $receipt = $generation->receipt;
    $meta = $generation->result->meta;
    $permalink = $generation->permalink();

    $metaLabels = [
        'entropy_in_bytes' => 'entropy in',
        'stream_bytes_used' => 'stream used',
        'entropy_out_bits' => 'entropy out',
        'rejections' => 'rejections',
        'duration_us' => 'duration',
        'alphabet_size' => 'alphabet',
        'crack_time' => 'crack time',
        'crack_assumption' => 'assumption',
    ];

    // The same instrumentation the API reports, in the same order the JS renders it.
    $metaRows = collect([
        'entropy_in_bytes' => \App\Random\Entropy\Seed::BYTES,
        'stream_bytes_used' => $meta['stream_bytes_used'] ?? null,
        'entropy_out_bits' => $meta['entropy_out_bits'] ?? null,
        'rejections' => $meta['rejections'] ?? null,
        'duration_us' => $generation->durationUs,
    ])->merge(collect($meta)->except(['stream_bytes_used', 'entropy_out_bits', 'rejections']))
      // `note` is a sentence the generator wants shown beside its result, not a
      // measurement. It renders under the canvas instead; a paragraph in the
      // instrumentation strip reads as a number that got away.
      ->except(['note', 'latex'])
      ->reject(fn ($v) => $v === null)
      ->map(function ($value, $key) use ($metaLabels) {
          $display = match (true) {
              is_bool($value) => $value ? 'yes' : 'no',
              $key === 'duration_us' => number_format((float) $value).' µs',
              $key === 'entropy_in_bytes', $key === 'stream_bytes_used' => number_format((float) $value).' bytes',
              $key === 'entropy_out_bits' => number_format((float) $value, 1).' bits',
              $key === 'alphabet_size' => number_format((float) $value).' chars',
              is_int($value) || is_float($value) => number_format((float) $value, is_float($value) && floor($value) != $value ? 2 : 0),
              is_array($value) => implode(' ', $value),
              default => (string) $value,
          };

          return [
              'label' => $metaLabels[$key] ?? str_replace('_', ' ', $key),
              'value' => $display,
              // A palette is the one measurement here that is better seen than
              // read. Every visual generator reports one, so it is worth the
              // three lines rather than printing seven hex codes at a reader.
              'swatches' => $key === 'palette' && is_array($value) ? array_values($value) : null,
          ];
      });

    // What was *asked for* (auto, unless the URL pinned a source) — as opposed to
    // what actually answered, which is the receipt's job to report.
    $selectedSource = (string) request()->query('source', 'auto');

    $isCanvas = $generator->renderer() === Renderer::Canvas;

    // Audio generators ship a score — events, envelopes and synthesis
    // parameters — on exactly the same terms a canvas generator ships a spec.
    // The browser makes the samples from it and the render key.
    $isAudio = $generator->renderer() === Renderer::Audio;

    $config = [
        'key' => $generator->key(),
        'version' => $generator->version(),
        'sensitive' => $generator->isSensitive(),
        'renderer' => $generator->renderer()->value,
        // Canvas generators hand the browser a spec and a 32-byte key rather
        // than pixels. Shipping them in the initial payload means the first
        // drawing happens without a round trip — the page arrives able to draw.
        'spec' => $isCanvas || $isAudio ? $generation->result->value : null,
        'render_key' => $generation->renderKey(),
        'params' => (object) $params,
        'source' => $selectedSource,
        'display' => $generation->result->display,
        'meta' => (object) [...$meta, 'entropy_in_bytes' => \App\Random\Entropy\Seed::BYTES, 'duration_us' => $generation->durationUs],
        'receipt' => (object) $receipt->toArray(),
        'token' => $generator->isSensitive() ? null : $generation->seed->token(),
        'permalink' => $permalink,
        'replayed' => $generation->replayed,
    ];
@endphp

<x-layout>
    <x-slot:title>{{ $generator->name() }}</x-slot:title>

    <div x-data="studio(@js($config))" @keydown.window="shortcut($event)">

        <div class="border-b border-line">
            <div class="mx-auto flex max-w-7xl flex-wrap items-end justify-between gap-4 px-6 pb-6 pt-10">
                <div>
                    <p class="flex items-center gap-2 font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">
                        <a href="{{ route('library') }}" class="hover:text-text">Library</a>
                        <span aria-hidden="true">/</span>
                        <span>{{ $generator->module()->label() }}</span>
                    </p>
                    <h1 class="mt-3 text-4xl font-semibold tracking-tighter sm:text-5xl">{{ $generator->name() }}</h1>
                    <p class="mt-3 max-w-xl text-muted">{{ $generator->tagline() }}</p>
                </div>

                <p class="font-mono text-xs text-muted num">
                    {{ $generator->key() }} · v{{ $generator->version() }} · renderer {{ $generator->renderer()->value }}
                </p>
            </div>
        </div>

        <div class="mx-auto grid max-w-7xl gap-px border-line bg-line lg:grid-cols-[minmax(0,1fr)_22rem] lg:border-x">

            {{-- ── Result, dominant ─────────────────────────────────────────── --}}
            <div class="bg-ground">
                <section aria-labelledby="result-heading" class="relative border-b border-line">
                    <h2 id="result-heading" class="sr-only">Result</h2>

                    <div x-show="replayed" x-cloak
                         class="flex items-center gap-2 border-b border-line bg-surface/50 px-6 py-2 font-mono text-[0.68rem] text-muted"
                         @if (! $generation->replayed) style="display: none" @endif>
                        Replayed from a permalink — this is the original randomness, recomputed.
                    </div>

                    <div class="flex min-h-[18rem] items-center justify-center px-6 py-14 sm:min-h-[22rem]">
                        @switch ($generator->renderer())
                            @case (Renderer::Text)
                                <pre x-ref="result" x-text="display"
                                     class="animate-rise max-w-full overflow-x-auto text-center font-mono text-2xl leading-relaxed text-text num sm:text-4xl"
                                >{{ $generation->result->display }}</pre>
                                @break

                            @case (Renderer::Canvas)
                                {{-- The browser re-derives every pixel from the render key, so
                                     dragging a slider redraws locally and never round-trips.
                                     The backing store is the spec's own pixel size and CSS does
                                     the fitting, which is why an export is full resolution
                                     however small the canvas is on screen. --}}
                                <div class="w-full" data-canvas-stage>
                                    <div class="relative mx-auto w-fit max-w-full">
                                        <canvas x-ref="canvas"
                                                width="{{ $generation->result->value['width'] }}"
                                                height="{{ $generation->result->value['height'] }}"
                                                role="img"
                                                :aria-label="display"
                                                aria-label="{{ $generation->result->display }}"
                                                class="block h-auto max-h-[70vh] w-auto max-w-full border border-line bg-surface/40"></canvas>

                                        <div x-show="drawing" x-cloak style="display: none"
                                             class="absolute inset-0 flex items-center justify-center bg-ground/75 font-mono text-[0.7rem] text-muted">
                                            <span class="flex items-center gap-2">
                                                <span class="animate-blip inline-block size-1.5 rounded-full bg-signal" aria-hidden="true"></span>
                                                Drawing <span class="text-text num" x-text="dimensions"></span> in your browser…
                                            </span>
                                        </div>
                                    </div>

                                    {{-- What the canvas is a picture of. For a text generator this
                                         string is the result; here the picture is the result, so the
                                         description belongs underneath it rather than nowhere. --}}
                                    <p x-text="display"
                                       class="mt-4 text-center font-mono text-[0.7rem] leading-relaxed text-muted num"
                                    >{{ $generation->result->display }}</p>

                                    <p x-show="note" x-cloak x-text="note"
                                       class="mx-auto mt-3 max-w-xl text-center text-sm leading-relaxed text-muted"
                                       @if (! isset($meta['note'])) style="display: none" @endif>{{ $meta['note'] ?? '' }}</p>
                                </div>

                                {{-- Without JavaScript the canvas above is an empty rectangle and
                                     nothing on the page explains why. The stage is hidden and
                                     this takes its place, rather than leaving a blank box that
                                     reads as a bug. --}}
                                <noscript>
                                    <style>[data-canvas-stage] { display: none; }</style>
                                    <div class="mx-auto max-w-xl border border-line px-6 py-8 text-center">
                                        <p class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">Drawn in the browser</p>
                                        <p class="mt-3 text-sm leading-relaxed text-muted">
                                            This generator does not send you an image. The server sends a
                                            short description of one — the parameters below, plus a 32-byte
                                            key — and the drawing is done here, from the same byte stream the
                                            server would have used. That needs JavaScript, so with it
                                            switched off there is nothing to show.
                                        </p>
                                        <p class="mt-3 text-sm leading-relaxed text-muted">
                                            The description itself is still public:
                                            <a href="/api/v1/g/{{ $generator->key() }}" class="font-mono text-xs text-signal hover:underline">/api/v1/g/{{ $generator->key() }}</a>
                                            returns it, and the maths is in
                                            <span class="font-mono text-xs text-text">resources/js</span>.
                                        </p>
                                        <p class="mt-4 font-mono text-[0.68rem] text-muted num">{{ $generation->result->display }}</p>
                                    </div>
                                </noscript>
                                @break

                            @case (Renderer::Audio)
                                {{-- The samples are made here, in plain JavaScript, from the
                                     score above and the render key — never on the server, and
                                     never by a Web Audio node graph. Web Audio only plays the
                                     finished buffer. Nothing ever autoplays: docs/09 §9, and
                                     the browser would refuse anyway. --}}
                                <div class="w-full" data-audio-stage>
                                    <div class="relative mx-auto w-full max-w-3xl">
                                        <canvas x-ref="waveform"
                                                role="img"
                                                :aria-label="display"
                                                aria-label="{{ $generation->result->display }}"
                                                class="block h-40 w-full border border-line bg-surface/40"></canvas>

                                        {{-- The playhead. One element moved with a percentage,
                                             rather than the waveform being redrawn every frame
                                             for the sake of a moving line. --}}
                                        <span x-show="playing" x-cloak style="display: none"
                                              :style="`left: ${Math.min(100, progress * 100)}%`"
                                              class="pointer-events-none absolute inset-y-0 w-px bg-signal"
                                              aria-hidden="true"></span>

                                        <div x-show="drawing" x-cloak style="display: none"
                                             class="absolute inset-0 flex items-center justify-center bg-ground/75 font-mono text-[0.7rem] text-muted">
                                            <span class="flex items-center gap-2">
                                                <span class="animate-blip inline-block size-1.5 rounded-full bg-signal" aria-hidden="true"></span>
                                                Synthesising in your browser…
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mx-auto mt-4 flex w-full max-w-3xl items-center gap-4">
                                        <button type="button" @click="togglePlay()" :disabled="drawing || audio === null"
                                                class="flex items-center gap-2 border border-line px-4 py-2 font-mono text-xs text-text transition-colors hover:border-signal hover:text-signal disabled:opacity-50">
                                            <span aria-hidden="true" x-text="playing ? '■' : '▶'">▶</span>
                                            <span x-text="playing ? 'Stop' : 'Play'">Play</span>
                                        </button>

                                        <div class="h-px flex-1 bg-line" role="presentation">
                                            <div class="h-px bg-signal" :style="`width: ${Math.min(100, progress * 100)}%`"></div>
                                        </div>

                                        <p class="shrink-0 font-mono text-[0.7rem] text-muted num">
                                            <span x-text="elapsed">0:00</span> / <span x-text="total">0:00</span>
                                        </p>
                                    </div>

                                    <p x-text="display"
                                       class="mt-4 text-center font-mono text-[0.7rem] leading-relaxed text-muted num"
                                    >{{ $generation->result->display }}</p>

                                    <p x-show="note" x-cloak x-text="note"
                                       class="mx-auto mt-3 max-w-xl text-center text-sm leading-relaxed text-muted"
                                       @if (! isset($meta['note'])) style="display: none" @endif>{{ $meta['note'] ?? '' }}</p>
                                </div>

                                {{-- Same reasoning as the canvas noscript: with JavaScript off
                                     there is no sound to be had, and an empty box that does
                                     nothing when clicked reads as a bug rather than as a
                                     design decision. --}}
                                <noscript>
                                    <style>[data-audio-stage] { display: none; }</style>
                                    <div class="mx-auto max-w-xl border border-line px-6 py-8 text-center">
                                        <p class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">Synthesised in the browser</p>
                                        <p class="mt-3 text-sm leading-relaxed text-muted">
                                            No audio file crosses the wire. The server sends a score — the
                                            events, envelopes and synthesis parameters below, plus a
                                            32-byte key — and the samples are made here, from the same byte
                                            stream the server would have used. A four-minute piece is about
                                            two kilobytes of JSON. That needs JavaScript, so with it
                                            switched off there is nothing to play.
                                        </p>
                                        <p class="mt-3 text-sm leading-relaxed text-muted">
                                            The score itself is still public:
                                            <a href="/api/v1/g/{{ $generator->key() }}" class="font-mono text-xs text-signal hover:underline">/api/v1/g/{{ $generator->key() }}</a>
                                            returns it, and the synthesis is in
                                            <span class="font-mono text-xs text-text">resources/js/audio</span>.
                                        </p>
                                        <p class="mt-4 font-mono text-[0.68rem] text-muted num">{{ $generation->result->display }}</p>
                                    </div>
                                </noscript>
                                @break

                            @case (Renderer::Math)
                                {{-- Every problem arrives twice: plain in `display`, TeX in
                                     `meta.latex`. x-effect redraws whenever a new generation
                                     lands, for the same reason the canvas repaints — the
                                     payload changed, not the DOM.

                                     The answers are rendered with the problems and hidden,
                                     rather than fetched on reveal: half of these generators
                                     are questions, and one of them is a guessing game that
                                     is over the moment the answer is on screen. --}}
                                <div x-data="mathResult()" x-effect="render(meta)" class="w-full">
                                    <div x-ref="problems" class="mx-auto max-w-2xl"></div>

                                    <div class="mt-6 flex justify-center">
                                        <button type="button" @click="toggle()" x-show="rows > 0" x-cloak
                                                class="border border-line px-3 py-1.5 font-mono text-[0.68rem] text-muted transition-colors hover:border-signal hover:text-signal">
                                            <span x-text="revealed ? 'Hide answers' : 'Show answers'">Show answers</span>
                                        </button>
                                    </div>

                                    {{-- Without JavaScript there is no KaTeX, and the plain form
                                         is the whole result rather than a description of it —
                                         so it simply stands in, unchanged. --}}
                                    <noscript>
                                        <pre class="mx-auto max-w-2xl overflow-x-auto font-mono text-sm leading-relaxed text-text num">{{ $generation->result->display }}</pre>
                                    </noscript>
                                </div>
                                @break

                            @default
                                {{-- SLOT: chart and gallery renderers. --}}
                                <pre x-ref="result" x-text="display"
                                     class="animate-rise max-w-full overflow-x-auto text-center font-mono text-xl text-text num"
                                >{{ $generation->result->display }}</pre>
                        @endswitch
                    </div>

                    <p x-show="error" x-cloak x-text="error" style="display: none"
                       role="alert"
                       class="border-t border-line bg-warn/10 px-6 py-3 font-mono text-xs text-warn"></p>

                    <div x-show="busy" x-cloak style="display: none"
                         class="pointer-events-none absolute inset-x-0 bottom-0 h-px overflow-hidden bg-line" aria-hidden="true">
                        <span class="animate-sweep block h-px w-1/3 bg-signal"></span>
                    </div>
                </section>

                {{-- ── Meta strip: the instrumentation is the decoration ─────── --}}
                <section aria-labelledby="meta-heading" class="border-b border-line">
                    <h2 id="meta-heading" class="sr-only">Measurements</h2>

                    {{-- The page is complete without JavaScript; Alpine takes the strip
                         over the moment it boots, so only one of these ever renders. --}}
                    <noscript>
                    <dl class="flex flex-wrap gap-x-8 gap-y-2 px-6 py-3 font-mono text-[0.7rem]">
                        @foreach ($metaRows as $row)
                            <div class="flex gap-2">
                                <dt class="text-muted">{{ $row['label'] }}</dt>
                                @if ($row['swatches'])
                                    <dd class="flex items-center gap-px">
                                        @foreach ($row['swatches'] as $hex)
                                            <span class="size-3 border border-line/60" style="background: {{ $hex }}" title="{{ $hex }}"></span>
                                        @endforeach
                                    </dd>
                                @else
                                    <dd class="text-text num">{{ $row['value'] }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                    </noscript>

                    <dl class="flex flex-wrap gap-x-8 gap-y-2 px-6 py-3 font-mono text-[0.7rem]">
                        <template x-for="row in metaRows" :key="row.key">
                            <div class="flex gap-2">
                                <dt class="text-muted" x-text="row.label"></dt>
                                <dd x-show="row.swatches" class="flex items-center gap-px">
                                    <template x-for="hex in (row.swatches ?? [])" :key="hex">
                                        <span class="size-3 border border-line/60" :style="{ background: hex }" :title="hex"></span>
                                    </template>
                                </dd>
                                <dd x-show="! row.swatches" class="text-text num" x-text="row.value"></dd>
                            </div>
                        </template>
                    </dl>
                </section>

                {{-- ── Receipt ──────────────────────────────────────────────── --}}
                <section aria-labelledby="receipt-heading" class="px-6 py-7">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="receipt-heading" class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">
                            Seed receipt
                        </h2>

                        <div class="flex flex-wrap items-center gap-2 font-mono text-[0.65rem]">
                            <span :class="receipt.class === 'A' ? 'border-signal/40 text-signal' : 'border-line text-muted'"
                                  class="border px-2 py-0.5 {{ $receipt->class->value === 'A' ? 'border-signal/40 text-signal' : 'border-line text-muted' }}">
                                Class <span x-text="receipt.class">{{ $receipt->class->value }}</span>
                                · <span x-text="receipt.class_label">{{ $receipt->class->label() }}</span>
                            </span>

                            <span x-show="receipt.cached" x-cloak class="border border-line px-2 py-0.5 text-muted"
                                  @if (! $receipt->cached) style="display: none" @endif>
                                cached
                            </span>

                            <span x-show="receipt.degraded" x-cloak class="border border-warn/50 px-2 py-0.5 text-warn"
                                  @if (! $receipt->degraded) style="display: none" @endif>
                                degraded
                            </span>
                        </div>
                    </div>

                    <p class="mt-4 max-w-2xl text-lg leading-relaxed text-text" x-text="receipt.narrative">{{ $receipt->narrative }}</p>

                    <p x-show="receipt.caveat" x-cloak class="mt-3 max-w-2xl text-sm leading-relaxed text-muted"
                       x-text="receipt.caveat"
                       @if ($receipt->class->caveat() === null) style="display: none" @endif>{{ $receipt->class->caveat() }}</p>

                    <dl class="mt-6 grid gap-px border border-line bg-line font-mono text-xs sm:grid-cols-2">
                        <div class="flex gap-3 bg-ground px-4 py-2.5">
                            <dt class="w-24 shrink-0 text-muted">source</dt>
                            <dd class="text-text" x-text="receipt.source_label">{{ $receipt->sourceLabel }}</dd>
                        </div>
                        <div class="flex gap-3 bg-ground px-4 py-2.5">
                            <dt class="w-24 shrink-0 text-muted">observed</dt>
                            <dd class="text-text num" x-text="receipt.observed_at">{{ $receipt->observedAt->format(DATE_ATOM) }}</dd>
                        </div>
                        <div class="flex gap-3 bg-ground px-4 py-2.5">
                            <dt class="w-24 shrink-0 text-muted">latency</dt>
                            <dd class="text-text num"><span x-text="receipt.latency_ms">{{ $receipt->latencyMs }}</span>ms</dd>
                        </div>
                        <div class="flex gap-3 bg-ground px-4 py-2.5">
                            <dt class="w-24 shrink-0 text-muted">mixed with</dt>
                            <dd class="text-text"
                                x-text="receipt.mixed_with_csprng ? 'csprng — always' : 'nothing — this source stands alone'"
                            >{{ $receipt->mixedWithCsprng ? 'csprng — always' : 'nothing — this source stands alone' }}</dd>
                        </div>
                    </dl>

                    <p class="mt-4">
                        <a :href="receipt.proof_url" x-show="receipt.proof_url" x-cloak
                           target="_blank" rel="noopener noreferrer"
                           href="{{ $receipt->proofUrl }}"
                           @if ($receipt->proofUrl === null) style="display: none" @endif
                           class="font-mono text-xs text-signal hover:underline">
                            <span x-text="receipt.proof_url">{{ $receipt->proofUrl }}</span> ↗
                        </a>
                        <span x-show="! receipt.proof_url" x-cloak class="font-mono text-xs text-muted"
                              @if ($receipt->proofUrl !== null) style="display: none" @endif>
                            This source publishes no per-draw proof URL.
                        </span>
                    </p>

                    {{-- ── Permalink, or an honest reason there isn't one ────── --}}
                    <div class="mt-8 border-t border-line pt-6">
                        @if ($generator->isSensitive())
                            <h3 class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">No permalink</h3>
                            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted">
                                This generator makes secrets, so it is never given a shareable link.
                                A password with a replayable URL is not a password — anyone holding
                                the link could recompute it byte for byte, forever. The seed token is
                                discarded the moment the response is written; nothing here is stored,
                                logged or recoverable.
                            </p>
                            <p class="mt-3 font-mono text-[0.68rem] text-muted num">
                                seed · withheld by design · {{ $generator->key() }} is marked sensitive
                            </p>
                        @else
                            <h3 class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">Permalink</h3>
                            <p class="mt-3 text-sm text-muted">
                                The token <em>is</em> the seed, so this link recomputes the result rather
                                than looking it up. No database, and it works for as long as
                                <span class="font-mono text-xs text-text">v{{ $generator->version() }}</span> does.
                            </p>
                            <div class="mt-4 flex flex-wrap items-stretch gap-2">
                                <input type="text" readonly
                                       aria-label="Permalink"
                                       :value="permalink" value="{{ $permalink }}"
                                       class="min-w-0 flex-1 border border-line bg-surface/50 px-3 py-2 font-mono text-xs text-text focus:border-signal focus:outline-none">
                                <button type="button" @click="copy(permalink)"
                                        class="border border-line px-4 py-2 font-mono text-xs text-text transition-colors hover:border-signal hover:text-signal">
                                    <span x-show="! copied">Copy link</span>
                                    <span x-show="copied" x-cloak class="text-signal">Copied</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </section>
            </div>

            {{-- ── Controls ─────────────────────────────────────────────────── --}}
            <aside class="bg-ground" aria-label="Controls">
                <div class="lg:sticky lg:top-20">
                    <h2 class="border-b border-line px-5 py-3 font-mono text-[0.68rem] uppercase tracking-[0.18em] text-muted">
                        Controls
                        <span class="ml-1 text-line">·</span>
                        <span class="text-muted">from schema()</span>
                    </h2>

                    <div class="divide-y divide-line border-b border-line">
                        {{-- Every control below is rendered from the generator's own
                             declared parameters. No generator writes UI. --}}
                        @foreach ($generator->schema()->params() as $param)
                            <x-control :param="$param" :value="$params[$param->name] ?? null" />
                        @endforeach
                    </div>

                    <div class="border-b border-line px-5 py-4">
                        <label for="source" class="text-sm text-text">Entropy source</label>
                        <select id="source" x-model="source" @change="generate()"
                                class="mt-3 w-full border border-line bg-surface/60 px-3 py-2 font-mono text-xs text-text focus:border-signal focus:outline-none">
                            <option value="auto" @selected($selectedSource === 'auto')>auto · rotate the healthy ones</option>
                            @foreach ($sources as $source)
                                <option value="{{ $source['key'] }}"
                                        @selected($selectedSource === $source['key'])
                                        @disabled($source['status'] !== 'up')>
                                    {{ $source['label'] }} · class {{ $source['class'] }}{{ $source['status'] !== 'up' ? ' · down' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-2 text-xs leading-relaxed text-muted">
                            Picking a source changes the story on the receipt, never the quality:
                            anything below class A is mixed with the OS CSPRNG before it reaches you.
                            This result actually came from
                            <span class="font-mono text-[0.7rem] text-text" x-text="receipt.source_label">{{ $receipt->sourceLabel }}</span>.
                        </p>
                    </div>

                    <div class="px-5 py-5">
                        {{-- Two buttons, because they do genuinely different things. That
                             difference is the seed concept, taught without a paragraph. --}}
                        <button type="button" @click="generate()" :disabled="busy"
                                class="flex w-full items-center justify-between gap-3 bg-signal px-4 py-3 text-left text-sm font-medium text-ground transition-opacity hover:opacity-90 disabled:opacity-50">
                            <span>
                                Generate
                                <span class="mt-0.5 block font-mono text-[0.65rem] font-normal opacity-70">new entropy · new receipt</span>
                            </span>
                            <kbd class="border border-ground/30 px-1.5 py-0.5 font-mono text-[0.62rem]">Space</kbd>
                        </button>

                        @if ($generator->isSensitive())
                            <p class="mt-3 border border-line px-4 py-3 text-xs leading-relaxed text-muted">
                                There is no same-seed re-render here. Recomputing a secret from a kept
                                seed is exactly the thing this generator refuses to make possible, so
                                every change of parameters draws fresh entropy.
                            </p>
                        @else
                            <button type="button" @click="rerender()" :disabled="busy"
                                    class="mt-2 flex w-full items-center justify-between gap-3 border border-line px-4 py-3 text-left text-sm text-text transition-colors hover:border-signal disabled:opacity-50">
                                <span>
                                    Re-render
                                    <span class="mt-0.5 block font-mono text-[0.65rem] text-muted">same seed · new parameters</span>
                                </span>
                                <span x-show="dirty" x-cloak class="size-1.5 shrink-0 rounded-full bg-signal" aria-hidden="true"></span>
                            </button>
                        @endif

                        <div class="mt-4 flex items-center justify-between gap-3 font-mono text-[0.65rem] text-muted">
                            <span>seed token</span>
                            @if ($generator->isSensitive())
                                <span class="text-muted">withheld</span>
                            @else
                                <span class="text-text num" x-text="token">{{ $generation->seed->token() }}</span>
                            @endif
                        </div>

                        <p class="mt-2 font-mono text-[0.62rem] leading-relaxed text-muted">
                            @if ($generator->isSensitive())
                                Nothing to keep: no token is issued for a generator that makes secrets.
                            @else
                                Re-render keeps this token. Generate replaces it. That is the whole idea.
                            @endif
                        </p>

                        <button type="button" @click="copy()"
                                class="mt-4 flex w-full items-center justify-between gap-3 border border-line px-4 py-2.5 text-left text-xs text-text transition-colors hover:border-signal hover:text-signal">
                            <span x-text="copied ? 'Copied to clipboard' : 'Copy result'">Copy result</span>
                            <kbd class="border border-line px-1.5 py-0.5 font-mono text-[0.62rem] text-muted">C</kbd>
                        </button>

                        @if ($generator->renderer() === Renderer::Canvas)
                            <button type="button" @click="downloadPng()" :disabled="drawing || rendered === null"
                                    class="mt-2 flex w-full items-center justify-between gap-3 border border-line px-4 py-2.5 text-left text-xs text-text transition-colors hover:border-signal hover:text-signal disabled:opacity-50">
                                <span>
                                    Download PNG
                                    <span class="mt-0.5 block font-mono text-[0.62rem] text-muted num" x-text="dimensions"></span>
                                </span>
                                <span x-show="exported" x-cloak class="shrink-0 font-mono text-[0.62rem] text-signal num" x-text="exported"></span>
                            </button>

                            <p class="mt-2 font-mono text-[0.62rem] leading-relaxed text-muted">
                                Burned into the corner before the file is written, so the image keeps
                                its provenance wherever it ends up:
                            </p>
                            <p class="mt-1.5 break-all border-l border-line pl-2 font-mono text-[0.6rem] leading-relaxed text-text"
                               x-text="receiptLine"></p>
                        @endif

                        @if ($generator->renderer() === Renderer::Math)
                            {{-- The worksheet is the seed architecture's most useful
                                 by-product: the link carries this token, so the printed
                                 sheet regenerates byte for byte, and changing one
                                 character of the token hands the next student a
                                 different sheet with the same shape. --}}
                            <a href="{{ route('worksheet', ['generator' => \Illuminate\Support\Str::after($generator->key(), '.')]) }}?{{ http_build_query([...$params, 'seed' => $generation->seed->token(), 'count' => 20]) }}"
                               target="_blank" rel="noopener"
                               class="mt-2 flex w-full items-center justify-between gap-3 border border-line px-4 py-2.5 text-left text-xs text-text transition-colors hover:border-signal hover:text-signal">
                                <span>
                                    Printable worksheet
                                    <span class="mt-0.5 block font-mono text-[0.62rem] text-muted num">20 problems · answers on page 2</span>
                                </span>
                                <span aria-hidden="true" class="shrink-0 font-mono text-[0.62rem] text-muted">↗</span>
                            </a>

                            <p class="mt-2 font-mono text-[0.62rem] leading-relaxed text-muted">
                                The seed is printed in the footer, so the same sheet comes back
                                from the same link — and a different seed is a different sheet.
                            </p>
                        @endif

                        @if ($generator->renderer() === Renderer::Audio)
                            <button type="button" @click="downloadWav()" :disabled="drawing || audio === null"
                                    class="mt-2 flex w-full items-center justify-between gap-3 border border-line px-4 py-2.5 text-left text-xs text-text transition-colors hover:border-signal hover:text-signal disabled:opacity-50">
                                <span>
                                    Download WAV
                                    <span class="mt-0.5 block font-mono text-[0.62rem] text-muted num">16-bit PCM · <span x-text="total">0:00</span></span>
                                </span>
                                <span x-show="exported" x-cloak class="shrink-0 font-mono text-[0.62rem] text-signal num" x-text="exported"></span>
                            </button>

                            <p class="mt-2 font-mono text-[0.62rem] leading-relaxed text-muted">
                                The file is the render, sample for sample — not a second performance
                                of it. The seed below reproduces it anywhere.
                            </p>

                            {{-- A volume control is on screen at all times, and so is a hard
                                 mute: docs/09 §9. This is the monitor level and it never
                                 touches the file — the mix level that *is* written into the
                                 WAV is the Volume control in the parameters panel, which
                                 starts 12 dB below full scale. --}}
                            <div class="mt-4 border-t border-line pt-4">
                                <div class="flex items-center justify-between gap-3 font-mono text-[0.65rem] text-muted">
                                    <label for="monitor-volume">monitor</label>
                                    <div class="flex items-center gap-2">
                                        <span class="text-signal num" x-text="`${Math.round(monitor * 100)}%`">80%</span>
                                        <button type="button" @click="toggleMute()"
                                                :class="muted ? 'border-warn/50 text-warn' : 'border-line text-muted'"
                                                class="border px-2 py-0.5 transition-colors hover:border-signal hover:text-signal">
                                            <span x-text="muted ? 'muted' : 'mute'">mute</span>
                                        </button>
                                    </div>
                                </div>

                                <input id="monitor-volume" type="range" min="0" max="1" step="0.01"
                                       x-model.number="monitor"
                                       @input="setMonitor($event.target.value)"
                                       class="mt-3 w-full">
                            </div>
                        @endif

                    </div>
                </div>
            </aside>
        </div>

        <p class="mx-auto max-w-7xl px-6 py-8 font-mono text-[0.68rem] text-muted">
            This page and
            <a href="/api/v1/g/{{ $generator->key() }}" class="text-signal hover:underline">/api/v1/g/{{ $generator->key() }}</a>
            run the same code. There is no private endpoint.
        </p>
    </div>
</x-layout>
