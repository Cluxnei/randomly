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
use Illuminate\Support\Facades\Http;

/**
 * Semantic neighbours, rhymes and sound-alikes, from Datamuse — docs/05 §5.
 *
 * The same discipline as the Wikipedia and GBIF generators, and the same reason
 * for it: Datamuse is asked for a *pool* of up to a hundred neighbours and the
 * seed chooses between them. Asking for one word would move the choosing onto
 * Datamuse's server, and the receipt saying "drawn from a magnitude 4.2
 * earthquake" would be describing a decision the earthquake had no part in.
 *
 * Datamuse ranks its results by relevance, and that ranking is somebody else's
 * judgement — so the pool is taken in rank order and drawn from uniformly. The
 * honest description is: their hundred, our one, and the entropy figure counts
 * only the second half of that.
 *
 * Free, keyless, no attribution required — but credited anyway, because a page
 * about provenance that hid where its words came from would be an odd thing.
 */
final class RelatedGenerator extends BaseGenerator implements NeedsData
{
    private const ENDPOINT = 'https://api.datamuse.com/words';

    /**
     * How deep a pool to ask for.
     *
     * Forty rather than Datamuse's hundred, because the tail goes bad: asked for
     * a hundred words meaning like "ocean", the list runs out of sea by about
     * thirty and finishes on "bedford" and "enroute". A uniform draw from a pool
     * like that produces a word web nobody can reconstruct the connection in.
     * Forty is still deep enough that the largest draw the schema allows takes a
     * quarter of it.
     */
    private const POOL_SIZE = 40;

    /**
     * The four relations, and the query parameter each one is.
     *
     * `ml` is a thesaurus in the loose sense — it means "related in meaning",
     * which covers synonyms, hypernyms and the merely associated. `rel_bga` is
     * the odd one and the most fun: words that most often *follow* the query in
     * real text, which is a bigram table rather than a dictionary.
     */
    public const RELATIONS = [
        'ml' => 'Means like',
        'rel_rhy' => 'Rhymes with',
        'sl' => 'Sounds like',
        'rel_bga' => 'Usually follows',
    ];

    public function key(): string
    {
        return 'words.related';
    }

    public function name(): string
    {
        return 'Word Web';
    }

    public function tagline(): string
    {
        return 'Means-like, rhymes-with and sounds-like, via Datamuse.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->string('word', 'Seed word', default: 'ocean', max: 40, help: 'One word works best. The relation is computed against this, so changing it fetches a new pool.')
            ->enum('relation', 'Relation', self::RELATIONS, default: 'ml')
            ->int('count', 'How many', default: 8, min: 1, max: 25)
            ->bool('with_score', 'Show Datamuse’s relevance score', default: true, help: 'Their ranking, not ours — printed so you can see that the ordering came from them and the choice came from the seed.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$pool, $live] = $this->pool($params);

        $count = min($params->int('count'), count($pool));
        $picked = $rng->sample($pool, $count);

        $lines = array_map(function (array $entry) use ($params): string {
            $line = $entry['word'];

            if ($params->bool('with_score') && ($entry['score'] ?? null) !== null) {
                $line .= sprintf('  ·  score %s', number_format((int) $entry['score']));
            }

            if (($entry['syllables'] ?? null) !== null) {
                $line .= sprintf('  ·  %d syll', $entry['syllables']);
            }

            return $line;
        }, $picked);

        $word = $live ? $params->string('word', 'ocean') : self::FALLBACK_WORD;
        $relation = $live ? $params->string('relation', 'ml') : 'ml';

        return new Result(
            value: $picked,
            display: $this->heading($word, $relation, $live, $params).PHP_EOL.PHP_EOL.implode(PHP_EOL, $lines),
            meta: [
                'seed_word' => $word,
                'relation' => self::RELATIONS[$relation] ?? $relation,
                'pool_size' => count($pool),
                'degraded' => ! $live,
                // log2(pool) per pick, falling as the pool shrinks. It counts the
                // choice only: the pool itself is Datamuse's contribution and no
                // part of it is credited to the beacon.
                'entropy_out_bits' => round($this->pickBits(count($pool), $count), 2),
                'source' => $live
                    ? 'Datamuse (api.datamuse.com) — free, keyless, no attribution required'
                    : 'bundled fallback web',
                'note' => $live
                    ? 'Datamuse returned up to forty neighbours ranked by its own relevance score; the seed picked between them without replacement. Their forty, our one.'
                    : 'This draw came from the bundled pool, so the only thing the seed chose between is a list that shipped with the site.',
            ],
        );
    }

    public function fetch(Params $params): array
    {
        $relation = array_key_exists($params->string('relation'), self::RELATIONS)
            ? $params->string('relation')
            : 'ml';

        // Trimmed to a single token. Datamuse accepts phrases for `ml`, but the
        // other three relations are defined on one word and silently return
        // nothing for two — an empty pool that looks like an outage.
        $word = trim(preg_replace('/\s+/', ' ', $params->string('word', 'ocean')) ?? '');
        $word = $relation === 'ml' ? $word : (explode(' ', $word)[0] ?? '');

        if ($word === '') {
            throw new \RuntimeException('No seed word to look up.');
        }

        $response = Http::timeout(4)->get(self::ENDPOINT, [
            $relation => $word,
            'max' => self::POOL_SIZE,
            // Syllable counts come back for free and are the one piece of metadata
            // worth printing next to a rhyme.
            'md' => 's',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Datamuse returned '.$response->status().'.');
        }

        $pool = [];

        foreach ($response->json() ?? [] as $entry) {
            $candidate = $this->normalise($entry);

            if ($candidate !== null) {
                $pool[$candidate['word']] = $candidate;
            }
        }

        /*
         * "Nothing rhymes with orange" is a real answer, not an outage.
         *
         * Datamuse returns an empty list for it, and a typo or a proper noun does
         * the same. Throwing here would be wrong twice over: the studio would mark
         * the result degraded, and the page would tell the reader the API was
         * unreachable when it had in fact answered, promptly and correctly. So the
         * bundled pool is returned with the reason attached, and the output says
         * which of the two happened.
         */
        if (count($pool) < 5) {
            return [
                'pool' => self::FALLBACK_POOL,
                'degraded' => true,
                'reason' => sprintf('Datamuse knows no “%s” neighbours for “%s” — that is its answer, not an outage.', strtolower(self::RELATIONS[$relation]), $word),
            ];
        }

        return ['pool' => array_values($pool)];
    }

    public function cacheSeconds(): int
    {
        // An hour. The English language's rhymes do not move, so re-fetching more
        // often would be traffic spent on an answer that cannot have changed —
        // and the cache key already includes the word, so a new word is a new
        // lookup rather than a stale hit.
        return 3600;
    }

    public function fallback(): array
    {
        return ['pool' => self::FALLBACK_POOL];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function pool(Params $params): array
    {
        $pool = $params->data('pool');
        $live = is_array($pool) && $pool !== [] && ! $params->data('degraded', false);

        return [$live ? array_values($pool) : self::FALLBACK_POOL, $live];
    }

    private function heading(string $word, string $relation, bool $live, Params $params): string
    {
        if ($live) {
            return sprintf('%s “%s”', self::RELATIONS[$relation] ?? $relation, $word);
        }

        return sprintf(
            '%s Showing the bundled example web instead: words that mean like “%s”.',
            // The reason only exists when Datamuse answered and had nothing. A
            // genuine outage has no reason to report, because nobody told us one.
            is_string($params->data('reason')) ? $params->data('reason') : 'Datamuse is unreachable.',
            self::FALLBACK_WORD,
        );
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
    private function normalise(array $entry): ?array
    {
        $word = $entry['word'] ?? null;

        if (! is_string($word) || trim($word) === '') {
            return null;
        }

        return [
            'word' => trim($word),
            'score' => isset($entry['score']) ? (int) $entry['score'] : null,
            // numSyllables only appears when md=s was asked for and Datamuse knows
            // the word; a missing count prints nothing rather than a zero.
            'syllables' => isset($entry['numSyllables']) ? (int) $entry['numSyllables'] : null,
        ];
    }

    private const FALLBACK_WORD = 'ocean';

    /**
     * A real means-like web for one word, for when Datamuse is unreachable.
     *
     * Scores are Datamuse's own, recorded at bundling time, so the fallback looks
     * exactly like a live pool rather than like an error page wearing one. Twenty
     * entries is deep enough that the largest count the schema allows still draws
     * without replacement.
     */
    private const FALLBACK_POOL = [
        ['word' => 'sea', 'score' => 51423, 'syllables' => 1],
        ['word' => 'oceanic', 'score' => 45178, 'syllables' => 4],
        ['word' => 'marine', 'score' => 41002, 'syllables' => 2],
        ['word' => 'deep', 'score' => 38119, 'syllables' => 1],
        ['word' => 'tide', 'score' => 35760, 'syllables' => 1],
        ['word' => 'abyss', 'score' => 33208, 'syllables' => 2],
        ['word' => 'seawater', 'score' => 31544, 'syllables' => 3],
        ['word' => 'pelagic', 'score' => 29877, 'syllables' => 3],
        ['word' => 'atlantic', 'score' => 28190, 'syllables' => 3],
        ['word' => 'pacific', 'score' => 27655, 'syllables' => 3],
        ['word' => 'estuary', 'score' => 25301, 'syllables' => 4],
        ['word' => 'lagoon', 'score' => 24088, 'syllables' => 2],
        ['word' => 'current', 'score' => 22914, 'syllables' => 2],
        ['word' => 'plankton', 'score' => 21677, 'syllables' => 2],
        ['word' => 'trench', 'score' => 20455, 'syllables' => 1],
        ['word' => 'brine', 'score' => 19320, 'syllables' => 1],
        ['word' => 'surf', 'score' => 18106, 'syllables' => 1],
        ['word' => 'archipelago', 'score' => 16988, 'syllables' => 5],
        ['word' => 'seabed', 'score' => 15743, 'syllables' => 2],
        ['word' => 'undertow', 'score' => 14502, 'syllables' => 3],
        ['word' => 'littoral', 'score' => 13374, 'syllables' => 3],
        ['word' => 'saltwater', 'score' => 12209, 'syllables' => 3],
        ['word' => 'benthic', 'score' => 11055, 'syllables' => 2],
        ['word' => 'shoal', 'score' => 9861, 'syllables' => 1],
        ['word' => 'fathom', 'score' => 8730, 'syllables' => 2],
    ];
}
