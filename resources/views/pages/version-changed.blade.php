@php
    [$moduleKey, $generatorKey] = explode('.', $generator->key(), 2);

    // The parameters survived the version bump even though the result did not, so
    // the offer below is a real one: same knobs, fresh entropy.
    $carried = \App\Random\Studio\Generation::decodeParams(request()->query('p'));
    $carried = array_map(fn ($v) => is_bool($v) ? ($v ? 1 : 0) : $v, $carried);

    if (request()->query('s')) {
        $carried['source'] = request()->query('s');
    }
@endphp

<x-layout>
    <x-slot:title>This link can no longer be reproduced</x-slot:title>

    <div class="mx-auto max-w-3xl px-6 py-24">
        <p class="font-mono text-[0.68rem] uppercase tracking-[0.18em] text-warn">
            409 · version changed
        </p>

        <h1 class="mt-5 text-4xl font-semibold leading-[1.05] tracking-tighter sm:text-5xl">
            This link was made with an older version.
        </h1>

        <p class="mt-6 text-lg leading-relaxed text-muted">
            {{ $exception->getMessage() }}
        </p>

        <dl class="mt-10 grid gap-px border border-line bg-line font-mono text-xs sm:grid-cols-3">
            <div class="bg-ground px-4 py-3">
                <dt class="text-muted">generator</dt>
                <dd class="mt-1 text-text num">{{ $exception->generatorKey }}</dd>
            </div>
            <div class="bg-ground px-4 py-3">
                <dt class="text-muted">link was made with</dt>
                <dd class="mt-1 text-text num">v{{ $exception->generatedWith }}</dd>
            </div>
            <div class="bg-ground px-4 py-3">
                <dt class="text-muted">current</dt>
                <dd class="mt-1 text-signal num">v{{ $exception->current }}</dd>
            </div>
        </dl>

        <p class="mt-8 max-w-2xl leading-relaxed text-muted">
            The seed in this link is intact — we simply will not pretend that running it
            through a changed algorithm gives you the same result. Reproducibility that
            quietly stops reproducing is worse than none at all, so this fails out loud
            instead. The original numbers are gone; what you can still have is a fresh
            draw with the same parameters.
        </p>

        <div class="mt-10 flex flex-wrap items-center gap-3">
            <a href="{{ route('studio', ['module' => $moduleKey, 'generator' => $generatorKey] + $carried) }}"
               class="inline-flex items-center gap-2 bg-signal px-5 py-2.5 text-sm font-medium text-ground transition-opacity hover:opacity-90">
                Generate fresh with the same parameters
                <span aria-hidden="true">→</span>
            </a>
            <a href="{{ route('library') }}"
               class="inline-flex items-center gap-2 border border-line px-5 py-2.5 text-sm text-text transition-colors hover:border-muted">
                Back to the library
            </a>
        </div>

        <p class="mt-12 border-t border-line pt-6 font-mono text-[0.68rem] leading-relaxed text-muted">
            The API reports the same thing as
            <span class="text-text">409 version_changed</span>, with
            <span class="text-text">generated_with</span> and
            <span class="text-text">current</span> in the body.
        </p>
    </div>
</x-layout>
