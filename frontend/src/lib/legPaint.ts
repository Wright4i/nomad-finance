import { arc, splitDateLine, type LngLat } from './route.ts'

export type LegPaint = {
  dashed: boolean
  miles: number | null
  airplane: boolean
  coordinates: LngLat[]
  segments: LngLat[][]
}

export function paintLeg(
  leg: { driving: boolean, miles: number, coordinates: LngLat[], travel?: string },
  from: LngLat | null,
  to: LngLat | null,
): LegPaint {
  const flight = leg.travel === 'fly'
  if (!flight && leg.driving && leg.coordinates.length >= 2) {
    return { dashed: false, miles: leg.miles, airplane: false, coordinates: leg.coordinates, segments: [] }
  }
  const start = from ?? leg.coordinates[0] ?? null
  const end = to ?? (leg.coordinates.length > 0 ? leg.coordinates[leg.coordinates.length - 1] : null)
  if (!start || !end) {
    return { dashed: true, miles: null, airplane: false, coordinates: [], segments: [] }
  }
  const coordinates = arc([start, end])
  return { dashed: true, miles: null, airplane: flight, coordinates, segments: splitDateLine(coordinates) }
}
