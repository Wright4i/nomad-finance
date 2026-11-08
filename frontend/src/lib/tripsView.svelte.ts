import {
  FORWARD_MONTHS_DEFAULT,
  HIDE_GLOBE_LOCATIONS_DEFAULT,
  HIDE_GLOBE_MILES_DEFAULT,
  HIDE_GLOBE_NAMES_DEFAULT,
  PAST_MONTHS_DEFAULT,
} from './pastMonths.ts';

/** Trips screen choices that should survive edit and cancel, until sidebar Trips opens again. */
export const tripsView = $state({
  pastMonths: PAST_MONTHS_DEFAULT,
  forwardMonths: FORWARD_MONTHS_DEFAULT,
  hideGlobeNames: HIDE_GLOBE_NAMES_DEFAULT,
  hideGlobeLocations: HIDE_GLOBE_LOCATIONS_DEFAULT,
  hideGlobeMiles: HIDE_GLOBE_MILES_DEFAULT,
  /** YYYY-MM from the stay calendar; seeds Add a stay arrival dates. */
  calendarMonth: new Date().toISOString().slice(0, 7),
});

export function resetTripsGlobeWindow(): void {
  tripsView.pastMonths = PAST_MONTHS_DEFAULT;
  tripsView.forwardMonths = FORWARD_MONTHS_DEFAULT;
  tripsView.hideGlobeNames = HIDE_GLOBE_NAMES_DEFAULT;
  tripsView.hideGlobeLocations = HIDE_GLOBE_LOCATIONS_DEFAULT;
  tripsView.hideGlobeMiles = HIDE_GLOBE_MILES_DEFAULT;
}
