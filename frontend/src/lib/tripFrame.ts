export const worldZoom = 3.4
export const locateZoom = 15
export const plannerZoom = 10

export const globeFlyMs = 1000

export function stayZoom(): number {
  return (worldZoom + locateZoom) / 2
}

export type MapPoint = { longitude: number, latitude: number }

export function tripFrame(points: MapPoint[]): MapPoint | null {
  const placed = points.filter((point) => Number.isFinite(point.longitude) && Number.isFinite(point.latitude))
  if (placed.length === 0) return null
  let x = 0
  let y = 0
  let latitude = 0
  for (const point of placed) {
    const radians = (point.longitude * Math.PI) / 180
    x += Math.cos(radians)
    y += Math.sin(radians)
    latitude += point.latitude
  }
  return {
    longitude: (Math.atan2(y, x) * 180) / Math.PI,
    latitude: latitude / placed.length,
  }
}
