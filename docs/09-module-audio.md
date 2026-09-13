# Module — Audio & Sound

Server sends a **score** (JSON: events, envelopes, synthesis params, seed). The browser
synthesises it. No audio files cross the wire, no FFmpeg, no storage — a 4-minute
generative piece is a 2 KB response.

Export is client-side: encode the PCM to a 16-bit WAV in an `ArrayBuffer`, download as
a Blob.

> **Amended in the build.** This document originally said the browser synthesises with
> the Web Audio API and exports through an `OfflineAudioContext`. It does neither.
> Every sample is written by plain JavaScript into a `Float32Array`
> (`resources/js/audio/`), and Web Audio's only job is playing the finished buffer.
>
> The reason is that audio's failure modes are silent: pink noise that is actually
> brown, a limiter that never engages, a string playing the wrong note. None of those
> throw, none look wrong in a diff, and a node graph can only be run inside a browser.
> Plain sample code runs unchanged under Node in `scripts/analyse-audio.mjs`, where the
> output is *measured* — and measuring has already caught two real bugs that listening
> and reviewing both missed. With the samples already in hand, an `OfflineAudioContext`
> has nothing left to render, so the WAV is encoded straight from them and the exported
> file is bit-for-bit what was played.

## 1. Why it's all client-side

Rendering audio in PHP means either shipping megabytes per request or adding a binary
dependency. Web Audio gives us oscillators, filters, convolution and an offline renderer
for free, and every browser has it. The server's job is to be the *composer*; the browser
is the *orchestra*.

## 2. Noise colours

The audible twin of `07-module-patterns.md` §4 — same `1/f^β` spectral shaping, one
dimension instead of two.

| Colour | Spectrum | Implementation | Character |
|---|---|---|---|
| **White** | flat | `U(−1,1)` per sample | hiss, harsh |
| **Pink** | −3 dB/octave | Voss–McCartney (§2.1) or a 3-pole IIR | balanced, "natural" |
| **Brown/Red** | −6 dB/octave | `b[n] = b[n−1] + 0.02·w[n]`, then normalise | deep rumble, waterfall |
| **Blue** | +3 dB/octave | differentiate **pink** | bright, airy |
| **Violet** | +6 dB/octave | differentiate **white** | thin, sizzly |
| **Grey** | white shaped by an inverse equal-loudness curve | biquad chain | *perceptually* flat — the interesting one |

> **Corrected after measuring it.** This table first said blue was differentiated white
> and violet was white differentiated twice. Both were wrong by a factor of two.
> Differentiating in time multiplies by `jω`, so amplitude scales with `f` and *power*
> with `f²` — and `10·log₁₀(f²)` is **6 dB per octave, not 3**. So one differentiation of
> white gives violet, and blue is one differentiation of pink (`−3 + 6 = +3`). Measured:
> white +0.05, pink −3.37, brown −5.88, blue +2.56, violet +5.97.

### 2.1 Voss–McCartney pink noise

Sum `N` white generators, each updating at half the rate of the last:

```
for each sample index n:
    for k in 0..N−1:
        if (n mod 2^k) == 0:  row[k] = U(−1,1)
    out = (Σ row) / N
```

`N = 16` gives clean 1/f across the audible band. Cheap, elegant, and the "generators
updating at different rates" idea visualises beautifully — show the 16 rows animating
under the waveform.

Practical use: the noise generators double as a **focus/sleep sound machine**, which is
a real reason for someone to keep the tab open. That's retention, for free.

## 3. Pitch and scales

MIDI note to frequency:

```
f = 440 · 2^((n − 69)/12)
```

Scales are interval sets in semitones from the root:

| Scale | Intervals | Mood |
|---|---|---|
| Major (Ionian) | 0 2 4 5 7 9 11 | bright |
| Natural minor (Aeolian) | 0 2 3 5 7 8 10 | sad |
| Dorian | 0 2 3 5 7 9 10 | jazzy minor |
| Phrygian | 0 1 3 5 7 8 10 | dark, Spanish |
| Lydian | 0 2 4 6 7 9 11 | dreamy |
| Mixolydian | 0 2 4 5 7 9 10 | bluesy |
| Major pentatonic | 0 2 4 7 9 | **can't sound wrong** |
| Minor pentatonic | 0 3 5 7 10 | blues |
| Blues | 0 3 5 6 7 10 | — |
| Whole tone | 0 2 4 6 8 10 | floating, unresolved |
| Hirajoshi | 0 2 3 7 8 | Japanese |

**Default to pentatonic.** With a pentatonic scale, uniformly random notes sound musical
to almost everyone — the scale does the work the composer would. This single default is
the difference between "random noise" and "oh, that's actually nice."

## 4. Melody generation

### 4.1 Random walk in scale degrees

Uniform random notes sound aimless. A bounded walk sounds like a melody:

```
dᵢ₊₁ = clamp(dᵢ + step, 0, range)
step ~ weighted({−2:0.1, −1:0.25, 0:0.1, +1:0.25, +2:0.1, −4:0.1, +4:0.1})
```

Small steps dominate, occasional leaps. This is the melodic contour rule that actual
music theory describes, expressed as a weight table.

### 4.2 Markov melodies

Order-1 or order-2 chains over scale degrees, trained on bundled folk-tune degree
sequences. Same slider trick as the words module: watch order 0 → 2 turn noise into
phrasing.

### 4.3 1/f music (Voss)

Voss's own application of pink noise: pitch chosen by summing several dice updating at
different rates. Empirically closer to real music than either white (too random) or
brown (too meandering). Direct callback to §2.1 — the same algorithm, used as a composer.

## 5. Rhythm — Euclidean patterns

Bjorklund's algorithm distributes `k` onsets over `n` steps as evenly as possible.
Astonishingly, nearly every traditional world rhythm is an `E(k,n)`:

```
E(3,8)  = [x..x..x.]   Cuban tresillo
E(5,8)  = [x.xx.xx.]   Cuban cinquillo
E(2,5)  = [x.x..]      Korean / Greek
E(7,12) = [x.xx.x.xx.x.]  West African bell
E(9,16) = [x.xx.x.x.xx.x.x.]  Brazilian samba
```

Random rhythm = draw `k`, `n`, and a rotation. It is essentially impossible to produce
an ugly result. `E(3,8) = [1,0,0,1,0,0,1,0]` is one of our known-answer tests.

## 6. Synthesis engines

| Engine | Formula | Sound |
|---|---|---|
| **Subtractive** | osc (saw/square/tri) → biquad filter (random cutoff/Q) → VCA | classic synth |
| **FM** | `y(t) = A·sin(2π f_c t + I·sin(2π f_m t))` | bells, metallic, electric piano. Ratio `f_m/f_c` integer ⇒ harmonic; irrational ⇒ clangorous |
| **Karplus–Strong** | `y[n] = ½·(y[n−N] + y[n−N−1])·d`, buffer seeded with noise, `N = fs/f` | plucked string from *pure noise* — the perfect demo for this site |
| **Additive** | `Σ aₖ·sin(2π k f t)` with random `aₖ` | organ, glassy pads |
| **Granular** | scatter 5–50 ms grains with random offset/pitch/pan | clouds, textures |

### ADSR envelope

```
0 → 1 over A,  1 → S over D,  hold S,  S → 0 over R
```

Random envelopes within musical bounds: `A ∈ [1ms, 400ms]`, `D ∈ [20ms, 800ms]`,
`S ∈ [0, 0.8]`, `R ∈ [50ms, 2s]`. A percussive draw (`A = 2ms, S = 0`) and a pad draw
(`A = 400ms, S = 0.7`) from the same generator feel like different instruments.

## 7. Generators

| Key | Name | Params |
|---|---|---|
| `audio.noise` | Noise Colours | colour, duration, filter, loop, volume |
| `audio.melody` | Melody | scale, root, bars, tempo, method (walk / markov / 1-over-f / uniform), engine |
| `audio.rhythm` | Euclidean Rhythm | k, n, rotation, tempo, kit, layers (up to 4 independent tracks) |
| `audio.pluck` | Plucked Strings | scale, density, decay, stereo spread |
| `audio.drone` | Drone | root, partials, beating rate, movement, duration |
| `audio.chord` | Chord Progressions | key, mode, length, voicing (from a weighted functional-harmony table) |
| `audio.bleep` | UI Sounds | category (success / error / notify / coin), count — downloadable pack |
| `audio.ambient` | Generative Ambient | mood preset, runs indefinitely, evolving |

## 8. Progression weights (`audio.chord`)

Functional harmony as a transition matrix over scale degrees, not a random draw:

```
I  → {IV:.3, V:.25, vi:.2, ii:.15, iii:.1}
ii → {V:.5, IV:.2, vii°:.2, I:.1}
IV → {V:.35, I:.3, ii:.2, vi:.15}
V  → {I:.55, vi:.25, IV:.1, iii:.1}      ← the deceptive cadence lives here
vi → {IV:.35, ii:.3, V:.2, I:.15}
```

Start on `I`, walk the chain, force a cadence on the final bar (`V → I`, or `V → vi` with
p = 0.2). The result sounds *written*. Showing the matrix next to the playing progression,
lighting up each transition as it fires, is the module's best visual.

## 8.1 Loudness has to be matched across generators

Measured RMS of the five defaults spanned 0.011 to 0.077 — nearly 17 dB. Moving between
generators in the studio meant a jolt every time, and a user riding the volume control
instead of listening. Peak normalisation does not fix it: sparse plucked notes have tall
transients and almost nothing between them, so they measure loud and sound quiet.

The master bus normalises to a reference RMS **first**, then applies the score's own
declared volume. Folding the two together would make the declared volume meaningless —
−12 dBFS would mean something different for every generator, which is the problem being
solved. Gain is capped so a near-silent render does not have its noise floor hauled up.

## 9. Verification: measure it, don't listen to it

Audio is the only module whose output cannot be checked by reading it, and its failure
modes are silent. Pink noise that is actually brown still sounds like noise. A string
whose loop filter runs backwards still sounds like *something*. A limiter that never
engages is inaudible until the one render that clips. None of these throw, none look
wrong in a diff, and most are not nameable by ear — but every one of them is a number.

`scripts/analyse-audio.mjs` renders a signal under Node and reports:

| Measure | Method | Catches |
|---|---|---|
| Spectral slope, dB/octave | Welch-averaged FFT, least squares over half-octave bands | a colour that is not the colour it claims |
| Fundamental, Hz | **autocorrelation** | a string playing the wrong note |
| Decay ratio | RMS of last tenth ÷ first tenth | a note that clicks or never dies |
| Peak before/after limiter | sample maximum | a master bus that clips |

This only works because synthesis is plain JavaScript producing `Float32Array` samples
rather than Web Audio nodes — the identical code runs in the browser, in an
`OfflineAudioContext` for WAV export, and under Node where it can be measured. Building
on `OscillatorNode` would have made the module untestable outside a browser.

**Two real bugs came out of this, neither of which any other test could have found:**

1. **Blue and violet were an octave-slope out**, per the note in §2 — caught by the slope
   measurement disagreeing with the table.
2. **Karplus–Strong ran its filter backwards.** It averaged each sample with the
   *previous* buffer slot instead of the next one. In a circular delay line the next slot
   is the oldest sample; the previous one has already been overwritten. Averaging with it
   subtracts adjacent samples in effect, which is a high-pass — so the string got
   *brighter* each lap instead of darker, and the spectrum tilted upward. It still sounded
   like a plucked note.

**A second measurement bug, found later:** autocorrelation peaks at *every* multiple of
the true period, since a waveform is just as self-similar at two periods as at one. On a
decaying note the longer lag can edge ahead on noise alone, and the reported pitch drops
an exact octave — a 196 Hz string measuring 98.2 Hz. That reads like a synthesis bug and
is nothing of the sort. The fix is to take the earliest lag scoring within 90% of the
best, rather than the best outright.

**And one wrong test**, worth recording because the failure mode is subtler than a bug.
The first pitch check took the tallest spectral peak and demanded it be the fundamental.
It measured 882.9 Hz for a 220 Hz string — the fourth harmonic. That was not a synthesis
error: Karplus–Strong is excited by noise, so initial energy lands unevenly across the
harmonic series, exactly as it does on a real string depending where it is plucked. A run
had the third harmonic louder than the first. The fix was in the measurement, not the
code — autocorrelation asks "at what lag does the waveform resemble itself?", which is
the question that has a right answer. It now reports 220.5 Hz, which is
`44100 ÷ round(44100/220)` exactly: the delay line is an integer number of samples, so
pitch quantises and lands a touch sharp.

## 10. Safety

Every generator goes through a master limiter and starts at −12 dBFS. Autoplay is never
used — Web Audio requires a user gesture anyway, and we want the first click to be
deliberate. A visible volume control and a hard mute are always on screen.
