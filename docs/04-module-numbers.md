# Module — Numbers

The foundation module. Everything here is pure math over the `Rng` byte stream, so it
has no external dependencies and is the reference implementation for the contract.

## 1. Rng primitives

```php
final class Rng
{
    public function bytes(int $n): string;
    public function uint32(): int;
    public function uint64(): int;
    public function float(): float;               // [0,1)
    public function intBetween(int $lo, int $hi): int;  // inclusive, unbiased
    public function bool(float $p = 0.5): bool;
    public function pick(array $items): mixed;
    public function shuffle(array $items): array;
    public function sample(array $items, int $k): array;
    public function weighted(array $items, array $weights): mixed;
    public function gaussian(float $mu = 0, float $sigma = 1): float;
}
```

### 1.1 Uniform float

Never `mt_rand()/mt_getrandmax()` — that has gaps and bias. Take 53 bits, the exact
mantissa width of an IEEE-754 double:

```
u = uint64() >> 11            (53 bits)
float = u × 2⁻⁵³              ∈ [0, 1)
```

This is the only mapping that yields every representable double in [0,1) with correct
probability and never returns exactly 1.0.

### 1.2 Unbiased integer in a range — bitmask rejection

The naive `x % n` is biased whenever `n` does not divide 2³², and the bias is visible in
a dice roller (the low faces come up more often). Draw the smallest number of bits that
covers the range and redraw when the value overshoots:

```
range = hi − lo
bits  = bit length of range
mask  = 2^bits − 1
do:
    x = next ⌈bits/8⌉ bytes, masked to `bits`
while x > range
return lo + x
```

This is the approach PHP's own `random_int()` takes, and it is what we implement.

**Why not Lemire's multiply-shift**, which the fast-RNG literature prefers: it needs the
full 64-bit product `x × n` of a 32-bit draw, and PHP's only integer type is a *signed*
64-bit int. The product silently promotes to float above `PHP_INT_MAX` and the algorithm
quietly stops being uniform — the exact bug this method exists to prevent. Bitmask
rejection never multiplies, so it has no such ceiling.

Rejection rate is at most 50% and is usually far lower. `meta.rejections` is surfaced in
the UI, and it teaches better than Lemire would have: a 1–100 range needs 7 bits and
therefore discards 28 of every 128 draws, about 22%. Watching a fifth of the draws get
thrown away is the clearest possible demonstration of what unbiased sampling costs.

### 1.3 Shuffle — Fisher–Yates (Durstenfeld)

```
for i from n-1 down to 1:
    j = intBetween(0, i)
    swap a[i], a[j]
```

Each of the n! permutations has probability exactly 1/n!, *given* an unbiased
`intBetween`. Uses §1.2, so that holds. The common broken version (`j = intBetween(0, n-1)`)
is n^n / n! biased — we call this out in the UI copy.

### 1.4 Weighted choice — Walker's alias method

Build once in O(n), draw in O(1) forever after:

```
Scale weights so Σw = n. Split into Small (w<1) and Large (w≥1).
Repeatedly pair a small s with a large l:
    prob[s] = w[s];  alias[s] = l;  w[l] -= (1 - w[s])
    move l to Small or Large depending on its new weight.
Draw: i = intBetween(0, n-1); return float() < prob[i] ? i : alias[i]
```

Worth it for the loaded-dice and lottery generators, where users pull thousands of draws.

## 2. Distributions

| Distribution | Method | Formula |
|---|---|---|
| Uniform | §1.2 / §1.1 | — |
| **Gaussian** | Box–Muller (polar form) | `Z₀ = √(−2 ln U₁) · cos(2π U₂)`, `Z₁ = √(−2 ln U₁) · sin(2π U₂)`; cache Z₁ |
| **Exponential** | inverse CDF | `X = −ln(U) / λ` |
| **Poisson** (λ < 30) | Knuth | multiply U's until product < e^(−λ); count − 1 |
| **Poisson** (λ ≥ 30) | PTRS transformed rejection | avoids the O(λ) loop |
| **Binomial** | sum of Bernoulli (n small) / BTRS (n large) | — |
| **Pareto** | inverse CDF | `X = x_m / U^(1/α)` |
| **Zipf** | rejection on the continuous approximation | `p(k) ∝ k^(−s)` |
| **Beta** | two Gammas | `X = G₁/(G₁+G₂)`, `G ~ Gamma(α,1)` via Marsaglia–Tsang |
| **Lévy / Cauchy** | inverse CDF | `X = tan(π(U − ½))` — the fat tail is visually dramatic |

Box–Muller note: `U₁` must be drawn from `(0,1]` not `[0,1)`, or `ln(0)` blows up.
Implementation takes `1 - float()`.

## 3. Generators

| Key | Name | Params | Notes |
|---|---|---|---|
| `numbers.integers` | Integers | count, min, max, unique, sort | the flagship; unique uses partial Fisher–Yates |
| `numbers.decimals` | Decimals | count, min, max, precision | |
| `numbers.gaussian` | Bell Curve | count, μ, σ, clamp | ships with a live histogram |
| `numbers.distribution` | Distribution Lab | distribution, params, count | picks any of §2; plots it |
| `numbers.dice` | Dice | notation (`4d6kh3+2`), rolls | full dice-notation parser: `NdS`, `kh`/`kl`, `!` explode, `±mod` |
| `numbers.coin` | Coin Flips | count, bias p | shows the longest run vs. expected `log₂ n` |
| `numbers.lottery` | Lottery | pool size, picks, bonus pool | presets: Mega-Sena 6/60, Powerball, EuroMillions |
| `numbers.uuid` | Identifiers | flavour (v4, v7, ULID, NanoID), count | v7 is time-ordered — shows the timestamp prefix |
| `numbers.bytes` | Raw Bytes | count, encoding (hex, b64, binary) | the "look at the entropy itself" generator |
| `numbers.prime` | Primes | bits, count | Miller–Rabin, 40 rounds |
| `numbers.coordinates` | Earth Points | count, land-only | see §4 |
| `numbers.timestamp` | Moments | range, count, format | random instant in a window |
| `numbers.password` | Passwords | length, charset flags | reports entropy `L·log₂(A)` |

## 4. Uniform points on a sphere (a favourite demo)

Sampling `lat ~ U(−90, 90)` is the classic bug: it clusters points at the poles, because
a latitude band's area shrinks as `cos(lat)`. The correct sampling:

```
u, v ~ U(0,1)
lat = asin(2u − 1) · 180/π
lon = 360v − 180
```

The studio shows both side by side on a globe — wrong on the left, right on the right.
It is the single best "you can see the math" moment in the whole product, and it costs
twenty lines.

Optional `land-only` rejects ocean points using a coarse bundled land mask (a 720×360
bitmask, ~32 KB) rather than an API call.

## 5. Meta surfaced in the UI

Each result reports: entropy consumed (bytes), entropy produced (bits, `log₂` of the
outcome space), rejection count, and generation time in µs. These numbers are the
texture that makes the page feel like an instrument rather than a toy.
