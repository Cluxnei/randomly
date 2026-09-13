<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Random\Entropy\EntropyPool;
use App\Random\GeneratorRegistry;
use App\Random\Og\OgCard;
use App\Random\Og\OgImage;
use App\Random\Studio\Generation;
use App\Random\Studio\Studio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The images a shared link previews as.
 *
 * These are re-derived from the URL rather than looked up, for the same reason
 * /r/{token} is: the token is the seed, so the card for a permalink can be drawn
 * by a crawler that arrives a month later without anything having been stored.
 */
final class OgController extends Controller
{
    public function __construct(
        private readonly GeneratorRegistry $registry,
        private readonly EntropyPool $pool,
        private readonly Studio $studio,
        private readonly OgImage $images,
    ) {}

    /** The card for one exact past result, recomputed from its token. */
    public function result(string $token, Request $request): BinaryFileResponse
    {
        $key = (string) $request->query('g', '');

        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("No generator at [{$key}].");
        }

        $generator = $this->registry->findOrFail($key);

        try {
            $generation = $this->studio->replay(
                token: $token,
                generatorKey: $key,
                version: (int) $request->query('v', 1),
                input: Generation::decodeParams($request->query('p')),
                sourceKey: $request->query('s'),
                reference: $request->query('r'),
            );

            $card = OgCard::forGeneration($generation);

            // A replayed receipt is rebuilt from a source key and a reference, so
            // it loses the sentence that made it worth sharing. If the link
            // carries the original and the signature checks out, use it. An
            // unsigned or altered one is silently dropped rather than rejected —
            // a crawler should still get a correct card, just a plainer one.
            $narrative = Generation::verifyNarrative($request->query('n'), $request->query('ns'));

            if ($narrative !== null) {
                $card = $card->withNarrative($narrative);
            }
        } catch (\Throwable) {
            // A bad token, a bumped version, a generator that refuses to replay:
            // the page itself says so in words, and the preview falls back to the
            // generator's own card rather than 404ing a share into a blank box.
            $card = OgCard::forGenerator($generator);
        }

        return $this->serve($card);
    }

    /**
     * The card for a studio page.
     *
     * `?t=` carries the token of the result that was on the page when it was
     * rendered, so the preview shows what the sharer actually saw. Without it —
     * a password, a generator built on live material, a link someone typed — the
     * card falls back to describing the generator, which is the only thing that
     * is true for every visit to that URL.
     */
    public function generator(string $module, string $generator, Request $request): BinaryFileResponse
    {
        $key = "{$module}.{$generator}";

        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("No generator at [{$key}].");
        }

        $instance = $this->registry->findOrFail($key);
        $token = (string) $request->query('t', '');

        if ($token !== '' && ! $instance->isSensitive() && $instance->isReproducible()) {
            try {
                return $this->serve(OgCard::forGeneration($this->studio->replay(
                    token: $token,
                    generatorKey: $key,
                    version: $instance->version(),
                    input: Generation::decodeParams($request->query('p')),
                    sourceKey: $request->query('s'),
                    reference: $request->query('r'),
                )));
            } catch (\Throwable) {
                // Fall through to the generator card.
            }
        }

        return $this->serve(OgCard::forGenerator($instance));
    }

    /** The card every page that is not a single result previews as. */
    public function site(): BinaryFileResponse
    {
        return $this->serve(OgCard::forSite(
            generators: $this->registry->all()->count(),
            sources: count($this->pool->sources()),
        ));
    }

    private function serve(OgCard $card): BinaryFileResponse
    {
        return response()
            ->file($this->images->path($card), [
                'Content-Type' => 'image/png',
                // A day, not a year: the URL is not itself content-addressed —
                // the file behind it is — so a crawler that comes back should be
                // allowed to notice a redesign.
                'Cache-Control' => 'public, max-age=86400',
            ])
            ->setEtag($card->fingerprint());
    }
}
