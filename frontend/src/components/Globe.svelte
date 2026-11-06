<script lang="ts">
  import { onMount } from 'svelte';
  import { GeoJSONSource, Map, Marker, RasterTileSource, setWorkerUrl } from 'maplibre-gl';
  import maplibreWorker from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
  import 'maplibre-gl/dist/maplibre-gl.css';
  import {
    cartoAttribution,
    darkMatterTiles,
    positronTiles,
    satelliteAttribution,
    satelliteTiles,
  } from '../lib/carto';
  import type { LngLat } from '../lib/route';
  import { session } from '../lib/session.svelte.ts';

  setWorkerUrl(maplibreWorker);

  type Pin = {
    id: number;
    longitude: string;
    latitude: string;
    placeName?: string;
    stayNumber?: number;
    city?: string | null;
    region?: string | null;
  };
  type Leg = { miles: number; coordinates: [number, number][] };

  let {
    pins = [],
    legs = [],
    selectedId = null,
    marker = null,
    satellite = false,
    flat = false,
    zoom = null,
    quiet = false,
    compact = false,
    locateToken = 0,
    onSelect = () => {},
    onMarker = () => {},
  }: {
    pins?: Pin[];
    legs?: Leg[];
    selectedId?: number | null;
    marker?: { longitude: string; latitude: string; name?: string; address?: string | null } | null;
    satellite?: boolean;
    /** Flat mercator tiles. The trip screen stays a globe unless this is set. */
    flat?: boolean;
    /** Camera zoom. The planner map sits further out than the satellite pin. */
    zoom?: number | null;
    /** A picture of the place. Clicks pass through to the cell around the map. */
    quiet?: boolean;
    compact?: boolean;
    /** Bumps when Locate stay should fly to street level, even if that stay is already centered. */
    locateToken?: number;
    onSelect?: (id: number) => void;
    onMarker?: (latitude: string, longitude: string) => void;
  } = $props();

  let host: HTMLDivElement | undefined = $state();
  let map: Map | null = null;
  let pin: Marker | null = null;
  let pinnedAt = '';
  let stayPins: Marker[] = [];
  let legPins: Marker[] = [];
  let failed = $state(false);
  let seenLocate = 0;
  let flownKey = '';

  const pinZoom = 15;
  const routeZoom = 3.4;
  /** Further out than the satellite pin so the city and state stay in frame. */
  const cityZoom = 8;

  function viewZoom(): number {
    if (typeof zoom === 'number') return zoom;
    if (satellite) return pinZoom;
    if (flat) return cityZoom;
    return routeZoom;
  }

  function basemap(): { id: string; tiles: string[]; attribution: string; maxzoom: number } {
    if (satellite) {
      return { id: 'satellite', tiles: satelliteTiles, attribution: satelliteAttribution, maxzoom: 19 };
    }
    if (session.user?.theme === 'light') {
      return { id: 'positron', tiles: positronTiles, attribution: cartoAttribution, maxzoom: 20 };
    }
    return { id: 'dark-matter', tiles: darkMatterTiles, attribution: cartoAttribution, maxzoom: 20 };
  }

  function routePaint(): { outline: string; line: string } {
    if (!satellite && session.user?.theme === 'light') return { outline: '#134e4a', line: '#0f766e' };
    return { outline: '#d7fff6', line: '#3ee0c5' };
  }

  function applyBasemap(): void {
    const choice = basemap();
    if (host) {
      host.dataset.basemap = choice.id;
      host.dataset.projection = flat ? 'mercator' : 'globe';
    }
    const source = map?.getSource('osm');
    if (source instanceof RasterTileSource) source.setTiles(choice.tiles);
    const paint = routePaint();
    if (map?.getLayer('route-outline')) map.setPaintProperty('route-outline', 'line-color', paint.outline);
    if (map?.getLayer('route')) map.setPaintProperty('route', 'line-color', paint.line);
  }

  function reduced(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function rememberView(): void {
    if (!host || !map) return;
    const center = map.getCenter();
    host.dataset.zoom = map.getZoom().toFixed(2);
    host.dataset.longitude = center.lng.toFixed(4);
    host.dataset.latitude = center.lat.toFixed(4);
  }

  function fly(longitude: number, latitude: number, zoom = viewZoom()): void {
    if (!map) return;
    const camera = { center: [longitude, latitude] as LngLat, zoom };
    if (reduced()) map.jumpTo(camera);
    else map.flyTo({ ...camera, essential: true, duration: 400 });
  }

  function drawPins(): void {
    if (!map) return;
    for (const item of stayPins) item.remove();
    stayPins = [];
    for (const item of pins) {
      const lng = Number(item.longitude);
      const lat = Number(item.latitude);
      if (!Number.isFinite(lng) || !Number.isFinite(lat)) continue;
      const where = [item.city, item.region].filter((part) => part).join(', ');
      const title = item.placeName || 'Stay';
      const button = document.createElement(quiet ? 'span' : 'button');
      if (button instanceof HTMLButtonElement) button.type = 'button';
      button.className = item.id === selectedId ? 'map-pin selected' : 'map-pin';
      button.setAttribute('aria-label', where === '' ? title : `${title}, ${where}`);
      const dot = document.createElement('span');
      dot.className = 'map-pin-dot';
      const label = document.createElement('span');
      label.className = 'map-pin-label';
      const name = document.createElement('strong');
      name.textContent = title;
      label.append(name);
      if (where !== '') {
        const place = document.createElement('span');
        place.textContent = where;
        label.append(place);
      }
      button.append(dot, label);
      if (!quiet) {
        button.addEventListener('click', (event) => {
          event.stopPropagation();
          onSelect(item.id);
        });
      }
      stayPins.push(new Marker({ element: button, anchor: 'left', offset: [-7, 0] }).setLngLat([lng, lat]).addTo(map));
    }
  }

  function drawLegLabels(): void {
    if (!map) return;
    for (const item of legPins) item.remove();
    legPins = [];
    for (const leg of legs) {
      if (leg.coordinates.length < 2) continue;
      const at = leg.coordinates[Math.floor(leg.coordinates.length / 2)];
      const label = document.createElement('span');
      label.className = 'map-leg';
      label.textContent = `${leg.miles} mi`;
      legPins.push(new Marker({ element: label, anchor: 'center' }).setLngLat(at).addTo(map));
    }
  }

  function routeData(): {
    type: 'FeatureCollection';
    features: {
      type: 'Feature';
      properties: Record<string, never>;
      geometry: { type: 'LineString'; coordinates: LngLat[] };
    }[];
  } {
    const features = legs
      .filter((leg) => leg.coordinates.length >= 2)
      .map((leg) => ({
        type: 'Feature' as const,
        properties: {},
        geometry: { type: 'LineString' as const, coordinates: leg.coordinates },
      }));
    return { type: 'FeatureCollection', features };
  }

  function drawRoute(): void {
    if (!map || !map.getSource('route')) return;
    (map.getSource('route') as GeoJSONSource).setData(routeData());
  }

  function markerLabel(): HTMLDivElement {
    const root = document.createElement('div');
    root.className = 'map-pin selected';
    const dot = document.createElement('span');
    dot.className = 'map-pin-dot';
    const label = document.createElement('span');
    label.className = 'map-pin-label';
    const name = document.createElement('strong');
    const address = document.createElement('span');
    address.className = 'map-pin-address';
    label.append(name, address);
    root.append(dot, label);
    return root;
  }

  function paintMarker(): void {
    if (!pin) return;
    const root = pin.getElement();
    const name = root.querySelector('strong');
    const address = root.querySelector('.map-pin-address');
    const title = marker?.name?.trim() || 'Stay';
    const line = marker?.address?.trim() || '';
    if (name) name.textContent = title;
    if (address instanceof HTMLElement) {
      address.textContent = line;
      address.hidden = line === '';
    }
    root.setAttribute('aria-label', line === '' ? title : `${title}, ${line}`);
  }

  function placeMarker(): void {
    if (!map || !marker) return;
    const lng = Number(marker.longitude);
    const lat = Number(marker.latitude);
    if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;
    if (!pin) {
      pin = new Marker({ element: markerLabel(), anchor: 'left', offset: [-7, 0], draggable: true })
        .setLngLat([lng, lat])
        .addTo(map);
      pin.on('dragend', () => {
        const at = pin?.getLngLat();
        if (!at) return;
        onMarker(String(at.lat), String(at.lng));
      });
    } else {
      pin.setLngLat([lng, lat]);
    }
    paintMarker();
    const at = `${lng},${lat}`;
    if (pinnedAt === at) return;
    pinnedAt = at;
    fly(lng, lat);
  }

  onMount(() => {
    if (!host) return;
    try {
      const choice = basemap();
      const lng = marker ? Number(marker.longitude) : Number.NaN;
      const lat = marker ? Number(marker.latitude) : Number.NaN;
      const known = Number.isFinite(lng) && Number.isFinite(lat);
      const focus = pins.find((item) => item.id === selectedId) ?? pins[0];
      const flatLng = focus ? Number(focus.longitude) : Number.NaN;
      const flatLat = focus ? Number(focus.latitude) : Number.NaN;
      const flatKnown = flat && Number.isFinite(flatLng) && Number.isFinite(flatLat);
      if (host) host.dataset.projection = flat ? 'mercator' : 'globe';
      map = new Map({
        container: host,
        style: {
          version: 8,
          projection: { type: flat ? 'mercator' : 'globe' },
          sources: {
            osm: {
              type: 'raster',
              tiles: choice.tiles,
              tileSize: 256,
              attribution: choice.attribution,
              maxzoom: choice.maxzoom,
            },
          },
          layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
        },
        center: known ? [lng, lat] : flatKnown ? [flatLng, flatLat] : [0, 20],
        zoom: known && satellite ? pinZoom : flatKnown ? viewZoom() : 1.2,
        interactive: !quiet,
        attributionControl: !quiet,
      });
      rememberView();
      map.on('moveend', rememberView);
      map.on('load', () => {
        if (!map) return;
        const line = { 'line-cap': 'round' as const, 'line-join': 'round' as const };
        const paint = routePaint();
        map.addSource('route', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
        map.addLayer({
          id: 'route-outline',
          type: 'line',
          source: 'route',
          layout: line,
          paint: { 'line-color': paint.outline, 'line-width': 7 },
        });
        map.addLayer({
          id: 'route',
          type: 'line',
          source: 'route',
          layout: line,
          paint: { 'line-color': paint.line, 'line-width': 4 },
        });
        applyBasemap();
        drawPins();
        drawLegLabels();
        drawRoute();
        placeMarker();
        const selected = pins.find((item) => item.id === selectedId) ?? pins[0];
        if (selected && !marker && seenLocate === 0) fly(Number(selected.longitude), Number(selected.latitude));
      });
    } catch (error) {
      failed = true;
      if (host) host.dataset.mapError = error instanceof Error ? error.message : 'The map did not start.';
    }
    return () => {
      pin?.remove();
      for (const item of stayPins) item.remove();
      for (const item of legPins) item.remove();
      stayPins = [];
      legPins = [];
      map?.remove();
      map = null;
    };
  });

  $effect(() => {
    pins;
    legs;
    selectedId;
    quiet;
    const token = locateToken;
    if (map) {
      drawPins();
      drawLegLabels();
    }
    if (map?.getSource('route')) drawRoute();
    const selected = pins.find((item) => item.id === selectedId);
    if (!selected || marker || !map) return;
    const lng = Number(selected.longitude);
    const lat = Number(selected.latitude);
    if (!Number.isFinite(lng) || !Number.isFinite(lat)) return;
    const locating = token !== seenLocate;
    if (locating) {
      seenLocate = token;
      flownKey = `select:${selected.id}:${selected.longitude}:${selected.latitude}`;
      fly(lng, lat, pinZoom);
      return;
    }
    const key = `select:${selected.id}:${selected.longitude}:${selected.latitude}`;
    if (flownKey === key) return;
    flownKey = key;
    fly(lng, lat);
  });

  $effect(() => {
    marker;
    if (map) placeMarker();
  });

  $effect(() => {
    session.user?.theme;
    satellite;
    flat;
    host;
    applyBasemap();
  });
</script>

<div class="globe" class:planner-map={compact} bind:this={host}></div>
{#if failed}
  <p class="muted m-0">The globe did not start. Typed coordinates still save.</p>
{/if}
