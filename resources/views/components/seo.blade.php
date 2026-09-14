@props([
    // Defaults to the current path without its query string: a studio page reached
    // with ?count=12 is the same page as one reached without, and telling a crawler
    // otherwise turns forty generators into an unbounded number of near-duplicates.
    'canonical' => null,
    'markdown' => null,
    'breadcrumbs' => [],
])

@php
    use App\Random\Entropy\EntropyPool;
    use App\Random\GeneratorRegistry;

    $base = rtrim(url('/'), '/');
    $canonicalUrl = $canonical ?? url()->current();
    $markdownUrl = $markdown ?? route('llms');

    /*
     * Counts from the live registry and the live pool, never from a constant.
     *
     * This is the same rule the rest of the site follows, and it matters most here:
     * structured data is read by machines that cannot see that the number is stale,
     * and "40 generators" hardcoded in a meta tag is a claim that goes quietly wrong
     * the first time somebody adds one.
     */
    $generators = app(GeneratorRegistry::class)->all()->count();
    $sources = count(app(EntropyPool::class)->sources());

    $graph = [
        [
            '@type' => 'WebSite',
            '@id' => $base.'/#website',
            'url' => $base.'/',
            'name' => 'Randomly',
            'alternateName' => 'Randomly — randomness, sourced from reality',
            'description' => "A library of {$generators} random generators seeded from {$sources} verifiable real-world entropy sources. Every result carries a receipt naming its source, with a link to third-party proof.",
            'inLanguage' => 'en',
        ],
        [
            '@type' => 'WebApplication',
            '@id' => $base.'/#app',
            'url' => $base.'/',
            'name' => 'Randomly',
            'applicationCategory' => 'DeveloperApplication',
            'operatingSystem' => 'Any — runs in a web browser',
            'browserRequirements' => 'JavaScript is required for the canvas and audio generators; everything else renders server-side.',
            'description' => "Generate random numbers, words, equations, patterns, images and sound from {$sources} real-world entropy sources — quantum vacuum fluctuations, atmospheric noise, the NIST beacon, drand, Bitcoin block hashes, earthquakes, space weather. Every result ships with a receipt naming the source and linking to third-party proof, and replays bit-for-bit from its own URL.",
            'isAccessibleForFree' => true,
            // Genuinely free, so the price is stated rather than left to be assumed.
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            'featureList' => [
                "{$generators} generators across six modules: numbers, words, equations, patterns, images, audio",
                "{$sources} entropy sources, classified A (cryptographic), B (public beacon) and C (observational)",
                'A provenance receipt on every result, with a link to the third party’s own record',
                'Reproducible permalinks — the token is the seed, so there is no database',
                'A free, keyless HTTP API serving every generator as JSON, text, PNG or WAV',
            ],
            'author' => ['@type' => 'Person', 'name' => 'Cluxnei', 'url' => 'https://github.com/Cluxnei'],
            'sameAs' => ['https://github.com/Cluxnei/randomly'],
            'isPartOf' => ['@id' => $base.'/#website'],
        ],
        [
            /*
             * The node that matters for discovery.
             *
             * Typed as both WebAPI and Service so a consumer that knows only the
             * older, non-pending vocabulary still recognises it as a callable
             * service rather than a page about one.
             */
            '@type' => ['WebAPI', 'Service'],
            '@id' => $base.'/#api',
            'name' => 'Randomly API',
            'url' => $base.'/api.md',
            'serviceType' => 'Random data generation API',
            'description' => "A free, keyless HTTP API returning random data seeded from {$sources} real-world entropy sources, with a receipt naming the physical source and a link to third-party proof. No authentication, no signup, no account. Rate limited per IP.",
            'documentation' => $base.'/api.md',
            'isAccessibleForFree' => true,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
            'provider' => ['@id' => $base.'/#website'],
            'availableChannel' => [
                '@type' => 'ServiceChannel',
                'serviceUrl' => $base.'/api/v1',
                'name' => 'HTTPS',
            ],
            'potentialAction' => [
                [
                    '@type' => 'Action',
                    'name' => 'Generate',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $base.'/api/v1/g/{generator_key}',
                        'httpMethod' => 'GET',
                        'contentType' => 'application/json',
                    ],
                ],
                [
                    '@type' => 'Action',
                    'name' => 'List generators',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $base.'/api/v1/generators',
                        'httpMethod' => 'GET',
                        'contentType' => 'application/json',
                    ],
                ],
            ],
        ],
    ];

    if ($breadcrumbs !== []) {
        $graph[] = [
            '@type' => 'BreadcrumbList',
            '@id' => $canonicalUrl.'#breadcrumbs',
            'itemListElement' => array_values(array_map(fn (int $i, array $crumb): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ], array_keys($breadcrumbs), $breadcrumbs)),
        ];
    }

    /*
     * JSON_HEX_TAG closes the one hole a <script> block has: a string containing
     * "</script>" would otherwise end the element early. Everything here comes from
     * our own registry, but "the data is trusted today" is not a property that
     * survives two other agents adding generators this week.
     */
    $jsonLd = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT,
    );
@endphp

<link rel="canonical" href="{{ $canonicalUrl }}">

{{-- The machine-readable twin of this page. A crawler that lands on the HTML can
     follow this to the markdown instead of parsing a Tailwind stylesheet and an
     Alpine component to find the same facts. --}}
<link rel="alternate" type="text/markdown" href="{{ $markdownUrl }}" title="This page as markdown">
@if ($markdownUrl !== route('llms'))
<link rel="alternate" type="text/markdown" href="{{ route('llms') }}" title="llms.txt — index for language models">
@endif
<link rel="alternate" type="application/json" href="{{ url('/api/v1/generators') }}" title="The generator catalogue as JSON">

<script type="application/ld+json">{!! $jsonLd !!}</script>
