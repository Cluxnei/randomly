<?php

declare(strict_types=1);

namespace App\Random\Equations;

/**
 * One problem and its answer, in both notations.
 *
 * Every equations generator emits these and nothing else, which is what lets a
 * single worksheet template, a single studio renderer and a single API shape
 * serve nine generators that have almost nothing else in common.
 *
 * Both notations are carried rather than one derived from the other: LaTeX is
 * what the page renders and plain is what the clipboard, the terminal and the
 * `curl` one-liner get. Deriving either from the other means writing a parser,
 * and a parser is a second place for the two to disagree.
 */
final readonly class Problem
{
    public function __construct(
        public string $prompt,
        public string $promptLatex,
        public string $answer,
        public string $answerLatex,
        /** Anything the generator wants to show its working with. */
        public array $extra = [],
        /**
         * The pieces needed to substitute the stated answer back into the stated
         * problem — the expression trees, the solution, the matrix.
         *
         * Never serialised. It exists because the single most valuable test in
         * this module is the one that solves every generated problem and checks
         * the answer is right, and that test cannot re-parse a printed string
         * without a parser that would become a second place for the two to
         * disagree.
         */
        public array $check = [],
    ) {}

    public static function of(Node $prompt, Node|string $answer, array $extra = [], array $check = []): self
    {
        return new self(
            prompt: $prompt->toPlain(),
            promptLatex: $prompt->toLatex(),
            answer: $answer instanceof Node ? $answer->toPlain() : $answer,
            answerLatex: $answer instanceof Node ? $answer->toLatex() : $answer,
            extra: $extra,
            check: $check,
        );
    }

    /** `lhs = rhs`, the shape most of this module's problems take. */
    public static function equation(Node $lhs, Node $rhs, Node|string $answer, array $extra = [], array $check = []): self
    {
        return new self(
            prompt: $lhs->toPlain().' = '.$rhs->toPlain(),
            promptLatex: $lhs->toLatex().' = '.$rhs->toLatex(),
            answer: $answer instanceof Node ? $answer->toPlain() : $answer,
            answerLatex: $answer instanceof Node ? $answer->toLatex() : $answer,
            extra: $extra,
            check: [...$check, 'lhs' => $lhs, 'rhs' => $rhs],
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'prompt_latex' => $this->promptLatex,
            'answer' => $this->answer,
            'answer_latex' => $this->answerLatex,
            ...$this->extra,
        ], fn ($v) => $v !== null);
    }
}
