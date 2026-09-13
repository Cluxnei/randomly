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
 * Words that never existed, grown from an order-n Markov chain over characters.
 *
 * The order slider is the lesson. At n = 2 the chain remembers two letters and
 * produces soup; at n = 3 it produces pronounceable English-shaped nonsense; at
 * n = 4 it remembers so much that most of what it emits is a real word it read in
 * training, and the rejection counter in the meta climbs to prove it. Watching
 * that counter go up as the slider moves right is the clearest demonstration
 * available of what "overfitting" means.
 */
final class PseudoWordsGenerator extends BaseGenerator
{
    /**
     * Attempts allowed per word before we stop insisting.
     *
     * Each attempt is a whole word grown and thrown away, and at order 4 with a
     * narrow length window most attempts fail. High enough that a legitimate
     * request succeeds, bounded because a user-controlled loop with no ceiling is
     * a denial of service waiting for someone to drag min-length to max-length.
     */
    private const MAX_ATTEMPTS = 250;

    public function key(): string
    {
        return 'words.pseudo';
    }

    public function name(): string
    {
        return 'Invented Words';
    }

    public function tagline(): string
    {
        return 'Order-n Markov chains over real vocabulary — noise at 2, plagiarism at 4.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('count', 'How many', default: 12, min: 1, max: 60)
            ->int('order', 'Chain order', default: 3, min: 2, max: 4, help: '2 is soup, 3 is pronounceable, 4 mostly regurgitates real words.')
            ->int('min_length', 'Shortest', default: 5, min: 3, max: 12)
            ->int('max_length', 'Longest', default: 10, min: 4, max: 16)
            ->enum('corpus', 'Trained on', Corpus::options(), default: Corpus::LARGE);
    }

    public function generate(Rng $rng, Params $params): Result
    {
        // A dragged pair of sliders can cross; swap rather than error, the same way
        // the integers generator does with min/max.
        $min = min($params->int('min_length'), $params->int('max_length'));
        $max = max($params->int('min_length'), $params->int('max_length'));

        $corpus = $params->string('corpus');
        $order = $params->int('order');
        $chain = MarkovChain::for($corpus, $order);

        $words = [];
        $bits = 0.0;
        $candidateBits = 0.0;
        $rejected = ['shape' => 0, 'real_word' => 0, 'no_vowel' => 0];
        $exhausted = 0;

        for ($i = 0; $i < $params->int('count'); $i++) {
            $word = null;

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $candidate = $chain->attempt($rng, $min, $max, $candidateBits);

                if ($candidate === null) {
                    $rejected['shape']++;

                    continue;
                }

                // A candidate copied verbatim out of the training list is not an
                // invented word, it is a lookup with extra steps.
                if ($chain->isRealWord($candidate)) {
                    $rejected['real_word']++;

                    continue;
                }

                // "brnth" is a plausible walk through an English character chain and
                // not a word anybody can say out loud.
                if (! MarkovChain::hasVowel($candidate)) {
                    $rejected['no_vowel']++;

                    continue;
                }

                $word = $candidate;
                $bits += $candidateBits;
                break;
            }

            if ($word === null) {
                // Order 4 with a tight length window can genuinely have almost
                // nothing left to invent. Saying so beats looping forever or
                // returning a short list the caller has to notice for themselves.
                $exhausted++;

                continue;
            }

            $words[] = $word;
        }

        return new Result(
            value: $words,
            display: implode(PHP_EOL, $words),
            meta: [
                'order' => $order,
                'corpus' => $corpus,
                'corpus_words' => Corpus::size($corpus),
                // Flattened into scalars rather than nested: the studio's meta strip
                // renders one value per row, and an array arrives there as "Array".
                'rejected_total' => array_sum($rejected),
                'rejected_wrong_length' => $rejected['shape'],
                'rejected_as_corpus_words' => $rejected['real_word'],
                'rejected_without_vowel' => $rejected['no_vowel'],
                'exhausted' => $exhausted,
                'entropy_out_bits' => round($bits, 2),
                'entropy_note' => 'Summed surprisal of the characters actually drawn — the chain is not uniform, so this is lower than log₂(alphabet) per letter.',
                // Naming this "rejected as real words" would have claimed a guarantee
                // the filter cannot give. It knows 7,776 words; English has hundreds of
                // thousands, and at order 4 the chain reproduces plenty of them —
                // "silence", "fright", "grouting" all came out of a single run and none
                // are in the corpus. That is the slider's lesson rather than a defect,
                // but the page has to say so instead of implying every word is new.
                'note' => sprintf(
                    'Candidates are checked against the %s-word training list only. English is far larger, so a higher chain order will produce real words this filter cannot recognise — which is exactly what the order slider is for.',
                    number_format(Corpus::size($corpus)),
                ),
            ],
        );
    }
}
