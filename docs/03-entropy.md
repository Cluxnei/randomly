# Entropy Sources

Every source below was **probed live on 2026-09-12** from this machine. Free, no API key,
no signup. Status column reflects that probe.

## 1. The catalogue

| Source | Physical origin | Endpoint | Key? | Probe | Refresh | Receipt proof |
|---|---|---|---|---|---|---|
| **CSPRNG** | OS entropy pool (interrupt timing, HW RNG) | `random_bytes()` | — | n/a | instant | — |
| **Atmospheric** | Radio noise from lightning | `random.org/integers/?format=plain` | no | 200 · 0.31s | instant | quota headers |
| **Quantum vacuum** | Vacuum field fluctuations measured by homodyne detection | `qrng.anu.edu.au/API/jsonI.php?length=N&type=uint8` | no | 200 | instant | ANU paper link |
| **NIST Beacon** | NIST entropy source, SHA-512 chained + signed | `beacon.nist.gov/beacon/2.0/pulse/last` | no | 200 · 0.52s | 60s | pulse URI + signature |
| **drand** | Threshold BLS over the League of Entropy network | `api.drand.sh/public/latest` | no | 200 · 0.80s | 3s | round + BLS signature |
| **Bitcoin PoW** | Global hashrate grinding a block header | `mempool.space/api/blocks/tip/hash` | no | 200 · 0.42s | ~600s | block explorer link |
| **Seismic** | Earthquakes worldwide, last hour | `earthquake.usgs.gov/.../all_hour.geojson` | no | 200 · 0.42s | ~60s | USGS event page |
| **Space weather** | Planetary K-index, geomagnetic activity | `services.swpc.noaa.gov/json/planetary_k_index_1m.json` | no | 200 | 60s | NOAA SWPC |
| **Atmosphere** | Live temperature + wind at a random coordinate | `api.open-meteo.com/v1/forecast` | no | 200 | ~900s | Open-Meteo |
| **Orbital** | ISS ground-track position | `api.open-notify.org/iss-now.json` | no | 200 | instant | timestamp |

Two candidates **failed to resolve** from this network and are excluded until verified:
`api.dictionaryapi.dev` and `poetrydb.org` (both returned connection failure, not 4xx).
`numbersapi.com` returned 404 on its documented path. Do not spec against them.

## 2. Entropy quality, honestly

Raw bytes from these sources are **not** equally good. Classify them:

- **Class A — cryptographic.** CSPRNG, ANU QRNG, random.org, NIST Beacon, drand.
  Full-entropy bytes, usable directly (after conditioning).
- **Class B — public and predictable-after-the-fact.** Bitcoin hashes, NIST pulses,
  drand rounds. High entropy *at the moment of publication*, but **globally known**.
  Never use alone for anything secret.
- **Class C — low-rate, biased, structured.** Seismic magnitudes, temperature, Kp index,
  ISS coordinates. A USGS feed carries maybe 20–40 bits of real surprise per minute,
  not the 11 KB the JSON weighs. These are **flavour**, not entropy.

**Rule:** Class B and C sources are *always* mixed with Class A before producing output.
The UI credits the interesting source ("derived from a M4.2 earthquake off Honshu")
while the pool guarantees the cryptographic floor. This is stated plainly on
`/entropy` — the honesty is part of the pitch, not a footnote.

## 3. Conditioning: raw source → uniform bytes

Use **HKDF-SHA256** (RFC 5869), which PHP gives us natively as `hash_hkdf()`.

**Extract** — compress biased input material into a uniform pseudorandom key:

```
PRK = HMAC-SHA256(salt, IKM)
```

**Expand** — stretch the PRK into as many bytes as we need:

```
T(0) = ""
T(i) = HMAC-SHA256(PRK, T(i-1) ‖ info ‖ i)
OKM  = T(1) ‖ T(2) ‖ … truncated to L bytes
```

**One deliberate deviation for the streaming case.** RFC 5869 uses an 8-bit counter,
capping output at 255 × 32 = 8 160 bytes — which a four-megapixel noise field burns
through before it has drawn the first row. Our `HkdfStream` widens the counter to 32 bits
little-endian (`T(i) = HMAC(PRK, T(i−1) ‖ info ‖ LE32(i))`). Every other property is
unchanged. It is written down here because `resources/js/rng.js` must reproduce it
byte for byte.

In PHP, one call:

```php
$okm = hash_hkdf('sha256', $ikm, $length, $info, $salt);
```

`info` carries the generator identity, so two generators fed the same seed never
produce correlated streams:

```
info = "randomly/v1|" . $generatorKey . "|" . $paramsHash
```

For Class C sources we also apply **von Neumann debiasing** before extraction as a
demonstration piece (it's visible on `/entropy` as a little animation): read bit pairs,
emit `0` for `01`, `1` for `10`, discard `00` and `11`. Cost: throughput drops to
`p(1-p)` of input, but output bias vanishes for any i.i.d. source.

## 4. The Seed

```php
final readonly class Seed
{
    public const BYTES = 16;

    public function __construct(
        public string $bytes,        // 16 bytes of conditioned entropy
        public Receipt $receipt,     // provenance
    ) {}

    public function token(): string;                    // Crockford base32, 26 chars
    public static function fromToken(string $t, Receipt $r): self;
    public function rng(string $key, int $version, array $params = []): Rng;
}
```

**Why 16 bytes, and why the token *is* the seed.** A permalink has to replay without a
database, so the token must carry the entire seed — you cannot recover 32 bytes from a
10-byte handle. 16 bytes encodes to 26 Crockford base32 characters, which is short enough
to paste into a chat and long enough that 2¹²⁸ seeds will never collide.

**The security consequence, handled rather than ignored.** A 128-bit public seed is
perfect for artwork and useless for secrets: anyone holding the link can recompute the
output. So generators that produce secrets — `numbers.password`, `numbers.bytes` —
declare `isSensitive(): true`, and sensitive generators are never given a shareable
permalink. A password with a replayable URL is not a password, and the tidy fix is
simply not to offer the link.

Crockford base32 (no `I`, `L`, `O`, `U`, and those characters forgiven on input) means a
token survives being read aloud, handwritten, or typed out of a screenshot.

```php
final readonly class Receipt
{
    public function __construct(
        public string  $sourceKey,     // 'nist-beacon'
        public string  $sourceLabel,   // 'NIST Randomness Beacon'
        public string  $narrative,     // 'Pulse #1937618, chained and signed 42s ago'
        public ?string $proofUrl,      // https://beacon.nist.gov/beacon/2.0/chain/2/pulse/1937618
        public Carbon  $observedAt,
        public bool    $degraded,      // true when we fell back to CSPRNG
        public int     $latencyMs,
    ) {}
}
```

`narrative` is written per-source and is the single most shareable string in the
product. Examples we should hit:

- `Atmospheric radio noise, sampled in Dublin 0.3s ago.`
- `Vacuum fluctuations measured at the Australian National University.`
- `Bitcoin block 000000…c50e — 340 exahashes of proof-of-work.`
- `A magnitude 4.2 earthquake, 31 km off the coast of Honshu, 6 minutes ago.`
- `Planetary K-index 1 — the geomagnetic field was quiet when this was made.`

## 5. Freshness without hammering the APIs

External sources are cached for `min(sourceRefresh, 10s)`. A cache hit must **never**
mean a repeated seed. The pool always folds in fresh local entropy:

```
ikm  = external_material ‖ random_bytes(32) ‖ hrtime_ns ‖ counter++
seed = HKDF-SHA256(ikm, salt = "randomly.seed.v1", info = generator|params)
```

So: the exotic source supplies the *story* and a real entropy contribution; the local
CSPRNG supplies the *guarantee* of uniqueness. Both are true at once, and the receipt
says so.

## 6. Deterministic replay

Given a seed, every generator is a pure function. Replay is exact:

```
Rng = HKDF-Expand(PRK = seed.bytes, info = generator|paramsHash) as a byte stream
```

The stream is consumed lazily in 32-byte blocks with a counter, which makes
`/r/{token}` a pure recomputation — no storage, no database.

**Caveat to respect:** replay is only bit-exact if the generator version and parameter
set are unchanged. Every permalink therefore encodes `v` (generator version). Bumping a
generator's algorithm bumps `v`; old links keep resolving against the old code path or
are shown as "generated with v1, which has since changed".

## 7. Interfaces

```php
interface EntropySource
{
    public function key(): string;
    public function label(): string;
    public function class(): EntropyClass;          // A | B | C
    public function isAvailable(): bool;            // cheap circuit-breaker check
    public function collect(int $bytes): Material;  // raw + narrative + proof
}
```

```php
final class EntropyPool
{
    public function seed(string $generatorKey, array $params, ?string $prefer = null): Seed;
}
```

`prefer` is the user's source pick in the UI ("I want this from an earthquake").
If that source is down, the pool degrades to CSPRNG and the receipt says so — the
request never fails.

**`auto` picks at random, not round-robin.** A rotation counter only advances within
one PHP process, and nothing promises how requests map onto processes — under the
built-in server every request gets a fresh one, so a static counter sits permanently at
zero and `auto` silently means "always the first source". The symptom is invisible
unless you reload the landing page a few times and notice the receipt never changes.
Variety in the receipts is part of the product, so it cannot rest on the process model.

## 8. Failure policy

| Condition | Behaviour |
|---|---|
| Source times out (>1.5s) | Circuit breaker opens for 60s; fall back to CSPRNG; `degraded = true` |
| Source returns malformed data | Same as timeout, plus a log line |
| All external sources down | CSPRNG only; `/entropy` shows every card red; generators keep working |
| Rate limit hit (random.org quota) | Source marked `exhausted` until midnight UTC, hidden from the picker |
