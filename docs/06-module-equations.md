# Module — Equations

Generating a random expression is easy. Generating one that is **well-formed, solvable,
and has a clean answer** is the actual problem. Two strategies, used in different places:

- **Forward generation** — grow a random expression tree, then evaluate it. Good for
  "look at this expression" output. Answers are ugly.
- **Backward construction** — start from the answer you want and build the problem
  around it. Good for anything where the user is expected to *solve* it. Answers are
  clean by construction.

Rendering is **KaTeX**, bundled through npm rather than pulled from a CDN — a worksheet
that silently loses its maths because someone else's CDN is unreachable is worse than a
slightly larger bundle, and Vite is already in the build. It renders in ~1ms against
MathJax's ~50ms, which matters when a worksheet lays out dozens of expressions at once.
Every result carries both `latex` and `plain` forms, so it is copyable either way.

**LaTeX validity needs its own check.** `(e^a)^2` emitted `e^{a}^{2}` — a double
superscript, which is a TeX syntax error that no amount of PHP testing can see, because
the string is perfectly well-formed PHP output. `scripts/check-latex.mjs` parses thousands
of generated expressions through the real KaTeX, in the same spirit as
`scripts/render-preview.mjs` for canvases: the only way to know the output is valid is to
hand it to the thing that has to read it.

## 1. Forward: PCFG expression trees

A probabilistic context-free grammar over an AST:

```
E → E + E | E − E | E × E | E ÷ E | E^k | f(E) | leaf
f → sin | cos | tan | ln | exp | √ | |·|
leaf → integer | rational | variable | constant (π, e)
```

Unbounded recursion explodes, so the terminal probability rises with depth `d`:

```
P(leaf | d) = 1 − (1 − p₀)^(d+1)
```

with `p₀ ≈ 0.25` and a hard cut at `d = d_max`. Expected node count stays finite and
the depth slider behaves intuitively.

**Domain guards** applied at build time, not by retrying blindly:

| Operation | Guard |
|---|---|
| `a ÷ b` | rebuild `b` until its evaluation is ≠ 0 (bounded attempts, else swap to `×`) |
| `ln(x)`, `log(x)` | wrap the child in `|·|` and add 1 |
| `√x` | wrap in `|·|` |
| `tan(x)` | reject `x` near `π/2 + kπ` within ε |
| `a^b` | clamp `b ∈ [−3, 4]`, integer only |
| overflow | evaluate with the guard `|result| < 10¹²`, else regrow the subtree |

## 2. Backward: problems with clean answers

### 2.1 Linear equations

Choose the solution `x = r` first, then wrap it:

```
a·x + b = c   with  c = a·r + b,   a ∈ [2,12]\{0}, b ∈ [−20,20], r ∈ [−12,12]
```

Harder tiers add a second side and parentheses: `a(x + b) = c·x + d`, solving for
`x = (d − ab) / (a − c)` — pick `a ≠ c` and pick `d` so the division is exact.

### 2.2 Quadratics

Choose integer roots `r₁, r₂` and a leading coefficient `a`:

```
a(x − r₁)(x − r₂) = a·x² − a(r₁+r₂)·x + a·r₁·r₂
```

Guaranteed factorable, integer coefficients, integer roots. For the "irrational roots"
tier, instead choose `a, b, c` with discriminant `Δ = b² − 4ac > 0` and non-square, and
present the exact surd form.

### 2.3 Systems

Pick the solution `(x, y)`, then generate a matrix with `det = ad − bc ≠ 0` and compute
the right-hand side. Always consistent, always uniquely solvable.

### 2.4 Calculus

Differentiation is closed over our function set — generate forward and differentiate
symbolically; the answer is always exact. Integration is not, so **generate the
antiderivative F first and present F′ as the problem**. That inverts the hard direction
into the easy one and is why this module can offer integrals at all.

Supported rules: power, product, quotient, chain, and the standard table
(`sin`, `cos`, `e^x`, `ln`, `1/x`).

### 2.5 Matrices

- Random matrix with a **chosen determinant**: start from `I`, apply random unimodular
  row operations (`Rᵢ += k·Rⱼ`) — determinant is preserved, entries stay integral.
- Invertible-with-integer-inverse: build from `det = ±1` by the same trick.
- Random symmetric positive-definite: `A = LLᵀ` with random positive-diagonal `L`.

## 3. Generators

| Key | Name | Params |
|---|---|---|
| `equations.arithmetic` | Mental Math | operations, digits, count, allow-negatives |
| `equations.expression` | Expression Tree | depth, function set, variables, show-value |
| `equations.linear` | Linear Equations | tier, count, integer-solutions |
| `equations.quadratic` | Quadratics | root type (integer / rational / surd), count |
| `equations.system` | Systems | size (2×2, 3×3), count |
| `equations.calculus` | Calculus | mode (derivative / integral), rules, count |
| `equations.matrix` | Matrices | rows, cols, kind (any / invertible / symmetric / SPD), range |
| `equations.identity` | True or False? | shows a trig/log identity, half of them subtly broken |
| `equations.sequence` | Find the Pattern | kind (arithmetic, geometric, quadratic, Fibonacci-like), terms shown |

`equations.identity` is the most shareable one: generate a real identity, then with
p = 0.5 corrupt exactly one sign or coefficient. The user guesses. It makes a great
social post.

## 4. Worksheet export

Any equations generator can emit a **worksheet**: N problems, print stylesheet, answers
on page 2, and the seed printed in the footer so a teacher can regenerate the identical
sheet — or hand a different student a different seed. That's a genuinely useful artefact
falling out of the seed architecture for free, and it's the module's marketing angle:
*"Every worksheet is unique, and every worksheet is reproducible."*

## 5. Evaluation

A tiny internal evaluator over the AST (no `eval`, no external CAS): ~150 lines,
`Node` types `Num | Var | Unary | Binary`, with `evaluate(array $bindings): float`,
`derive(string $var): Node`, `simplify(): Node`, `toLatex(): string`.

`simplify()` only needs the cheap rules — `x·1`, `x+0`, `x^1`, constant folding,
double negation — enough to keep output readable without building a real CAS.
