<script lang="ts">
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { onMount } from 'svelte';
  import CalendarEntryDialog from '../components/CalendarEntryDialog.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api } from '../lib/api';
  import { calendarBillColor, calendarBillShown, calendarBillSide } from '../lib/calendarBill';
  import { fitThree } from '../lib/fitThree';
  import { monthCells, shiftYearMonth } from '../lib/dates';
  import { exponentOf, formatChipDate, formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { CalendarEntry, Trip } from '../lib/types';

  type Payday = { id: number; payor: string; on: string; paycheckMinor: number; length: string };

  const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  type Occurrence = {
    id: number;
    name: string;
    type: string;
    on: string;
    amountMinor: number;
    baseAmountMinor: number | null;
    currency: string;
    exponent: number;
    paid: boolean;
    status: 'active' | 'paused' | 'paid';
  };

  let occurrences = $state<Occurrence[]>([]);
  let trips = $state<Trip[]>([]);
  let paydays = $state<Payday[]>([]);
  let entries = $state<CalendarEntry[]>([]);
  let entryOpen = $state(false);
  let editing = $state<CalendarEntry | null>(null);
  let message = $state('');
  let tripsReady = $state(false);
  let loading = $state(true);
  let day = $state<string | null>(null);
  let dialog: HTMLDialogElement | undefined = $state();
  let showIncome = $state(true);
  let showStays = $state(true);
  let showPaidBills = $state(true);
  let showUnpaidBills = $state(true);

  let month = $state(todayISO().slice(0, 7));

  function monthParts(value: string): { year: number; month: number } {
    return { year: Number(value.slice(0, 4)), month: Number(value.slice(5, 7)) };
  }

  function moveMonth(by: number): void {
    const current = monthParts(month);
    const next = shiftYearMonth(current.year, current.month, by);
    month = `${next.year}-${String(next.month).padStart(2, '0')}`;
  }

  let days = $derived.by(() => {
    const current = monthParts(month);
    return monthCells(current.year, current.month);
  });
  let monthStart = $derived(`${month}-01`);
  let monthEnd = $derived.by(() => {
    const current = monthParts(month);
    const last = new Date(current.year, current.month, 0).getDate();
    return `${month}-${String(last).padStart(2, '0')}`;
  });

  function weekdayName(date: string): string {
    const [year, monthNumber, dayNumber] = date.split('-').map(Number);
    return weekdays[new Date(year, monthNumber - 1, dayNumber).getDay()];
  }

  function stayCaption(trip: Trip): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trip.arriveOn) || !/^\d{4}-\d{2}-\d{2}$/.test(trip.departOn)) return 'Stay';
    if (trip.arriveOn === trip.departOn) return 'Stay';
    return `${formatDate(trip.arriveOn)} – ${formatDate(trip.departOn)}`;
  }

  function dayStays(date: string): Trip[] {
    return trips.filter((trip) => trip.arriveOn <= date && date <= trip.departOn);
  }

  function dayEntries(date: string): CalendarEntry[] {
    return entries
      .filter((entry) => entry.startOn <= date && date <= entry.endOn)
      .sort((left, right) => left.startOn.localeCompare(right.startOn) || left.id - right.id);
  }

  function addEntry(): void {
    editing = null;
    entryOpen = true;
  }

  function openEntry(event: MouseEvent, entry: CalendarEntry): void {
    event.preventDefault();
    event.stopPropagation();
    editing = entry;
    entryOpen = true;
  }

  async function reloadEntries(): Promise<void> {
    const data = await api<{ entries: CalendarEntry[] }>(`/api/calendar-entries?start=${monthStart}&end=${monthEnd}`);
    entries = data.entries;
  }

  type Mark = {
    id: number;
    name: string;
    type: string;
    amountMinor: number;
    baseAmountMinor?: number | null;
    currency: string;
    exponent: number;
    kind: 'due' | 'paid' | 'income';
    status?: 'active' | 'paused' | 'paid';
    href: string;
  };

  type BillMark = Mark & {
    kind: 'due' | 'paid';
    status: 'active' | 'paused' | 'paid';
  };

  let baseCurrency = $derived(session.user?.baseCurrency ?? 'USD');
  let baseExponent = $derived(exponentOf(baseCurrency));

  function incomeMarks(date: string): Mark[] {
    const hits: Mark[] = [];
    for (const payday of paydays) {
      if (payday.on !== date) continue;
      hits.push({
        id: payday.id,
        name: payday.payor,
        type: payday.length,
        amountMinor: payday.paycheckMinor,
        currency: baseCurrency,
        exponent: baseExponent,
        kind: 'income',
        href: `/pay-cycles/${payday.id}`,
      });
    }
    return hits;
  }

  function billMarks(date: string): BillMark[] {
    return occurrences.filter((item) => item.on === date).map((item) => ({
      id: item.id,
      name: item.name,
      type: item.type,
      amountMinor: -item.amountMinor,
      baseAmountMinor: item.baseAmountMinor,
      currency: item.currency,
      exponent: item.exponent,
      kind: item.paid ? 'paid' : 'due',
      status: item.status,
      href: `/bills/${item.id}`,
    }));
  }

  function visibleBills(date: string): BillMark[] {
    return billMarks(date).filter((mark) => calendarBillShown(mark.status, mark.kind, showPaidBills, showUnpaidBills));
  }

  function dayItemCount(date: string): number {
    return (showStays ? dayStays(date).length : 0)
      + dayEntries(date).length
      + (showIncome ? incomeMarks(date).length : 0)
      + visibleBills(date).length;
  }

  let shown = $derived(days.flatMap((date) => (date === null ? [] : [...incomeMarks(date), ...billMarks(date)])));

  let totals = $derived.by(() => {
    let income = 0;
    let billMinor = 0;
    let paidMinor = 0;
    const leftOut: string[] = [];
    for (const mark of shown) {
      if (mark.kind === 'income') {
        income += mark.amountMinor;
        continue;
      }
      if (mark.baseAmountMinor == null) {
        leftOut.push(mark.name);
        continue;
      }
      if (mark.status && calendarBillSide(mark.status, mark.kind) === 'paid') {
        paidMinor += mark.baseAmountMinor;
      } else {
        billMinor += mark.baseAmountMinor;
      }
    }
    return {
      income,
      debt: billMinor,
      paid: paidMinor,
      net: income - billMinor,
      leftOut,
    };
  });

  function choose(date: string): void {
    day = date;
    dialog?.showModal();
  }

  onMount(() => {
    api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}&fuel=0`).then((data) => {
      trips = data.trips;
    }).catch((err: unknown) => {
      message = err instanceof Error ? err.message : 'Could not load stays.';
    }).finally(() => {
      tripsReady = true;
    });
  });

  $effect(() => {
    const start = monthStart;
    const end = monthEnd;
    let stale = false;
    loading = true;
    Promise.all([
      api<{ paydays: Payday[] }>(`/api/pay-cycles/month?start=${start}&end=${end}&today=${todayISO()}`),
      api<{ occurrences: Occurrence[] }>(`/api/bills/calendar?start=${start}&end=${end}`),
      api<{ entries: CalendarEntry[] }>(`/api/calendar-entries?start=${start}&end=${end}`),
    ])
      .then(([payData, billData, entryData]) => {
        if (stale) return;
        paydays = payData.paydays;
        occurrences = billData.occurrences;
        entries = entryData.entries;
      })
      .catch((err: unknown) => {
        if (!stale) message = err instanceof Error ? err.message : 'Could not load the calendar.';
      })
      .finally(() => {
        if (!stale) loading = false;
      });
    return () => {
      stale = true;
    };
  });
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="m-0 text-4xl">Calendar</h1>
    <button class="btn primary" type="button" onclick={addEntry}>Add entry</button>
  </div>
  <Note bind:message />
  {#if loading || !tripsReady}
    <Spinner />
  {:else}
  <div class="flex items-center justify-between gap-3">
    <button class="btn" type="button" onclick={() => moveMonth(-1)}>Previous month</button>
    <p class="m-0" data-month={month}>{formatMonth(month)}</p>
    <button class="btn" type="button" onclick={() => moveMonth(1)}>Next month</button>
  </div>
  <div class="calendar-board">
    <div class="calendar-filters" role="group" aria-label="Show on the calendar">
      <label class="calendar-filter" style="--tone: var(--teal)">
        <input type="checkbox" bind:checked={showIncome} />
        <span class="calendar-swatch"></span>
        <svg class="calendar-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 19V7M8 11l4-4 4 4M6 19.5h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Show Income
      </label>
      <label class="calendar-filter" style="--tone: var(--sky)">
        <input type="checkbox" bind:checked={showStays} />
        <span class="calendar-swatch"></span>
        <Glyph name="tree" mini />
        Show Stays
      </label>
      <label class="calendar-filter" style="--tone: var(--lilac)">
        <input type="checkbox" bind:checked={showPaidBills} />
        <span class="calendar-swatch"></span>
        <svg class="calendar-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M7 4.5h7l4 4V19.5H7V4.5zM14 4.5v4h4M9.5 12.5h5M9.5 16h3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Show Paid Bills
      </label>
      <label class="calendar-filter" style="--tone: var(--coral)">
        <input type="checkbox" bind:checked={showUnpaidBills} />
        <span class="calendar-swatch"></span>
        <svg class="calendar-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M7 4.5h7l4 4V19.5H7V4.5zM14 4.5v4h4M9.5 12.5h5M9.5 16h3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Show Unpaid Bills
      </label>
    </div>
    <div class="month-scroll">
      <div class="month" aria-label="Month">
        {#each weekdays as name}
          <p class="kicker m-0 month-head">{name}</p>
        {/each}
        {#each days as date, index (`${month}-${index}`)}
          {#if date}
            {@const count = dayItemCount(date)}
            <div
              class="day"
              class:today={date === todayISO()}
              role="button"
              tabindex="0"
              data-date={date}
              aria-current={date === todayISO() ? 'date' : undefined}
              onclick={() => choose(date)}
              onkeydown={(event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                choose(date);
              }}
            >
              <strong><span class="day-week">{weekdayName(date)}&nbsp;</span>{Number(date.slice(8))}</strong>
              <span class="day-items" class:day-scroll={count > 3} use:fitThree={count}>
                {#if showStays}
                  {#each dayStays(date) as trip (trip.id)}
                    <span class="bill-chip stay-chip" title={trip.placeName}>
                      <span class="bill-chip-line">
                        <Glyph name="tree" mini />
                        <span class="bill-chip-name">{trip.placeName}</span>
                      </span>
                      <span class="bill-chip-amount">{stayCaption(trip)}</span>
                    </span>
                  {/each}
                {/if}
                {#each dayEntries(date) as entry (entry.id)}
                  <button
                    class="bill-chip entry-chip"
                    type="button"
                    data-calendar-entry={entry.id}
                    title={entry.text}
                    onclick={(event) => openEntry(event, entry)}
                    onkeydown={(event) => event.stopPropagation()}
                  >
                    <span class="bill-chip-line">
                      <Glyph name="entry" mini />
                      <span class="bill-chip-name">{entry.text}</span>
                    </span>
                  </button>
                {/each}
                {#if showIncome}
                  {#each incomeMarks(date) as mark (`${date}-income-${mark.id}`)}
                    <span class="bill-chip income-chip" title={mark.name}>
                      <span class="bill-chip-line">
                        <Glyph name={mark.type} mini />
                        <span class="bill-chip-name">{mark.name}</span>
                      </span>
                      <span class="bill-chip-amount money">{formatMinor(mark.amountMinor, mark.currency, mark.exponent)}</span>
                    </span>
                  {/each}
                {/if}
                {#each visibleBills(date) as mark (`${date}-${mark.kind}-${mark.id}`)}
                  <span class="bill-chip" class:paid-chip={calendarBillColor(mark.status, mark.kind) === '--lilac'} title={mark.name}>
                    <span class="bill-chip-line">
                      <Glyph name={mark.type} mini />
                      <span class="bill-chip-name">{mark.name}</span>
                    </span>
                    <span class="bill-chip-amount money">{formatMinor(mark.amountMinor, mark.currency, mark.exponent)}</span>
                  </span>
                {/each}
              </span>
              {#if count > 3}
                <span class="day-more" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path d="M12 5v11M7.5 12.5 12 17.5 16.5 12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
              {/if}
            </div>
          {:else}
            <span class="month-pad"></span>
          {/if}
        {/each}
      </div>
    </div>
    <div class="month-total">
      <p class="kicker m-0">Total</p>
      <p class="month-total-figures m-0">
        <span class="month-income">Income <strong class="money">{formatMinor(totals.income, baseCurrency, baseExponent)}</strong></span>
        <span class="month-debt">Debt <strong class="money">{formatMinor(totals.debt, baseCurrency, baseExponent)}</strong></span>
        <span class="month-net">Net <strong class="money">{formatMinor(totals.net, baseCurrency, baseExponent)}</strong></span>
        <span class="month-rule" aria-hidden="true"></span>
        <span class="month-paid">Paid <strong class="money">{formatMinor(totals.paid, baseCurrency, baseExponent)}</strong></span>
        {#if totals.leftOut.length > 0}
          <span class="month-bills">Left out {totals.leftOut.join(', ')}</span>
        {/if}
      </p>
    </div>
  </div>
  {/if}
</div>

<dialog class="modal" bind:this={dialog} aria-labelledby="day-title" onclose={() => (day = null)}>
  {#if day}
    <div class="modal-body">
    <h2 id="day-title" class="m-0 text-2xl">{formatDate(day)}</h2>
    <section class="day-section">
      <h3 class="m-0 text-lg">Stay</h3>
      {#if dayStays(day).length === 0}
        <p class="muted m-0">No stay this day.</p>
      {/if}
      {#each dayStays(day) as trip (trip.id)}
        <a class="row" href={`/trips/${trip.id}`} onclick={(event) => { dialog?.close(); follow(event, `/trips/${trip.id}`); }}>
          <span class="flex items-center gap-2 min-w-0">
            <Glyph name="tree" mini />
            <span>
              <strong>{trip.placeName}</strong>
              <span class="block muted text-sm">{stayCaption(trip)}</span>
            </span>
          </span>
        </a>
      {/each}
    </section>
    <hr class="day-rule" />
    <section class="day-section">
      <h3 class="m-0 text-lg">Income</h3>
      {#if incomeMarks(day).length === 0}
        <p class="muted m-0">No income this day.</p>
      {/if}
      {#each incomeMarks(day) as mark (`income-${mark.id}`)}
        <a class="row" href={mark.href} onclick={(event) => { dialog?.close(); follow(event, mark.href); }}>
          <span class="flex items-center gap-2 min-w-0">
            <Glyph name={mark.type} mini />
            <span>
              <strong>{mark.name}</strong>
              <span class="block muted text-sm">Income</span>
            </span>
          </span>
          <strong class="money teal">{formatMinor(mark.amountMinor, mark.currency, mark.exponent)}</strong>
        </a>
      {/each}
    </section>
    <hr class="day-rule" />
    <section class="day-section">
      <h3 class="m-0 text-lg">Bills</h3>
      {#if billMarks(day).length === 0}
        <p class="muted m-0">No bills this day.</p>
      {/if}
      {#each billMarks(day) as mark (`${mark.kind}-${mark.id}`)}
        <a class="row" href={mark.href} onclick={(event) => { dialog?.close(); follow(event, mark.href); }}>
          <span class="flex items-center gap-2 min-w-0">
            <Glyph name={mark.type} mini />
            <span>
              <strong>{mark.name}</strong>
              <span class="block muted text-sm">{calendarBillSide(mark.status, mark.kind) === 'paid' ? 'Paid' : 'Due'} · {mark.type}</span>
            </span>
          </span>
          <strong class="money" class:lilac={calendarBillColor(mark.status, mark.kind) === '--lilac'} class:coral={calendarBillColor(mark.status, mark.kind) === '--coral'}>{formatMinor(mark.amountMinor, mark.currency, mark.exponent)}</strong>
        </a>
      {/each}
    </section>
    </div>
    <div class="modal-actions">
      <button class="btn" type="button" onclick={() => dialog?.close()}>Close</button>
    </div>
  {/if}
</dialog>

<CalendarEntryDialog bind:open={entryOpen} entry={editing} onsaved={reloadEntries} />
