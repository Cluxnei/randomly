/**
 * L-systems: rewrite the string, then walk it with a turtle.
 *
 * Two things here are worth explaining, because both are the non-obvious choice.
 *
 * **The turtle runs twice.** An L-system has no idea how big its own drawing is
 * until it has drawn it — the plant's bounding box depends on every stochastic
 * choice made along the way — so the figure has to be measured before it can be
 * scaled to fit. The alternative is storing every segment and transforming them
 * afterwards, which for a few hundred thousand segments is a few megabytes of
 * arrays. Walking the string a second time costs a few milliseconds and no memory
 * at all. The prng is re-seeded from the same word in between, so the second walk
 * makes exactly the same choices as the first.
 *
 * **Depth means different things to different systems.** A plant branches, so the
 * natural gradient is bracket nesting: trunk dark, tips light. A dragon curve
 * never branches and would come out a single flat colour, so those are coloured
 * by progress along the path instead — which turns the fold order into something
 * you can actually see.
 */
import { Surface } from '../images/raster.js'
import { rampAt, shade, toRgb } from './colour.js'
import { splitmix32 } from './prng.js'

/**
 * Where the expansion stops regardless of what the iteration slider says.
 *
 * Geometric growth means the difference between "instant" and "the tab is gone"
 * is one iteration. Four hundred thousand symbols is roughly a fifth of a second
 * to expand and draw, and is already more strokes than a 900×600 canvas has the
 * resolution to distinguish.
 */
const MAX_SYMBOLS = 400000

/** Rewrite every symbol at once, `iterations` times. */
function expand (axiom, rules, iterations, random, stochastic) {
  let current = axiom

  for (let i = 0; i < iterations; i++) {
    if (current.length > MAX_SYMBOLS) break

    let next = ''

    for (const symbol of current) {
      const alternatives = rules[symbol]

      if (!alternatives) {
        next += symbol
        continue
      }

      next += alternatives.length === 1 || stochastic <= 0
        ? alternatives[0][1]
        : choose(alternatives, random)
    }

    current = next
  }

  return current
}

/** Weighted choice over a rule's alternatives — the stochastic production itself. */
function choose (alternatives, random) {
  let total = 0
  for (const [weight] of alternatives) total += weight

  let target = random() * total

  for (const [weight, replacement] of alternatives) {
    target -= weight
    if (target < 0) return replacement
  }

  return alternatives[alternatives.length - 1][1]
}

/**
 * Walk the string, handing every drawn segment to `emit` along with its bracket
 * nesting depth and its ordinal. Measuring and drawing are the same walk with a
 * different callback.
 */
function walk (string, spec, random, emit) {
  const turn = (spec.angle * Math.PI) / 180
  const jitter = spec.jitter

  let x = 0
  let y = 0
  let heading = (spec.heading * Math.PI) / 180
  let depth = 0
  let drawn = 0

  const stack = []

  for (const symbol of string) {
    switch (symbol) {
      case 'F':
      case 'G': {
        // Length jitter as well as angle jitter: a plant whose every internode is
        // the same length still reads as machinery, and this is the cheapest cure.
        const step = jitter > 0 ? 1 + (random() - 0.5) * 0.5 * jitter : 1
        const nx = x + Math.cos(heading) * step
        const ny = y + Math.sin(heading) * step

        emit(x, y, nx, ny, depth, drawn++)

        x = nx
        y = ny
        break
      }

      case 'f':
        x += Math.cos(heading)
        y += Math.sin(heading)
        break

      case '+':
      case '-': {
        const wobble = jitter > 0 ? 1 + (random() - 0.5) * 0.45 * jitter : 1
        heading += (symbol === '+' ? turn : -turn) * wobble
        break
      }

      case '|':
        heading += Math.PI
        break

      case '[':
        stack.push(x, y, heading, depth)
        depth++
        break

      case ']':
        depth = stack.pop()
        heading = stack.pop()
        y = stack.pop()
        x = stack.pop()
        break

      default:
        break
    }
  }

  return drawn
}

export function render (ctx, spec, rng) {
  const { width, height, seed } = spec

  const colours = toRgb(spec.palette)
  // The ramp's dark end is where the trunk is drawn, so the ground has to be
  // darker than the darkest stop or the trunk disappears into it.
  const ground = shade(colours[0], 0.16)

  // Alternative productions are what "stochastic" means for a rule set; the
  // hand-drawn wobble on top of it belongs only to systems that have them. A Koch
  // snowflake with jittered turns is not a Koch snowflake, and the three
  // deterministic presets are here precisely to be the textbook figures.
  const branching = Object.values(spec.rules).some(alternatives => alternatives.length > 1)
  const jitter = branching ? spec.stochastic : 0

  const string = expand(spec.axiom, spec.rules, spec.iterations, splitmix32(seed >>> 0), spec.stochastic)
  const geometry = { ...spec, jitter }

  // Pass one: measure. Nothing is stored.
  let minX = Infinity; let maxX = -Infinity
  let minY = Infinity; let maxY = -Infinity
  let deepest = 1

  const total = walk(string, geometry, splitmix32(seed >>> 0), (x0, y0, x1, y1, depth) => {
    if (depth > deepest) deepest = depth
    if (x1 < minX) minX = x1
    if (x1 > maxX) maxX = x1
    if (y1 < minY) minY = y1
    if (y1 > maxY) maxY = y1
    if (x0 < minX) minX = x0
    if (x0 > maxX) maxX = x0
    if (y0 < minY) minY = y0
    if (y0 > maxY) maxY = y0
  })

  const surface = new Surface(width, height)
  surface.fill(ground[0], ground[1], ground[2])

  if (total > 0) {
    const pad = Math.round(Math.min(width, height) * 0.06)
    // A figure one segment wide — a single F, or an angle that folds everything
    // onto one line — would divide by zero. Falling back to 1 puts it in the
    // middle at unit scale rather than filling the canvas with NaN.
    const scale = Math.min(
      (width - pad * 2) / (maxX - minX || 1),
      (height - pad * 2) / (maxY - minY || 1),
    )

    const offsetX = (width - (maxX - minX) * scale) / 2 - minX * scale
    const offsetY = (height - (maxY - minY) * scale) / 2 - minY * scale

    // Deep branches are thin, and a curve that never branches keeps one weight.
    // The depth ceiling is measured rather than guessed: a plant's real nesting is
    // about one level per iteration, and dividing by anything larger would push
    // every branch into the dark end of the ramp.
    const rgb = [0, 0, 0]

    walk(string, geometry, splitmix32(seed >>> 0), (x0, y0, x1, y1, depth, index) => {
      const t = branching
        ? Math.min(1, depth / deepest)
        : index / total

      rampAt(colours, 0.34 + 0.66 * t, rgb)

      const weight = spec.thickness * (branching ? Math.max(0.35, 1 - depth / (deepest + 2)) : 1)

      surface.stroke(
        x0 * scale + offsetX, y0 * scale + offsetY,
        x1 * scale + offsetX, y1 * scale + offsetY,
        rgb[0], rgb[1], rgb[2],
        1, weight / 2, false,
      )
    })
  }

  surface.commit(ctx)
}
