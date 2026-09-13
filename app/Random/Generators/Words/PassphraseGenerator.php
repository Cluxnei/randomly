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
 * Diceware, with the arithmetic shown.
 *
 * The EFF large list has exactly 7,776 = 6⁵ entries, one per five-dice roll, so
 * each word is worth log₂(7776) ≈ 12.925 bits and the whole phrase is L times
 * that. Nothing about this is an estimate, which is the entire reason the module
 * leads with it: the number can be checked by counting the lines in a file.
 *
 * The other half of the point is the breakdown. Capitalising every word and
 * gluing the words together with a dash are deterministic rules — an attacker
 * applies them for free — so they add exactly zero bits. Most password meters
 * reward them anyway. This one lists them at 0.00 and says why.
 */
final class PassphraseGenerator extends BaseGenerator
{
    private const SEPARATORS = [
        'dash' => '-',
        'space' => ' ',
        'dot' => '.',
        'underscore' => '_',
        'none' => '',
    ];

    /** Two digits, so the honest bonus is log₂(100) rather than log₂(10). */
    private const NUMBER_MAX = 99;

    public function key(): string
    {
        return 'words.passphrase';
    }

    public function name(): string
    {
        return 'Passphrase';
    }

    public function tagline(): string
    {
        return '12.9 bits per word, from a list of 7,776 — and zero for the capital letters.';
    }

    public function module(): Module
    {
        return Module::Words;
    }

    /**
     * A passphrase is a secret, so it gets no shareable permalink — the same rule
     * the password generator lives under, for the same reason: a replayable URL
     * for a credential is not a credential.
     */
    public function isSensitive(): bool
    {
        return true;
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->int('words', 'Words', default: 6, min: 4, max: 12, help: 'Six words is 77.5 bits. Four is 51.7 — fine for a laptop login, not for a password manager.')
            ->enum('list', 'Wordlist', Corpus::options(), default: Corpus::LARGE)
            ->enum('separator', 'Separator', [
                'dash' => 'Dash · correct-horse',
                'space' => 'Space · correct horse',
                'dot' => 'Dot · correct.horse',
                'underscore' => 'Underscore · correct_horse',
                'none' => 'None · correcthorse',
            ], default: 'dash')
            ->bool('capitalise', 'Capitalise each word', help: 'Adds zero bits. It is a rule, and the attacker has the rule too.')
            ->bool('append_number', 'Append a number', help: 'Adds 6.6 bits, because this one is actually drawn at random.');
    }

    public function generate(Rng $rng, Params $params): Result
    {
        $list = $params->string('list');
        $size = Corpus::size($list);
        $count = $params->int('words');
        $capitalise = $params->bool('capitalise');
        $separator = self::SEPARATORS[$params->string('separator')] ?? '-';

        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            // With replacement, exactly as five physical dice would be: drawing
            // without replacement would make each later word slightly more
            // predictable than the last, and log₂(N) per word would stop being true.
            $picked[] = Corpus::entry($list, $rng->intBetween(0, $size - 1));
        }

        $words = array_map(
            fn (array $entry): string => $capitalise ? ucfirst($entry['word']) : $entry['word'],
            $picked,
        );

        $number = $params->bool('append_number') ? $rng->intBetween(0, self::NUMBER_MAX) : null;
        $phrase = implode($separator, $words).($number !== null ? $number : '');

        $bitsPerWord = log($size, 2);
        $wordBits = $count * $bitsPerWord;
        $numberBits = $number !== null ? log(self::NUMBER_MAX + 1, 2) : 0.0;
        $totalBits = $wordBits + $numberBits;

        $breakdown = $this->breakdown($count, $size, $wordBits, $capitalise, $params->string('separator'), $number, $numberBits);

        return new Result(
            value: [
                'phrase' => $phrase,
                // The rolls travel with the words so the UI can show which five dice
                // produced each one. It is the shortest path from "12.925 bits" to
                // "here is the physical process that number describes".
                'words' => array_map(fn (array $e, string $shown): array => [
                    'roll' => $e['roll'],
                    'word' => $e['word'],
                    'shown' => $shown,
                ], $picked, $words),
                'number' => $number,
                // Structured rather than prose, because the studio panel wants rows
                // and the API wants numbers. The meta strip below gets the one-line
                // version — nested arrays there render as "Array".
                'breakdown' => $breakdown,
            ],
            display: $phrase,
            meta: [
                'entropy_out_bits' => round($totalBits, 2),
                'bits_per_word' => round($bitsPerWord, 3),
                'list_size' => $size,
                'formula' => sprintf('H = %d × log₂(%s) = %s bits', $count, number_format($size), round($wordBits, 2)),
                'crack_time' => CrackTime::describe($totalBits),
                'crack_assumption' => CrackTime::ASSUMPTION,
                'free_extras' => $this->freeExtras($breakdown),
            ],
        );
    }

    /**
     * The zero-bit rows, said out loud in one line.
     *
     * The breakdown itself is structured data on the result. This is the sentence
     * that has to survive being skim-read: whatever the meter next door tells you,
     * the shift key and the dashes bought you nothing.
     */
    private function freeExtras(array $breakdown): string
    {
        $free = array_column(array_filter($breakdown, fn (array $row): bool => $row['bits'] === 0.0), 'component');

        return $free === []
            ? 'none — every part of this phrase was drawn'
            : implode(' and ', $free).' — worth 0.00 bits, because the rule is deterministic';
    }

    /**
     * Where every bit came from, including the ones worth nothing.
     *
     * Listing the zeroes is the point. A meter that shows a phrase getting
     * "stronger" when you shift-key the first letters is teaching a superstition,
     * and the fix is not a smaller bonus — it is a line that reads 0.00.
     */
    private function breakdown(
        int $count,
        int $size,
        float $wordBits,
        bool $capitalise,
        string $separator,
        ?int $number,
        float $numberBits,
    ): array {
        $rows = [[
            'component' => sprintf('%d words drawn uniformly from %s', $count, number_format($size)),
            'bits' => round($wordBits, 2),
        ]];

        if ($capitalise) {
            $rows[] = [
                'component' => 'Capitalising each word',
                'bits' => 0.0,
                'note' => 'A deterministic rule. An attacker applies it to their whole candidate list for free.',
            ];
        }

        if ($separator !== 'none') {
            $rows[] = [
                'component' => 'Separator between words',
                'bits' => 0.0,
                'note' => 'Chosen by you, not by the dice, and visible in this output. Worth nothing.',
            ];
        }

        if ($number !== null) {
            $rows[] = [
                'component' => 'Appended number 0–'.self::NUMBER_MAX,
                'bits' => round($numberBits, 2),
                'note' => 'This one does count: the digits were drawn, not decided.',
            ];
        }

        return $rows;
    }
}
