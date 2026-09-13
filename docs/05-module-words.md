# Module — Words

Two families: **drawn** (pick real words from a list or an API) and **grown**
(synthesise words that never existed but sound like they could).

## 1. Local corpora (bundled, no network)

| File | Size | Source | Licence |
|---|---|---|---|
| `eff-large-wordlist.txt` | 7 776 words | EFF Diceware list | CC BY 3.0 US — attribute in footer |
| `eff-short-wordlist.txt` | 1 296 words | EFF short list | CC BY 3.0 US |
| `syllables.json` | onsets/nuclei/codas per language flavour | hand-built | ours |
| `markov/*.json` | pre-trained order-3 character tables | trained from the above | ours |

Bundling these is the difference between a generator that works offline in 2ms and one
that begs an API for a noun. Drawn generators default to local; API sources are an
explicit, credited upgrade.

## 2. Diceware passphrases — the entropy story

The EFF large list has exactly 7 776 = 6⁵ entries, one per five-dice roll. Each word
contributes:

```
H_word = log₂(7776) ≈ 12.925 bits
H_total = L · log₂(7776)
```

So 6 words ≈ **77.5 bits**, 7 words ≈ 90.5 bits. The UI shows this live as the user
drags the length slider, next to an offline-cracking estimate at a stated guess rate:

```
t = 2^(H−1) / rate      (expected time, half the keyspace)
```

with `rate = 10¹¹ guesses/s` (a realistic GPU rig against a fast hash) stated on screen —
never a bare "strong/weak" badge. Adding a separator or capitalisation is shown as
adding **zero** bits when the rule is deterministic, which is a lie most password
meters tell and we won't.

## 3. Grown words — order-n Markov chains

Train on a corpus of characters with boundary markers:

```
P(cᵢ | cᵢ₋ₙ … cᵢ₋₁)
```

Order 3 over English gives pronounceable nonsense (`brantish`, `coveline`, `thrumber`);
order 2 is too soupy, order 4 mostly regurgitates real words. Default **n = 3**, exposed
as a slider so users can watch the transition from noise → plausible → plagiarism. That
slider *is* the lesson.

Implementation: a `{context => AliasTable}` map, so each character is an O(1) draw.
Reject candidates that appear verbatim in the training corpus (that's what makes it a
*new* word) and candidates without a vowel.

## 4. Grown words — syllable grammars

Deterministic phonotactics, for when Markov output is too mushy:

```
word     := syllable{2,4}
syllable := onset? nucleus coda?
onset    := one of {b,br,ch,cr,dr,fl,gr,kh,pl,sh,sk,sp,st,thr,tr,v,z…}
nucleus  := one of {a,e,i,o,u,ae,ai,ea,ee,io,ou,y}
coda     := one of {∅,l,m,n,r,s,th,nd,ng,rk,st}
```

Flavour presets reshape the weights: `elvish` (liquids, open syllables, no hard stops),
`nordic` (consonant clusters, `k`/`j`/`ø`), `brand` (2 syllables, ends in a vowel — the
startup-name generator), `latin` (`-us`, `-a`, `-um` endings). Flavours are pure weight
tables, so adding one is a JSON edit.

## 5. External word sources (all probed working)

| Generator | API | Notes |
|---|---|---|
| `words.related` | `api.datamuse.com/words?ml=…&max=…` | free, keyless, no attribution required; semantic neighbours, rhymes (`rel_rhy`), sound-alikes |
| `words.random` | `random-word-api.herokuapp.com/word?number=N` | free; can be slow (cold dyno) — 1.5s timeout, falls back to the local list |
| `words.identity` | `randomuser.me/api/?results=N` | full fake identity: name, location, photo. Flag clearly as **fictional** |
| `words.article` | `en.wikipedia.org/api/rest_v1/page/random/summary` | a real random Wikipedia article — title, extract, thumbnail. CC BY-SA, attributed |
| `words.book` | `openlibrary.org/search.json` | random real book title/author |
| `words.species` | `api.gbif.org/v1/species/search` | random real species binomial — surprisingly beautiful output |

`dictionaryapi.dev` and `poetrydb.org` were **unreachable** at spec time; excluded.

## 6. Generators

| Key | Name | Params |
|---|---|---|
| `words.passphrase` | Passphrase | words (4–12), separator, list (large/short), capitalise, add-number |
| `words.pseudo` | Invented Words | count, markov order, min/max length, flavour |
| `words.syllabic` | Name Forge | count, syllables, flavour, gender-neutral toggle |
| `words.brand` | Brand Names | count, style, TLD-available hint (no lookup — just a pattern hint) |
| `words.lorem` | Lorem | paragraphs, words-per-sentence distribution, flavour (latin / markov / tech) |
| `words.related` | Word Web | seed word, relation (means-like, rhymes, sounds-like), count |
| `words.identity` | Fictional People | count, nationality filter |
| `words.article` | Random Knowledge | count |
| `words.species` | Random Life | count |

## 7. Lorem sentence shaping

Sentence length should not be uniform — real prose is **log-normally** distributed.
Draw word counts as:

```
L = round(exp(μ + σ·Z)),  Z ~ N(0,1),  μ = ln(14), σ = 0.45, clamped to [4, 40]
```

That one line is the difference between lorem that reads like text and lorem that reads
like a list. Punctuation (commas at ~0.3 per 10 words, occasional em-dash) is drawn from
a Poisson.
