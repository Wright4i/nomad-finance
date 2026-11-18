export function nestedStay<T extends { arriveOn: string, departOn: string }>(
  covering: T[],
  day: string,
): T | undefined {
  const inside = covering.filter((stay) => day > stay.arriveOn && day < stay.departOn)
  if (inside.length === 0) return undefined
  inside.sort(
    (a, b) => b.arriveOn.localeCompare(a.arriveOn) || a.departOn.localeCompare(b.departOn),
  )
  return inside[0]
}
