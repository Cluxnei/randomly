# Bundled corpora

## EFF Diceware wordlists

- `eff_large_wordlist.txt` — 7,776 words (6⁵, one per five-dice roll) → **log₂(7776) ≈ 12.925 bits per word**
- `eff_short_wordlist_1.txt` — 1,296 words (6⁴) → **log₂(1296) ≈ 10.340 bits per word**

Source: Electronic Frontier Foundation, *Deep Dive: EFF's New Wordlists for Random
Passphrases* (2016). <https://www.eff.org/dice>

Licence: **CC BY 3.0 US** — <https://creativecommons.org/licenses/by/3.0/us/>
Attribution is rendered in the site footer and returned by `/api/v1/sources`.

Format is the EFF's own: `<five-digit dice roll>\t<word>`. The rolls are kept rather
than stripped because showing which five dice produced a word is the clearest possible
illustration of where the 12.925 bits come from.

These are bundled rather than fetched. A passphrase generator that has to ask a third
party for a noun is one network outage away from being useless, and the whole point of
the module is that the entropy accounting is verifiable offline.

## Syllable grammars and lorem vocabularies

- `syllables.json` — onset/nucleus/coda weight tables per flavour (elvish, nordic, brand, latin)
- `lorem.json` — the classical Latin lorem vocabulary, plus an engineering-prose word bank

Both are hand-built for this project and carry no third-party licence. They are data,
not code, on purpose: adding a flavour or a vocabulary is a JSON edit, and nothing in
`app/Random/Generators/Words` needs to know it happened.

The classical lorem word list descends from Cicero's *De finibus bonorum et malorum*
(45 BC) by way of a 15th-century typesetter, and is long out of copyright.

## Markov tables

Trained at runtime from the EFF large list rather than bundled — the training pass is
cheap, the result is cached, and a table checked into the repository is a table nobody
can regenerate from the corpus it claims to come from.
