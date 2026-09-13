/**
 * Parse a batch of LaTeX with the same KaTeX the browser loads, and report what
 * it refused.
 *
 * No amount of PHP testing can see this failure. A malformed expression renders
 * as red error text on the page, the suite stays green, the API returns 200, and
 * only somebody looking at a worksheet finds out — which is exactly how
 * `(e^a)^2` shipped as `e^{a}^{2}`, a double superscript and a TeX syntax error
 * that no precedence rule on the PHP side could have predicted.
 *
 * Reads a JSON array of strings on stdin. Used by tests/Unit/EquationsTest.php.
 */
import katex from 'katex'

const chunks = []

for await (const chunk of process.stdin) chunks.push(chunk)

const failures = []

for (const tex of JSON.parse(Buffer.concat(chunks).toString('utf8'))) {
  try {
    katex.renderToString(tex, { throwOnError: true, strict: 'ignore' })
  } catch (error) {
    failures.push({ tex, message: error.message })
  }
}

console.log(JSON.stringify(failures))
