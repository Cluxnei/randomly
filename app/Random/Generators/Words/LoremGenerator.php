<?php

declare(strict_types=1);

namespace App\Random\Generators\Words;

use App\Random\Generators\Contracts\BaseGenerator;
use App\Random\Generators\Module;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Generators\Result;
use App\Random\Rng\Rng;

/**
 * Placeholder prose whose sentences are log-normally long.
 *
 * Every lorem generator on the internet draws sentence length uniformly, and the
 * result reads like a list: every line the same weight, no rhythm, nothing that
 * looks like a paragraph. Real prose is log-normal — a pile of short sentences, a
 * long tail of long ones — so lengths come from
 *
 *     L = round(exp(μ + σ·Z)),  μ = ln 14,  σ = 0.45,  Z ~ N(0,1)
 *
 * clamped to [4, 40]. That single line is the entire difference, and it is why
 * this file bothers to exist next to a hundred npm packages that do the same job.
 */
final class LoremGenerator extends BaseGenerator
{
    /** ln(14): fourteen words is the middle of the range most English prose sits in. */
    private const MU = 2.6390573296153; // log(14)

    /** Wide enough for a five-word sentence next to a thirty-word one; wider reads as noise. */
    private const SIGMA = 0.45;

    private const MIN_WORDS = 4;

    private const MAX_WORDS = 40;

    /** Commas per word. 0.3 per ten words is roughly what edited English does. */
    private const COMMA_RATE = 0.03;

    /** One sentence in eight takes a dash instead of one of its commas. */
    private const DASH_CHANCE = 0.12;

    private const TERMINATORS = ['.' => 90, '?' => 6, '!' => 4];

    /** How many invented words the markov flavour coins before it starts reusing them. */
    private const MARKOV_LEXICON = 48;

    /** Rank offset that turns a brutal 1/rank curve into a readable one. See inventedVocabulary(). */
    private const ZIPF_FLATTENING = 9;

    /** @var array<string, mixed>|null */
    private static ?array $vocabularies = null;

    public function key(): string
    {
        return 'words.lorem';
    }

    public function name(): string
    {
        return 'Lorem';
    }

    public function tagline(): string
    {
        return 'Sentence lengths drawn log-normally, so it reads like prose and not like a list.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('paragraphs', 'Paragraphs', default: 3, min: 1, max: 12)
            ->int('sentences', 'Sentences each', default: 5, min: 1, max: 12, help: 'Give or take one — paragraphs of identical length are the other tell of generated text.')
            ->enum('flavour', 'Flavour', [
                'latin' => 'Latin · the classical filler',
                'tech' => 'Tech · plausible engineering prose',
                'markov' => 'Invented · a language that never existed',
            ], default: 'latin')
            ->bool('start_with_lorem', 'Open with "Lorem ipsum"', default: true, help: 'Latin only. The opening everyone recognises as placeholder text.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $flavour = $params->string('flavour');
        [$words, $connectives, $rate, $weights] = $this->vocabulary($rng, $flavour);

        $paragraphs = [];
        $lengths = [];
        $bits = 0.0;

        for ($p = 0; $p < $params->int('paragraphs'); $p++) {
            // ±1 sentence, because paragraphs of exactly equal length are the other
            // giveaway that text was generated.
            $count = max(1, $params->int('sentences') + $rng->intBetween(-1, 1));
            $sentences = [];

            for ($s = 0; $s < $count; $s++) {
                $opening = $p === 0 && $s === 0 ? $this->opening($flavour, $params) : null;

                if ($opening !== null) {
                    $sentences[] = $opening;
                    $lengths[] = str_word_count($opening);

                    continue;
                }

                $length = $this->sentenceLength($rng);
                $lengths[] = $length;
                $sentences[] = $this->sentence($rng, $length, $words, $connectives, $rate, $weights, $bits);
            }

            $paragraphs[] = implode(' ', $sentences);
        }

        return new Result(
            value: [
                'paragraphs' => $paragraphs,
                'sentence_lengths' => $lengths,
            ],
            display: implode(PHP_EOL.PHP_EOL, $paragraphs),
            meta: [
                'flavour' => $flavour,
                'word_count' => array_sum($lengths),
                'sentence_lengths' => $lengths,
                'mean_sentence_words' => round(array_sum($lengths) / max(1, count($lengths)), 1),
                'length_model' => sprintf(
                    'log-normal: round(exp(μ + σZ)), μ = ln 14, σ = %s, clamped to [%d, %d]',
                    self::SIGMA,
                    self::MIN_WORDS,
                    self::MAX_WORDS,
                ),
                'entropy_out_bits' => round($bits, 2),
                'entropy_note' => 'Word choices only. The length and punctuation draws add more, but counting a continuous draw in bits needs a quantisation nobody agreed on.',
            ],
        );
    }

    /**
     * The one line the whole generator is built around.
     *
     * Clamping truncates the distribution rather than resampling it, which biases
     * the tails very slightly toward the bounds. At μ = ln 14 and σ = 0.45 the
     * bounds sit past three sigma in both directions, so it costs nothing
     * measurable and it guarantees a sentence can never be one word or a page.
     */
    private function sentenceLength(Rng $rng): int
    {
        $length = (int) round(exp(self::MU + self::SIGMA * $rng->gaussian()));

        return max(self::MIN_WORDS, min(self::MAX_WORDS, $length));
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $connectives
     * @param  array<string, int|float>|null  $weights  Zipf weights over $words, or null for uniform
     */
    private function sentence(
        Rng $rng,
        int $length,
        array $words,
        array $connectives,
        float $rate,
        ?array $weights,
        float &$bits,
    ): string {
        $chosen = [];
        $previousWasConnective = false;

        // A comma or a dash directly after a function word ("latency without —
        // proxy") is the punctuation equivalent of a dangling preposition: the mark
        // has to fall at the end of a phrase, and a connective is never the end of one.
        $unmarkable = [];

        for ($i = 0; $i < $length; $i++) {
            // Never two function words in a row, and never one at the end: "of the"
            // and a sentence trailing off on "with" are what make naive filler text
            // read as broken rather than as foreign.
            $useConnective = $connectives !== []
                && ! $previousWasConnective
                && $i > 0
                && $i < $length - 1
                && $rng->bool($rate);

            if ($useConnective) {
                $unmarkable[$i] = true;
                $chosen[] = $rng->pick($connectives);
                $bits += log(count($connectives), 2);
                $previousWasConnective = true;

                continue;
            }

            $chosen[] = $this->contentWord($rng, $words, $weights, end($chosen) ?: null, $bits);
            $previousWasConnective = false;
        }

        return $this->punctuate($rng, $chosen, $unmarkable);
    }

    /**
     * One content word that is not the word immediately before it.
     *
     * English essentially never repeats a word back to back, so "serialise
     * serialise" reads as a bug rather than as filler. Redrawing does bend the
     * distribution very slightly away from the flavour's weights — the cost is
     * three attempts at most, and it is paid only where the alternative is output
     * nobody would accept.
     *
     * @param  list<string>  $words
     * @param  array<string, int|float>|null  $weights
     */
    private function contentWord(Rng $rng, array $words, ?array $weights, ?string $previous, float &$bits): string
    {
        $candidate = '';
        $candidateBits = 0.0;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidateBits = 0.0;

            if ($weights !== null) {
                $candidate = Weights::draw($rng, $weights, $candidateBits);
            } else {
                $candidate = $rng->pick($words);
                $candidateBits = log(count($words), 2);
            }

            if ($candidate !== $previous) {
                break;
            }
        }

        $bits += $candidateBits;

        return $candidate;
    }

    /**
     * @param  list<string>  $words
     * @param  array<int, true>  $unmarkable  positions no mark may follow
     */
    private function punctuate(Rng $rng, array $words, array $unmarkable = []): string
    {
        $length = count($words);
        $marks = [];

        // Commas are events in a stretch of text, which is what a Poisson counts.
        // Drawing a fixed comma count instead would put a comma in every four-word
        // sentence and none in a thirty-word one.
        $commas = $this->poisson($rng, self::COMMA_RATE * $length);

        for ($i = 0; $i < $commas; $i++) {
            // Never on the last two words: a comma immediately before a full stop is
            // the single most obvious artefact of generated punctuation.
            if ($length < 6) {
                break;
            }

            $at = $rng->intBetween(1, $length - 3);

            if (isset($marks[$at]) || isset($marks[$at - 1]) || isset($marks[$at + 1]) || isset($unmarkable[$at])) {
                continue;
            }

            $marks[$at] = ',';
        }

        if ($marks !== [] && $rng->bool(self::DASH_CHANCE)) {
            $marks[array_key_first($marks)] = ' —';
        }

        $text = '';
        foreach ($words as $i => $word) {
            $text .= $word.($marks[$i] ?? '').($i === $length - 1 ? '' : ' ');
        }

        return ucfirst($text).Weights::draw($rng, self::TERMINATORS);
    }

    /**
     * Knuth's method: multiply uniforms until the product drops below e^−λ.
     *
     * Exact rather than approximate, and at λ well under one — a comma every thirty
     * words or so — it almost always returns after a single draw.
     */
    private function poisson(Rng $rng, float $lambda): int
    {
        $limit = exp(-$lambda);
        $product = 1.0;
        $k = 0;

        do {
            $k++;
            $product *= $rng->float();
        } while ($product > $limit);

        return $k - 1;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: float, 3: array<string, float>|null}
     */
    private function vocabulary(Rng $rng, string $flavour): array
    {
        if ($flavour === 'markov') {
            return $this->inventedVocabulary($rng);
        }

        $data = self::vocabularies()[$flavour] ?? self::vocabularies()['latin'];

        return [$data['words'], $data['connectives'], (float) $data['connective_rate'], null];
    }

    /**
     * Coin a small lexicon, then reuse it — with real English function words holding
     * it together.
     *
     * A page of never-repeated invented words reads as noise; a page that reuses
     * four dozen of them reads as a language, because that is what a language does.
     * The function words are Jabberwocky's trick: keep the grammar words real and
     * the reader's ear supplies a syntax the generator never wrote.
     *
     * @return array{0: list<string>, 1: list<string>, 2: float, 3: array<string, float>}
     */
    private function inventedVocabulary(Rng $rng): array
    {
        $chain = MarkovChain::for(Corpus::LARGE, 3);
        $lexicon = [];

        // Bounded, because an unbounded loop inside a web request is one bad tuning
        // change away from a hung worker. A short lexicon still reads fine.
        $attempts = 0;

        while (count($lexicon) < self::MARKOV_LEXICON && ++$attempts < 5_000) {
            $candidate = $chain->attempt($rng, 3, 9);

            if ($candidate === null || $chain->isRealWord($candidate) || ! MarkovChain::hasVowel($candidate)) {
                continue;
            }

            // Keyed, because a duplicate would silently get double the Zipf weight of
            // the rank it happened to land on.
            $lexicon[$candidate] = true;
        }

        // Zipf, flattened. A pure 1/rank curve over a lexicon this small hands the
        // top word 22% of every sentence, and the output turns into one word
        // chanted at you. Offsetting the rank stretches the head of the curve out
        // to roughly what "the" gets in real English — common, not deafening.
        $weights = [];
        $rank = 0;
        foreach (array_keys($lexicon) as $word) {
            $weights[$word] = 1 / (++$rank + self::ZIPF_FLATTENING);
        }

        return [
            array_keys($lexicon),
            self::vocabularies()['tech']['connectives'],
            0.3,
            $weights,
        ];
    }

    private function opening(string $flavour, Params $params): ?string
    {
        $opening = self::vocabularies()[$flavour]['opening'] ?? null;

        if ($opening === null || ! $params->bool('start_with_lorem')) {
            return null;
        }

        return ucfirst($opening).'.';
    }

    private static function vocabularies(): array
    {
        if (self::$vocabularies !== null) {
            return self::$vocabularies;
        }

        $path = dirname(__DIR__, 4).'/resources/data/lorem.json';

        return self::$vocabularies = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['flavours'];
    }
}
