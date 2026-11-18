export const vehicleKinds = [
  'car',
  'truck',
  'motorcycle',
  'rv',
  'semi',
  'utv',
  'atv',
  'golf cart',
  'dirt bike',
] as const

export type VehicleKind = (typeof vehicleKinds)[number]

export function vehicleTone(kind: string): string {
  return kind.replaceAll(' ', '-')
}
