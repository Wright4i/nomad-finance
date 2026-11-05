/** [longitude, latitude] */
export type LngLat = [number, number];

export function arc(points: LngLat[]): LngLat[] {
  const line: LngLat[] = [];
  for (let i = 0; i < points.length; i++) {
    const current = points[i];
    if (i === 0) {
      line.push(current);
      continue;
    }
    const previous = points[i - 1];
    for (let step = 1; step <= 24; step++) {
      line.push(interpolate(previous, current, step / 24));
    }
  }
  return line;
}

function interpolate(start: LngLat, end: LngLat, fraction: number): LngLat {
  const lat1 = (start[1] * Math.PI) / 180;
  const lon1 = (start[0] * Math.PI) / 180;
  const lat2 = (end[1] * Math.PI) / 180;
  const lon2 = (end[0] * Math.PI) / 180;
  const delta = 2 * Math.asin(Math.sqrt(
    Math.sin((lat2 - lat1) / 2) ** 2
    + Math.cos(lat1) * Math.cos(lat2) * Math.sin((lon2 - lon1) / 2) ** 2,
  ));
  if (delta === 0) {
    return end;
  }
  const a = Math.sin((1 - fraction) * delta) / Math.sin(delta);
  const b = Math.sin(fraction * delta) / Math.sin(delta);
  const x = a * Math.cos(lat1) * Math.cos(lon1) + b * Math.cos(lat2) * Math.cos(lon2);
  const y = a * Math.cos(lat1) * Math.sin(lon1) + b * Math.cos(lat2) * Math.sin(lon2);
  const z = a * Math.sin(lat1) + b * Math.sin(lat2);
  return [
    (Math.atan2(y, x) * 180) / Math.PI,
    (Math.atan2(z, Math.sqrt(x * x + y * y)) * 180) / Math.PI,
  ];
}
