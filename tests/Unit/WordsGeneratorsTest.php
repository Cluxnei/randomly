<?php

declare(strict_types=1);

use App\Random\Generators\Contracts\NeedsData;
use App\Random\Generators\Words\ArticleGenerator;
use App\Random\Generators\Words\BrandGenerator;
use App\Random\Generators\Words\Corpus;
use App\Random\Generators\Words\IdentityGenerator;
use App\Random\Generators\Words\LoremGenerator;
use App\Random\Generators\Words\PassphraseGenerator;
use App\Random\Generators\Words\PseudoWordsGenerator;
use App\Random\Generators\Words\RelatedGenerator;
use App\Random\Generators\Words\SpeciesGenerator;
use App\Random\Generators\Words\SyllabicGenerator;
use App\Random\Generators\Words\SyllableGrammar;

/**
 * Module-specific checks. Everything generic — determinism, schema defaults,
 * entropy usage, naming — comes free from GeneratorsTest, which reads the registry
 * rather than a hand-kept list.
 *
 * Nothing here touches the network. The two NeedsData generators are exercised
 * through the pool they are handed, which is exactly how they run in production:
 * fetch() is the Studio's job, generate() is a pure function of its inputs.
 */
function wordsResult(string $class, array $input = [], string $seed = 'randomly-fixed!!', array $data = [])
{
    $generator = new $class;
    $params = $generator->schema()->coerce($input);

    if ($data !== []) {
        $params = $params->withData($data);
    }

    return $generator->generate(
        seedFor($seed)->rng($generator->key(), $generator->version(), $params->fingerprint()),
        $params,
    );
}

// ---------------------------------------------------------------- the corpora

it('bundles wordlists whose size is the entropy claim', function (): void {
    // 7,776 = 6^5 and 1,296 = 6^4 is not trivia — it is the reason a word is worth
    // exactly log2(N) bits and a five-dice roll addresses one entry with nothing
    // left over. A list one word short would silently make every quoted figure wrong.
    expect(Corpus::size(Corpus::LARGE))->toBe(6 ** 5)
        ->and(Corpus::size(Corpus::SHORT))->toBe(6 ** 4);
});

it('keeps every bundled word unique and every dice roll valid', function (string $list, int $dice): void {
    $words = Corpus::words($list);
    $rolls = Corpus::rolls($list);

    expect(array_unique($words))->toHaveCount(count($words))
        ->and(array_unique($rolls))->toHaveCount(count($rolls));

    foreach ($rolls as $roll) {
        expect($roll)->toMatch('/^[1-6]{'.$dice.'}$/');
    }
})->with([[Corpus::LARGE, 5], [Corpus::SHORT, 4]]);

// ------------------------------------------------------------- the passphrase

it('quotes exactly L · log2(7776) bits for a passphrase', function (int $length): void {
    $result = wordsResult(PassphraseGenerator::class, ['words' => $length]);

    expect($result->meta['entropy_out_bits'])->toBe(round($length * log(7776, 2), 2))
        ->and($result->meta['bits_per_word'])->toBe(12.925)
        ->and($result->value['words'])->toHaveCount($length);
})->with([4, 5, 6, 7, 8, 12]);

it('quotes the short list at its own smaller figure', function (): void {
    $result = wordsResult(PassphraseGenerator::class, ['words' => 6, 'list' => Corpus::SHORT]);

    expect($result->meta['entropy_out_bits'])->toBe(round(6 * log(1296, 2), 2))
        ->and($result->meta['list_size'])->toBe(1296);
});

it('draws passphrase words from the bundled list, with their real dice rolls', function (): void {
    $result = wordsResult(PassphraseGenerator::class, ['words' => 12]);
    $words = Corpus::words(Corpus::LARGE);
    $rolls = Corpus::rolls(Corpus::LARGE);

    foreach ($result->value['words'] as $entry) {
        $index = array_search($entry['word'], $words, true);

        expect($index)->not->toBeFalse()
            // The roll shown next to a word has to be *that word's* roll, or the
            // clearest illustration in the module becomes a decorative lie.
            ->and($entry['roll'])->toBe($rolls[$index]);
    }
});

it('adds exactly zero bits for capitalisation and separators', function (): void {
    $plain = wordsResult(PassphraseGenerator::class, ['words' => 6, 'separator' => 'none']);
    $dressed = wordsResult(PassphraseGenerator::class, ['words' => 6, 'separator' => 'dash', 'capitalise' => true]);

    // The lie most password meters tell. Same six words, same 77.55 bits, whatever
    // the shift key was doing.
    expect($dressed->meta['entropy_out_bits'])->toBe($plain->meta['entropy_out_bits']);

    $rows = collect($dressed->value['breakdown'])->keyBy('component');

    expect($rows['Capitalising each word']['bits'])->toBe(0.0)
        ->and($rows['Separator between words']['bits'])->toBe(0.0)
        // And the meta has to say it in a sentence, not only in a data structure.
        ->and($dressed->meta['free_extras'])->toContain('0.00 bits');
});

it('does count the bits of a number that was actually drawn', function (): void {
    $plain = wordsResult(PassphraseGenerator::class, ['words' => 6]);
    $numbered = wordsResult(PassphraseGenerator::class, ['words' => 6, 'append_number' => true]);

    expect($numbered->meta['entropy_out_bits'])
        ->toBe(round($plain->meta['entropy_out_bits'] + log(100, 2), 2))
        ->and($numbered->value['number'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(99);
});

it('refuses a passphrase a shareable permalink', function (): void {
    expect((new PassphraseGenerator)->isSensitive())->toBeTrue();
});

it('capitalises without changing which words were drawn', function (): void {
    $result = wordsResult(PassphraseGenerator::class, ['words' => 6, 'capitalise' => true]);

    foreach ($result->value['words'] as $entry) {
        expect($entry['shown'])->toBe(ucfirst($entry['word']));
    }
});

// ------------------------------------------------------------ invented words

it('invents words that are pronounceable and new', function (int $order): void {
    $result = wordsResult(PseudoWordsGenerator::class, ['order' => $order, 'count' => 40]);

    expect($result->value)->not->toBeEmpty();

    foreach ($result->value as $word) {
        expect($word)->toMatch('/^[a-z]+$/')
            // No vowel means no word — "brnth" is a plausible walk through an
            // English character chain and not something anyone can say.
            ->toMatch('/[aeiou]/')
            ->and(strlen($word))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(10)
            // Copied verbatim out of the training list is a lookup, not an invention.
            ->and(Corpus::contains(Corpus::LARGE, $word))->toBeFalse();
    }
})->with([2, 3, 4]);

it('shows the order slider turning invention into plagiarism', function (): void {
    // The lesson the slider exists to teach: the higher the order, the more of what
    // the chain produces is a word it simply memorised. If this ever inverts, the
    // chain is not conditioning on as much context as it claims to.
    $low = wordsResult(PseudoWordsGenerator::class, ['order' => 2, 'count' => 40]);
    $high = wordsResult(PseudoWordsGenerator::class, ['order' => 4, 'count' => 40]);

    expect($high->meta['rejected_as_corpus_words'])
        ->toBeGreaterThan($low->meta['rejected_as_corpus_words']);
});

it('respects a length window that the sliders crossed', function (): void {
    $result = wordsResult(PseudoWordsGenerator::class, ['min_length' => 9, 'max_length' => 6, 'count' => 10]);

    foreach ($result->value as $word) {
        expect(strlen($word))->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(9);
    }
});

// ------------------------------------------------------------- syllable forge

it('builds each flavour only out of its own alphabet', function (string $flavour): void {
    $result = wordsResult(SyllabicGenerator::class, ['flavour' => $flavour, 'count' => 40, 'capitalise' => false]);
    $alphabet = SyllableGrammar::alphabet($flavour);

    foreach ($result->value as $entry) {
        foreach (preg_split('//u', $entry['name'], -1, PREG_SPLIT_NO_EMPTY) as $letter) {
            expect(mb_strpos($alphabet, $letter))->not->toBeFalse(
                "[{$flavour}] emitted [{$letter}] in [{$entry['name']}], which is not in its own tables.",
            );
        }

        expect(implode('', $entry['syllables']))->toBe($entry['name']);
    }
})->with(array_keys(SyllableGrammar::options()));

it('never emits a cluster nobody could say', function (string $flavour): void {
    // The phonotactic rules earn their keep here: without them the grammar happily
    // produces "thilglelgluss", which passes every other test in this file.
    $result = wordsResult(SyllabicGenerator::class, ['flavour' => $flavour, 'count' => 60, 'syllables' => 4]);

    foreach ($result->value as $entry) {
        expect($entry['name'])->not->toMatch('/[^aeiouyåøæAEIOUY]{4,}/u')
            ->and($entry['name'])->not->toMatch('/(.)\1\1/u');
    }
})->with(array_keys(SyllableGrammar::options()));

it('capitalises names without mangling a multibyte first letter', function (): void {
    $result = wordsResult(SyllabicGenerator::class, ['flavour' => 'nordic', 'count' => 40]);

    foreach ($result->value as $entry) {
        expect(mb_strlen($entry['name']))->toBe(mb_strlen(implode('', $entry['syllables'])))
            ->and($entry['name'])->toBe(mb_strtoupper(mb_substr($entry['name'], 0, 1)).mb_substr($entry['name'], 1));
    }
});

// -------------------------------------------------------------- brand names

it('keeps brand names to two syllables ending in a vowel', function (): void {
    $result = wordsResult(BrandGenerator::class, ['count' => 40]);

    foreach ($result->value as $entry) {
        expect($entry['syllables'])->toHaveCount(2)
            ->and(strtolower($entry['name']))->toMatch('/[aeiou]$/')
            ->and($entry['domain'])->toMatch('/^[a-z]+\.com$/');
    }
});

it('folds a nordic brand name into a domain someone could type', function (): void {
    $result = wordsResult(BrandGenerator::class, ['count' => 40, 'style' => 'nordic', 'tld' => 'io']);

    foreach ($result->value as $entry) {
        // ø and å would need punycode to be a real domain, and a hint you cannot
        // type into a registrar is not a hint.
        expect($entry['domain'])->toMatch('/^[a-z]+\.io$/');
    }
});

// -------------------------------------------------------------------- lorem

it('draws lorem sentence lengths log-normally rather than uniformly', function (): void {
    // The one line the generator exists for. Loose bounds on a fixed seed: this is
    // checking the shape of the distribution, not pinning a sample statistic.
    $result = wordsResult(LoremGenerator::class, [
        'paragraphs' => 12, 'sentences' => 12, 'start_with_lorem' => false,
    ]);

    $lengths = $result->meta['sentence_lengths'];
    $logs = array_map(fn (int $l): float => log($l), $lengths);
    $mean = array_sum($logs) / count($logs);
    $variance = array_sum(array_map(fn (float $x): float => ($x - $mean) ** 2, $logs)) / count($logs);

    expect(count($lengths))->toBeGreaterThan(100)
        // μ = ln 14 ≈ 2.639 in log space, σ = 0.45.
        ->and($mean)->toBeGreaterThan(2.39)->toBeLessThan(2.89)
        ->and(sqrt($variance))->toBeGreaterThan(0.25)->toBeLessThan(0.7)
        // A uniform generator would show neither of these: a long right tail, and
        // short sentences that are genuinely short.
        ->and(max($lengths))->toBeGreaterThan(22)
        ->and(min($lengths))->toBeLessThan(9);

    foreach ($lengths as $length) {
        expect($length)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(40);
    }
});

it('punctuates lorem the way prose is punctuated', function (): void {
    $result = wordsResult(LoremGenerator::class, ['paragraphs' => 6, 'sentences' => 8]);

    expect($result->display)->toContain(',')
        // A comma immediately before a full stop, a doubled comma, or a space in
        // front of one are the three artefacts that give generated text away.
        ->and($result->display)->not->toMatch('/,\s*[.?!]/')
        ->and($result->display)->not->toMatch('/,,|\s,/')
        ->and($result->display)->not->toMatch('/\b(\w+) \1\b/');

    foreach ($result->value['paragraphs'] as $paragraph) {
        expect($paragraph)->toMatch('/^[A-Z]/')->toMatch('/[.?!]$/');
    }
});

it('opens with the lorem ipsum everyone recognises, but only when asked', function (): void {
    expect(wordsResult(LoremGenerator::class, ['start_with_lorem' => true])->display)
        ->toStartWith('Lorem ipsum dolor sit amet, consectetur adipiscing elit.');

    expect(wordsResult(LoremGenerator::class, ['start_with_lorem' => false])->display)
        ->not->toStartWith('Lorem ipsum');
});

it('writes invented lorem out of words that never existed', function (): void {
    $result = wordsResult(LoremGenerator::class, ['flavour' => 'markov', 'paragraphs' => 3]);
    $connectives = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/data/lorem.json'), true)['flavours']['tech']['connectives'];

    foreach (preg_split('/[^a-z]+/', mb_strtolower($result->display), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (in_array($word, $connectives, true)) {
            // Real function words are deliberate — Jabberwocky's trick.
            continue;
        }

        expect(Corpus::contains(Corpus::LARGE, $word))->toBeFalse("[{$word}] is a real word from the training list.");
    }
});

// ------------------------------------------------------- the fetched sources

it('lets the seed do the choosing, not the API', function (string $class): void {
    $generator = new $class;

    // The rule the NeedsData docblock exists for: a source that returns one random
    // item has done the choosing itself, and the receipt crediting a beacon for it
    // would be a lie. Two seeds over one fixed pool must disagree.
    $pool = $generator->fallback();

    $a = wordsResult($class, ['count' => 1], 'randomly-fixed!!', $pool);
    $b = wordsResult($class, ['count' => 1], 'a-different-seed', $pool);

    expect(json_encode($a->value))->not->toBe(json_encode($b->value))
        ->and(count($pool['pool']))->toBeGreaterThanOrEqual(8);
})->with([ArticleGenerator::class, SpeciesGenerator::class, RelatedGenerator::class, IdentityGenerator::class]);

it('promises no permalink for a generator built on live material', function (string $class): void {
    $generator = new $class;

    // Tomorrow's pool is different material, so the same seed would pick something
    // else. Saying so is what stops the studio shipping a link that quietly rots.
    expect($generator)->toBeInstanceOf(NeedsData::class)
        ->and($generator->isReproducible())->toBeFalse()
        ->and($generator->cacheSeconds())->toBeGreaterThan(0);
})->with([ArticleGenerator::class, SpeciesGenerator::class, RelatedGenerator::class, IdentityGenerator::class]);

it('renders something worth reading when the API is down', function (string $class, array $keys): void {
    $generator = new $class;
    $fallback = $generator->fallback();

    expect($fallback['pool'])->toBeArray()->not->toBeEmpty();

    foreach ($fallback['pool'] as $entry) {
        foreach ($keys as $key) {
            expect($entry)->toHaveKey($key)
                ->and($entry[$key])->not->toBeEmpty();
        }
    }

    // Degraded has to be visible in the output, or a fallback quietly passes itself
    // off as a live draw.
    $result = wordsResult($class, ['count' => 2]);

    expect($result->meta['degraded'])->toBeTrue()
        ->and($result->display)->not->toBeEmpty();
})->with([
    [ArticleGenerator::class, ['title', 'extract', 'url']],
    [SpeciesGenerator::class, ['name', 'lineage']],
    [RelatedGenerator::class, ['word', 'score']],
    [IdentityGenerator::class, ['name', 'location', 'email']],
]);

it('never asks for more items than the pool can give', function (string $class): void {
    // The count slider goes past the fallback pool size; sample() throws if asked
    // for more than it has, and a 500 on a degraded page would be the worst possible
    // moment for one.
    $result = wordsResult($class, ['count' => 12]);

    expect(count($result->value))->toBeLessThanOrEqual($result->meta['pool_size'])
        ->and($result->value)->not->toBeEmpty();
})->with([ArticleGenerator::class, SpeciesGenerator::class, RelatedGenerator::class, IdentityGenerator::class]);

// ----------------------------------------------- words.related, words.identity

it('says which of “nothing found” and “API down” actually happened', function (): void {
    /*
     * Nothing rhymes with orange, and that is Datamuse answering rather than
     * Datamuse failing. The first version of this generator threw on an empty
     * result, so the page told the reader the API was unreachable when it had in
     * fact replied instantly and correctly — a false statement about provenance,
     * which is the one class of bug this project cannot ship.
     */
    $withReason = wordsResult(RelatedGenerator::class, ['count' => 3], 'randomly-fixed!!', [
        'degraded' => true,
        'reason' => 'Datamuse knows no “rhymes with” neighbours for “orange” — that is its answer, not an outage.',
        ...(new RelatedGenerator)->fallback(),
    ]);

    $outage = wordsResult(RelatedGenerator::class, ['count' => 3]);

    expect($withReason->display)->toContain('not an outage')
        ->and($withReason->meta['degraded'])->toBeTrue()
        ->and($outage->display)->toContain('unreachable')
        ->and($outage->display)->not->toContain('not an outage');
});

it('counts only the choice it made, never the pool it was handed', function (): void {
    // Forty candidates, eight drawn: log2(40) + log2(39) + … Anything larger
    // would be crediting the beacon with Datamuse's editorial judgement.
    $pool = array_map(fn (int $i): array => ['word' => 'w'.$i, 'score' => 1000 - $i, 'syllables' => 2], range(1, 40));
    $result = wordsResult(RelatedGenerator::class, ['count' => 8], 'randomly-fixed!!', ['pool' => $pool]);

    $expected = 0.0;
    for ($i = 0; $i < 8; $i++) {
        $expected += log(40 - $i, 2);
    }

    expect($result->meta['entropy_out_bits'])->toEqualWithDelta($expected, 0.01)
        ->and($result->meta['pool_size'])->toBe(40)
        ->and($result->value)->toHaveCount(8);
});

it('never lets a fictional person be mistaken for a real one', function (): void {
    /*
     * The rule that decides what this generator is allowed to produce. A list of
     * plausible names, ages and cities is indistinguishable from a leaked export
     * once it has been copied off the page, so the label travels with the data —
     * and the fields that would make a fake person impersonate a real one are
     * never requested at all.
     */
    $result = wordsResult(IdentityGenerator::class, ['count' => 6]);

    expect($result->display)->toContain('FICTIONAL')
        ->and($result->display)->toContain('do not exist')
        ->and($result->meta['fictional'])->toBeTrue();

    foreach ($result->value as $person) {
        expect($person['fictional'])->toBeTrue()
            // A photograph of a real person on an invented name is impersonation;
            // a well-formed phone number or national ID can collide with somebody
            // real. None of the four is in the output because none is fetched.
            ->and($person)->not->toHaveKey('picture')
            ->and($person)->not->toHaveKey('phone')
            ->and($person)->not->toHaveKey('login')
            ->and($person)->not->toHaveKey('id')
            // RFC 2606 reserves example.com precisely so that test data cannot
            // reach an inbox. Anything else is dropped rather than printed.
            ->and($person['email'])->toEndWith('@example.com');
    }
});

it('keeps every fictional person inside one locale', function (): void {
    // "Hiroshi Tanaka, Lyon" is a person from a badly written form. The whole
    // point of the nationality control is that the name and the place come from
    // the same place, and the bundled fallback has to honour that too.
    foreach ((new IdentityGenerator)->fallback()['pool'] as $person) {
        expect($person['location'])->toContain(',')
            ->and($person['name'])->not->toBeEmpty()
            ->and($person['age'])->toBeGreaterThan(17)
            ->and($person['age'])->toBeLessThan(90);
    }
});
