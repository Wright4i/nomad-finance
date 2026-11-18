export type LngLat = [number, number]

export function arc(points: LngLat[]): LngLat[] {
  const line: LngLat[] = []
  let i = 0
  while (i < points.length) {
    const current = points[i]
    if (i === 0) {
      line.push(current)
      i += 1
      continue
    }
    const previous = points[i - 1]
    let step = 1
    while (step <= 24) {
      line.push(interpolate(previous, current, step / 24))
      step += 1
    }
    i += 1
  }
  return line
}

export function splitDateLine(points: LngLat[]): LngLat[][] {
  if (points.length === 0) return []
  const segments: LngLat[][] = [[points[0]]]
  let i = 1
  while (i < points.length) {
    const current = points[i]
    i += 1
    const segment = segments[segments.length - 1]
    const previous = segment[segment.length - 1]
    const jump = current[0] - previous[0]
    if (Math.abs(jump) <= 180) {
      segment.push(current)
      continue
    }
    const delta = jump > 0 ? jump - 360 : jump + 360
    const boundary = delta > 0 ? 180 : -180
    const span = delta
    const t = span === 0 ? 0 : Math.min(1, Math.max(0, (boundary - previous[0]) / span))
    const latitude = previous[1] + t * (current[1] - previous[1])
    segment.push([boundary, latitude])
    segments.push([[boundary === 180 ? -180 : 180, latitude], current])
  }
  return segments
}

function interpolate(start: LngLat, end: LngLat, fraction: number): LngLat {
  const lat1 = (start[1] * Math.PI) / 180
  const lon1 = (start[0] * Math.PI) / 180
  const lat2 = (end[1] * Math.PI) / 180
  const lon2 = (end[0] * Math.PI) / 180
  const delta = 2 * Math.asin(Math.sqrt(
    Math.sin((lat2 - lat1) / 2) ** 2
    + Math.cos(lat1) * Math.cos(lat2) * Math.sin((lon2 - lon1) / 2) ** 2,
  ))
  if (delta === 0) {
    return end
  }
  const a = Math.sin((1 - fraction) * delta) / Math.sin(delta)
  const b = Math.sin(fraction * delta) / Math.sin(delta)
  const x = a * Math.cos(lat1) * Math.cos(lon1) + b * Math.cos(lat2) * Math.cos(lon2)
  const y = a * Math.cos(lat1) * Math.sin(lon1) + b * Math.cos(lat2) * Math.sin(lon2)
  const z = a * Math.sin(lat1) + b * Math.sin(lat2)
  return [
    (Math.atan2(y, x) * 180) / Math.PI,
    (Math.atan2(z, Math.sqrt(x * x + y * y)) * 180) / Math.PI,
  ]
}
