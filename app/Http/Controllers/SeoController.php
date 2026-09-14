<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml.
 *
 * Both are routes rather than files in public/ for the same reason: they have to
 * name absolute URLs, and this project has no single hostname baked into it. A
 * checked-in robots.txt would have to hardcode a domain that is wrong on every
 * deployment but one, and a hand-written sitemap would silently stop listing
 * generators the moment somebody added one — which, with two agents adding
 * generators this week, is not a hypothetical.
 */
final class SeoController extends Controller
{
    public function __construct(private readonly GeneratorRegistry $registry) {}

    public function robots(): Response
    {
        return response(view('seo.robots', ['count' => $this->registry->all()->count()])->render(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('library'), 'priority' => '0.9'],
            ['loc' => route('entropy'), 'priority' => '0.8'],
            ['loc' => route('credits'), 'priority' => '0.4'],

            // The machine-readable surfaces are listed because they are the point
            // of the exercise: a crawler that only ever fetches these three files
            // has the whole API and every generator's parameters.
            ['loc' => route('llms'), 'priority' => '0.8'],
            ['loc' => route('llms.full'), 'priority' => '0.7'],
            ['loc' => route('docs.api'), 'priority' => '0.8'],
        ];

        foreach ($this->registry->all() as $generator) {
            /** @var Generator $generator */
            [$module, $name] = explode('.', $generator->key(), 2);

            $urls[] = ['loc' => route('studio', ['module' => $module, 'generator' => $name]), 'priority' => '0.7'];
            $urls[] = ['loc' => route('docs.generator', ['module' => $module, 'generator' => $name]), 'priority' => '0.6'];
        }

        /*
         * No <lastmod>. There is no database and no per-generator timestamp, so
         * every honest candidate is a lie: the deploy time is not when this page
         * changed, and today's date is not either. An omitted optional element
         * costs nothing; a fabricated one teaches a crawler to distrust the rest.
         */
        return response(view('seo.sitemap', ['urls' => $urls])->render(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
