<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Random\Entropy\EntropyPool;
use App\Random\Entropy\Seed;
use Illuminate\Console\Command;

/**
 * The Phase 1 demo: draw one seed from the world and prove it is usable.
 *
 * It exists to make the entropy layer visible before there is any UI to show it
 * in — the receipt is printed in full, including the parts we would rather not
 * advertise (degraded, mixed with the CSPRNG), because the honesty is the pitch.
 */
final class SeedCommand extends Command
{
    protected $signature = 'randomly:seed
                            {--source= : Draw from one named source (csprng, random-org, nist-beacon, drand) instead of rotating}';

    protected $description = 'Draw a single seed from the entropy pool and print its receipt';

    public function handle(EntropyPool $pool): int
    {
        $prefer = $this->option('source') ?? config('randomly.entropy.default_source');

        if ($prefer !== 'auto' && $pool->source((string) $prefer) === null) {
            $this->components->error("Unknown source [{$prefer}]. Known: ".implode(', ', array_keys($pool->sources())).'.');

            return self::FAILURE;
        }

        $seed = $pool->seed($prefer === 'auto' ? null : (string) $prefer);
        $receipt = $seed->receipt;

        $this->newLine();
        $this->line('  <options=bold>randomly</> <fg=gray>· one seed, drawn from the world</>');
        $this->newLine();
        $this->line('  <options=bold>'.$receipt->narrative.'</>');
        $this->newLine();

        $this->components->twoColumnDetail('Token', "<options=bold>{$seed->token()}</>");
        $this->components->twoColumnDetail('Seed', '<fg=gray>'.bin2hex($seed->bytes).'</>');
        $this->components->twoColumnDetail('Source', $receipt->sourceLabel.' <fg=gray>('.$receipt->sourceKey.')</>');
        $this->components->twoColumnDetail('Class', 'Class '.$receipt->class->value.' <fg=gray>· '.$receipt->class->label().'</>');
        $this->components->twoColumnDetail('Observed at', $receipt->observedAt->format('Y-m-d H:i:s T'));
        $this->components->twoColumnDetail('Proof', $receipt->proofUrl ?? '<fg=gray>none — nothing to point at for local entropy</>');
        $this->components->twoColumnDetail('Degraded', $receipt->degraded
            ? '<fg=yellow>yes · the source was unreachable, we fell back to the kernel</>'
            : '<fg=green>no</>');
        $this->components->twoColumnDetail('Mixed with CSPRNG', $receipt->mixedWithCsprng
            ? '<fg=green>yes · fresh local bytes folded in before HKDF</>'
            : '<fg=gray>no · the kernel was the source</>');
        $this->components->twoColumnDetail('Latency', $receipt->latencyMs.' ms');

        if ($caveat = $receipt->class->caveat()) {
            $this->newLine();
            $this->line('  <fg=yellow>▍</> <fg=gray>'.$caveat.'</>');
        }

        $this->demonstrate($seed);

        return self::SUCCESS;
    }

    /**
     * A seed nobody can draw from is just a number. Pull six lottery numbers out
     * of it so the derivation path — seed → HKDF stream → unbiased integers — is
     * shown working end to end, with the cost of that unbiasedness printed too.
     */
    private function demonstrate(Seed $seed): void
    {
        $rng = $seed->rng('numbers.integers', 1, ['count' => 6]);
        $numbers = $rng->sample(range(1, 60), 6);
        sort($numbers);

        $this->newLine();
        $this->line('  <fg=gray>Six distinct numbers from 1–60, derived from that seed:</>');
        $this->newLine();
        $this->line('  '.implode(' ', array_map(
            fn (int $n): string => sprintf('<options=bold;fg=black;bg=cyan> %02d </>', $n),
            $numbers,
        )));
        $this->newLine();
        $this->line(sprintf(
            '  <fg=gray>%d bytes of stream consumed · %d draw%s rejected to keep the range unbiased</>',
            $rng->bytesRead(),
            $rng->rejections(),
            $rng->rejections() === 1 ? '' : 's',
        ));
        $this->newLine();
    }
}
