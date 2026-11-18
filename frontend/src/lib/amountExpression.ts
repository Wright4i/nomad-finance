
type Rational = { n: bigint, d: bigint }

type Tok =
  | { k: 'n', n: bigint, d: bigint }
  | { k: 'o', o: '+' | '-' | '*' | '/' }
  | { k: 'l' }
  | { k: 'r' }

function gcd(a: bigint, b: bigint): bigint {
  let x = a < 0n ? -a : a
  let y = b < 0n ? -b : b
  while (y !== 0n) {
    const next = x % y
    x = y
    y = next
  }
  return x
}

function norm(n: bigint, d: bigint): Rational | null {
  if (d === 0n) return null
  if (d < 0n) {
    n = -n
    d = -d
  }
  const g = gcd(n, d)
  return { n: n / g, d: d / g }
}

function add(a: Rational, b: Rational): Rational | null {
  return norm(a.n * b.d + b.n * a.d, a.d * b.d)
}

function sub(a: Rational, b: Rational): Rational | null {
  return norm(a.n * b.d - b.n * a.d, a.d * b.d)
}

function mul(a: Rational, b: Rational): Rational | null {
  return norm(a.n * b.n, a.d * b.d)
}

function div(a: Rational, b: Rational): Rational | null {
  if (b.n === 0n) return null
  return norm(a.n * b.d, a.d * b.n)
}

function tokenize(input: string, exponent: number): Tok[] | null {
  const tokens: Tok[] = []
  let i = 0
  while (i < input.length) {
    const ch = input[i]
    if (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r') {
      i += 1
      continue
    }
    if (ch >= '0' && ch <= '9') {
      let whole = ''
      while (i < input.length && input[i] >= '0' && input[i] <= '9') {
        whole += input[i]
        i += 1
      }
      let frac = ''
      if (i < input.length && input[i] === '.') {
        i += 1
        const start = i
        while (i < input.length && input[i] >= '0' && input[i] <= '9') {
          frac += input[i]
          i += 1
        }
        if (i === start) return null
      }
      if (frac.length > exponent) return null
      const digits = `${whole}${frac}`.replace(/^0+(?=\d)/, '')
      const parsed = norm(BigInt(digits === '' ? '0' : digits), 10n ** BigInt(frac.length))
      if (parsed === null) return null
      tokens.push({ k: 'n', n: parsed.n, d: parsed.d })
      continue
    }
    if (ch === '+' || ch === '-' || ch === '*' || ch === '/') {
      tokens.push({ k: 'o', o: ch })
      i += 1
      continue
    }
    if (ch === '(') {
      tokens.push({ k: 'l' })
      i += 1
      continue
    }
    if (ch === ')') {
      tokens.push({ k: 'r' })
      i += 1
      continue
    }
    return null
  }
  return tokens
}

export function evaluateAmount(input: string, exponent: number): Rational | null {
  if (!Number.isInteger(exponent) || exponent < 0 || exponent > 18) return null
  const tokens = tokenize(input.trim(), exponent)
  if (tokens === null || tokens.length === 0) return null
  let i = 0

  const parseExpr = (): Rational | null => {
    let left = parseTerm()
    if (left === null) return null
    while (i < tokens.length) {
      const tok = tokens[i]
      if (tok.k !== 'o' || (tok.o !== '+' && tok.o !== '-')) break
      i += 1
      const right = parseTerm()
      if (right === null) return null
      const next = tok.o === '+' ? add(left, right) : sub(left, right)
      if (next === null) return null
      left = next
    }
    return left
  }

  const parseTerm = (): Rational | null => {
    let left = parseUnary()
    if (left === null) return null
    while (i < tokens.length) {
      const tok = tokens[i]
      if (tok.k !== 'o' || (tok.o !== '*' && tok.o !== '/')) break
      i += 1
      const right = parseUnary()
      if (right === null) return null
      const next = tok.o === '*' ? mul(left, right) : div(left, right)
      if (next === null) return null
      left = next
    }
    return left
  }

  const parseUnary = (): Rational | null => {
    const tok = tokens[i]
    if (tok?.k === 'o' && tok.o === '-') {
      i += 1
      const value = parseUnary()
      if (value === null) return null
      return norm(-value.n, value.d)
    }
    return parsePrimary()
  }

  const parsePrimary = (): Rational | null => {
    const tok = tokens[i]
    if (tok?.k === 'n') {
      i += 1
      return { n: tok.n, d: tok.d }
    }
    if (tok?.k === 'l') {
      i += 1
      const value = parseExpr()
      if (value === null) return null
      if (tokens[i]?.k !== 'r') return null
      i += 1
      return value
    }
    return null
  }

  const value = parseExpr()
  if (value === null || i !== tokens.length) return null
  return value
}

export function rationalToMinor(value: Rational, exponent: number): number | null {
  if (!Number.isInteger(exponent) || exponent < 0 || exponent > 18) return null
  if (value.d <= 0n) return null
  const negative = value.n < 0n
  const abs = negative ? -value.n : value.n
  const scale = 10n ** BigInt(exponent)
  const scaled = abs * scale
  let quot = scaled / value.d
  const rem = scaled % value.d
  if (rem * 2n >= value.d) quot += 1n
  if (quot > BigInt(Number.MAX_SAFE_INTEGER)) return null
  const minor = Number(quot)
  return negative ? -minor : minor
}

function plainMinor(trimmed: string, exponent: number): number | null {
  const [whole, frac = ''] = trimmed.split('.')
  if (frac.length > exponent) return null
  const padded = frac.padEnd(exponent, '0')
  const minor = Number(whole) * 10 ** exponent + (padded === '' ? 0 : Number(padded))
  return Number.isSafeInteger(minor) ? minor : null
}

export function signedAmountMinor(input: string, exponent: number): number | null {
  const trimmed = input.trim()
  if (trimmed === '') return null
  if (/^\d+(\.\d+)?$/.test(trimmed)) return plainMinor(trimmed, exponent)
  const value = evaluateAmount(trimmed, exponent)
  if (value === null) return null
  return rationalToMinor(value, exponent)
}

export function parseAmountMinor(input: string, exponent: number): number | null {
  const minor = signedAmountMinor(input, exponent)
  if (minor === null || minor < 0) return null
  return minor
}

export function settledAmount(input: string, exponent: number): string | null {
  const trimmed = input.trim()
  if (trimmed === '' || /^\d+(\.\d+)?$/.test(trimmed)) return null
  const minor = parseAmountMinor(trimmed, exponent)
  if (minor === null) return null
  return formatAmountInput(minor, exponent)
}

export function formatAmountInput(minor: number, exponent: number): string {
  const abs = Math.abs(minor)
  const scale = 10 ** exponent
  const whole = Math.trunc(abs / scale)
  const sign = minor < 0 ? '-' : ''
  if (exponent === 0) return `${sign}${whole}`
  const frac = String(abs % scale).padStart(exponent, '0')
  return `${sign}${whole}.${frac}`
}
