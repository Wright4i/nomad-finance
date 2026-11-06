import { arc, splitDateLine, type LngLat } from './route.ts';

export type LegPaint = {
  dashed: boolean;
  /** Null hides the miles label. A road leg keeps its miles. */
  miles: number | null;
  airplane: boolean;
  /** Short-path samples. The airplane uses the middle of this list. */
  coordinates: LngLat[];
  /** Dashed pieces. A date-line crossing is more than one piece so the map does not wrap. */
  segments: LngLat[][];
};

/**
 * A road leg stays a solid line labeled in miles.
 * A leg with no road route is a dashed arc, with no miles and an airplane at the middle.
 */
export function paintLeg(
  leg: { driving: boolean; miles: number; coordinates: LngLat[] },
  from: LngLat | null,
  to: LngLat | null,
): LegPaint {
  if (leg.driving && leg.coordinates.length >= 2) {
    return { dashed: false, miles: leg.miles, airplane: false, coordinates: leg.coordinates, segments: [] };
  }
  const start = from ?? leg.coordinates[0] ?? null;
  const end = to ?? (leg.coordinates.length > 0 ? leg.coordinates[leg.coordinates.length - 1] : null);
  if (!start || !end) {
    return { dashed: true, miles: null, airplane: false, coordinates: [], segments: [] };
  }
  const coordinates = arc([start, end]);
  return { dashed: true, miles: null, airplane: true, coordinates, segments: splitDateLine(coordinates) };
}
