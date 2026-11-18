const cartoKey = (import.meta.env.VITE_CARTO_KEY ?? '').trim()

const hosts = ['a', 'b', 'c', 'd']

function carto(style: string): string[] {
  const query = cartoKey ? `?key=${encodeURIComponent(cartoKey)}` : ''
  return hosts.map(
    (host) =>
      `https://${host}.basemaps.cartocdn.com/rastertiles/${style}/{z}/{x}/{y}.png${query}`,
  )
}

export const darkMatterTiles = carto('dark_all')
export const positronTiles = carto('light_all')
export const voyagerTiles = carto('voyager')
export const cartoAttribution = '© OpenStreetMap © CARTO'

export const satelliteTiles = [
  'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
]
export const satelliteAttribution = '© Esri, Maxar, Earthstar Geographics'
