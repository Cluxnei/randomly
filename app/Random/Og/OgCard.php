<?php

declare(strict_types=1);

namespace App\Random\Og;

use App\Random\Generators\Contracts\Generator;
use App\Random\Studio\Generation;

/**
 * Everything that ends up on a share card, and nothing else.
 *
 * Deliberately a bag of strings rather than a Generation: the renderer below it
 * must not be able to reach for a fact nobody decided to put on the card, and
 * the fingerprint that names the cached file is then simply a hash of what is
 * actually drawn. Change the copy, get a new file; change nothing, get a cache
 * hit.
 */
final readonly class OgCard
{
    /**
     * @param  list<string>  $palette  Hex colours, drawn as a strip. Empty for generators that make no colour.
     * @param  'signal'|'warn'|'muted'  $tone
     */
    public function __construct(
        public string $title,
        public ?string $narrative = null,
        public ?string $badge = null,
        public string $tone = 'signal',
        public array $palette = [],
        public ?string $footerLeft = null,
        public ?string $footerRight = null,
    ) {}

    /**
     * Swap in the original receipt sentence.
     *
     * Used when a permalink carries a signed narrative from the moment of
     * generation, which a replay cannot otherwise recover. Returns a new card, so
     * the fingerprint — and therefore the cached filename — changes with it.
     */
    public function withNarrative(string $narrative): self
    {
        return new self(
            title: $this->title,
            narrative: $narrative,
            badge: $this->badge,
            tone: $this->tone,
            palette: $this->palette,
            footerLeft: $this->footerLeft,
            footerRight: $this->footerRight,
        );
    }

    /**
     * The card for one finished result.
     *
     * The title is the generator's own `display` string and the sub-line is the
     * receipt narrative, both verbatim — a card that paraphrased either would be
     * advertising something the page does not say.
     */
    public static function forGeneration(Generation $generation): self
    {
        $receipt = $generation->receipt;
        $palette = $generation->result->meta['palette'] ?? [];

        return new self(
            title: $generation->result->display,
            narrative: $receipt->narrative,
            badge: sprintf(
                'Class %s · %s%s',
                $receipt->class->value,
                $receipt->class->label(),
                $receipt->degraded ? ' · degraded' : '',
            ),
            tone: $receipt->degraded ? 'warn' : 'signal',
            palette: is_array($palette) ? array_values(array_filter($palette, 'is_string')) : [],
            footerLeft: $generation->generator->name().' · '.$generation->generator->key(),
            footerRight: self::shortUrl($generation->permalink()),
        );
    }

    /**
     * The card for a generator with no result on it.
     *
     * Used where showing the result would be wrong rather than merely awkward: a
     * password generator's output must never be baked into an image that gets
     * posted, and a generator whose material is fetched live cannot promise the
     * link will still produce it. The tagline is the honest thing to show.
     */
    public static function forGenerator(Generator $generator): self
    {
        return new self(
            title: $generator->name(),
            narrative: $generator->tagline(),
            badge: $generator->isSensitive() ? 'Never replayable · makes secrets' : 'Seeded from the physical world',
            tone: $generator->isSensitive() ? 'warn' : 'signal',
            footerLeft: $generator->key().' · v'.$generator->version(),
            footerRight: self::shortUrl(route('studio', [
                'module' => explode('.', $generator->key(), 2)[0],
                'generator' => explode('.', $generator->key(), 2)[1],
            ])),
        );
    }

    /** The site's own card: the pitch, plus the two counts that back it up. */
    public static function forSite(int $generators, int $sources): self
    {
        return new self(
            title: 'Randomness, sourced from reality.',
            narrative: sprintf(
                '%d generators, seeded from %d live entropy sources. Every result ships with a receipt naming where its randomness came from, and replays bit-for-bit from its URL.',
                $generators,
                $sources,
            ),
            badge: 'No accounts · No keys',
            footerLeft: 'A library of random generators',
            footerRight: self::shortUrl(route('home')),
        );
    }

    /**
     * A hash of everything drawn, which is also the cached filename.
     *
     * The renderer's version is folded in so that changing the layout
     * invalidates every card on disk without anyone having to remember to empty
     * the directory.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            OgImage::VERSION,
            $this->title,
            $this->narrative,
            $this->badge,
            $this->tone,
            $this->palette,
            $this->footerLeft,
            $this->footerRight,
        ], JSON_THROW_ON_ERROR)), 0, 40);
    }

    /**
     * Hosts and paths read on a card; query strings do not.
     *
     * A permalink carries the generator, the version and base64'd parameters and
     * runs to a few hundred characters. The half a reader can actually use is
     * the front.
     */
    private static function shortUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['host'] ?? '').($parts['path'] ?? '');
    }
}
