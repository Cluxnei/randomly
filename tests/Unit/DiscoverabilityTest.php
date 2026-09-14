<?php

declare(strict_types=1);

use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/*
 * The machine-readable surfaces.
 *
 * Every assertion here is really the same assertion: that these documents are
 * generated from the registry rather than typed out. A hand-kept list of forty
 * generators and their parameters would pass a review, ship, and then go quietly
 * wrong the first time somebody added a generator — and the only reader who would
 * notice is a language model that has no way to tell it has been lied to.
 *
 * Nothing below touches the network. Page requests pin `source=csprng`, which is
 * the kernel and never leaves the machine; the markdown endpoints draw no entropy
 * at all, which is itself worth keeping true — a crawler should not be able to
 * make this site call the USGS.
 */
uses(TestCase::class);

function registry(): GeneratorRegistry
{
    return app(GeneratorRegistry::class);
}

function generators(): array
{
    return registry()->all()->values()->all();
}

it('serves llms.txt as markdown, in the shape llmstxt.org describes', function () {
    $response = $this->get('/llms.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');

    $body = $response->getContent();
    $lines = explode("\n", $body);

    // An H1 with the project name, then a blockquote summary. That is the whole
    // convention, and getting it wrong makes the file just another text file.
    expect($lines[0])->toBe('# Randomly')
        ->and($lines[1])->toBe('')
        ->and($lines[2])->toStartWith('> ')
        ->and($body)->toContain('## Start here');

    // Curated sections of links, not prose with URLs loose in it.
    expect(substr_count($body, "\n## "))->toBeGreaterThanOrEqual(4);
});

it('indexes every shipped generator in llms.txt, and nothing that is not shipped', function () {
    $body = $this->get('/llms.txt')->getContent();

    foreach (generators() as $generator) {
        /** @var Generator $generator */
        expect($body)->toContain('`'.$generator->key().'`');
    }

    // The count in the summary is the live count, or the very first sentence a
    // model reads about this project is already wrong.
    expect($body)->toContain('library of '.count(generators()).' random generators');

    // Planned generators live in a disjoint list (see RoadmapTest) and must not
    // leak into a document that reads as a catalogue of what you can call today.
    $planned = array_merge(...array_map(
        fn (array $group): array => array_column($group, 'key'),
        array_values(require resource_path('roadmap.php')),
    ));

    foreach ($planned as $key) {
        expect($body)->not->toContain('`'.$key.'`');
    }
});

it('puts every generator, parameter and entropy source in llms-full.txt', function () {
    $response = $this->get('/llms-full.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');

    $body = $response->getContent();

    foreach (generators() as $generator) {
        /** @var Generator $generator */
        expect($body)->toContain('`'.$generator->key().'` — '.$generator->name());

        // The parameters are the part a caller cannot guess, so every declared
        // name and every declared bound has to actually be in the file.
        foreach ($generator->schema()->params() as $name => $param) {
            expect($body)->toContain('| `'.$name.'` | '.$param->type.' |');
        }
    }

    foreach (app(EntropyPool::class)->sources() as $source) {
        expect($body)->toContain('`'.$source->key().'`')
            ->and($body)->toContain($source->origin());
    }
});

it('serves the API contract on its own at /api.md', function () {
    $response = $this->get('/api.md');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');

    $body = $response->getContent();

    foreach (['/api/v1/generators', '/api/v1/sources', '/api/v1/generate', '/api/v1/g/', '/api/v1/replay/'] as $endpoint) {
        expect($body)->toContain($endpoint);
    }

    // Every error a caller can actually receive, named. A client that only
    // handles 200 is a client that will misread a 409 as success.
    foreach (['invalid_params', 'invalid_token', 'unknown_generator', 'not_replayable', 'unsupported_format', 'version_changed', 'rate_limited'] as $error) {
        expect($body)->toContain('`'.$error.'`');
    }

    // The limits are read from config, not typed into prose.
    expect($body)->toContain((string) config('randomly.limits.per_minute'))
        ->and($body)->toContain((string) config('randomly.limits.media_per_minute'));
});

it('documents each generator at its own .md address, from its own schema', function () {
    foreach (generators() as $generator) {
        /** @var Generator $generator */
        [$module, $name] = explode('.', $generator->key(), 2);

        $response = $this->get("/g/{$module}/{$name}.md");

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');

        $body = $response->getContent();

        expect($body)->toContain('# '.$generator->name())
            ->and($body)->toContain($generator->tagline())
            ->and($body)->toContain('`'.$generator->key().'`')
            // An example a reader can paste, at this generator's own address.
            ->and($body)->toContain("curl -s '".url('/api/v1/g/'.$generator->key()));

        foreach ($generator->schema()->params() as $paramName => $param) {
            expect($body)->toContain('| `'.$paramName.'` | '.$param->type.' |');
        }

        // The three flags are behavioural promises to a client, so each one that
        // is unusual has to be stated rather than left to be discovered by a 404.
        if ($generator->isSensitive()) {
            expect($body)->toContain('**Sensitive.**');
        }

        if (! $generator->isReproducible()) {
            expect($body)->toContain('**Not reproducible.**');
        }

        if (! $generator->usesEntropy()) {
            expect($body)->toContain('**Draws no entropy.**');
        }
    }
});

it('escapes nothing into HTML entities, because markdown is not HTML', function () {
    // The bug this guards: Blade escapes for an HTML context, so `&` in a curl
    // one-liner's query string becomes `&amp;` and the example a reader pastes
    // does not run.
    $body = $this->get('/g/numbers/integers.md')->getContent();

    expect($body)->toContain('?count=10&min=1&max=100')
        ->and($body)->not->toContain('&amp;')
        ->and($body)->not->toContain('&#039;');
});

it('404s on a generator that does not exist rather than rendering an empty document', function () {
    $this->get('/g/numbers/not-a-generator.md')->assertNotFound();
    $this->get('/g/nonsense/integers.md')->assertNotFound();
});

it('still serves the studio HTML at the address without the .md suffix', function () {
    // The markdown route is declared first and matches `{generator}.md`; if its
    // pattern were greedy it would swallow the studio route entirely.
    $this->get('/g/numbers/integers?source=csprng')
        ->assertOk()
        ->assertSee('Integers', escape: false);
});

it('lists every page and every generator in a well-formed sitemap', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();

    // preserve_keys: false — every element here is called <url>, so keeping the
    // keys would collapse eighty-seven of them into one.
    $locations = array_map(fn ($url): string => (string) $url->loc, iterator_to_array($xml->url, false));

    foreach ([route('home'), route('library'), route('entropy'), route('credits'), route('llms'), route('llms.full'), route('docs.api')] as $expected) {
        expect($locations)->toContain($expected);
    }

    foreach (generators() as $generator) {
        /** @var Generator $generator */
        [$module, $name] = explode('.', $generator->key(), 2);

        expect($locations)->toContain(route('studio', ['module' => $module, 'generator' => $name]))
            ->and($locations)->toContain(route('docs.generator', ['module' => $module, 'generator' => $name]));
    }

    // Two entries per generator plus the seven fixed URLs, and no duplicates —
    // a sitemap that lists the same page twice is a sitemap built by hand.
    expect($locations)->toHaveCount(7 + 2 * count(generators()))
        ->and(array_unique($locations))->toHaveCount(count($locations));
});

it('welcomes crawlers, names the AI ones and points at the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');

    $body = $response->getContent();

    expect($body)->toContain("User-agent: *\nAllow: /")
        ->and($body)->toContain('Sitemap: '.route('sitemap'))
        ->and($body)->not->toContain('Disallow: /');

    foreach (['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'CCBot'] as $agent) {
        expect($body)->toContain("User-agent: {$agent}\nAllow: /");
    }
});

it('emits structured data that parses and declares the API as a callable service', function () {
    $html = Blade::render('<x-seo />');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
    expect($matches)->toHaveCount(2);

    $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($data['@context'])->toBe('https://schema.org');

    $nodes = collect($data['@graph'])->keyBy(fn (array $node) => implode('+', (array) $node['@type']));

    expect($nodes->keys()->all())->toBe(['WebSite', 'WebApplication', 'WebAPI+Service']);

    $app = $nodes['WebApplication'];
    expect($app['isAccessibleForFree'])->toBeTrue()
        ->and($app['offers']['price'])->toBe('0')
        // The counts a machine reads are the live counts, same as everywhere else.
        ->and($app['featureList'][0])->toStartWith(registry()->all()->count().' generators')
        ->and($app['featureList'][1])->toStartWith(count(app(EntropyPool::class)->sources()).' entropy sources');

    $api = $nodes['WebAPI+Service'];
    expect($api['availableChannel']['serviceUrl'])->toBe(url('/api/v1'))
        ->and($api['documentation'])->toBe(url('/api.md'))
        ->and($api['isAccessibleForFree'])->toBeTrue()
        ->and($api['offers']['price'])->toBe('0')
        ->and($api['potentialAction'][0]['target']['urlTemplate'])->toContain('/api/v1/g/')
        ->and($api['potentialAction'][0]['target']['httpMethod'])->toBe('GET');
});

it('adds a breadcrumb trail only where there is one to add', function () {
    $withoutCrumbs = Blade::render('<x-seo />');
    expect($withoutCrumbs)->not->toContain('BreadcrumbList');

    $html = Blade::render(
        '<x-seo :breadcrumbs="$crumbs" />',
        ['crumbs' => [['name' => 'Randomly', 'url' => 'https://example.test'], ['name' => 'Library', 'url' => 'https://example.test/library']]],
    );

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
    $graph = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR)['@graph'];

    $crumbs = collect($graph)->firstWhere('@type', 'BreadcrumbList');

    expect($crumbs['itemListElement'])->toHaveCount(2)
        ->and($crumbs['itemListElement'][0]['position'])->toBe(1)
        ->and($crumbs['itemListElement'][1]['name'])->toBe('Library');
});

it('gives a studio page a canonical URL and a link to its markdown twin', function () {
    $html = $this->get('/g/patterns/perlin?source=csprng&scale=4')->getContent();

    // Canonical without the query string: forty generators, not an unbounded
    // number of near-duplicate pages differing by a slider position.
    expect($html)->toContain('<link rel="canonical" href="'.url('/g/patterns/perlin').'">')
        ->and($html)->toContain('<link rel="alternate" type="text/markdown" href="'.url('/g/patterns/perlin.md').'"');

    // The description is about this generator, not about the site.
    $generator = registry()->findOrFail('patterns.perlin');
    expect($html)->toContain($generator->name().' — '.$generator->tagline());
});
