<script lang="ts">
  import Glyph from '../components/Glyph.svelte';
  import { onMount } from 'svelte';
  import { api, explain } from '../lib/api';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Vehicle } from '../lib/types';

  let vehicles = $state<Vehicle[]>([]);
  let message = $state('');

  onMount(() => {
    api<{ vehicles: Vehicle[] }>('/api/vehicles').then((data) => {
      vehicles = data.vehicles;
    }).catch((err: unknown) => {
      message = explain(err).message;
    });
  });

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
          starred: true,
        },
      });
      const data = await api<{ vehicles: Vehicle[] }>('/api/vehicles');
      vehicles = data.vehicles;
    } catch (err) {
      message = explain(err).message;
    }
  }
</script>

<div class="grid gap-4 max-w-xl">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="m-0 text-4xl">Vehicles</h1>
    <a class="btn primary" href="/settings/vehicles/new" onclick={(event) => follow(event, '/settings/vehicles/new')}>New vehicle</a>
  </div>
  {#if message}<p class="banner" aria-live="polite">{message}</p>{/if}
  {#if vehicles.length === 0}
    <p class="muted m-0">No vehicles yet.</p>
  {/if}
  {#each vehicles as vehicle (vehicle.id)}
    <div class="row">
      <a class="flex items-center gap-3 min-w-0 no-underline" style={`--tone: var(--tone-${vehicle.kind})`} href={`/settings/vehicles/${vehicle.id}`} onclick={(event) => follow(event, `/settings/vehicles/${vehicle.id}`)}>
        <Glyph name={vehicle.kind} />
        <span>
          <strong>{vehicle.name}</strong>
          <span class="block muted text-sm">{vehicle.kind}</span>
        </span>
      </a>
      <button class="btn" type="button" aria-pressed={vehicle.starred} aria-label={vehicle.starred ? `${vehicle.name} is the default` : `Star ${vehicle.name}`} onclick={() => star(vehicle)}>
        {vehicle.starred ? '★' : '☆'}
      </button>
    </div>
  {/each}
</div>
