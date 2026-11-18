export function basemapId(theme: string | null | undefined, satellite = false): 'satellite' | 'positron' | 'voyager' {
  if (satellite) return 'satellite'
  return theme === 'light' ? 'voyager' : 'positron'
}
