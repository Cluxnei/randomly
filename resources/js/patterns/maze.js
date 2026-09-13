/**
 * Mazes: one to three panels, floors coloured by distance from the corner.
 *
 * The carving happened on the server — see MazeGenerator, which explains why —
 * so this file gets two bits per cell and does two things with them: a breadth
 * first sweep for the distance field, and a per-pixel wall test.
 *
 * The wall test is what makes this a pixel renderer rather than a path renderer.
 * Stroking every wall as a line would mean up to four thousand `lineTo` calls per
 * panel and a seam wherever two strokes meet at a corner; asking each pixel "am I
 * within half a wall of a wall?" is one pass, has no seams, and stays inside
 * createImageData, which is what lets the preview script render it with no canvas
 * at all.
 */
import { rampAt, shade, toRgb } from './colour.js'

const EAST = 1
const SOUTH = 2

/** Unpack the base64'd two-bits-per-cell encoding the server sent. */
function unpack (encoded, count) {
  const raw = atob(encoded)
  const cells = new Uint8Array(count)

  for (let i = 0; i < count; i++) {
    // Four cells to a byte, most significant pair first — the order the PHP
    // shifted them in.
    cells[i] = (raw.charCodeAt(i >> 2) >> (6 - 2 * (i & 3))) & 3
  }

  return cells
}

/**
 * Distance from cell 0, in cells, by breadth-first search.
 *
 * The maze is a tree, so there is exactly one route to every cell and this is
 * both the shortest path and the only path. Colouring by it is what turns the
 * three algorithms' biases into something you can see: randomised DFS produces
 * one long gradient snaking through the whole grid, while Kruskal and Wilson's
 * spread outwards from the corner in a rough contour map.
 */
function depths (cells, cols, rows) {
  const depth = new Int32Array(cols * rows).fill(-1)
  const queue = new Int32Array(cols * rows)

  depth[0] = 0
  queue[0] = 0

  let tail = 1

  for (let head = 0; head < tail; head++) {
    const i = queue[head]
    const x = i % cols
    const y = (i / cols) | 0
    const d = depth[i] + 1

    // A passage is stored once, on its west or north cell, so a move east or
    // south reads this cell's bits and a move west or north reads the
    // neighbour's.
    if ((cells[i] & EAST) && depth[i + 1] < 0) { depth[i + 1] = d; queue[tail++] = i + 1 }
    if ((cells[i] & SOUTH) && depth[i + cols] < 0) { depth[i + cols] = d; queue[tail++] = i + cols }
    if (x > 0 && (cells[i - 1] & EAST) && depth[i - 1] < 0) { depth[i - 1] = d; queue[tail++] = i - 1 }
    if (y > 0 && (cells[i - cols] & SOUTH) && depth[i - cols] < 0) { depth[i - cols] = d; queue[tail++] = i - cols }
  }

  let max = 0
  for (let i = 0; i < depth.length; i++) if (depth[i] > max) max = depth[i]

  return { depth, max }
}

/** Everything a panel needs precomputed, so the pixel loop only does arithmetic. */
function prepare (panel, x0, y0, panelWidth, panelHeight) {
  const { cols, rows } = panel
  const cells = unpack(panel.cells, cols * rows)

  return {
    cells,
    cols,
    rows,
    x0,
    y0,
    cw: panelWidth / cols,
    ch: panelHeight / rows,
    ...depths(cells, cols, rows),
  }
}

export function render (ctx, spec, rng) {
  const { width, height, panels, gutter } = spec

  const colours = toRgb(spec.palette)
  // The ink belongs to the palette rather than being a fixed near-black: a maze
  // drawn in #111 across a warm ramp reads as two pictures stacked, and the whole
  // point of a generated palette is that everything on screen came out of it.
  const ink = shade(colours[0], 0.35)
  const flat = spec.colouring !== 'depth'

  const panelWidth = (width - gutter * (panels.length + 1)) / panels.length
  const panelHeight = height - gutter * 2

  const prepared = panels.map((panel, i) => prepare(
    panel, gutter + i * (panelWidth + gutter), gutter, panelWidth, panelHeight,
  ))

  // Half the wall on either side of the boundary, so the gap between two cells
  // measures a full `wall` and a cell's own floor is symmetrical.
  const half = spec.wall / 2

  const image = ctx.createImageData(width, height)
  const data = image.data
  const rgb = [0, 0, 0]

  for (let y = 0, p = 0; y < height; y++) {
    for (let x = 0; x < width; x++, p += 4) {
      let r = ink[0]
      let g = ink[1]
      let b = ink[2]

      for (let n = 0; n < prepared.length; n++) {
        const panel = prepared[n]

        const fx = (x - panel.x0) / panel.cw
        const fy = (y - panel.y0) / panel.ch

        if (fx < 0 || fy < 0 || fx >= panel.cols || fy >= panel.rows) continue

        const cx = fx | 0
        const cy = fy | 0
        const ux = fx - cx
        const uy = fy - cy
        const i = cy * panel.cols + cx
        const cell = panel.cells[i]

        // A passage on the far side of a boundary is recorded on the other cell,
        // so west reads cx−1 and north reads cy−1. The two special cases are the
        // entrance and the exit, which are holes in the outer wall rather than
        // passages in the tree — kept here rather than in the encoding so the
        // server's data stays exactly a spanning tree.
        const openWest = cx > 0 ? (panel.cells[i - 1] & EAST) !== 0 : (cy === 0)
        const openEast = cx + 1 < panel.cols
          ? (cell & EAST) !== 0
          : (cy === panel.rows - 1)
        const openNorth = cy > 0 ? (panel.cells[i - panel.cols] & SOUTH) !== 0 : false
        const openSouth = cy + 1 < panel.rows ? (cell & SOUTH) !== 0 : false

        const nearWest = ux < half
        const nearEast = ux > 1 - half
        const nearNorth = uy < half
        const nearSouth = uy > 1 - half

        const wall =
          (nearWest && !openWest) ||
          (nearEast && !openEast) ||
          (nearNorth && !openNorth) ||
          (nearSouth && !openSouth) ||
          // The corner post. Without it, a cell open on all four sides would show
          // a small hole where four walls should meet, and the grid stops reading
          // as a maze.
          ((nearWest || nearEast) && (nearNorth || nearSouth))

        if (!wall) {
          const t = flat || panel.max === 0 ? 0.72 : panel.depth[i] / panel.max
          // The floor never uses the darkest stop: that end of the ramp is where
          // the ink lives, and a cell at depth 0 would otherwise be invisible
          // against the wall beside it.
          rampAt(colours, 0.18 + 0.82 * t, rgb)
          r = rgb[0]; g = rgb[1]; b = rgb[2]
        }

        break
      }

      data[p] = r
      data[p + 1] = g
      data[p + 2] = b
      data[p + 3] = 255
    }
  }

  ctx.putImageData(image, 0, 0)
}
