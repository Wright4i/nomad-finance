<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatDate, formatMinor } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Vehicle } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const kinds = ['car', 'truck', 'motorcycle', 'rv', 'semi'] as const;

  function tenthsToInput(tenths: number): string {
    return `${Math.trunc(tenths / 10)}.${tenths % 10}`;
  }

  function parseTenths(input: string): number | null {
    const trimmed = input.trim();
    if (!/^\d+(\.\d)?$/.test(trimmed)) return null;
    const [whole, frac = '0'] = trimmed.split('.');
    const tenths = Number(whole) * 10 + Number(frac);
    return tenths > 0 ? tenths : null;
  }

  let name = $state('');
  let kind = $state<(typeof kinds)[number]>('rv');
  let mpgMin = $state('6.0');
  let mpgMax = $state('12.0');
  let starred = $state(false);
  let currentOdometer = $state('');
  let vehicle = $state<Vehicle | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let rangeHelp: HTMLDialogElement | undefined = $state();

  async function load(): Promise<void> {
    if (!id) return;
    const data = await api<{ vehicle: Vehicle }>(`/api/vehicles/${id}`);
    vehicle = data.vehicle;
    name = data.vehicle.name;
    kind = data.vehicle.kind;
    mpgMin = tenthsToInput(data.vehicle.mpgMinTenths);
    mpgMax = tenthsToInput(data.vehicle.mpgMaxTenths);
    starred = data.vehicle.starred;
    currentOdometer = String(data.vehicle.currentOdometerMiles);
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
    });
  });

  async function saveVehicle(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    const min = parseTenths(mpgMin);
    const max = parseTenths(mpgMax);
    const problems: Record<string, string> = {};
    if (name.trim() === '') problems.name = 'Use a name of 1 to 80 characters.';
    if (min === null) problems.mpgMinTenths = 'Enter a minimum miles per gallon greater than zero.';
    if (max === null) problems.mpgMaxTenths = 'Enter a maximum miles per gallon greater than zero.';
    if (min !== null && max !== null && min >= max) problems.mpgMaxTenths = 'The maximum is above the minimum.';
    if (currentOdometer.trim() === '' || !/^\d+$/.test(currentOdometer.trim())) {
      problems.currentOdometerMiles = 'Enter the current odometer. Zero is only the reading you type.';
    }
    fields = problems;
    if (Object.keys(problems).length > 0) return;
    saving = true;
    message = '';
    try {
      const body = { name, kind, mpgMinTenths: min, mpgMaxTenths: max, starred, currentOdometerMiles: Number(currentOdometer) };
      const data = await api<{ vehicle: Vehicle }>(id ? `/api/vehicles/${id}` : '/api/vehicles', {
        method: id ? 'PATCH' : 'POST',
        body,
      });
      if (!id) {
        follow(new MouseEvent('click'), `/settings/vehicles/${data.vehicle.id}`);
        return;
      }
      vehicle = data.vehicle;
      starred = data.vehicle.starred;
      message = 'Saved.';
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-3xl" onsubmit={saveVehicle}>
  <h1 class="m-0 text-4xl">{id ? name || 'Edit vehicle' : 'New vehicle'}</h1>
  {#if message}<p class="banner" class:teal={message === 'Saved.'} aria-live="polite">{message}</p>{/if}
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <div class="field">
    <span>Kind</span>
    <div class="flex flex-wrap gap-2">
      {#each kinds as option}
        <button class="choice" class:on={kind === option} type="button" style={`--tone: var(--tone-${option})`} aria-pressed={kind === option} onclick={() => (kind = option)}>
          <Glyph name={option} mini />
          {option}
        </button>
      {/each}
    </div>
  </div>
  <label class="field">
    <span>Current odometer</span>
    <input name="currentOdometer" inputmode="numeric" bind:value={currentOdometer} />
    {#if fields.currentOdometerMiles}<em>{fields.currentOdometerMiles}</em>{/if}
  </label>
  <div class="grid grid-cols-2 gap-3">
    <label class="field">
      <span class="inline-flex items-center gap-2">
        Minimum mpg
        <button class="icon-btn" type="button" aria-label="How the range is used" onclick={() => rangeHelp?.showModal()}>?</button>
      </span>
      <input name="mpgMin" inputmode="decimal" bind:value={mpgMin} />
      {#if fields.mpgMinTenths}<em>{fields.mpgMinTenths}</em>{/if}
    </label>
    <label class="field">
      <span>Maximum mpg</span>
      <input name="mpgMax" inputmode="decimal" bind:value={mpgMax} />
      {#if fields.mpgMaxTenths}<em>{fields.mpgMaxTenths}</em>{/if}
    </label>
  </div>
  <button class="choice w-fit" class:on={starred} type="button" aria-pressed={starred} onclick={() => (starred = !starred)}>{starred ? '★ Default vehicle' : '☆ Make this the default'}</button>
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving} aria-busy={saving}>{saving ? 'Saving…' : 'Save vehicle'}</button>
    <a class="btn" href="/settings/vehicles" onclick={(event) => follow(event, '/settings/vehicles')}>Back</a>
  </div>
</form>

{#if vehicle}
  <section class="grid gap-3 mt-8 max-w-3xl">
    <h2 class="m-0 text-2xl">Fuel</h2>
    <p class="m-0">{tenthsToInput(vehicle.mpgMinTenths)}–{tenthsToInput(vehicle.mpgMaxTenths)} mpg · calculated {vehicle.milesPerGallon ?? '—'}</p>
    <table class="w-full">
      <thead>
        <tr>
          <th class="text-left">Date</th>
          <th class="text-right">Odometer</th>
          <th class="text-right">Price per gallon</th>
          <th class="text-right">Gallons</th>
          <th class="text-right">Miles</th>
          <th class="text-right">Stretch mpg</th>
        </tr>
      </thead>
      <tbody>
        {#each vehicle.readings as reading (reading.id)}
          <tr>
            <td>{formatDate(reading.postedOn)}</td>
            <td class="text-right">{reading.odometerMiles ?? '—'}</td>
            <td class="text-right money">{reading.pricePerGallonMinor === null ? '—' : formatMinor(reading.pricePerGallonMinor)}</td>
            <td class="text-right">{reading.gallons ?? '—'}</td>
            <td class="text-right">{reading.miles ?? '—'}</td>
            <td class="text-right">{reading.milesPerGallon ?? reading.note ?? '—'}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </section>
{/if}

<dialog class="modal" bind:this={rangeHelp} aria-labelledby="range-help-title">
  <div class="modal-body">
    <h2 id="range-help-title" class="m-0 text-2xl">How the range is used</h2>
    <p class="m-0">The min/max range is to throw out outlier mpg readings from missed odometer entries and fill-ups while not towing</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => rangeHelp?.close()}>Close</button>
  </div>
</dialog>
