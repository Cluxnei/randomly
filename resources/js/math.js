/**
 * The Equations module's browser half.
 *
 * KaTeX rather than MathJax, and the reason is the worksheet: a page of thirty
 * problems is sixty expressions, and at MathJax's ~50ms each that is three
 * seconds of blocked main thread before a teacher can print. KaTeX renders the
 * same page in well under a tenth of that.
 *
 * Bundled through npm rather than pulled from a CDN. The fonts are the actual
 * argument — KaTeX ships its own faces, and a CDN link means the page is
 * unreadable until a third party answers, on a site whose whole claim is that
 * you can verify where everything came from.
 *
 * Two entry points, because there are two consumers: the studio, which re-renders
 * whenever a new seed arrives, and the worksheet, which is static HTML and just
 * needs every marked element turned into maths once.
 */
import Alpine from 'alpinejs'
import katex from 'katex'
import 'katex/dist/katex.min.css'

const OPTIONS = {
  // A generated expression must never be able to take the page down. An
  // unparseable string renders as itself in red, which is visible, local, and
  // debuggable — three things a thrown exception in a render loop is not.
  throwOnError: false,
  strict: 'ignore',
  output: 'html',
}

/** Render TeX into an element, falling back to the source text if KaTeX refuses. */
export function renderTex (element, tex, displayMode = false) {
  try {
    katex.render(String(tex), element, { ...OPTIONS, displayMode })
  } catch {
    element.textContent = String(tex)
  }

  return element
}

function element (tag, className, text = null) {
  const node = document.createElement(tag)
  node.className = className

  if (text !== null) node.textContent = text

  return node
}

/**
 * Turn every `data-tex` element on the page into rendered maths.
 *
 * Used by the worksheet, which is server-rendered HTML with no Alpine component
 * around it — the print view has to work as a plain document.
 */
export function renderDocument (root = document) {
  root.querySelectorAll('[data-tex]').forEach((node) => {
    renderTex(node, node.getAttribute('data-tex'), node.hasAttribute('data-tex-display'))
  })
}

/**
 * The studio's result area for `renderer: math`.
 *
 * Driven by `x-effect="render(meta)"` rather than by reading the parent's state
 * directly, so a new generation redraws for the same reason a canvas does: the
 * payload changed. `meta.latex` is a list of `[problem, answer]` pairs — the
 * only channel the API response has for anything but a plain string, which is
 * why it travels there rather than in `value`.
 */
Alpine.data('mathResult', () => ({
  revealed: false,
  rows: 0,

  render (meta) {
    const pairs = Array.isArray(meta?.latex) ? meta.latex : []
    const container = this.$refs.problems

    if (!container) return

    this.rows = pairs.length
    container.replaceChildren(...pairs.map((pair, index) => this.problem(pair, index, pairs.length)))
  },

  problem ([prompt, answer], index, total) {
    const row = element('div', 'flex items-baseline gap-4 py-2 text-left')

    if (total > 1) {
      row.append(element(
        'span',
        'w-6 shrink-0 pt-1 text-right font-mono text-[0.7rem] text-muted num',
        `${index + 1}.`
      ))
    }

    const body = element('div', 'min-w-0 flex-1 overflow-x-auto')
    body.append(renderTex(element('div', 'text-text'), prompt, true))

    // The answer is present in the DOM from the start and only hidden, so
    // revealing it is instant and so that it is still there for a reader who
    // prints the page or runs a screen reader over it.
    const key = element('div', 'mt-1 font-mono text-sm text-signal')
    key.append(renderTex(element('span', ''), answer, false))
    key.hidden = !this.revealed
    key.dataset.answer = ''

    body.append(key)
    row.append(body)

    return row
  },

  toggle () {
    this.revealed = !this.revealed

    this.$refs.problems?.querySelectorAll('[data-answer]').forEach((node) => {
      node.hidden = !this.revealed
    })
  },
}))

// The worksheet has no Alpine component around it, so the document scan runs on
// its own. Both branches are needed: as a module script this usually executes
// after parsing, but not when the bundle is already cached and inlined.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => renderDocument())
} else {
  renderDocument()
}
