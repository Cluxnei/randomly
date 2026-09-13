<?php

declare(strict_types=1);

namespace App\Random\Generators\Contracts;

use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Renderer;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

interface Generator
{
    public function key(): string;

    public function name(): string;

    public function tagline(): string;

    public function module(): Module;

    public function renderer(): Renderer;

    /**
     * Bump whenever the algorithm changes what a given seed produces.
     *
     * Permalinks carry this number. A replay against a bumped generator fails
     * loudly rather than quietly returning something different — reproducibility
     * that silently stops reproducing is worse than none.
     */
    public function version(): int;

    public function schema(): ParamSchema;

    /**
     * Does this generator produce secrets?
     *
     * Sensitive generators get no shareable permalink. A password with a public,
     * replayable URL is not a password, and the tidy fix is simply not to offer
     * the link.
     */
    public function isSensitive(): bool;

    /**
     * Can a permalink reproduce this result bit for bit?
     *
     * True for anything derived purely from the seed. False for a generator built
     * on live external material: the real Wikipedia article pool moves, so the
     * same seed against tomorrow's pool picks something else. Saying so lets the
     * studio withhold a link that would quietly stop working rather than shipping
     * a promise it cannot keep.
     */
    public function isReproducible(): bool;

    /**
     * Does this generator actually consume randomness?
     *
     * Almost all of them do. An identicon does not — it is a pure function of the
     * string you type, which is the entire point of an identicon. Saying so is not
     * pedantry: without it the studio would draw a seed from a live beacon,
     * possibly waiting on the network to do it, and then attach a receipt reading
     * "derived from a magnitude 4.2 earthquake" to a result that would have been
     * byte-identical had the earthquake never happened. That is a false receipt,
     * and a false receipt is the one thing this project cannot ship.
     */
    public function usesEntropy(): bool;

    /**
     * Pure: same Rng stream and params in, same Result out, forever.
     *
     * No clock, no network, no globals. All impurity lives upstream in EntropyPool.
     */
    public function generate(Rng $rng, Params $params): Result;
}
