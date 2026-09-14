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
 * Fictional people, for seeding a database or filling a mockup — docs/05 §5.
 *
 * Two rules shape this one, and both are about not producing something that could
 * be mistaken for a record of a real person.
 *
 * **It says what it is, everywhere.** The heading, the meta and every line of the
 * output carry the word fictional. A list of plausible names, ages and cities is
 * indistinguishable from a leaked customer export once it has been copied out of
 * this page, so the label travels with the data rather than sitting beside it.
 *
 * **It asks for less than the API offers.** randomuser.me will return a photograph,
 * a phone number, a password and a national identifier for every person. The
 * photographs are of real people, and a real face attached to an invented name is
 * exactly the impersonation this project will not ship. Fabricated phone numbers
 * and national IDs are worse in a quieter way: they are perfectly well-formed, so
 * they can collide with a real person's, and somebody's number ends up in a test
 * fixture that eventually dials it. The request uses the API's own `inc` filter,
 * so those fields are never fetched at all — the cleanest way to prove they are
 * not in the output is that they were never in the response.
 *
 * The choosing is the seed's, as everywhere else in this module: the API is asked
 * for a pool and `generate()` draws from it.
 */
final class IdentityGenerator extends BaseGenerator implements NeedsData
{
    private const ENDPOINT = 'https://randomuser.me/api/';

    private const POOL_SIZE = 40;

    /**
     * The fields requested, and the only ones that exist downstream of the fetch.
     *
     * Deliberately short: name, where they are, how old they are, and an address
     * on example.com — a domain RFC 2606 reserves precisely so that it can never
     * reach anybody. No picture, no phone, no login, no id.
     */
    private const FIELDS = 'gender,name,location,email,dob,nat';

    /**
     * Nationalities randomuser.me supports, which is what decides whether a name
     * and a city hold together.
     *
     * The point of the filter is coherence: "Hiroshi Tanaka, Lyon" is a person
     * from a badly written form, not a person. Each nationality draws its names
     * and its places from the same locale.
     */
    public const NATIONALITIES = [
        'any' => 'Any (mixed)',
        'br' => 'Brazil',
        'ca' => 'Canada',
        'de' => 'Germany',
        'dk' => 'Denmark',
        'es' => 'Spain',
        'fi' => 'Finland',
        'fr' => 'France',
        'gb' => 'United Kingdom',
        'ie' => 'Ireland',
        'in' => 'India',
        'no' => 'Norway',
        'nl' => 'Netherlands',
        'nz' => 'New Zealand',
        'tr' => 'Turkey',
        'us' => 'United States',
    ];

    public function key(): string
    {
        return 'words.identity';
    }

    public function name(): string
    {
        return 'Fictional People';
    }

    public function tagline(): string
    {
        return 'Names that hold together across a nationality.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 5, min: 1, max: 20)
            ->enum('nationality', 'Nationality', self::NATIONALITIES, default: 'any', help: 'Names and places come from the same locale, so the person holds together. Mixed draws each person from a random one.')
            ->bool('with_location', 'Include where they live', default: true)
            ->bool('with_email', 'Include an email address', default: true, help: 'Always on example.com, which RFC 2606 reserves precisely so that test data cannot reach a real inbox.')
            ->bool('with_age', 'Include an age', default: true);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        [$pool, $live] = $this->pool($params);

        $count = min($params->int('count'), count($pool));
        $picked = $rng->sample($pool, $count);

        $lines = array_map(function (array $person) use ($params): string {
            $parts = [$person['name']];

            if ($params->bool('with_age') && $person['age'] !== null) {
                $parts[] = $person['age'].' years old';
            }

            if ($params->bool('with_location') && $person['location'] !== '') {
                $parts[] = $person['location'];
            }

            if ($params->bool('with_email') && $person['email'] !== null) {
                $parts[] = $person['email'];
            }

            return implode('  ·  ', $parts);
        }, $picked);

        return new Result(
            value: $picked,
            display: $this->heading($live).PHP_EOL.PHP_EOL.implode(PHP_EOL, $lines),
            meta: [
                'fictional' => true,
                'pool_size' => count($pool),
                'degraded' => ! $live,
                'nationality' => self::NATIONALITIES[$params->string('nationality', 'any')] ?? 'Any (mixed)',
                'entropy_out_bits' => round($this->pickBits(count($pool), $count), 2),
                'source' => $live ? 'randomuser.me' : 'bundled fallback list',
                'omitted_fields' => 'photograph, phone number, password, national ID — never requested',
                'note' => 'These people do not exist. The photographs randomuser.me offers are of real people and are never fetched; neither are phone numbers or national identifiers, which are fabricated well enough to collide with somebody real. Names, places and an @example.com address are the whole of it.',
            ],
        );
    }

    public function fetch(Params $params): array
    {
        $nationality = $params->string('nationality', 'any');

        $query = [
            'results' => self::POOL_SIZE,
            'inc' => self::FIELDS,
            // Drops the pagination block from the response. Nothing here reads it,
            // and a fetch should not carry fields it has no use for.
            'noinfo' => '',
        ];

        if ($nationality !== 'any' && array_key_exists($nationality, self::NATIONALITIES)) {
            $query['nat'] = $nationality;
        }

        $response = Http::timeout(5)->get(self::ENDPOINT, $query);

        if (! $response->successful()) {
            throw new \RuntimeException('randomuser.me returned '.$response->status().'.');
        }

        $pool = [];

        foreach ($response->json('results') ?? [] as $record) {
            $person = $this->normalise($record);

            if ($person !== null) {
                $pool[] = $person;
            }
        }

        if (count($pool) < 5) {
            throw new \RuntimeException('randomuser.me returned too few usable people to draw from.');
        }

        return ['pool' => $pool];
    }

    public function cacheSeconds(): int
    {
        // Five minutes. Shorter than the species pool because there is no truth
        // being cached here — a stale pool is only a repeat, and a repeat is the
        // one thing a "random person" generator should not serve all afternoon.
        return 300;
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

    private function heading(bool $live): string
    {
        return $live
            ? 'FICTIONAL PEOPLE — invented names and places for test data. These people do not exist.'
            : 'FICTIONAL PEOPLE — randomuser.me is unreachable, so these came from the bundled list. These people do not exist either.';
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
        $name = $record['name'] ?? null;

        if (! is_array($name) || ! isset($name['first'], $name['last'])) {
            return null;
        }

        $location = $record['location'] ?? [];

        $place = array_values(array_filter([
            is_string($location['city'] ?? null) ? $location['city'] : null,
            is_string($location['state'] ?? null) ? $location['state'] : null,
            is_string($location['country'] ?? null) ? $location['country'] : null,
        ]));

        return [
            'name' => trim(implode(' ', array_filter([
                $name['title'] ?? null,
                $name['first'],
                $name['last'],
            ]))),
            'gender' => is_string($record['gender'] ?? null) ? $record['gender'] : null,
            'age' => isset($record['dob']['age']) ? (int) $record['dob']['age'] : null,
            'location' => implode(', ', $place),
            // Guarded rather than trusted: the API is asked for example.com
            // addresses and has always sent them, but an address on a real domain
            // slipping into output labelled "test data" is somebody's inbox.
            'email' => $this->safeEmail($record['email'] ?? null),
            'nationality' => is_string($record['nat'] ?? null) ? $record['nat'] : null,
            'fictional' => true,
        ];
    }

    private function safeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        return str_ends_with(strtolower($email), '@example.com') ? $email : null;
    }

    /**
     * People for when randomuser.me is down.
     *
     * Written here rather than fetched and frozen, so nothing in the list is a
     * cached copy of what the API once said about anybody. Names and cities are
     * paired within one locale apiece, which is the whole point of the
     * nationality filter above.
     */
    private const FALLBACK_POOL = [
        ['name' => 'Ms Ingrid Halvorsen', 'gender' => 'female', 'age' => 41, 'location' => 'Bergen, Vestland, Norway', 'email' => 'ingrid.halvorsen@example.com', 'nationality' => 'no', 'fictional' => true],
        ['name' => 'Mr Tobias Lindqvist', 'gender' => 'male', 'age' => 29, 'location' => 'Uppsala, Uppsala, Sweden', 'email' => 'tobias.lindqvist@example.com', 'nationality' => 'se', 'fictional' => true],
        ['name' => 'Mrs Beatriz Carvalho', 'gender' => 'female', 'age' => 53, 'location' => 'Recife, Pernambuco, Brazil', 'email' => 'beatriz.carvalho@example.com', 'nationality' => 'br', 'fictional' => true],
        ['name' => 'Mr Kwame Mensah', 'gender' => 'male', 'age' => 36, 'location' => 'Kumasi, Ashanti, Ghana', 'email' => 'kwame.mensah@example.com', 'nationality' => 'gh', 'fictional' => true],
        ['name' => 'Miss Aiko Nakamura', 'gender' => 'female', 'age' => 24, 'location' => 'Sendai, Miyagi, Japan', 'email' => 'aiko.nakamura@example.com', 'nationality' => 'jp', 'fictional' => true],
        ['name' => 'Mr Eoin Gallagher', 'gender' => 'male', 'age' => 47, 'location' => 'Galway, Connacht, Ireland', 'email' => 'eoin.gallagher@example.com', 'nationality' => 'ie', 'fictional' => true],
        ['name' => 'Ms Priya Raghunathan', 'gender' => 'female', 'age' => 33, 'location' => 'Coimbatore, Tamil Nadu, India', 'email' => 'priya.raghunathan@example.com', 'nationality' => 'in', 'fictional' => true],
        ['name' => 'Mr Dylan Whitcombe', 'gender' => 'male', 'age' => 61, 'location' => 'Shrewsbury, Shropshire, United Kingdom', 'email' => 'dylan.whitcombe@example.com', 'nationality' => 'gb', 'fictional' => true],
        ['name' => 'Mrs Marta Kowalczyk', 'gender' => 'female', 'age' => 38, 'location' => 'Wrocław, Lower Silesia, Poland', 'email' => 'marta.kowalczyk@example.com', 'nationality' => 'pl', 'fictional' => true],
        ['name' => 'Mr Hugo Ferreira', 'gender' => 'male', 'age' => 27, 'location' => 'Coimbra, Centro, Portugal', 'email' => 'hugo.ferreira@example.com', 'nationality' => 'pt', 'fictional' => true],
        ['name' => 'Ms Noor Al-Amin', 'gender' => 'female', 'age' => 44, 'location' => 'Amman, Amman, Jordan', 'email' => 'noor.alamin@example.com', 'nationality' => 'jo', 'fictional' => true],
        ['name' => 'Mr Lucas Meunier', 'gender' => 'male', 'age' => 31, 'location' => 'Nantes, Pays de la Loire, France', 'email' => 'lucas.meunier@example.com', 'nationality' => 'fr', 'fictional' => true],
        ['name' => 'Miss Sofia Bianchi', 'gender' => 'female', 'age' => 22, 'location' => 'Bologna, Emilia-Romagna, Italy', 'email' => 'sofia.bianchi@example.com', 'nationality' => 'it', 'fictional' => true],
        ['name' => 'Mr Andreas Fischer', 'gender' => 'male', 'age' => 56, 'location' => 'Freiburg, Baden-Württemberg, Germany', 'email' => 'andreas.fischer@example.com', 'nationality' => 'de', 'fictional' => true],
        ['name' => 'Mrs Elena Vargas', 'gender' => 'female', 'age' => 49, 'location' => 'Valparaíso, Valparaíso, Chile', 'email' => 'elena.vargas@example.com', 'nationality' => 'cl', 'fictional' => true],
        ['name' => 'Mr Jesse Kauppinen', 'gender' => 'male', 'age' => 34, 'location' => 'Tampere, Pirkanmaa, Finland', 'email' => 'jesse.kauppinen@example.com', 'nationality' => 'fi', 'fictional' => true],
        ['name' => 'Ms Chioma Okonkwo', 'gender' => 'female', 'age' => 26, 'location' => 'Enugu, Enugu, Nigeria', 'email' => 'chioma.okonkwo@example.com', 'nationality' => 'ng', 'fictional' => true],
        ['name' => 'Mr Mateo Giménez', 'gender' => 'male', 'age' => 39, 'location' => 'Rosario, Santa Fe, Argentina', 'email' => 'mateo.gimenez@example.com', 'nationality' => 'ar', 'fictional' => true],
        ['name' => 'Mrs Laila Haddad', 'gender' => 'female', 'age' => 58, 'location' => 'Sfax, Sfax, Tunisia', 'email' => 'laila.haddad@example.com', 'nationality' => 'tn', 'fictional' => true],
        ['name' => 'Mr Ruben de Vries', 'gender' => 'male', 'age' => 45, 'location' => 'Groningen, Groningen, Netherlands', 'email' => 'ruben.devries@example.com', 'nationality' => 'nl', 'fictional' => true],
    ];
}
