/** Local CARTO basemap key. This register stays on this machine. */
export const cartoKey = 'cb1_52gm_1_277dc3375cc94923451ce2db';

const hosts = ['a', 'b', 'c', 'd'];

function carto(style: string): string[] {
  return hosts.map(
    (host) =>
      `https://${host}.basemaps.cartocdn.com/rastertiles/${style}/{z}/{x}/{y}.png?key=${cartoKey}`,
  );
}

export const darkMatterTiles = carto('dark_all');
export const positronTiles = carto('light_all');
export const cartoAttribution = '© OpenStreetMap © CARTO';

/** Esri World Imagery. The path order is z/y/x. */
export const satelliteTiles = [
  'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
];
export const satelliteAttribution = '© Esri, Maxar, Earthstar Geographics';
