<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Contracts\NeedsData;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A real Wikipedia article, chosen by the beacon rather than by Wikipedia.
 *
 * The subtle part is who does the choosing. Wikipedia's random endpoint returns
 * one article, already picked — build on that and the receipt saying "drawn from
 * a magnitude 4.2 earthquake" becomes a lie, because the earthquake had no say in
 * which article came back. So fetch() collects a pool of twenty and generate()
 * picks from it with the seed. The pool is somebody else's randomness; the choice
 * inside it is ours, and only the choice is what the receipt claims.
 *
 * Content is CC BY-SA from Wikipedia, attributed in the output and linked back.
 */
final class ArticleGenerator extends BaseGenerator implements NeedsData
{
    private const ENDPOINT = 'https://en.wikipedia.org/api/rest_v1/page/random/summary';

    /** Wikipedia asks API clients to identify themselves; an anonymous flood gets blocked. */
    private const USER_AGENT = 'Randomly/1.0 (https://github.com/randomly; verifiable-entropy showcase)';

    private const POOL_SIZE = 20;

    public function key(): string
    {
        return 'words.article';
    }

    public function name(): string
    {
        return 'Random Knowledge';
    }

    public function tagline(): string
    {
        return 'A Wikipedia summary you would never have gone looking for.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 3, min: 1, max: 10)
            ->bool('with_extract', 'Include the summary', default: true);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$pool, $live] = $this->pool($params);
        $count = min($params->int('count'), count($pool));

        // Without replacement: three copies of the same article is a worse answer
        // than two articles, and the pool is twenty deep precisely so it can afford it.
        $picked = $rng->sample($pool, $count);

        $lines = array_map(function (array $article) use ($params): string {
            $line = $article['title'];

            if ($article['description'] !== null && $article['description'] !== '') {
                $line .= ' — '.$article['description'];
            }

            if ($params->bool('with_extract') && $article['extract'] !== '') {
                $line .= PHP_EOL.$article['extract'];
            }

            return $line.PHP_EOL.$article['url'];
        }, $picked);

        return new Result(
            value: $picked,
            display: implode(PHP_EOL.PHP_EOL, $lines),
            meta: [
                'pool_size' => count($pool),
                'degraded' => ! $live,
                // log2(pool) per pick, falling as the pool shrinks — the honest count
                // of how much of this result the seed is actually responsible for.
                'entropy_out_bits' => round($this->pickBits(count($pool), $count), 2),
                'source' => 'Wikipedia (English)',
                'licence' => 'CC BY-SA 4.0 — text from Wikipedia, linked back to the article',
                'note' => 'Twenty articles were fetched; the seed chose between them. Wikipedia picked the pool, not the answer.',
            ],
        );
    }

    /**
     * Twenty random summaries, concurrently.
     *
     * The REST endpoint hands back one article per call, so a pool means twenty
     * calls — sequentially that is twenty round trips and a page that hangs. Http::pool
     * issues them together, and the whole thing is cached for five minutes so a
     * slider drag never becomes traffic on Wikipedia's servers.
     */
    public function fetch(Params $params): array
    {
        $responses = Http::pool(fn ($pool) => array_map(
            fn (): mixed => $pool->withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(4)
                ->get(self::ENDPOINT),
            range(1, self::POOL_SIZE),
        ));

        $articles = [];

        foreach ($responses as $response) {
            // A pooled request can come back as an exception rather than a response,
            // and one dead connection out of twenty should cost one article, not the
            // whole pool.
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $article = $this->normalise($response->json());

            if ($article !== null) {
                $articles[$article['title']] = $article;
            }
        }

        // A pool of two would make the "the seed chose" claim close to meaningless,
        // so too few candidates is a failed fetch and the Studio falls back.
        if (count($articles) < 5) {
            throw new \RuntimeException('Wikipedia returned too few usable articles to draw from.');
        }

        return ['pool' => array_values($articles)];
    }

    public function cacheSeconds(): int
    {
        // Long enough that dragging the count slider is free, short enough that two
        // people a few minutes apart do not get the same twenty articles.
        return 300;
    }

    public function fallback(): array
    {
        return ['pool' => self::FALLBACK_POOL];
    }

    /**
     * The candidates to choose between, and whether they came from the live API.
     *
     * generate() is pure and must work with no data attached at all — that is how the
     * determinism tests run it, and how the page renders when Wikipedia is down. The
     * flag travels with the pool so the result can say which happened: a fallback
     * presented as a live draw would be a false receipt.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function pool(Params $params): array
    {
        $pool = $params->data('pool');
        $live = is_array($pool) && $pool !== [] && ! $params->data('degraded', false);

        return [$live ? array_values($pool) : self::FALLBACK_POOL, $live];
    }

    private function pickBits(int $pool, int $count): float
    {
        $bits = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $bits += log(max(1, $pool - $i), 2);
        }

        return $bits;
    }

    /** @return array<string, mixed>|null */
    private function normalise(?array $summary): ?array
    {
        $title = $summary['title'] ?? null;

        // Disambiguation pages and list stubs are not knowledge, they are signposts.
        if (! is_string($title) || ($summary['type'] ?? 'standard') !== 'standard') {
            return null;
        }

        return [
            'title' => $title,
            'description' => $summary['description'] ?? null,
            'extract' => (string) ($summary['extract'] ?? ''),
            'url' => $summary['content_urls']['desktop']['page'] ?? 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title)),
            'thumbnail' => $summary['thumbnail']['source'] ?? null,
        ];
    }

    /**
     * What the page shows when Wikipedia is unreachable.
     *
     * Real articles, real URLs, kept small and picked to be worth reading — a
     * fallback that returns "unavailable" teaches the user nothing and makes the
     * outage the product. The result meta flags it as degraded so nobody mistakes
     * these for a live draw.
     */
    private const FALLBACK_POOL = [
        ['title' => 'Antikythera mechanism', 'description' => 'Ancient Greek analogue computer', 'extract' => 'An ancient Greek hand-powered orrery, described as the oldest known example of an analogue computer, used to predict astronomical positions and eclipses decades in advance.', 'url' => 'https://en.wikipedia.org/wiki/Antikythera_mechanism', 'thumbnail' => null],
        ['title' => 'Tuvan throat singing', 'description' => 'Overtone singing tradition', 'extract' => 'A style of singing from Tuva in which one performer produces two or more pitches simultaneously by amplifying overtones of a fundamental note.', 'url' => 'https://en.wikipedia.org/wiki/Tuvan_throat_singing', 'thumbnail' => null],
        ['title' => 'Lake Natron', 'description' => 'Salt lake in Tanzania', 'extract' => 'A shallow soda lake whose alkalinity can reach pH 10.5, coloured deep red by salt-loving microorganisms, and the only regular breeding ground for East Africa\'s lesser flamingos.', 'url' => 'https://en.wikipedia.org/wiki/Lake_Natron', 'thumbnail' => null],
        ['title' => 'Voynich manuscript', 'description' => 'Undeciphered illustrated codex', 'extract' => 'An illustrated codex hand-written in an unknown writing system, carbon-dated to the early 15th century and never convincingly decoded despite a century of attention from professional and amateur cryptographers.', 'url' => 'https://en.wikipedia.org/wiki/Voynich_manuscript', 'thumbnail' => null],
        ['title' => 'Pitch drop experiment', 'description' => 'Long-running viscosity experiment', 'extract' => 'A long-term experiment measuring the flow of a piece of pitch over many decades, demonstrating that a substance that shatters like a solid is in fact a fluid of extremely high viscosity.', 'url' => 'https://en.wikipedia.org/wiki/Pitch_drop_experiment', 'thumbnail' => null],
        ['title' => 'Cave of the Crystals', 'description' => 'Cave in Naica, Mexico', 'extract' => 'A cave connected to the Naica Mine in Chihuahua, containing some of the largest natural crystals ever found — selenite beams up to twelve metres long, formed in mineral-saturated water at a near-constant 58 °C.', 'url' => 'https://en.wikipedia.org/wiki/Cave_of_the_Crystals', 'thumbnail' => null],
        ['title' => 'Kessler syndrome', 'description' => 'Orbital debris cascade scenario', 'extract' => 'A scenario in which the density of objects in low Earth orbit is high enough that collisions cascade, each one generating debris that raises the likelihood of further collisions.', 'url' => 'https://en.wikipedia.org/wiki/Kessler_syndrome', 'thumbnail' => null],
        ['title' => 'Blue Marble', 'description' => 'Photograph of Earth', 'extract' => 'A photograph of Earth taken in 1972 by the crew of Apollo 17 from about 29,000 kilometres away, one of the most reproduced images in history.', 'url' => 'https://en.wikipedia.org/wiki/The_Blue_Marble', 'thumbnail' => null],
    ];
}
