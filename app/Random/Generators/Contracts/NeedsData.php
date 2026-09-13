<?php

declare(strict_types=1);

namespace App\Random\Generators\Contracts;

use App\Random\Generators\Params;

/**
 * A generator whose raw material comes from somewhere else.
 *
 * `generate()` is pure by contract — no clock, no network, no globals — which is
 * what makes determinism testable and replay possible. A generator that wants a
 * real Wikipedia article or a real species binomial cannot honour that on its own,
 * so the fetching is lifted out: the Studio calls `fetch()` (impure, cached,
 * timed out, with a fallback), hands the result to the generator through
 * `Params::withData()`, and `generate()` stays a pure function of its inputs.
 *
 * **Fetch a pool, not an answer.** `fetch()` should return many candidates and let
 * the Rng choose between them. A source that returns one random item has done the
 * choosing itself, which would make the receipt a lie — the entropy would have
 * come from someone else's server, not from the beacon we credited.
 */
interface NeedsData
{
    /**
     * Collect candidate material. May be slow, may fail — the Studio catches.
     *
     * @return array<string, mixed>
     */
    public function fetch(Params $params): array;

    /** Seconds this material stays usable. Keeps us off other people's APIs. */
    public function cacheSeconds(): int;

    /** What to work from when the fetch fails. Never an exception: the page still renders. */
    public function fallback(): array;
}
