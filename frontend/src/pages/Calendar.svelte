<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api } from '../lib/api';
  import { calendarBillColor, calendarBillShown, calendarBillSide } from '../lib/calendarBill';
  import { exponentOf, formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { Trip } from '../lib/types';

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
  let message = $state('');
  let month = $state(todayISO().slice(0, 7));
  let day = $state<string | null>(null);
  let dialog: HTMLDialogElement | undefined = $state();
  let showIncome = $state(true);
  let showStays = $state(true);
  let showPaidBills = $state(true);
  let showUnpaidBills = $state(true);

  function shift(by: number): void {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(year, monthNumber - 1 + by, 1);
    month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function cells(value: string): Array<string | null> {
    const [year, monthNumber] = value.split('-').map(Number);
    const first = new Date(year, monthNumber - 1, 1).getDay();
    const count = new Date(year, monthNumber, 0).getDate();
    const listed: Array<string | null> = Array.from({ length: first }, () => null);
    for (let index = 1; index <= count; index += 1) {
      listed.push(`${value}-${String(index).padStart(2, '0')}`);
    }
    return listed;
  }

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
      + (showIncome ? incomeMarks(date).length : 0)
      + visibleBills(date).length;
  }

  function fitThree(node: HTMLElement, count: number) {
    let items = count;
    let frame = 0;

    const apply = () => {
      const chips = [...node.querySelectorAll(':scope > .bill-chip')].filter((chip): chip is HTMLElement => chip instanceof HTMLElement);
      if (items <= 3 || chips.length <= 3) {
        if (node.style.maxHeight !== '') node.style.maxHeight = '';
        return;
      }
      const first = chips[0];
      const third = chips[2];
      const height = Math.ceil(third.offsetTop + third.offsetHeight - first.offsetTop);
      if (height < 1) return;
      const current = node.style.maxHeight === '' ? NaN : parseFloat(node.style.maxHeight);
      if (Number.isNaN(current) || Math.abs(current - height) > 1) node.style.maxHeight = `${height}px`;
    };

    const schedule = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(apply);
    };

    const resize = new ResizeObserver(schedule);
    const watch = () => {
      resize.disconnect();
      resize.observe(node);
      for (const chip of node.querySelectorAll(':scope > .bill-chip')) resize.observe(chip);
    };
    const mutations = new MutationObserver(() => {
      watch();
      schedule();
    });
    const keepScrollbarClick = (event: MouseEvent) => {
      if (event.offsetX > node.clientWidth || event.offsetY > node.clientHeight) event.stopPropagation();
    };
    mutations.observe(node, { childList: true });
    node.addEventListener('click', keepScrollbarClick);
    watch();
    schedule();

    return {
      update(next: number) {
        items = next;
        watch();
        schedule();
      },
      destroy() {
        cancelAnimationFrame(frame);
        resize.disconnect();
        mutations.disconnect();
        node.removeEventListener('click', keepScrollbarClick);
      },
    };
  }

  let shown = $derived(cells(month).flatMap((date) => (date === null ? [] : [...incomeMarks(date), ...billMarks(date)])));

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
    });
  });

  $effect(() => {
    const current = month;
    let stale = false;
    api<{ paydays: Payday[] }>(`/api/pay-cycles/month?month=${current}&today=${todayISO()}`)
      .then((data) => {
        if (!stale) paydays = data.paydays;
      })
      .catch((err: unknown) => {
        if (!stale) message = err instanceof Error ? err.message : 'Could not load income.';
      });
    return () => {
      stale = true;
    };
  });

  $effect(() => {
    const current = month;
    let stale = false;
    api<{ occurrences: Occurrence[] }>(`/api/bills/calendar?month=${current}`)
      .then((data) => {
        if (!stale) occurrences = data.occurrences;
      })
      .catch((err: unknown) => {
        if (!stale) message = err instanceof Error ? err.message : 'Could not load the calendar.';
      });
    return () => {
      stale = true;
    };
  });
</script>

<div class="grid gap-4">
  <h1 class="m-0 text-4xl">Calendar</h1>
  <Note bind:message />
  <div class="flex flex-wrap items-center gap-2">
    <button class="btn" type="button" onclick={() => shift(-1)}>Previous month</button>
    <button class="btn" type="button" onclick={() => shift(1)}>Next month</button>
    <p class="m-0" data-month={month}>{formatMonth(month)}</p>
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
        {#each cells(month) as date, index (`${month}-${index}`)}
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
      <p class="kicker m-0">Month total</p>
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
