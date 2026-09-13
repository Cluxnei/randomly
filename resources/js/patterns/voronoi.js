/**
 * Voronoi cells, by exact nearest-site search over a bucketed grid.
 *
 * Two passes. The first assigns every pixel to its nearest site and records how
 * far away the second-nearest was; the second turns that into colour. Splitting
 * them is what makes area colouring possible at all — a cell's area is not known
 * until every pixel has been assigned — and it costs one Int32Array.
 *
 * The search is exact rather than approximate. Sites go into buckets of roughly
 * one site each, and a pixel walks rings of buckets outwards, stopping once the
 * next ring is further away than the second-best site already found. Jump
 * flooding would be the fashionable alternative and is genuinely faster on a GPU,
 * but it is approximate, and an approximate boundary in a picture *about* where
 * the boundaries fall is the wrong trade.
 */
import { shade, toRgb } from './colour.js'

/** Bucket the sites so a pixel never has to look at all of them. */
function bucket (sites, width, height) {
  // Aim at one site per bucket: fewer and each bucket scan gets long, more and
  // the ring walk spends its time on empty cells.
  const cell = Math.max(4, Math.sqrt((width * height) / Math.max(1, sites.length)))
  const cols = Math.max(1, Math.ceil(width / cell))
  const rows = Math.max(1, Math.ceil(height / cell))

  const buckets = Array.from({ length: cols * rows }, () => [])

  sites.forEach(([x, y], i) => {
    const cx = Math.min(cols - 1, (x / cell) | 0)
    const cy = Math.min(rows - 1, (y / cell) | 0)
    buckets[cy * cols + cx].push(i)
  })

  return { buckets, cell, cols, rows }
}

export function render (ctx, spec, rng) {
  const { width, height, sites, edges } = spec

  const colours = toRgb(spec.palette)
  const ink = shade(colours[0], 0.22)

  const { buckets, cell, cols, rows } = bucket(sites, width, height)

  const owner = new Int32Array(width * height)
  // Distance from each pixel to its cell's nearest edge, in pixels — see the
  // derivation below. The border test is a comparison against it.
  const gap = new Float32Array(width * height)
  const area = new Int32Array(sites.length)

  for (let y = 0, i = 0; y < height; y++) {
    const cy = Math.min(rows - 1, (y / cell) | 0)

    for (let x = 0; x < width; x++, i++) {
      const cx = Math.min(cols - 1, (x / cell) | 0)

      let best = Infinity
      let second = Infinity
      let nearest = 0
      let runnerUp = 0

      for (let ring = 0; ring < cols + rows; ring++) {
        // A ring at distance `ring` cannot contain anything nearer than
        // (ring−1)·cell, so once that exceeds the second-best there is nothing
        // left to find. Squared throughout — the square root would be two per
        // pixel for no change in the ordering.
        const reach = (ring - 1) * cell
        if (ring > 0 && reach * reach > second) break

        for (let gy = cy - ring; gy <= cy + ring; gy++) {
          if (gy < 0 || gy >= rows) continue

          for (let gx = cx - ring; gx <= cx + ring; gx++) {
            if (gx < 0 || gx >= cols) continue
            // Only the perimeter is new; the interior was covered by earlier rings.
            if (ring > 0 && Math.abs(gx - cx) !== ring && Math.abs(gy - cy) !== ring) continue

            for (const s of buckets[gy * cols + gx]) {
              const dx = sites[s][0] - x
              const dy = sites[s][1] - y
              const d = dx * dx + dy * dy

              if (d < best) {
                second = best
                runnerUp = nearest
                best = d
                nearest = s
              } else if (d < second) {
                second = d
                runnerUp = s
              }
            }
          }
        }
      }

      owner[i] = nearest
      area[nearest]++

      /*
       * Distance from the pixel to the boundary itself, in pixels.
       *
       * The tempting (F₂ − F₁)/2 is wrong, and wrong in a way that is invisible
       * until it is not: it is the true distance only when the two sites are far
       * apart compared with the pixel's distance from them. Where two sites sit
       * close together, F₂ − F₁ changes very slowly across a wide region and a
       * fixed threshold on it opens the border out into a great dark wedge.
       *
       * The boundary is the perpendicular bisector of the two sites, and the
       * distance to it is (d₂² − d₁²) / 2|AB| exactly — which costs one hypot per
       * pixel and no square roots at all, since the squared distances are already
       * in hand.
       */
      const ax = sites[nearest][0]
      const ay = sites[nearest][1]
      const separation = second === Infinity
        ? 1
        : Math.hypot(sites[runnerUp][0] - ax, sites[runnerUp][1] - ay) || 1

      gap[i] = second === Infinity ? 1e9 : (second - best) / (2 * separation)
    }
  }

  /*
   * Area colouring, out of a palette that was not built to be a scale.
   *
   * Two problems, two fixes. Areas are ranked rather than used raw, because cell
   * sizes in a random Voronoi diagram are heavily skewed and a linear map of the
   * pixel count leaves almost everything in the first two colours. And the palette
   * is sorted by lightness first, so that walking it by rank runs dark to light
   * instead of hopping around the colour wheel — lightness is the channel the eye
   * reads as ordering, and it is the only one a golden-angle palette can be made
   * to agree on.
   *
   * The stops are then used discretely, never interpolated: two categorical
   * colours next to each other in lightness can still be 200° apart in hue, and
   * blending across that gap is the mud the palette module warns about.
   */
  const byArea = new Int32Array(sites.length)
  let scale = colours

  if (spec.colouring === 'area') {
    const order = Array.from(sites.keys()).sort((a, b) => area[a] - area[b])
    order.forEach((site, rank) => { byArea[site] = rank })

    // Rec. 709 luma: the cheap perceptual weighting, and more than enough to put
    // a handful of colours in a defensible order.
    scale = [...colours].sort(
      (a, b) => (0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]) - (0.2126 * b[0] + 0.7152 * b[1] + 0.0722 * b[2]),
    )
  }

  const image = ctx.createImageData(width, height)
  const data = image.data
  const last = colours.length - 1

  for (let i = 0, p = 0; i < owner.length; i++, p += 4) {
    const site = owner[i]

    const c = spec.colouring === 'area'
      ? scale[Math.min(last, ((byArea[site] * scale.length) / sites.length) | 0)]
      : colours[Math.min(last, sites[site][2])]

    let r = c[0]
    let g = c[1]
    let b = c[2]

    if (edges > 0) {
      // Antialiased by the same coverage arithmetic the rasteriser uses: full ink
      // up to the border's half-width, fading out over the last pixel of it.
      const coverage = Math.min(1, Math.max(0, edges - gap[i]))
      if (coverage > 0) {
        r += (ink[0] - r) * coverage
        g += (ink[1] - g) * coverage
        b += (ink[2] - b) * coverage
      }
    }

    data[p] = r
    data[p + 1] = g
    data[p + 2] = b
    data[p + 3] = 255
  }

  if (spec.dots) {
    // Drawn last and straight into the image: a site marker is three pixels wide
    // and antialiasing it would be more code than the whole feature.
    for (const [x, y] of sites) {
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          const px = x + dx
          const py = y + dy
          if (px < 0 || py < 0 || px >= width || py >= height) continue

          const p = (py * width + px) * 4
          data[p] = ink[0]
          data[p + 1] = ink[1]
          data[p + 2] = ink[2]
        }
      }
    }
  }

  ctx.putImageData(image, 0, 0)
}
