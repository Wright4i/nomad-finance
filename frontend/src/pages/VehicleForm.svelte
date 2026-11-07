<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatDate, formatMinor, minorToInput, parseMajor } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Vehicle, VehicleReading } from '../lib/types';
  import { vehicleKinds, vehicleTone, type VehicleKind } from '../lib/vehicleKinds';

  let { id = null }: { id?: string | null } = $props();

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

  function formatMiles(value: number | null): string {
    if (value == null) return '—';
    return value.toLocaleString('en-US');
  }

  let name = $state('');
  let kind = $state<VehicleKind>('rv');
  let mpgMin = $state('6.0');
  let mpgMax = $state('12.0');
  let starred = $state(false);
  let othersStarred = $state(false);
  let currentOdometer = $state('');
  let fuelPrice = $state('');
  let vehicle = $state<Vehicle | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let rangeHelp: HTMLDialogElement | undefined = $state();
  let fuelDialog: HTMLDialogElement | undefined = $state();
  let editBillId = $state<number | null>(null);
  let editDate = $state('');
  let editOdometer = $state('');
  let editAmount = $state('');
  let editPrice = $state('');
  let editFields = $state<Record<string, string>>({});
  let editMessage = $state('');
  let editSaving = $state(false);
  let showStar = $derived(starred || !othersStarred);

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
    fuelPrice = data.vehicle.fuelPriceMinor == null ? '' : minorToInput(data.vehicle.fuelPriceMinor, 2);
  }

  onMount(() => {
    api<{ vehicles: Vehicle[] }>('/api/vehicles')
      .then((data) => {
        othersStarred = data.vehicles.some((item) => item.starred && String(item.id) !== (id ?? ''));
      })
      .catch(() => {
        othersStarred = false;
      });
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
    const priceMinor = fuelPrice.trim() === '' ? null : parseMajor(fuelPrice, 2);
    if (fuelPrice.trim() !== '' && (priceMinor === null || priceMinor < 1)) {
      problems.fuelPriceMinor = 'Enter a price per gallon greater than zero, or leave it empty.';
    }
    fields = problems;
    if (Object.keys(problems).length > 0) return;
    saving = true;
    message = '';
    try {
      const body = {
        name,
        kind,
        mpgMinTenths: min,
        mpgMaxTenths: max,
        starred,
        currentOdometerMiles: Number(currentOdometer),
        fuelPriceMinor: priceMinor,
      };
      const data = await api<{ vehicle: Vehicle }>(id ? `/api/vehicles/${id}` : '/api/vehicles', {
        method: id ? 'PATCH' : 'POST',
        body,
      });
      if (!id) {
        follow(new MouseEvent('click'), `/vehicles/${data.vehicle.id}`);
        return;
      }
      vehicle = data.vehicle;
      starred = data.vehicle.starred;
      fuelPrice = data.vehicle.fuelPriceMinor == null ? '' : minorToInput(data.vehicle.fuelPriceMinor, 2);
      message = 'Saved.';
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  async function refreshVehicle(): Promise<void> {
    if (!id) return;
    const data = await api<{ vehicle: Vehicle }>(`/api/vehicles/${id}`);
    vehicle = data.vehicle;
    currentOdometer = String(data.vehicle.currentOdometerMiles);
  }

  function openFuel(reading: VehicleReading): void {
    editBillId = reading.id;
    editDate = formatDate(reading.postedOn);
    editOdometer = reading.odometerMiles == null ? '' : String(reading.odometerMiles);
    editAmount = minorToInput(reading.amountMinor, 2);
    editPrice = reading.pricePerGallonMinor == null ? '' : minorToInput(reading.pricePerGallonMinor, 2);
    editFields = {};
    editMessage = '';
    fuelDialog?.showModal();
  }

  async function saveFuel(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (editBillId == null) return;
    const problems: Record<string, string> = {};
    if (editOdometer.trim() === '' || !/^\d+$/.test(editOdometer.trim())) {
      problems.odometerMiles = 'Enter the odometer.';
    }
    const amountMinor = parseMajor(editAmount, 2);
    if (amountMinor === null || amountMinor < 1) {
      problems.amountMinor = 'Enter an amount greater than zero.';
    }
    let priceMinor: number | null = null;
    if (editPrice.trim() !== '') {
      priceMinor = parseMajor(editPrice, 2);
      if (priceMinor === null || priceMinor < 1) {
        problems.pricePerGallonMinor = 'Enter a price per gallon greater than zero, or leave it blank.';
      }
    }
    editFields = problems;
    if (Object.keys(problems).length > 0) return;
    editSaving = true;
    editMessage = '';
    try {
      await api(`/api/bills/${editBillId}/fuel`, {
        method: 'PATCH',
        body: {
          odometerMiles: Number(editOdometer),
          amountMinor,
          pricePerGallonMinor: priceMinor,
        },
      });
      fuelDialog?.close();
      await refreshVehicle();
    } catch (err) {
      const problem = explain(err);
      editMessage = problem.message;
      editFields = problem.fields;
    } finally {
      editSaving = false;
    }
  }

  async function deleteFuel(): Promise<void> {
    if (editBillId == null) return;
    if (!confirm('Delete this fuel entry?')) return;
    editSaving = true;
    editMessage = '';
    try {
      await api(`/api/bills/${editBillId}/fuel/delete`, { method: 'POST', body: { confirm: true } });
      fuelDialog?.close();
      await refreshVehicle();
    } catch (err) {
      const problem = explain(err);
      editMessage = problem.message;
      editFields = problem.fields;
    } finally {
      editSaving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-3xl" onsubmit={saveVehicle}>
  <h1 class="m-0 text-4xl">{id ? name || 'Edit vehicle' : 'New vehicle'}</h1>
  <Note bind:message />
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <div class="field">
    <span>Kind</span>
    <div class="flex flex-wrap gap-2">
      {#each vehicleKinds as option}
        <button class="choice" class:on={kind === option} type="button" style={`--tone: var(--tone-${vehicleTone(option)})`} aria-pressed={kind === option} onclick={() => (kind = option)}>
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
  <label class="field">
    <span>Fallback price per gallon</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <input name="fuelPrice" inputmode="decimal" placeholder="Leave empty for miles only…" bind:value={fuelPrice} />
    </span>
    {#if fields.fuelPriceMinor}<em>{fields.fuelPriceMinor}</em>{/if}
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
  {#if showStar}
    <button class="choice w-fit" class:on={starred} type="button" aria-pressed={starred} onclick={() => (starred = !starred)}>{starred ? '★ Default vehicle' : '☆ Make this the default'}</button>
  {/if}
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving} aria-busy={saving}>{saving ? 'Saving…' : 'Save vehicle'}</button>
    <a class="btn" href="/vehicles" onclick={(event) => follow(event, '/vehicles')}>Back</a>
  </div>
</form>

{#if vehicle}
  <section class="grid gap-3 mt-8 max-w-3xl min-w-0">
    <h2 class="m-0 text-2xl">Fuel</h2>
    <p class="m-0">{tenthsToInput(vehicle.mpgMinTenths)}–{tenthsToInput(vehicle.mpgMaxTenths)} mpg · calculated {vehicle.milesPerGallon ?? '—'}</p>
    {#if vehicle.readings.length === 0}
      <p class="m-0 muted">No fuel yet.</p>
    {:else}
      <div class="fuel-scroll">
        <table class="fuel-table">
          <colgroup>
            <col style="width: 16%" />
            <col style="width: 12%" />
            <col style="width: 12%" />
            <col style="width: 14%" />
            <col style="width: 11%" />
            <col style="width: 10%" />
            <col style="width: 11%" />
            <col style="width: 14%" />
          </colgroup>
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Odometer</th>
              <th scope="col">Amount</th>
              <th scope="col">Price per gallon</th>
              <th scope="col">Gallons</th>
              <th scope="col">Miles</th>
              <th scope="col">Stretch mpg</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            {#each vehicle.readings as reading (reading.id)}
              <tr>
                <td data-label="Date">{formatDate(reading.postedOn)}</td>
                <td data-label="Odometer">{formatMiles(reading.odometerMiles)}</td>
                <td class="money" data-label="Amount">{formatMinor(reading.amountMinor)}</td>
                <td class="money" data-label="Price per gallon">{reading.pricePerGallonMinor === null ? '—' : formatMinor(reading.pricePerGallonMinor)}</td>
                <td data-label="Gallons">{reading.gallons ?? '—'}</td>
                <td data-label="Miles">{formatMiles(reading.miles)}</td>
                <td data-label="Stretch mpg">{reading.milesPerGallon ?? reading.note ?? '—'}</td>
                <td data-label="">
                  <div class="fuel-actions">
                    <button class="icon-btn fuel-edit" type="button" aria-label="Edit fuel" onclick={() => openFuel(reading)}>
                      <Glyph name="pencil" mini />
                    </button>
                  </div>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
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

<dialog class="modal" bind:this={fuelDialog} aria-labelledby="fuel-edit-title">
  <form onsubmit={saveFuel}>
    <div class="modal-body">
      <h2 id="fuel-edit-title" class="m-0 text-2xl">Edit fuel</h2>
      {#if editDate}<p class="m-0 muted">{editDate}</p>{/if}
      <Note bind:message={editMessage} />
      <label class="field">
        <span>Odometer</span>
        <input name="fuelOdometer" inputmode="numeric" bind:value={editOdometer} />
        {#if editFields.odometerMiles}<em>{editFields.odometerMiles}</em>{/if}
      </label>
      <label class="field">
        <span>Amount</span>
        <span class="money-input">
          <span class="money-mark" aria-hidden="true">$</span>
          <input name="fuelAmount" inputmode="decimal" bind:value={editAmount} />
        </span>
        {#if editFields.amountMinor}<em>{editFields.amountMinor}</em>{/if}
      </label>
      <label class="field">
        <span>Price per gallon</span>
        <span class="money-input">
          <span class="money-mark" aria-hidden="true">$</span>
          <input name="fuelPricePerGallon" inputmode="decimal" bind:value={editPrice} />
        </span>
        {#if editFields.pricePerGallonMinor}<em>{editFields.pricePerGallonMinor}</em>{/if}
      </label>
    </div>
    <div class="modal-actions">
      <button class="btn primary" type="submit" disabled={editSaving}>{editSaving ? 'Saving…' : 'Save'}</button>
      <button class="btn" type="button" onclick={() => fuelDialog?.close()}>Close</button>
      <button class="btn danger" type="button" onclick={deleteFuel} disabled={editSaving}>Delete</button>
    </div>
  </form>
</dialog>
