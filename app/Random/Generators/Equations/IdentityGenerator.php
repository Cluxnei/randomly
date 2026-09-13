<?php

declare(strict_types=1);

namespace App\Random\Generators\Equations;

use App\Random\Equations\Corruption;
use App\Random\Equations\Identities;
use App\Random\Equations\Node;
use App\Random\Equations\Problem;
use App\Random\Generators\Params;
use App\Random\Generators\ParamSchema;
use App\Random\Rng\Rng;

/**
 * True or false?
 *
 * A real identity, and then a coin flip: half of them get exactly one sign,
 * coefficient or function name changed, somewhere inside an otherwise correct
 * statement. One token. That is the whole design — a wrong answer that looks
 * obviously wrong is not a question.
 *
 * What it does *not* do is assume that corrupting something made it false.
 * Turning `cos 2x = 1 − 2sin²x` into `cos 2x = 2cos²x − 1` changes the statement
 * into a different true identity, and a generator that reported "false" there
 * would be confidently teaching a falsehood. So the printed verdict is always
 * the verdict of a numerical check at half a dozen probe points, never a record
 * of what the corruption step intended.
 */
final class IdentityGenerator extends EquationsGenerator
{
    public function key(): string
    {
        return 'equations.identity';
    }

    public function name(): string
    {
        return 'True or False?';
    }

    public function tagline(): string
    {
        return 'A real identity — half of them subtly broken. You guess; the verdict is checked, not assumed.';
    }

    public function schema(): ParamSchema
    {
        return ParamSchema::make()
            ->enum('topic', 'Topic', [
                'trig' => 'Trigonometric',
                'log' => 'Logarithms and exponentials',
                'mixed' => 'Mixed',
            ], default: 'mixed')
            ->int('count', 'How many', default: 4, min: 1, max: 30)
            ->bool('explain', 'Say what is wrong', default: true, help: 'Prints the correct form beside a false statement.');
    }

    public function problems(Rng $rng, Params $params): array
    {
        $identities = Identities::all($params->string('topic'));
        $explain = $params->bool('explain');
        $problems = [];

        for ($i = 0; $i < $params->int('count'); $i++) {
            $problems[] = $this->problem($rng, $rng->pick($identities), $explain);
        }

        return $problems;
    }

    private function problem(Rng $rng, array $identity, bool $explain): Problem
    {
        $lhs = $identity['lhs'];
        $rhs = $identity['rhs'];
        $description = null;

        if ($rng->bool()) {
            [$lhs, $rhs, $description] = $this->corrupt($rng, $identity, $lhs, $rhs);
        }

        $holds = Identities::holds($lhs, $rhs, $identity['domain']);
        $statement = $lhs->toPlain().' = '.$rhs->toPlain();

        return new Problem(
            prompt: $statement,
            promptLatex: $lhs->toLatex().' = '.$rhs->toLatex(),
            answer: $this->answer($holds, $explain, $identity, $description),
            answerLatex: $holds ? '\text{True}' : '\text{False}',
            extra: array_filter([
                'holds' => $holds,
                'identity' => $identity['key'],
                'corruption' => $description,
            ], fn ($v) => $v !== null),
            check: ['lhs' => $lhs, 'rhs' => $rhs, 'domain' => $identity['domain'], 'holds' => $holds],
        );
    }

    /**
     * Bend one token, and keep bending until the result is something a reader
     * could actually judge.
     *
     * The right-hand side is where the interesting edits live, but a few
     * identities have a bare variable there — `ln(e^a) = a` — with nothing in it
     * to bend, so those are corrupted on the left instead. And a corruption that
     * lands out of domain is discarded rather than printed: an undefined
     * statement is not a false one.
     *
     * @return array{Node, Node, ?string}
     */
    private function corrupt(Rng $rng, array $identity, Node $lhs, Node $rhs): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $side = Corruption::apply($rng, $rhs);

            if ($side !== null) {
                [$candidate, $description] = $side;

                if (Identities::isEvaluable($lhs, $candidate, $identity['domain'])) {
                    return [$lhs, $candidate, $description];
                }

                continue;
            }

            $side = Corruption::apply($rng, $lhs);

            if ($side === null) {
                break;
            }

            [$candidate, $description] = $side;

            if (Identities::isEvaluable($candidate, $rhs, $identity['domain'])) {
                return [$candidate, $rhs, $description];
            }
        }

        // Nothing bendable, or nothing bendable into a statement worth asking
        // about. The true form is a perfectly good question.
        return [$lhs, $rhs, null];
    }

    private function answer(bool $holds, bool $explain, array $identity, ?string $description): string
    {
        if ($holds) {
            return 'True';
        }

        if (! $explain) {
            return 'False';
        }

        $correct = $identity['lhs']->toPlain().' = '.$identity['rhs']->toPlain();

        return 'False — '.($description ?? 'it was altered').'; it should read '.$correct;
    }

    protected function meta(Params $params, array $problems): array
    {
        // How the coin actually landed. The split is 50/50 by design, but a
        // corruption that happens to land on another true identity is counted
        // true, so the realised ratio drifts above half — and saying so out loud
        // is more honest than implying a guarantee the generator does not make.
        $true = count(array_filter($problems, fn (Problem $p): bool => $p->check['holds']));

        return ['topic' => $params->string('topic'), 'true_statements' => $true];
    }
}
