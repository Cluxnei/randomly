/**
 * Prints a deterministic byte vector from the JS RNG, for comparison against PHP.
 *
 * Used by tests/Unit/RngParityTest.php. The two implementations claiming to be
 * the same stream is a load-bearing promise — the site tells visitors that a
 * canvas drawn in their browser is the same draw the server would have made — so
 * it gets checked rather than asserted.
 *
 *   node scripts/rng-vector.mjs <prkHex> <info> <length>
 */
import { deriveBytes, Rng, hexToBytes, decodeToken } from '../resources/js/rng.js'

const [prkHex, info, length] = process.argv.slice(2)

const bytes = await deriveBytes(hexToBytes(prkHex), info, Number(length))
const rng = new Rng(bytes)

const ints = []
for (let i = 0; i < 20; i++) ints.push(rng.intBetween(1, 100))

const floats = []
for (let i = 0; i < 5; i++) floats.push(rng.float().toFixed(15))

console.log(JSON.stringify({
  stream: Buffer.from(bytes).toString('hex'),
  ints,
  rejections: rng.rejections,
  floats,
  shuffle: new Rng(bytes).shuffle([...Array(16).keys()]),
  token: Buffer.from(decodeToken('QGZPPD2MX955XFVBX6GC7KWX00')).toString('hex'),
}))
