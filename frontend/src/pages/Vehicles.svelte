<script lang="ts">
  import Note from '../components/Note.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { onMount } from 'svelte';
  import { api, explain } from '../lib/api';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Vehicle } from '../lib/types';
  import { vehicleTone } from '../lib/vehicleKinds';

  let vehicles = $state<Vehicle[]>([]);
  let message = $state('');
  let hasStar = $derived(vehicles.some((vehicle) => vehicle.starred));

  onMount(() => {
    api<{ vehicles: Vehicle[] }>('/api/vehicles').then((data) => {
      vehicles = data.vehicles;
    }).catch((err: unknown) => {
      message = explain(err).message;
    });
  });

  function odometerChip(miles: number): string {
    return `${miles.toLocaleString('en-US')} mi`;
  }

  function mpgChip(vehicle: Vehicle): string {
    return vehicle.milesPerGallon ? `${vehicle.milesPerGallon} mpg` : '—';
  }

  async function star(vehicle: Vehicle): Promise<void> {
    message = '';
    try {
      await api(`/api/vehicles/${vehicle.id}`, {
        method: 'PATCH',
        body: {
          name: vehicle.name,
          kind: vehicle.kind,
          mpgMinTenths: vehicle.mpgMinTenths,
          mpgMaxTenths: vehicle.mpgMaxTenths,
          starred: !vehicle.starred,
          currentOdometerMiles: vehicle.currentOdometerMiles,
          fuelPriceMinor: vehicle.fuelPriceMinor,
        },
      });
      const data = await api<{ vehicles: Vehicle[] }>('/api/vehicles');
      vehicles = data.vehicles;
    } catch (err) {
      message = explain(err).message;
    }
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Vehicles</h1>
    <a class="btn primary" href="/vehicles/new" onclick={(event) => follow(event, '/vehicles/new')}>New vehicle</a>
  </div>
  <Note bind:message />
  {#if vehicles.length === 0}
    <p class="muted m-0">No vehicles yet.</p>
  {/if}
  {#if vehicles.length > 0}
    <div class="vehicle-columns">
      {#each vehicles as vehicle (vehicle.id)}
        <article class="card stripe grid gap-3" style={`--tone: var(--tone-${vehicleTone(vehicle.kind)})`}>
          <div class="pay-card-head">
            <a class="flex items-center gap-3 min-w-0 no-underline" href={`/vehicles/${vehicle.id}`} onclick={(event) => follow(event, `/vehicles/${vehicle.id}`)}>
              <Glyph name={vehicle.kind} />
              <span>
                <strong>{vehicle.name}</strong>
                <span class="block muted text-sm">{vehicle.kind}</span>
              </span>
            </a>
            {#if vehicle.starred || !hasStar}
              <button class="btn" type="button" aria-pressed={vehicle.starred} aria-label={vehicle.starred ? `${vehicle.name} is the default` : `Star ${vehicle.name}`} onclick={() => star(vehicle)}>
                {vehicle.starred ? '★' : '☆'}
              </button>
            {/if}
          </div>
          <div class="pay-chips">
            <span class="pay-chip pay-chip-roll" data-chip="odometer">{odometerChip(vehicle.currentOdometerMiles)}</span>
            <span class="pay-chip pay-chip-kind" data-chip="mpg">{mpgChip(vehicle)}</span>
          </div>
        </article>
      {/each}
    </div>
  {/if}
</div>
