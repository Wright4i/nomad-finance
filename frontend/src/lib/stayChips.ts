import type { Trip } from './types'

export function stayLocality(trip: Pick<Trip, 'city' | 'region'>): string {
  return [trip.city, trip.region].filter((part) => typeof part === 'string' && part.trim() !== '').join(', ')
}

export function showStayBalanceDue(trip: Pick<Trip, 'amountDueMinor' | 'balanceDueOn'>): boolean {
  return trip.amountDueMinor > 0 && typeof trip.balanceDueOn === 'string' && trip.balanceDueOn !== ''
}

export function showStayFuelCost(trip: Pick<Trip, 'fuel'>): boolean {
  const cost = trip.fuel?.costMinor
  return typeof cost === 'number' && cost > 0
}

export function showStayFlight(trip: Pick<Trip, 'travel'>): boolean {
  return trip.travel === 'fly'
}
