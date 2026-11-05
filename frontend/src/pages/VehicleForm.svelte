<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import DateField from '../components/DateField.svelte';
  import { api, explain } from '../lib/api';
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates';
  import { exponentOf, formatDate, formatMinor, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import type { Account, ChargeQuote, Vehicle, VehicleReading } from '../lib/types';

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
  let vehicle = $state<Vehicle | null>(null);
  let accounts = $state<Account[]>([]);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let editing = $state<VehicleReading | null>(null);
  let editAmount = $state('');
  let editAccountId = $state('');
  let editOdometer = $state('');
  let editPrice = $state('');
  let editDate = $state('');
  let quote = $state<ChargeQuote | null>(null);
  let pendingEdit = $state<Record<string, unknown> | null>(null);
  let pendingDelete = $state<number | null>(null);
  let dialog: HTMLDialogElement | undefined = $state();
  let rangeHelp: HTMLDialogElement | undefined = $state();

  let account = $derived(accounts.find((item) => String(item.id) === editAccountId) ?? null);

  async function load(): Promise<void> {
    const accountData = await api<{ accounts: Account[] }>('/api/accounts');
    accounts = accountData.accounts;
    if (!id) return;
    const data = await api<{ vehicle: Vehicle }>(`/api/vehicles/${id}`);
    vehicle = data.vehicle;
    name = data.vehicle.name;
    kind = data.vehicle.kind;
    mpgMin = tenthsToInput(data.vehicle.mpgMinTenths);
    mpgMax = tenthsToInput(data.vehicle.mpgMaxTenths);
    starred = data.vehicle.starred;
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
    fields = problems;
    if (Object.keys(problems).length > 0) return;
    saving = true;
    message = '';
    try {
      const body = { name, kind, mpgMinTenths: min, mpgMaxTenths: max, starred };
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

  function openEdit(reading: VehicleReading): void {
    editing = reading;
    editAmount = minorToInput(reading.amountMinor, exponentOf(reading.currency));
    editAccountId = String(reading.accountId);
    editOdometer = reading.odometerMiles === null ? '' : String(reading.odometerMiles);
    editPrice = reading.pricePerGallonMinor === null ? '' : minorToInput(reading.pricePerGallonMinor, exponentOf(reading.currency));
    editDate = reading.postedOn;
    quote = null;
    pendingEdit = null;
    pendingDelete = null;
    dialog?.showModal();
  }

  async function saveReading(): Promise<void> {
    if (!editing || !vehicle) return;
    const exponent = exponentOf(editing.currency);
    const amountMinor = parseMajor(editAmount, account?.exponent ?? exponent);
    const priceMinor = editPrice.trim() === '' ? null : parseMajor(editPrice, account?.exponent ?? exponent);
    const posted = readDateInput('postedOn');
    const problems: Record<string, string> = {};
    if (amountMinor === null || amountMinor <= 0) {
      problems.amountMinor = 'Enter an amount greater than zero.';
    }
    if (!posted) problems.postedOn = DATE_INPUT_ERROR;
    else if (posted > todayISO()) problems.postedOn = 'Use today or an earlier date.';
    if (Object.keys(problems).length > 0 || amountMinor === null || amountMinor <= 0 || !posted) {
      fields = problems;
      return;
    }
    editDate = posted;
    const body: Record<string, unknown> = {
      accountId: Number(editAccountId),
      categoryId: editing.categoryId,
      amountMinor,
      direction: editing.direction,
      postedOn: posted,
      today: todayISO(),
      payee: editing.payee ?? '',
      memo: editing.memo ?? '',
      tripId: editing.tripId,
      vehicleId: vehicle.id,
    };
    if (editOdometer.trim() !== '') body.odometerMiles = Number(editOdometer);
    if (priceMinor !== null) body.pricePerGallonMinor = priceMinor;
    const amountChanged = amountMinor !== editing.amountMinor;
    const accountChanged = Number(editAccountId) !== editing.accountId;
    if (amountChanged || accountChanged) {
      const data = await api<{ quote: ChargeQuote }>(`/api/postings/${editing.id}/quote`, { method: 'POST', body });
      quote = data.quote;
      pendingEdit = body;
      pendingDelete = null;
      return;
    }
    await api(`/api/postings/${editing.id}`, { method: 'PATCH', body });
    dialog?.close();
    await load();
  }

  async function askDelete(reading: VehicleReading): Promise<void> {
    editing = null;
    pendingEdit = null;
    pendingDelete = reading.id;
    const data = await api<{ quote: ChargeQuote }>(`/api/postings/${reading.id}/delete-quote`, { method: 'POST', body: {} });
    quote = data.quote;
    dialog?.showModal();
  }

  async function confirm(): Promise<void> {
    if (quote?.refused) return;
    if (pendingDelete !== null) {
      await api(`/api/postings/${pendingDelete}/delete`, { method: 'POST', body: {} });
    } else if (editing && pendingEdit) {
      await api(`/api/postings/${editing.id}`, { method: 'PATCH', body: pendingEdit });
    }
    dialog?.close();
    quote = null;
    pendingEdit = null;
    pendingDelete = null;
    editing = null;
    await load();
  }

  function closeDialog(): void {
    dialog?.close();
    quote = null;
    pendingEdit = null;
    pendingDelete = null;
    editing = null;
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
          <th class="text-right">Stretch mpg</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each vehicle.readings as reading (reading.id)}
          <tr>
            <td>{formatDate(reading.postedOn)}</td>
            <td class="text-right">{reading.odometerMiles ?? '—'}</td>
            <td class="text-right money">{reading.pricePerGallonMinor === null ? '—' : formatMinor(reading.pricePerGallonMinor, reading.currency, exponentOf(reading.currency))}</td>
            <td class="text-right">{reading.gallons ?? '—'}</td>
            <td class="text-right">{reading.milesPerGallon ?? reading.note ?? '—'}</td>
            <td class="text-right">
              <button class="btn" type="button" onclick={() => openEdit(reading)}>Edit</button>
              <button class="btn" type="button" onclick={() => askDelete(reading)}>Delete</button>
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  </section>
{/if}

<dialog class="modal" bind:this={dialog} aria-labelledby="reading-title">
  {#if quote}
    <h2 id="reading-title" class="m-0 text-2xl">Move this balance?</h2>
    <p>{quote.accountName}</p>
    <p class="money">{formatMinor(quote.oldBalanceMinor, quote.currency, quote.exponent)} → {formatMinor(quote.newBalanceMinor, quote.currency, quote.exponent)}</p>
    {#if quote.refused}<p>{quote.refused}</p>{/if}
    <div class="flex flex-wrap gap-2">
      {#if !quote.refused}
        <button class="btn primary" type="button" onclick={confirm}>{pendingDelete !== null ? 'Delete' : 'Apply'}</button>
      {/if}
      <button class="btn" type="button" onclick={closeDialog}>Cancel</button>
    </div>
  {:else if editing}
    <h2 id="reading-title" class="m-0 text-2xl">Edit reading</h2>
    <label class="field">
      <span>Date</span>
      <DateField name="postedOn" bind:value={editDate} />
      {#if fields.postedOn}<em>{fields.postedOn}</em>{/if}
    </label>
    <label class="field">
      <span>Account</span>
      <select name="accountId" bind:value={editAccountId}>
        {#each accounts as item (item.id)}
          <option value={String(item.id)}>{item.name}</option>
        {/each}
      </select>
    </label>
    <label class="field">
      <span>Amount</span>
      <input name="amount" inputmode="decimal" autocomplete="off" bind:value={editAmount} />
      {#if fields.amountMinor}<em>{fields.amountMinor}</em>{/if}
    </label>
    <label class="field">
      <span>Odometer, miles</span>
      <input name="odometer" inputmode="numeric" autocomplete="off" bind:value={editOdometer} />
    </label>
    <label class="field">
      <span>Price per gallon</span>
      <input name="price" inputmode="decimal" autocomplete="off" bind:value={editPrice} />
    </label>
    <div class="flex flex-wrap gap-2 mt-3">
      <button class="btn primary" type="button" onclick={saveReading}>Save</button>
      <button class="btn" type="button" onclick={closeDialog}>Cancel</button>
    </div>
  {/if}
</dialog>

<dialog class="modal" bind:this={rangeHelp} aria-labelledby="range-help-title">
  <h2 id="range-help-title" class="m-0 text-2xl">How the range is used</h2>
  <p>The min/max range is to throw out outlier mpg readings from missed odometer entries and fill-ups while not towing</p>
  <button class="btn mt-3" type="button" onclick={() => rangeHelp?.close()}>Close</button>
</dialog>
