<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\Entropy\Contracts\EntropySource;
use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Generators\Contracts\Generator;
use App\Random\Generators\Module;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The machine-readable side of the site.
 *
 * Everything here is rendered from the same registry and entropy pool the HTML
 * pages and the API render from, so a generator added to config/randomly.php
 * appears in `/llms.txt`, in `/llms-full.txt` and at its own `.md` address on the
 * next request, with its real parameters and their real bounds. Nothing on these
 * endpoints is typed out by hand, because a hand-kept copy of a parameter list is
 * a copy that will be wrong by the end of the month — and a document that lies
 * about the API is worse than no document, since the reader cannot tell.
 *
 * They are served as `text/markdown` rather than HTML because the intended reader
 * is a language model deciding whether it can call this API, and handing it a
 * navigation bar, a Tailwind stylesheet and an Alpine component to chew through
 * first is pure cost to both of us.
 */
final class DocsController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly EntropyPool $pool,
    ) {}

    /** `/llms.txt` — the index, per the llmstxt.org convention. */
    public function index(): Response
    {
        return $this->markdown('markdown.llms', [
            'byModule' => $this->byModule(),
            'sources' => $this->sources(),
            'count' => $this->registry->all()->count(),
        ]);
    }

    /** `/llms-full.txt` — the whole thing, in one file, for a model with a context window to spend. */
    public function full(): Response
    {
        return $this->markdown('markdown.llms-full', [
            'byModule' => $this->byModule(),
            'sources' => $this->sources(),
            'count' => $this->registry->all()->count(),
        ]);
    }

    /** `/api.md` — the contract alone, for a reader who only wants to call it. */
    public function api(): Response
    {
        return $this->markdown('markdown.api', [
            'byModule' => $this->byModule(),
            'sources' => $this->sources(),
            'count' => $this->registry->all()->count(),
        ]);
    }

    /** `/g/{module}/{generator}.md` — one generator, from its own schema(). */
    public function generator(string $module, string $generator): Response
    {
        $key = "{$module}.{$generator}";

        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("No generator at [{$key}].");
        }

        $subject = $this->registry->findOrFail($key);

        return $this->markdown('markdown.generator', [
            'generator' => $subject,
            'entry' => $this->entry($subject),
            'sources' => $this->sources(),
        ]);
    }

    /**
     * Generators grouped by module, as plain arrays, each carrying the extra
     * fields a document needs that the API catalogue has no reason to send.
     *
     * @return array<string, array{module: Module, generators: list<array<string, mixed>>}>
     */
    private function byModule(): array
    {
        $grouped = [];

        foreach (Module::cases() as $module) {
            $generators = $this->registry->module($module)
                ->map(fn (Generator $g) => $this->entry($g))
                ->values()
                ->all();

            if ($generators === []) {
                continue;
            }

            $grouped[$module->value] = ['module' => $module, 'generators' => $generators];
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    private function entry(Generator $generator): array
    {
        [$module, $name] = explode('.', $generator->key(), 2);

        return [
            'key' => $generator->key(),
            'name' => $generator->name(),
            'tagline' => $generator->tagline(),
            'module' => $generator->module()->value,
            'renderer' => $generator->renderer()->value,
            'version' => $generator->version(),
            'sensitive' => $generator->isSensitive(),
            'reproducible' => $generator->isReproducible(),
            'uses_entropy' => $generator->usesEntropy(),
            'formats' => $this->formatsFor($generator),
            'params' => $generator->schema()->toArray(),
            'defaults' => $generator->schema()->defaults(),
            'url' => route('studio', ['module' => $module, 'generator' => $name]),
            'markdown_url' => route('docs.generator', ['module' => $module, 'generator' => $name]),
            'api_url' => url("/api/v1/g/{$generator->key()}"),
        ];
    }

    /**
     * Mirrors GeneratorRegistry::formatsFor(), which is private there.
     *
     * Duplicated deliberately rather than widened: the catalogue's shape is the
     * API's contract, and a document is not a good enough reason to open a hole
     * in a class two other agents are adding generators to this week.
     *
     * @return list<string>
     */
    private function formatsFor(Generator $generator): array
    {
        return match ($generator->renderer()->value) {
            'canvas' => ['application/json', 'text/plain', 'image/png'],
            'audio' => ['application/json', 'text/plain', 'audio/wav'],
            default => ['application/json', 'text/plain'],
        };
    }

    /**
     * The entropy sources, described rather than polled.
     *
     * EntropyPool::status() is the live view and belongs on `/entropy`; a document
     * wants the stable facts — what the source is, what class it is, how often it
     * moves — and not whatever earthquake happened to be cached when a crawler
     * arrived.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(): array
    {
        return array_values(array_map(fn (EntropySource $source): array => [
            'key' => $source->key(),
            'label' => $source->label(),
            'class' => $source->class()->value,
            'class_label' => $source->class()->label(),
            'caveat' => $source->class()->caveat(),
            'origin' => $source->origin(),
            'refresh_seconds' => $source->refreshSeconds(),
        ], array_values($this->pool->sources())));
    }

    /** @param array<string, mixed> $data */
    private function markdown(string $view, array $data): Response
    {
        /*
         * Decoded, because these views emit markdown and not HTML.
         *
         * Blade's {{ }} escapes for an HTML context, which is right everywhere
         * else on the site and wrong here: it turns the ampersands in a curl
         * one-liner's query string into `&amp;`, so the example a reader pastes
         * does not run. Undoing it once, centrally, beats sprinkling {!! !!}
         * through five templates and discovering the one that was missed in a
         * bug report. Nothing in these documents is user input — every string
         * comes from the registry, the entropy pool or the templates themselves.
         *
         * Trimmed and given exactly one trailing newline on the way out: Blade's
         * directives leave ragged edges, and a file a model is going to read
         * should not open with three blank lines.
         */
        $body = html_entity_decode(trim(view($view, $data)->render()), ENT_QUOTES | ENT_HTML5, 'UTF-8')."\n";

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
