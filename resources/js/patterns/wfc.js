/**
 * Wave Function Collapse, overlapping model.
 *
 * Given a small sample image, produce a larger one that is *locally* like it:
 * every N×N window of the output appears somewhere in the input. That is the
 * whole specification, and everything the algorithm does follows from treating it
 * as a constraint satisfaction problem.
 *
 *   1. Extract every N×N window of the sample, with its symmetries. These are
 *      the patterns, and their frequencies are their weights.
 *   2. Two patterns are compatible in a direction when the cells they share
 *      under that offset agree. This is the adjacency relation.
 *   3. Every output cell starts holding all patterns at once — the wave.
 *   4. Collapse the cell with the least Shannon entropy to a single pattern,
 *      drawn by weight, then propagate: any pattern with no remaining support
 *      from a neighbour is removed, which may remove others in turn.
 *   5. Repeat until every cell holds exactly one pattern.
 *
 * The min-entropy heuristic in step 4 is what makes it work rather than merely
 * terminate. Collapsing the most constrained cell first means the decision with
 * the fewest options is made while its neighbours still have room to
 * accommodate it; collapsing in scan order, or at random, walls the solver into
 * contradictions almost immediately.
 *
 * **Backtracking.** The constraint graph has cycles, so propagation is not
 * complete: a cell can be left with options that no global assignment can
 * actually use, and the contradiction only surfaces several decisions later.
 * The solver therefore journals every change it makes and can rewind to the
 * last decision, refute the pattern it chose there, and carry on. Where that
 * runs out — the journal has a memory budget and the rewind has a depth — it
 * restarts, and where *that* runs out it fills the remaining cells with their
 * most likely pattern. The one thing it never does is fail to draw something.
 *
 * On budget: this has to survive a slider drag, so the output grid is capped at a
 * few thousand cells and the pattern set at a couple of hundred. Both ceilings
 * are where the cost lives — propagation is O(cells × patterns × adjacency) and
 * all three multiply.
 */
import { splitmix32 } from './prng.js'
import { hexToRgb } from './shared.js'
import { Surface } from '../images/raster.js'
import { rect } from '../images/shapes.js'

/** mxgmn's direction order: left, down, right, up. `OPPOSITE` indexes back. */
const DX = [-1, 0, 1, 0]
const DY = [0, 1, 0, -1]
const OPPOSITE = [2, 3, 0, 1]

/**
 * The most patterns the solver will carry.
 *
 * Propagation cost is linear in the pattern count and so is every per-cell array,
 * so this is the difference between a render and a hang. A 12×12 sample at N = 3
 * with eight symmetries can reach a thousand distinct patterns, most of them seen
 * once; keeping the commonest two hundred loses variety the output was never
 * going to show at this grid size anyway.
 */
const MAX_PATTERNS = 220

/**
 * How much the undo journal may hold, in 32-bit words.
 *
 * Backtracking needs an exact inverse of everything propagation did, and
 * propagation does a great deal: a full solve at 180 patterns over 3400 cells can
 * ban half a million (cell, pattern) pairs, and each ban is seven words plus the
 * decrements it caused. Journalling all of it would run to tens of megabytes.
 *
 * Two million words is 8 MB, and the journal stays inside it by discarding its
 * own prefix — see compact(). Older decisions become permanent, which is what
 * makes the depth below a real limit rather than a suggestion.
 */
const MAX_JOURNAL = 2000000

/**
 * How many decisions stay rewindable.
 *
 * Chronological backtracking almost always recovers within a few levels: a
 * contradiction is caused by a decision near where it surfaced, because
 * propagation is local. Twenty-four levels is far past where the recovery rate
 * flattens out, and keeping the whole history instead would cost memory that
 * buys nothing — beyond a couple of dozen levels the right move is a restart,
 * not a deeper rewind.
 */
const MAX_BACKTRACK_DEPTH = 24

/** Journal record tags. */
const RECORD_BAN = 0
const RECORD_DECREMENT = 1

/** Restarts before the solver gives up and fills in whatever is left. */
const MAX_RESTARTS = 5

/**
 * Read a sample out of its wire form.
 *
 * The sample travels as one string of digits per row, which survives JSON at a
 * byte a cell where an array of arrays costs three.
 */
function readSample (sample) {
  const rows = sample.rows.length
  const cols = sample.rows[0].length
  const cells = new Uint8Array(cols * rows)

  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      cells[y * cols + x] = sample.rows[y].charCodeAt(x) - 48
    }
  }

  return { cols, rows, cells }
}

/** The eight symmetries of a square tile, as index maps into an N×N block. */
function transform (tile, n, kind) {
  const out = new Uint8Array(n * n)

  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      let sx
      let sy

      switch (kind) {
        case 1: sx = n - 1 - y; sy = x; break              // rotate 90°
        case 2: sx = n - 1 - x; sy = n - 1 - y; break      // rotate 180°
        case 3: sx = y; sy = n - 1 - x; break              // rotate 270°
        case 4: sx = n - 1 - x; sy = y; break              // mirror
        case 5: sx = y; sy = x; break                      // mirror + 90°
        case 6: sx = x; sy = n - 1 - y; break              // mirror + 180°
        case 7: sx = n - 1 - y; sy = n - 1 - x; break      // mirror + 270°
        default: sx = x; sy = y
      }

      out[y * n + x] = tile[sy * n + sx]
    }
  }

  return out
}

/**
 * Every N×N window of the sample, with weights.
 *
 * The sample is read periodically — the window wraps at its edges — which is what
 * makes the pattern set closed under translation and stops the solver needing
 * special cases for the sample's own border. It also means a sample has to be
 * authored to tile, which the bundled ones are.
 */
export function extract (sample, n, symmetry) {
  const { cols, rows, cells } = sample
  const index = new Map()
  const patterns = []
  const weights = []

  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      const tile = new Uint8Array(n * n)

      for (let dy = 0; dy < n; dy++) {
        for (let dx = 0; dx < n; dx++) {
          tile[dy * n + dx] = cells[((y + dy) % rows) * cols + ((x + dx) % cols)]
        }
      }

      // Symmetry counts are 1, 2, 4 and 8; the transform indices are ordered so
      // that a prefix of them is exactly the group wanted. 2 is the mirror pair,
      // 4 the rotations, 8 the full dihedral group.
      const kinds = symmetry === 1 ? [0]
        : symmetry === 2 ? [0, 4]
          : symmetry === 4 ? [0, 1, 2, 3]
            : [0, 1, 2, 3, 4, 5, 6, 7]

      for (const kind of kinds) {
        const variant = transform(tile, n, kind)
        const key = variant.join('')
        const seen = index.get(key)

        if (seen === undefined) {
          index.set(key, patterns.length)
          patterns.push(variant)
          weights.push(1)
        } else {
          weights[seen]++
        }
      }
    }
  }

  if (patterns.length <= MAX_PATTERNS) {
    return { patterns, weights }
  }

  /*
   * Too many patterns: keep the commonest.
   *
   * Dropping patterns can in principle make a sample unsolvable, because a
   * pattern that appears once may be the only bridge between two regions of the
   * adjacency graph. In practice the ones that appear once are the ones the
   * output has no room to show, and the solver's restart path covers the rest.
   */
  const order = weights.map((w, i) => i).sort((a, b) => weights[b] - weights[a]).slice(0, MAX_PATTERNS)

  return {
    patterns: order.map(i => patterns[i]),
    weights: order.map(i => weights[i]),
  }
}

/** Do these two patterns agree on the cells they share at this offset? */
function agrees (a, b, dx, dy, n) {
  const xmin = dx < 0 ? 0 : dx
  const xmax = dx < 0 ? dx + n : n
  const ymin = dy < 0 ? 0 : dy
  const ymax = dy < 0 ? dy + n : n

  for (let y = ymin; y < ymax; y++) {
    for (let x = xmin; x < xmax; x++) {
      if (a[y * n + x] !== b[(y - dy) * n + (x - dx)]) return false
    }
  }

  return true
}

/**
 * The adjacency relation, as four lists per pattern.
 *
 * `propagator[d][t]` holds every pattern that may sit one cell away in direction
 * d from a pattern t. Built once and shared by every cell, which is why the
 * per-cell state can be nothing but counters.
 */
export function buildPropagator (patterns, n) {
  const count = patterns.length
  const propagator = []

  for (let d = 0; d < 4; d++) {
    const lists = []

    for (let t = 0; t < count; t++) {
      const list = []

      for (let t2 = 0; t2 < count; t2++) {
        if (agrees(patterns[t], patterns[t2], DX[d], DY[d], n)) list.push(t2)
      }

      lists.push(Int32Array.from(list))
    }

    propagator.push(lists)
  }

  return propagator
}

class Solver {
  constructor (patterns, weights, propagator, cols, rows, random) {
    this.patterns = patterns
    this.weights = weights
    this.propagator = propagator
    this.cols = cols
    this.rows = rows
    this.random = random

    this.count = patterns.length
    this.cells = cols * rows

    this.wave = new Uint8Array(this.cells * this.count)
    // One counter per (cell, pattern, direction): how many patterns in that
    // neighbour still support this pattern here. A counter hitting zero is
    // exactly the condition for a ban, which is what turns propagation from a
    // set intersection into an increment.
    this.compatible = new Int32Array(this.cells * this.count * 4)

    this.sumsOfOnes = new Int32Array(this.cells)
    this.sumsOfWeights = new Float64Array(this.cells)
    this.sumsOfWeightLogWeights = new Float64Array(this.cells)
    this.entropies = new Float64Array(this.cells)

    this.weightLogWeights = new Float64Array(this.count)
    this.totalWeight = 0
    this.totalWeightLogWeight = 0

    for (let t = 0; t < this.count; t++) {
      this.weightLogWeights[t] = weights[t] * Math.log(weights[t])
      this.totalWeight += weights[t]
      this.totalWeightLogWeight += this.weightLogWeights[t]
    }

    this.startingEntropy = Math.log(this.totalWeight) - this.totalWeightLogWeight / this.totalWeight

    this.stack = new Int32Array(this.cells * this.count)
    this.stackSize = 0

    this.journal = new Int32Array(MAX_JOURNAL)
    this.journalSize = 0
    this.journalFull = false

    this.decisions = []
    this.distribution = new Float64Array(this.count)
  }

  clear () {
    this.wave.fill(1)

    for (let i = 0; i < this.cells; i++) {
      for (let t = 0; t < this.count; t++) {
        for (let d = 0; d < 4; d++) {
          this.compatible[(i * this.count + t) * 4 + d] = this.propagator[OPPOSITE[d]][t].length
        }
      }

      this.sumsOfOnes[i] = this.count
      this.sumsOfWeights[i] = this.totalWeight
      this.sumsOfWeightLogWeights[i] = this.totalWeightLogWeight
      this.entropies[i] = this.startingEntropy
    }

    this.stackSize = 0
    this.journalSize = 0
    this.journalFull = false
    this.decisions = []
  }

  /**
   * The least-entropy undecided cell.
   *
   * Ties are broken by a small random offset rather than by index. Taking the
   * first minimum is the obvious spelling and biases the whole solve towards the
   * top-left corner, which shows up in the output as a diagonal grain.
   *
   * Returns −1 when every cell is decided.
   */
  lowestEntropy () {
    let best = Infinity
    let argmin = -1

    for (let i = 0; i < this.cells; i++) {
      if (this.sumsOfOnes[i] <= 1) continue

      const noise = 1e-6 * this.random()
      if (this.entropies[i] + noise < best) {
        best = this.entropies[i] + noise
        argmin = i
      }
    }

    return argmin
  }

  /** Collapse one cell to a single pattern, drawn by weight. Returns false on failure. */
  observe (i) {
    const base = i * this.count
    let total = 0

    for (let t = 0; t < this.count; t++) {
      const w = this.wave[base + t] ? this.weights[t] : 0
      this.distribution[t] = w
      total += w
    }

    if (total <= 0) return -1

    let target = this.random() * total
    let chosen = this.count - 1

    for (let t = 0; t < this.count; t++) {
      target -= this.distribution[t]
      if (target < 0) { chosen = t; break }
    }

    // Recorded before the bans below, so rewinding to this mark restores the
    // cell to the state it was in when it was chosen.
    this.decisions.push({ cell: i, pattern: chosen, mark: this.journalSize })

    if (this.decisions.length > MAX_BACKTRACK_DEPTH * 2) this.compact()

    for (let t = 0; t < this.count; t++) {
      if (this.wave[base + t] && t !== chosen) this.ban(i, t)
    }

    return chosen
  }

  /**
   * Append one undo record.
   *
   * The tag goes *last*, which looks backwards and is the point: the journal is
   * only ever read in reverse, and the two record types are different lengths, so
   * a reader walking backwards has to know the length before it can find the
   * start. Putting the tag at the front meant guessing the length and checking
   * whether the word there looked like a tag — which is not a check at all, since
   * a cell index of 0 is indistinguishable from the ban tag.
   */
  recordBan (i, t, c0, c1, c2, c3) {
    if (this.journalFull) return
    if (!this.reserve(7)) return

    const j = this.journal
    let p = this.journalSize
    j[p++] = i
    j[p++] = t
    j[p++] = c0
    j[p++] = c1
    j[p++] = c2
    j[p++] = c3
    j[p++] = RECORD_BAN
    this.journalSize = p
  }

  recordDecrement (i, t, d) {
    if (this.journalFull) return
    if (!this.reserve(4)) return

    const j = this.journal
    let p = this.journalSize
    j[p++] = i
    j[p++] = t
    j[p++] = d
    j[p++] = RECORD_DECREMENT
    this.journalSize = p
  }

  reserve (words) {
    if (this.journalSize + words <= MAX_JOURNAL) return true
    if (this.compact() && this.journalSize + words <= MAX_JOURNAL) return true

    // Out of budget even after compaction, which needs a single decision to have
    // journalled eight megabytes on its own. Everything so far becomes permanent
    // — the solver keeps going forward, it just can no longer rewind — and a
    // contradiction from here on costs a restart instead of a backtrack.
    this.journalFull = true
    this.decisions = []

    return false
  }

  /**
   * Throw away the oldest end of the journal.
   *
   * Everything before the oldest decision still worth keeping is unreachable —
   * nothing can rewind past a decision that has been forgotten — so it is moved
   * out from under the live part and the remaining marks are rebased. One
   * copyWithin of a few megabytes, occasionally, in exchange for a solver that
   * can backtrack at any pattern count instead of only at small ones.
   */
  compact () {
    if (this.decisions.length <= 1) return false

    const keep = this.decisions.slice(-MAX_BACKTRACK_DEPTH)
    const base = keep[0].mark

    if (base === 0) return false

    this.journal.copyWithin(0, base, this.journalSize)
    this.journalSize -= base

    for (const decision of keep) decision.mark -= base

    this.decisions = keep

    return true
  }

  ban (i, t) {
    const slot = i * this.count + t
    const c = slot * 4

    this.recordBan(i, t, this.compatible[c], this.compatible[c + 1], this.compatible[c + 2], this.compatible[c + 3])

    this.wave[slot] = 0
    this.compatible[c] = 0
    this.compatible[c + 1] = 0
    this.compatible[c + 2] = 0
    this.compatible[c + 3] = 0

    this.stack[this.stackSize++] = slot

    this.sumsOfOnes[i] -= 1
    this.sumsOfWeights[i] -= this.weights[t]
    this.sumsOfWeightLogWeights[i] -= this.weightLogWeights[t]

    const sum = this.sumsOfWeights[i]
    this.entropies[i] = sum > 0 ? Math.log(sum) - this.sumsOfWeightLogWeights[i] / sum : 0
  }

  /** Remove every pattern left without support. Returns false on a contradiction. */
  propagate () {
    while (this.stackSize > 0) {
      const slot = this.stack[--this.stackSize]
      const i1 = (slot / this.count) | 0
      const t1 = slot - i1 * this.count

      const x1 = i1 % this.cols
      const y1 = (i1 / this.cols) | 0

      for (let d = 0; d < 4; d++) {
        /*
         * The output has edges; it is not a torus.
         *
         * Wrapping was the first build and it is worth recording why it failed,
         * because the picture it produced looked like a renderer bug and was
         * not one. A periodic output imposes a *global* constraint — the pattern
         * has to close up on itself in both directions — and for a sample of
         * diagonal stripes with period 4 on a grid 74 cells wide, no tiling
         * exists at all. The solver did exactly what it should: contradicted,
         * backtracked, restarted five times, gave up, and filled the canvas with
         * the single most likely pattern. One flat yellow rectangle.
         *
         * With edges, a cell at the boundary simply has one fewer neighbour to
         * satisfy, every sample is solvable at every grid size, and the cost is
         * that the result no longer tiles with itself.
         */
        const x2 = x1 + DX[d]
        const y2 = y1 + DY[d]

        if (x2 < 0 || y2 < 0 || x2 >= this.cols || y2 >= this.rows) continue

        const i2 = y2 * this.cols + x2

        const list = this.propagator[d][t1]

        for (let l = 0; l < list.length; l++) {
          const t2 = list[l]
          const c = (i2 * this.count + t2) * 4 + d

          if (this.compatible[c] === 0) continue

          this.compatible[c] -= 1
          this.recordDecrement(i2, t2, d)

          if (this.compatible[c] === 0) {
            this.ban(i2, t2)
            if (this.sumsOfOnes[i2] === 0) return false
          }
        }
      }
    }

    return true
  }

  /**
   * Rewind everything journalled after `mark`, exactly in reverse.
   *
   * The journal holds the inverse of every mutation propagation makes, in order,
   * which is what makes this a rewind rather than a recomputation. Recomputing
   * the compatibility counters from a restored wave would be correct and would
   * cost a full pass over cells × patterns × adjacency per backtrack, which at
   * these sizes is tens of millions of operations for something that happens
   * hundreds of times.
   */
  rewind (mark) {
    const j = this.journal

    while (this.journalSize > mark) {
      const tag = j[this.journalSize - 1]
      const p = this.journalSize - (tag === RECORD_BAN ? 7 : 4)

      if (tag === RECORD_BAN) {
        const i = j[p]
        const t = j[p + 1]
        const slot = i * this.count + t
        const c = slot * 4

        this.wave[slot] = 1
        this.compatible[c] = j[p + 2]
        this.compatible[c + 1] = j[p + 3]
        this.compatible[c + 2] = j[p + 4]
        this.compatible[c + 3] = j[p + 5]

        this.sumsOfOnes[i] += 1
        this.sumsOfWeights[i] += this.weights[t]
        this.sumsOfWeightLogWeights[i] += this.weightLogWeights[t]

        const sum = this.sumsOfWeights[i]
        this.entropies[i] = sum > 0 ? Math.log(sum) - this.sumsOfWeightLogWeights[i] / sum : 0
      } else {
        this.compatible[(j[p] * this.count + j[p + 1]) * 4 + j[p + 2]] += 1
      }

      this.journalSize = p
    }

    this.stackSize = 0
  }

  /**
   * Solve, with backtracking and then restarts.
   *
   * Returns the number of contradictions survived, which is worth reporting: it
   * is the honest measure of how hard a sample is, and a sample that never
   * contradicts is one whose adjacency graph happens to be tree-like.
   */
  run () {
    let contradictions = 0
    let restarts = 0

    this.clear()

    for (;;) {
      const i = this.lowestEntropy()

      if (i === -1) return { solved: true, contradictions, restarts }

      this.observe(i)

      while (!this.propagate()) {
        contradictions++

        /*
         * Chronological backtracking: rewind to the last decision, refute the
         * pattern it chose, and try again from there.
         *
         * Refuting rather than re-drawing is what makes this terminate. Drawing
         * again from the same distribution would usually pick the same pattern
         * and loop; banning it shrinks the search space by one on every pass, so
         * the cell eventually either finds a pattern that works or empties, and
         * an empty cell backtracks one level further.
         */
        let recovered = false

        while (this.decisions.length > 0) {
          const decision = this.decisions.pop()
          this.rewind(decision.mark)

          if (this.sumsOfOnes[decision.cell] <= 1) continue

          this.ban(decision.cell, decision.pattern)

          if (this.sumsOfOnes[decision.cell] === 0) continue

          recovered = true
          break
        }

        if (recovered) continue

        if (restarts >= MAX_RESTARTS) {
          return { solved: false, contradictions, restarts }
        }

        restarts++
        this.clear()
        break
      }
    }
  }

  /**
   * One pattern index per cell.
   *
   * A cell the solver never resolved — only reachable after every restart has
   * been spent — takes its most likely remaining pattern. That is a guess rather
   * than a solution and can leave a local adjacency violation, which is a far
   * better outcome than an empty canvas.
   */
  collapsed () {
    const out = new Int32Array(this.cells)

    for (let i = 0; i < this.cells; i++) {
      const base = i * this.count
      let best = -1
      let bestWeight = -1

      for (let t = 0; t < this.count; t++) {
        if (this.wave[base + t] && this.weights[t] > bestWeight) {
          bestWeight = this.weights[t]
          best = t
        }
      }

      out[i] = best < 0 ? 0 : best
    }

    return out
  }
}

/**
 * Run the whole thing and return the output as sample colour indices.
 *
 * Exported for scripts/check-wfc.mjs, which asserts that the result contains no
 * adjacency the sample does not — the one promise this generator makes, and one
 * that a picture alone cannot demonstrate.
 */
export function solve (spec) {
  const sample = readSample(spec.sample)
  const { patterns, weights } = extract(sample, spec.n, spec.symmetry)
  const propagator = buildPropagator(patterns, spec.n)

  const solver = new Solver(patterns, weights, propagator, spec.cols, spec.rows, splitmix32(spec.seed >>> 0))
  const result = solver.run()
  const chosen = solver.collapsed()

  // Each cell shows its pattern's top-left cell, so the grid of cells maps one
  // to one onto the picture. The remaining N−1 cells of every pattern are what
  // constrained its neighbours and are not drawn twice.
  const cells = new Uint8Array(spec.cols * spec.rows)
  for (let i = 0; i < cells.length; i++) cells[i] = patterns[chosen[i]][0]

  return { cells, chosen, patterns, weights, ...result }
}

export function render (ctx, spec) {
  const { width, height, cols, rows } = spec
  const { cells } = solve(spec)

  const surface = new Surface(width, height)
  const colours = spec.palette.map(hexToRgb)

  // Cells are square and sized to cover the canvas, so the grid bleeds off the
  // edge rather than leaving a margin — a tiling that stops short of its own
  // frame advertises that it is a tiling.
  const size = Math.max(width / cols, height / rows)

  for (let y = 0; y < rows; y++) {
    for (let x = 0; x < cols; x++) {
      const c = colours[Math.min(colours.length - 1, cells[y * cols + x])]

      /*
       * Integer edges, shared exactly between neighbours.
       *
       * Fractional edges are right for Mondrian, where a gutter separates every
       * cell, and wrong here, where they abut. Two neighbours blending onto the
       * same boundary pixel at 0.4 and 0.6 coverage do not sum to 1 — the second
       * blend is applied to the result of the first — so the pixel comes out
       * dark, and the whole tiling acquires a grid of fine seams that look like
       * a deliberate stroke. Rounding both edges makes one cell a pixel wider
       * than its neighbour occasionally, which on flat colour nobody can see.
       */
      rect(surface, Math.round(x * size), Math.round(y * size), Math.round((x + 1) * size), Math.round((y + 1) * size), c[0], c[1], c[2], 1)
    }
  }

  surface.commit(ctx, spec.grain, spec.grain_seed)
}
