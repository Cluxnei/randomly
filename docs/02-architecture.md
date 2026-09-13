# Architecture

**Stack:** Laravel 13 · PHP 8.5 · Blade + Alpine 3 + Tailwind 4 · Vite · SQLite (optional, phase 3) · Pest.

The whole design rests on one idea: **a generator is a class, and everything else builds
itself from that class.** The catalogue, the studio's control panel, the JSON API, and
the permalink format are all derived from the generator's declared schema. Adding a
generator is one file and one line in a registry — no route, no controller, no Blade view,
no JS.

---

## 1. The core contract

```php
interface Generator
{
    public function key(): string;            // 'numbers.gaussian'
    public function name(): string;           // 'Gaussian Numbers'
    public function tagline(): string;        // 'The bell curve, drawn from real noise.'
    public function module(): Module;         // Module::Numbers
    public function version(): int;           // bump when output changes for a given seed
    public function schema(): ParamSchema;    // declares the controls
    public function render(): Renderer;       // Text | Canvas | Audio | Math | Gallery
    public function generate(Rng $rng, Params $params): Result;
}
```

`generate()` is **pure**: same `Rng` stream + same `Params` ⇒ same `Result`, forever.
It never touches the network, the clock, or global state. All impurity lives upstream
in the entropy pool.

```php
final readonly class Result
{
    public function __construct(
        public mixed  $value,      // scalars, arrays, or a spec the client renders
        public string $display,    // the copyable, human-facing form
        public array  $meta = [],  // stats, entropy in bits, timings — shown under the result
    ) {}
}
```

## 2. The parameter schema (this is what generates the UI)

```php
ParamSchema::make()
    ->int('count', 'How many', default: 10, min: 1, max: 1000)
    ->int('min', 'Minimum', default: 1, max: 1_000_000)
    ->int('max', 'Maximum', default: 100, max: 1_000_000)
    ->bool('unique', 'No repeats', default: false)
    ->enum('sort', 'Order', ['none' => 'As drawn', 'asc' => 'Ascending'], default: 'none')
    ->float('sigma', 'Spread (σ)', default: 1.0, min: 0.1, max: 10, step: 0.1);
```

There is deliberately **no cross-field rule builder** (`->rule('max', 'gt:min')` and
friends). Coercion clamps every value into its declared range and drops anything
undeclared, so `generate()` never has to defend itself against the request; and where two
fields can contradict each other, the generator resolves it in the way the user obviously
meant. `numbers.integers` handed `min: 90, max: 10` swaps them rather than erroring —
a 422 thrown mid-slider-drag would be obnoxious, and the intent is unambiguous.

One Blade component, `<x-control :param="$p" />`, switches on the param type and renders
a slider, a stepper, a toggle, or a segmented control. **No generator ever writes UI.**
The same schema produces the API's validation rules and the OpenAPI description.

### 1.1 Generators that need outside material

`generate()` is pure, and a generator wanting a real Wikipedia article or a real species
binomial cannot honour that on its own. Rather than weaken the contract for everyone, the
fetching is lifted out:

```php
interface NeedsData
{
    public function fetch(Params $params): array;   // impure, may fail
    public function cacheSeconds(): int;
    public function fallback(): array;              // never an exception
}
```

The Studio calls `fetch()` — cached, wrapped, degrading to `fallback()` on any throw —
and hands the result over through `Params::withData()`. `generate()` reads it from
`$params->data('key')` and stays a pure function of its inputs.

Three rules make this honest rather than a loophole:

1. **Fetched data is outside the seed fingerprint.** Parameters feed the fingerprint;
   fetched material must not, or a pool that shifts between requests would derive a
   different stream and break replay invisibly.
2. **Fetch a pool, not an answer.** A source returning one random item has done the
   choosing itself — the entropy would be that server's, not the beacon's we credited on
   the receipt. `fetch()` returns ~20 candidates and the Rng picks.
3. **No permalink.** External material moves, so the same seed against tomorrow's pool
   picks differently. `isReproducible()` returns false for any `NeedsData` generator and
   the studio withholds the link rather than shipping one that quietly stops working.

## 3. Directory layout

```
app/
  Random/
    Entropy/
      Contracts/EntropySource.php
      Sources/CsprngSource.php
              RandomOrgSource.php      AnuQrngSource.php
              NistBeaconSource.php     DrandSource.php
              BitcoinSource.php        SeismicSource.php
              SpaceWeatherSource.php   AtmosphereSource.php   IssSource.php
      EntropyPool.php  Seed.php  Receipt.php  Material.php  EntropyClass.php
    Rng/
      Rng.php                 # the byte stream + primitives
      HkdfStream.php          # HKDF-Expand in counter mode
      Distributions/Gaussian.php Exponential.php Poisson.php Zipf.php AliasTable.php
      Sampling.php            # shuffle, reservoir, weighted, without-replacement
    Generators/
      Contracts/Generator.php  ParamSchema.php  Params.php  Result.php  Renderer.php
      Numbers/…  Words/…  Equations/…  Patterns/…  Images/…  Audio/…
    GeneratorRegistry.php
  Http/Controllers/
    LibraryController.php  StudioController.php  ReplayController.php
    Api/GenerateController.php  Api/EntropyController.php
resources/
  views/  components/  js/generators/{noise,flowfield,audio}.js
  data/   eff-large-wordlist.txt  syllables.json  scales.json  tiles/
docs/
```

## 4. Request flow

```
GET /g/numbers/gaussian
  └─ StudioController
       ├─ registry->find('numbers.gaussian')
       ├─ schema() ──────────────► renders the control panel
       └─ generates one result server-side so the page is never empty

POST /api/v1/generate  {generator, params, source?}
  └─ GenerateController
       ├─ validate against schema()
       ├─ EntropyPool->seed(key, params, source)   ← the only impure step
       ├─ new Rng(HkdfStream(seed, info))
       ├─ generator->generate($rng, $params)
       └─ {value, display, meta, receipt, permalink}

GET /r/{token}?g=…&v=…&p=…
  └─ ReplayController — rebuilds the Rng from the token, recomputes, renders identically
```

The studio page hits the same JSON endpoint that third parties do. One code path.

## 5. Where work happens

| Concern | Server (PHP) | Client (Alpine + Canvas/Web Audio) |
|---|---|---|
| Entropy collection, conditioning, seeding | ✅ | — |
| Numbers, words, equations | ✅ generated fully | renders text/KaTeX |
| Noise, patterns, images | emits a **spec** (algorithm + params + seed) | ✅ draws pixels |
| Audio | emits a **score** (notes, envelopes, params + seed) | ✅ synthesises |
| PNG / WAV export | optional GD/`imagick` path for shareable OG images | ✅ Canvas `toBlob` / WAV encoder |

Pixels and samples are big; seeds are 32 bytes. Shipping the seed and drawing on the
client keeps responses tiny and makes live parameter tweaking feel instant — the client
re-renders from the same seed without a round trip.

The client's PRNG must match the server's for this to be honest: `resources/js/rng.js`
implements the *same* HKDF-SHA256 counter stream via `crypto.subtle`, so a canvas drawn
in the browser is provably the same draw the server would have made. This is worth a
paragraph on the site — it's a real correctness property, not a decoration — and it is
pinned by `tests/Unit/RngParityTest.php`, which runs both implementations and compares
raw stream bytes, integer draws with their rejection counts, doubles, shuffles, and token
decoding. Two implementations of one algorithm in two languages is exactly the kind of
promise that rots silently.

**The render key.** The browser is handed a derived 32-byte key, not the seed and not the
generator's `info` string. That `info` embeds a hash of `json_encode($params)`, and PHP
escapes forward slashes where `JSON.stringify` does not — so any parameter containing a
`/` would silently desynchronise the two streams. Deriving a key server-side removes the
shared-canonicalisation problem entirely: both sides then expand from the same PRK with a
plain ASCII info, which is trivially identical in both languages. A render key is a seed
by another name, so sensitive generators are never given one.

**Looking at the output.** `scripts/render-preview.mjs` runs any canvas renderer under
Node with a shim context and node's own zlib for PNG encoding — no browser, no native
canvas dependency. It exists because "the tests pass" says nothing about whether a
generative image is beautiful or mud, and this project's output has to be looked at.

## 6. Registry

```php
final class GeneratorRegistry
{
    /** @return Collection<string, Generator> */
    public function all(): Collection;
    public function module(Module $m): Collection;
    public function find(string $key): Generator;
    public function featured(): Collection;   // hand-picked for the landing page
}
```

Populated by a single `config/randomly.php` array of class names. Explicit over
auto-discovery: it's one line per generator, it's greppable, and it controls ordering
in the catalogue.

## 7. Caching & resilience

- External entropy: `Cache::remember("entropy.$key", min($refresh, 10), …)`.
- Circuit breaker per source: 3 consecutive failures ⇒ open 60s ⇒ half-open probe.
- `/entropy` polls a cached status summary every 5s; it never triggers live fetches.
- Rate limit on `/api/v1/generate`: 60/min per IP, generous but bounded.

## 8. Testing policy (deliberately thin)

Pest, unit only, no HTTP, no browser. Five kinds of test and nothing else:

1. **Determinism** — same seed ⇒ identical result, per generator. One parametrised test
   covering the whole registry.
2. **Known-answer vectors** — HKDF against RFC 5869 test vectors; Euclidean rhythm
   `E(3,8) = [1,0,0,1,0,0,1,0]`; Perlin at integer lattice points = 0.
3. **Range/shape invariants** — outputs land inside declared bounds; `count` is honoured;
   `unique` really is unique.
4. **Distribution sanity** — cheap χ² on 10k draws with a loose threshold, plus mean/σ
   within tolerance for Gaussian. Fixed seed, so it never flakes.
5. **Schema validity** — every registered generator's defaults validate against its own
   schema. Catches typos at build time.

No tests for controllers, views, external sources (mocked or skipped), or rendering.
