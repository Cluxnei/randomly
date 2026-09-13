/**
 * The browser half of the random number generator.
 *
 * This is a deliberate twin of app/Random/Rng/{HkdfStream,Rng}.php. Pattern and
 * image generators ship a 32-byte render key instead of pixels, and the canvas
 * re-derives the same byte stream here — so a drawing made in the browser is
 * provably the same drawing the server would have made. That claim is only worth
 * making if the two implementations agree byte for byte, which is what
 * `npm run check:rng` verifies against PHP.
 *
 * Expansion follows HKDF-Expand (RFC 5869) with one documented deviation, matching
 * the PHP side: a 32-bit little-endian counter instead of 8 bits, because the
 * RFC's 255-block cap is 8160 bytes and a noise field burns that immediately.
 *
 *     T(0) = ""
 *     T(i) = HMAC-SHA256(PRK, T(i-1) ‖ info ‖ LE32(i))
 */

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** Crockford base32, forgiving the aliases a human introduces when retyping a token. */
export function decodeToken (token) {
  const clean = token.trim().toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1').replace(/-/g, '')

  let bits = ''
  for (const char of clean) {
    const index = CROCKFORD.indexOf(char)
    if (index === -1) throw new Error(`Not a valid token character: ${char}`)
    bits += index.toString(2).padStart(5, '0')
  }

  const bytes = new Uint8Array(Math.floor(bits.length / 8))
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = parseInt(bits.slice(i * 8, i * 8 + 8), 2)
  }

  return bytes
}

export function hexToBytes (hex) {
  const bytes = new Uint8Array(hex.length / 2)
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16)
  }
  return bytes
}

const encoder = new TextEncoder()

/**
 * Fill a buffer of deterministic bytes.
 *
 * WebCrypto is async, so this happens once during setup and the Rng that reads it
 * is fully synchronous — a render loop cannot await per pixel. Ask for more than
 * you need; 64 KB covers any generator we have, since procedural noise derives
 * everything from a small permutation table rather than drawing per pixel.
 */
export async function deriveBytes (prk, info, length) {
  const key = await crypto.subtle.importKey('raw', prk, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const infoBytes = encoder.encode(info)

  const out = new Uint8Array(length)
  let block = new Uint8Array(0)
  let counter = 0
  let offset = 0

  while (offset < length) {
    counter++

    const input = new Uint8Array(block.length + infoBytes.length + 4)
    input.set(block, 0)
    input.set(infoBytes, block.length)
    // Little-endian, matching pack('V', $counter) in PHP.
    new DataView(input.buffer).setUint32(block.length + infoBytes.length, counter, true)

    block = new Uint8Array(await crypto.subtle.sign('HMAC', key, input))
    out.set(block.subarray(0, Math.min(block.length, length - offset)), offset)
    offset += block.length
  }

  return out
}

/**
 * The same primitives as App\Random\Rng\Rng, drawing from a pre-filled buffer.
 *
 * Running out of bytes throws rather than wrapping around: silently recycling the
 * stream would make the output non-reproducible in a way nobody would notice.
 */
export class Rng {
  constructor (bytes) {
    this.bytes = bytes
    this.offset = 0
    this.rejections = 0
  }

  static async from (keyHex, info = 'randomly/render', length = 65536) {
    return new Rng(await deriveBytes(hexToBytes(keyHex), info, length))
  }

  read (n) {
    if (this.offset + n > this.bytes.length) {
      throw new RangeError(`Stream exhausted: wanted ${n} more bytes of ${this.bytes.length}.`)
    }
    const slice = this.bytes.subarray(this.offset, this.offset + n)
    this.offset += n
    return slice
  }

  uint32 () {
    const b = this.read(4)
    // >>> 0 keeps it unsigned; JS bitwise operators work on signed 32-bit ints.
    return ((b[0] << 24) | (b[1] << 16) | (b[2] << 8) | b[3]) >>> 0
  }

  /**
   * A uniform double in [0, 1) with a full 53-bit mantissa.
   *
   * Reads 7 bytes and drops 3 bits, exactly as the PHP does — 56 bits is the most
   * that fits in a JS number without losing integer precision anyway.
   */
  float () {
    const b = this.read(7)
    let v = 0
    for (let i = 0; i < 7; i++) v = v * 256 + b[i]
    return Math.floor(v / 8) * (2 ** -53)
  }

  /** Bitmask rejection — the same unbiased path as PHP's, rejections included. */
  intBetween (lo, hi) {
    if (lo > hi) throw new RangeError(`Empty range: [${lo}, ${hi}].`)
    if (lo === hi) return lo

    const range = hi - lo
    let bits = 0
    for (let r = range; r > 0; r >>>= 1) bits++
    const bytes = Math.ceil(bits / 8)
    const mask = bits === 32 ? 0xffffffff : (1 << bits) - 1

    for (;;) {
      const b = this.read(bytes)
      let v = 0
      for (let i = 0; i < bytes; i++) v = ((v << 8) | b[i]) >>> 0
      v = (v & mask) >>> 0

      if (v <= range) return lo + v
      this.rejections++
    }
  }

  bool (p = 0.5) {
    return this.float() < p
  }

  pick (items) {
    return items[this.intBetween(0, items.length - 1)]
  }

  /** Fisher-Yates (Durstenfeld), identical ordering to the PHP implementation. */
  shuffle (items) {
    const a = [...items]
    for (let i = a.length - 1; i > 0; i--) {
      const j = this.intBetween(0, i)
      ;[a[i], a[j]] = [a[j], a[i]]
    }
    return a
  }

  gaussian (mu = 0, sigma = 1) {
    if (this.spare !== undefined) {
      const z = this.spare
      this.spare = undefined
      return mu + sigma * z
    }

    const u1 = 1 - this.float()
    const u2 = this.float()
    const r = Math.sqrt(-2 * Math.log(u1))

    this.spare = r * Math.sin(2 * Math.PI * u2)
    return mu + sigma * r * Math.cos(2 * Math.PI * u2)
  }

  /** A 256-entry permutation table, doubled — the standard Perlin/Simplex setup. */
  permutation () {
    const p = this.shuffle([...Array(256).keys()])
    return Uint8Array.from([...p, ...p])
  }
}
