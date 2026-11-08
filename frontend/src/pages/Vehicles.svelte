<script lang="ts">
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { onMount } from 'svelte';
  import { api, explain } from '../lib/api';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Vehicle } from '../lib/types';
  import { vehicleTone } from '../lib/vehicleKinds';

  let vehicles = $state<Vehicle[]>([]);
  let message = $state('');
  let ready = $state(false);
  let showArchived = $state(false);
  let saving = $state(false);
  let deleteTarget = $state<Vehicle | null>(null);
  let deleteDialog: HTMLDialogElement | undefined = $state();
  let hasStar = $derived(vehicles.some((vehicle) => vehicle.starred));

  async function load(): Promise<void> {
    const data = await api<{ vehicles: Vehicle[] }>(`/api/vehicles?includeArchived=${showArchived ? '1' : '0'}`);
    vehicles = data.vehicles;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
    }).finally(() => {
      ready = true;
    });
  });

  async function toggleArchived(): Promise<void> {
    showArchived = !showArchived;
    message = '';
    try {
      await load();
    } catch (err) {
      message = explain(err).message;
    }
  }

  function askDelete(vehicle: Vehicle): void {
    deleteTarget = vehicle;
    deleteDialog?.showModal();
  }

  async function confirmDelete(): Promise<void> {
    if (!deleteTarget) return;
    saving = true;
    message = '';
    const gone = deleteTarget.id;
    try {
      await api(`/api/vehicles/${gone}/delete`, { method: 'POST', body: { confirm: true } });
      vehicles = vehicles.filter((vehicle) => vehicle.id !== gone);
      deleteDialog?.close();
      deleteTarget = null;
    } catch (err) {
      deleteDialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

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
      await load();
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
  <button class="btn w-fit" type="button" onclick={() => void toggleArchived()}>{showArchived ? 'Hide archived' : 'Show archived'}</button>
  <Note bind:message />
  {#if !ready}
    <Spinner />
  {:else if vehicles.length === 0}
    <p class="muted m-0">No vehicles yet.</p>
  {:else}
    <div class="vehicle-columns">
      {#each vehicles as vehicle (vehicle.id)}
        <article class="card stripe grid gap-3" style={`--tone: var(--tone-${vehicleTone(vehicle.kind)})`}>
          <div class="pay-card-head">
            <a class="flex items-center gap-3 min-w-0 no-underline" href={`/vehicles/${vehicle.id}`} onclick={(event) => follow(event, `/vehicles/${vehicle.id}`)}>
              <Glyph name={vehicle.kind} />
              <span>
                <strong>{vehicle.name}</strong>
                <span class="block muted text-sm">{vehicle.kind}{vehicle.archivedAt ? ' · archived' : ''}</span>
              </span>
            </a>
            {#if vehicle.archivedAt}
              <button class="btn danger" type="button" disabled={saving} onclick={() => askDelete(vehicle)}>Delete</button>
            {:else if vehicle.starred || !hasStar}
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

<dialog class="modal" bind:this={deleteDialog} aria-labelledby="delete-vehicle-title">
  <div class="modal-body">
    <h2 id="delete-vehicle-title" class="text-xl m-0">Delete this vehicle?</h2>
    <p class="m-0">This removes {deleteTarget?.name ?? 'this vehicle'}. Its fuel entries stay, without this vehicle. It does not come back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => deleteDialog?.close()}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={() => void confirmDelete()}>Delete vehicle</button>
  </div>
</dialog>
