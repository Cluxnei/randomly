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
 * A real species binomial, drawn from the GBIF backbone taxonomy.
 *
 * Same discipline as the Wikipedia generator: fetch a pool, let the seed choose.
 * The twist here is that GBIF's search results are ordered taxonomically, so
 * twenty consecutive records at one offset are twenty weevils from one genus and
 * the "choice" would be a choice between near-identical things. The pool is
 * therefore assembled from several taxonomic groups at once — birds, mammals,
 * ferns, fungi — which is what makes the draw feel like a draw.
 */
final class SpeciesGenerator extends BaseGenerator implements NeedsData
{
    private const ENDPOINT = 'https://api.gbif.org/v1/species/search';

    /** The GBIF Backbone Taxonomy. Restricting to it keeps names canonical rather than one of forty synonyms. */
    private const BACKBONE = 'd7dddbf4-2cf0-4f39-9b2a-bb099caae36c';

    /**
     * Groups to draw from, with a conservative ceiling on the offset.
     *
     * The ceilings are well inside each group's accepted-species count as of the
     * bundling date. GBIF returns an empty page for an offset past the end, so a
     * ceiling that has gone stale costs one slice of the pool rather than an
     * error — which is why they are deliberately pessimistic.
     */
    private const GROUPS = [
        ['key' => 212, 'label' => 'birds', 'max_offset' => 12_000],
        ['key' => 359, 'label' => 'mammals', 'max_offset' => 18_000],
        ['key' => 131, 'label' => 'amphibians', 'max_offset' => 8_000],
        ['key' => 121, 'label' => 'sharks and rays', 'max_offset' => 3_000],
        ['key' => 216, 'label' => 'insects', 'max_offset' => 900_000],
        ['key' => 367, 'label' => 'arachnids', 'max_offset' => 90_000],
        ['key' => 220, 'label' => 'flowering plants', 'max_offset' => 250_000],
        ['key' => 196, 'label' => 'monocots', 'max_offset' => 80_000],
        ['key' => 186, 'label' => 'mushroom-forming fungi', 'max_offset' => 35_000],
        ['key' => 7_228_684, 'label' => 'ferns', 'max_offset' => 12_000],
    ];

    /** Five groups × four records. Twenty candidates spread across five branches of the tree of life. */
    private const SLICES = 5;

    private const PER_SLICE = 4;

    public function key(): string
    {
        return 'words.species';
    }

    public function name(): string
    {
        return 'Random Life';
    }

    public function tagline(): string
    {
        return 'A real species drawn from GBIF — twenty million names, one of them yours.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 5, min: 1, max: 12)
            ->bool('with_lineage', 'Show the lineage', default: true, help: 'Kingdom down to family, which is usually more surprising than the name.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$pool, $live] = $this->pool($params);
        $count = min($params->int('count'), count($pool));
        $picked = $rng->sample($pool, $count);

        $lines = array_map(function (array $species) use ($params): string {
            $line = $species['name'];

            if ($species['common'] !== null) {
                $line .= ' — '.$species['common'];
            }

            if ($species['authorship'] !== null && $species['authorship'] !== '') {
                $line .= ' · '.$species['authorship'];
            }

            if ($params->bool('with_lineage') && $species['lineage'] !== '') {
                $line .= PHP_EOL.'  '.$species['lineage'];
            }

            return $line;
        }, $picked);

        return new Result(
            value: $picked,
            display: implode(PHP_EOL.PHP_EOL, $lines),
            meta: [
                'pool_size' => count($pool),
                'degraded' => ! $live,
                'entropy_out_bits' => round($this->pickBits(count($pool), $count), 2),
                'source' => 'GBIF Backbone Taxonomy',
                'note' => 'The pool spans several taxonomic groups on purpose: twenty consecutive GBIF records are twenty beetles from one genus, and choosing between those is not much of a choice.',
            ],
        );
    }

    public function fetch(Params $params): array
    {
        // PHP's own randomness here, never the seeded Rng: this runs outside
        // generate() and must not touch the stream the receipt describes. Which
        // slices of GBIF land in the pool is external material, exactly like the
        // articles Wikipedia happens to return; the seeded choice happens later.
        $groups = (array) array_rand(self::GROUPS, self::SLICES);

        $responses = Http::pool(fn ($pool) => array_map(
            function (int $index) use ($pool): mixed {
                $group = self::GROUPS[$index];

                return $pool->timeout(4)->get(self::ENDPOINT, [
                    'datasetKey' => self::BACKBONE,
                    'rank' => 'SPECIES',
                    'status' => 'ACCEPTED',
                    'highertaxonKey' => $group['key'],
                    'limit' => self::PER_SLICE,
                    'offset' => random_int(0, $group['max_offset']),
                ]);
            },
            $groups,
        ));

        $species = [];

        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            foreach ($response->json('results') ?? [] as $record) {
                $entry = $this->normalise($record);

                if ($entry !== null) {
                    $species[$entry['name']] = $entry;
                }
            }
        }

        if (count($species) < 5) {
            throw new \RuntimeException('GBIF returned too few usable species to draw from.');
        }

        return ['pool' => array_values($species)];
    }

    public function cacheSeconds(): int
    {
        // Longer than the Wikipedia pool: GBIF's taxonomy changes on the order of
        // months, so re-fetching often would be traffic spent on nothing.
        return 900;
    }

    public function fallback(): array
    {
        return ['pool' => self::FALLBACK_POOL];
    }

    /**
     * The candidates to choose between, and whether they came from the live API.
     *
     * generate() is pure and must work with no data attached at all — that is how the
     * determinism tests run it, and how the page renders when GBIF is down. The flag
     * travels with the pool so the result can say which happened: a fallback
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
    private function normalise(array $record): ?array
    {
        $name = $record['canonicalName'] ?? null;

        // A binomial is two words. Anything else in a SPECIES-ranked record is a
        // hybrid formula or a malformed entry, and neither is what was asked for.
        if (! is_string($name) || substr_count(trim($name), ' ') !== 1) {
            return null;
        }

        $lineage = array_values(array_filter([
            $record['kingdom'] ?? null,
            $record['phylum'] ?? null,
            $record['class'] ?? null,
            $record['order'] ?? null,
            $record['family'] ?? null,
        ]));

        return [
            'name' => trim($name),
            // GBIF authorship strings routinely carry trailing whitespace, which
            // turns into a visible stray space in the middle of a rendered line.
            'authorship' => isset($record['authorship']) ? trim((string) $record['authorship']) : null,
            'common' => $this->vernacular($record),
            'lineage' => implode(' › ', $lineage),
            'key' => $record['key'] ?? null,
            'url' => isset($record['key']) ? 'https://www.gbif.org/species/'.$record['key'] : null,
        ];
    }

    /**
     * GBIF lists vernacular names in every language it has one for, unlabelled by
     * usefulness. The first English one is the only reliably readable choice; a
     * species with none keeps just its binomial, which is no loss.
     */
    private function vernacular(array $record): ?string
    {
        foreach ($record['vernacularNames'] ?? [] as $entry) {
            if (($entry['language'] ?? null) === 'eng' && isset($entry['vernacularName'])) {
                return $entry['vernacularName'];
            }
        }

        return null;
    }

    /**
     * Real binomials for when GBIF is unreachable — and chosen to be worth reading,
     * since a fallback that says "unavailable" makes the outage the product.
     */
    private const FALLBACK_POOL = [
        ['name' => 'Chrysaora achlyos', 'authorship' => 'Martin, Gershwin, Burnett, Cargo & Bloom, 1997', 'common' => 'black sea nettle', 'lineage' => 'Animalia › Cnidaria › Scyphozoa › Semaeostomeae › Pelagiidae', 'key' => null, 'url' => null],
        ['name' => 'Welwitschia mirabilis', 'authorship' => 'Hook.f.', 'common' => 'tree tumbo', 'lineage' => 'Plantae › Tracheophyta › Gnetopsida › Welwitschiales › Welwitschiaceae', 'key' => null, 'url' => null],
        ['name' => 'Rhinopithecus roxellana', 'authorship' => 'Milne-Edwards, 1870', 'common' => 'golden snub-nosed monkey', 'lineage' => 'Animalia › Chordata › Mammalia › Primates › Cercopithecidae', 'key' => null, 'url' => null],
        ['name' => 'Hydnellum peckii', 'authorship' => 'Banker', 'common' => 'bleeding tooth fungus', 'lineage' => 'Fungi › Basidiomycota › Agaricomycetes › Thelephorales › Bankeraceae', 'key' => null, 'url' => null],
        ['name' => 'Eunice aphroditois', 'authorship' => 'Pallas, 1788', 'common' => 'bobbit worm', 'lineage' => 'Animalia › Annelida › Polychaeta › Eunicida › Eunicidae', 'key' => null, 'url' => null],
        ['name' => 'Phyllopteryx dewysea', 'authorship' => 'Stiller, Wilson & Rouse, 2015', 'common' => 'ruby seadragon', 'lineage' => 'Animalia › Chordata › Actinopterygii › Syngnathiformes › Syngnathidae', 'key' => null, 'url' => null],
        ['name' => 'Amorphophallus titanum', 'authorship' => '(Becc.) Becc.', 'common' => 'titan arum', 'lineage' => 'Plantae › Tracheophyta › Liliopsida › Alismatales › Araceae', 'key' => null, 'url' => null],
        ['name' => 'Opisthocomus hoazin', 'authorship' => '(Statius Muller, 1776)', 'common' => 'hoatzin', 'lineage' => 'Animalia › Chordata › Aves › Opisthocomiformes › Opisthocomidae', 'key' => null, 'url' => null],
        ['name' => 'Grimpoteuthis bathynectes', 'authorship' => 'Voss & Pearcy, 1990', 'common' => 'dumbo octopus', 'lineage' => 'Animalia › Mollusca › Cephalopoda › Octopoda › Opisthoteuthidae', 'key' => null, 'url' => null],
        ['name' => 'Dionaea muscipula', 'authorship' => 'J.Ellis', 'common' => 'Venus flytrap', 'lineage' => 'Plantae › Tracheophyta › Magnoliopsida › Caryophyllales › Droseraceae', 'key' => null, 'url' => null],
    ];
}
