<script lang="ts">
  import { onMount } from 'svelte';
  import DateField from '../components/DateField.svelte';
  import { api, explain } from '../lib/api';
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates';
  import { exponentOf, formatDate, formatMinor, parseMajor, todayISO } from '../lib/money';
  import type { Account, Category, ChargeQuote, EnvelopeSheet, Posting, Trip, Vehicle } from '../lib/types';

  let accounts = $state<Account[]>([]);
  let categories = $state<Category[]>([]);
  let trips = $state<Trip[]>([]);
  let vehicles = $state<Vehicle[]>([]);
  let postings = $state<Posting[]>([]);
  let accountId = $state('');
  let categoryId = $state('');
  let vehicleId = $state('');
  let tripId = $state('');
  let amount = $state('');
  let direction = $state<'out' | 'in'>('out');
  let postedOn = $state(todayISO());
  let stayDate = $state('');
  let payee = $state('');
  let memo = $state('');
  let odometer = $state('');
  let price = $state('');
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saved = $state<Posting | null>(null);
  let sheet = $state<EnvelopeSheet | null>(null);
  let quote = $state<ChargeQuote | null>(null);
  let pending = $state<Record<string, unknown> | null>(null);
  let quoting = $state(false);
  let dialog: HTMLDialogElement | undefined = $state();

  let account = $derived(accounts.find((item) => String(item.id) === accountId) ?? null);
  let category = $derived(categories.find((item) => String(item.id) === categoryId) ?? null);
  let fuelOn = $derived(Boolean(category?.fuel));
  let exponent = $derived(account?.exponent ?? exponentOf('USD'));
  let remaining = $derived(sheet?.categories.find((item) => String(item.id) === categoryId) ?? null);
  let today = $derived(todayISO());
  let floorMiles = $derived(fuelOn && vehicleId !== '' ? lastOdometer(vehicleId) : null);
  let odometerVehicle = $state('');

  function coveringStay(date: string, rows: Trip[]): string {
    const covering = rows.filter((trip) => trip.arriveOn <= date && trip.departOn >= date);
    const arriving = covering.find((trip) => trip.arriveOn === date);
    if (arriving) return String(arriving.id);
    return covering[0] ? String(covering[0].id) : '';
  }

  function defaultVehicle(rows: Vehicle[]): string {
    const starred = rows.find((item) => item.starred);
    if (starred) return String(starred.id);
    if (rows.length === 1) return String(rows[0].id);
    return '';
  }

  function lastOdometer(id: string): number | null {
    let last: number | null = null;
    for (const posting of postings) {
      if (String(posting.vehicleId) !== id || posting.odometerMiles === null) continue;
      if (last === null || posting.odometerMiles > last) last = posting.odometerMiles;
    }
    return last;
  }

  function prefillOdometer(id: string): void {
    const last = id === '' ? null : lastOdometer(id);
    odometer = last === null ? '' : String(last);
  }

  function syncStay(date: string): void {
    stayDate = date;
    tripId = coveringStay(date, trips);
  }

  $effect(() => {
    if (!fuelOn || vehicleId === '') {
      odometerVehicle = '';
      return;
    }
    if (vehicleId === odometerVehicle) return;
    odometerVehicle = vehicleId;
    prefillOdometer(vehicleId);
  });

  function onDate(): void {
    if (/^\d{4}-\d{2}-\d{2}$/.test(postedOn) && postedOn !== stayDate) syncStay(postedOn);
  }

  function focusField(problems: Record<string, string>): void {
    const names: Record<string, string> = {
      amountMinor: 'amount',
      accountId: 'accountId',
      categoryId: 'categoryId',
      vehicleId: 'vehicleId',
      odometerMiles: 'odometer',
      pricePerGallonMinor: 'price',
      postedOn: 'postedOn',
      payee: 'payee',
      memo: 'memo',
      tripId: 'tripId',
    };
    for (const key of Object.keys(names)) {
      if (!problems[key]) continue;
      document.querySelector<HTMLElement>(`[name="${names[key]}"]`)?.focus();
      return;
    }
  }

  onMount(async () => {
    try {
      const [accountData, categoryData, tripData, postingData, envelopeData, vehicleData] = await Promise.all([
        api<{ accounts: Account[] }>('/api/accounts'),
        api<{ categories: Category[] }>('/api/categories'),
        api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}&fuel=0`),
        api<{ postings: Posting[] }>('/api/postings'),
        api<EnvelopeSheet>(`/api/envelopes?today=${todayISO()}`),
        api<{ vehicles: Vehicle[] }>('/api/vehicles'),
      ]);
      accounts = accountData.accounts;
      categories = categoryData.categories;
      trips = tripData.trips;
      postings = postingData.postings;
      sheet = envelopeData;
      vehicles = vehicleData.vehicles;
      if (accounts[0]) accountId = String(accounts[0].id);
      if (categories[0]) categoryId = String(categories[0].id);
      vehicleId = defaultVehicle(vehicles);
      syncStay(postedOn);
    } catch (err) {
      message = explain(err).message;
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    const amountMinor = parseMajor(amount, exponent);
    const parsedDate = readDateInput('postedOn');
    const problems: Record<string, string> = {};
    if (amountMinor === null || amountMinor <= 0) {
      problems.amountMinor = 'Enter an amount greater than zero.';
    }
    if (!parsedDate) {
      problems.postedOn = DATE_INPUT_ERROR;
    } else if (parsedDate > today) {
      problems.postedOn = 'Use today or an earlier date.';
    } else {
      postedOn = parsedDate;
    }
    if (fuelOn && (odometer.trim() !== '' || price.trim() !== '') && vehicleId === '') {
      problems.vehicleId = 'Choose a vehicle.';
    }
    const priceMinor = !fuelOn || price.trim() === '' ? null : parseMajor(price, exponent);
    if (fuelOn && price.trim() !== '' && (priceMinor === null || priceMinor <= 0)) {
      problems.pricePerGallonMinor = 'Enter a price per gallon greater than zero.';
    }
    const floor = fuelOn && vehicleId !== '' ? lastOdometer(vehicleId) : null;
    const miles = odometer.trim() === '' ? null : Number(odometer);
    if (floor !== null && miles !== null && (!Number.isInteger(miles) || miles < floor)) {
      problems.odometerMiles = `Enter an odometer at or above ${floor}.`;
    }
    if (Object.keys(problems).length > 0 || amountMinor === null) {
      fields = problems;
      focusField(problems);
      return;
    }
    const body: Record<string, unknown> = {
      accountId: Number(accountId),
      categoryId: Number(categoryId),
      amountMinor,
      direction,
      postedOn,
      today,
      payee,
      memo,
      tripId: tripId === '' ? null : Number(tripId),
    };
    if (fuelOn) {
      if (odometer.trim() !== '') body.odometerMiles = Number(odometer);
      if (priceMinor !== null) body.pricePerGallonMinor = priceMinor;
      if (vehicleId !== '' && (odometer.trim() !== '' || priceMinor !== null)) body.vehicleId = Number(vehicleId);
    }
    quoting = true;
    try {
      const data = await api<{ quote: ChargeQuote }>('/api/postings/quote', { method: 'POST', body });
      pending = body;
      quote = data.quote;
      dialog?.showModal();
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
      focusField(problem.fields);
    } finally {
      quoting = false;
    }
  }

  async function apply(): Promise<void> {
    if (!pending || quote?.refused) return;
    try {
      const data = await api<{ posting: Posting }>('/api/postings', { method: 'POST', body: pending });
      saved = data.posting;
      dialog?.close();
      quote = null;
      pending = null;
      amount = '';
      price = '';
      await reload();
      if (fuelOn && vehicleId !== '') prefillOdometer(vehicleId);
    } catch (err) {
      message = explain(err).message;
    }
  }

  function cancel(): void {
    dialog?.close();
    quote = null;
    pending = null;
  }

  async function reload(): Promise<void> {
    const [accountData, postingData, envelopeData] = await Promise.all([
      api<{ accounts: Account[] }>('/api/accounts'),
      api<{ postings: Posting[] }>('/api/postings'),
      api<EnvelopeSheet>(`/api/envelopes?today=${todayISO()}`),
    ]);
    accounts = accountData.accounts;
    postings = postingData.postings;
    sheet = envelopeData;
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="m-0 text-4xl">New Log</h1>
  {#if message}<p class="banner m-0" aria-live="polite">{message}</p>{/if}
  {#if account}
    <p class="m-0">{account.name} <span class="money">{formatMinor(account.balanceMinor, account.currency, account.exponent)}</span></p>
  {/if}
  {#if remaining && sheet}
    <p class="m-0">{remaining.name} remaining <span class="money">{formatMinor(remaining.remainingMinor ?? 0, sheet.baseCurrency, sheet.exponent)}</span></p>
  {/if}
  {#if saved}
    <p class="banner m-0" aria-live="polite">
      {saved.accountName} {formatMinor(saved.balanceMinor, saved.currency, saved.exponent)}
      · {saved.categoryName} remaining {formatMinor(saved.remainingMinor, saved.currency, saved.exponent)}
    </p>
  {/if}
  <label class="field">
    <span>Amount</span>
    <input name="amount" inputmode="decimal" autocomplete="off" bind:value={amount} />
    {#if fields.amountMinor}<em>{fields.amountMinor}</em>{/if}
  </label>
  <label class="field">
    <span>Account</span>
    <select name="accountId" bind:value={accountId}>
      {#each accounts as item (item.id)}
        <option value={String(item.id)}>{item.name}</option>
      {/each}
    </select>
  </label>
  <label class="field">
    <span>Bucket</span>
    <select name="categoryId" bind:value={categoryId}>
      {#each categories as item (item.id)}
        <option value={String(item.id)}>{item.name}</option>
      {/each}
    </select>
  </label>
  <fieldset class="field border-0 p-0 m-0">
    <legend>Direction</legend>
    <div class="flex flex-wrap gap-2">
      <button class="choice" class:on={direction === 'out'} type="button" aria-pressed={direction === 'out'} onclick={() => (direction = 'out')}>Out</button>
      <button class="choice" class:on={direction === 'in'} type="button" aria-pressed={direction === 'in'} onclick={() => (direction = 'in')}>In</button>
    </div>
  </fieldset>
  {#if fuelOn}
    <label class="field">
      <span>Vehicle</span>
      <select name="vehicleId" bind:value={vehicleId}>
        <option value="">Choose a vehicle</option>
        {#each vehicles as item (item.id)}
          <option value={String(item.id)}>{item.starred ? '★ ' : ''}{item.name}</option>
        {/each}
      </select>
      {#if fields.vehicleId}<em>{fields.vehicleId}</em>{/if}
    </label>
    <label class="field">
      <span>Odometer, miles</span>
      <input name="odometer" inputmode="numeric" autocomplete="off" min={floorMiles ?? undefined} bind:value={odometer} />
      {#if fields.odometerMiles}<em>{fields.odometerMiles}</em>{/if}
    </label>
    <label class="field">
      <span>Price per gallon</span>
      <input name="price" inputmode="decimal" autocomplete="off" bind:value={price} />
      {#if fields.pricePerGallonMinor}<em>{fields.pricePerGallonMinor}</em>{/if}
    </label>
  {/if}
  <label class="field">
    <span>Date</span>
    <DateField name="postedOn" bind:value={postedOn} oninput={onDate} />
    {#if fields.postedOn}<em>{fields.postedOn}</em>{/if}
  </label>
  <label class="field">
    <span>Payee</span>
    <input name="payee" autocomplete="off" bind:value={payee} />
  </label>
  <label class="field">
    <span>Memo</span>
    <textarea name="memo" autocomplete="off" bind:value={memo}></textarea>
  </label>
  <label class="field">
    <span>Stay</span>
    <select name="tripId" bind:value={tripId}>
      <option value="">None</option>
      {#each trips as trip (trip.id)}
        <option value={String(trip.id)}>{trip.placeName}</option>
      {/each}
    </select>
  </label>
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={quoting} aria-busy={quoting}>{quoting ? 'Saving…' : 'Save'}</button>
  </div>
</form>

<section class="grid gap-2 mt-8">
  <h2 class="m-0 text-2xl">Recent</h2>
  {#each postings as posting (posting.id)}
    <p class="m-0">
      {formatDate(posting.postedOn)}
      {#if posting.payee}· {posting.payee}{/if}
      · {posting.direction === 'out' ? 'Out' : 'In'}
      {formatMinor(posting.amountMinor, posting.currency, posting.exponent)}
      {#if posting.lines.length > 1}
        {#each posting.lines as line (`${posting.id}-${line.categoryId}`)}
          · {line.categoryName} {formatMinor(line.amountMinor, posting.currency, posting.exponent)}
        {/each}
      {:else}
        · {posting.categoryName}
      {/if}
      {#if posting.placeName}· {posting.placeName}{/if}
      {#if posting.stretch?.milesPerGallon}
        · {posting.stretch.miles} mi · {posting.stretch.gallons} gal · {posting.stretch.milesPerGallon} mpg
      {:else if posting.stretch?.note}
        · {posting.stretch.note}
      {/if}
    </p>
  {/each}
</section>

<dialog class="modal" bind:this={dialog} aria-labelledby="move-balance">
  {#if quote}
    <h2 id="move-balance" class="m-0 text-2xl">Move this balance?</h2>
    <p>{quote.accountName}</p>
    <p class="money">{formatMinor(quote.oldBalanceMinor, quote.currency, quote.exponent)} → {formatMinor(quote.newBalanceMinor, quote.currency, quote.exponent)}</p>
    {#if quote.overLimit}<p>This would put the card over its limit.</p>{/if}
    {#if quote.refused}<p>{quote.refused}</p>{/if}
    <div class="flex flex-wrap gap-2">
      {#if !quote.refused}
        <button class="btn primary" type="button" onclick={apply}>Apply</button>
      {/if}
      <button class="btn" type="button" onclick={cancel}>Cancel</button>
    </div>
  {/if}
</dialog>
