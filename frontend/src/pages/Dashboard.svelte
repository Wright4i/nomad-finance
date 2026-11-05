<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api } from '../lib/api';
  import { formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { Dashboard, DuePie, EnvelopeGroup, Trip } from '../lib/types';

  const groupLabel: Record<EnvelopeGroup['group'], string> = {
    needs: 'Needs',
    wants: 'Wants',
    savings: 'Savings',
  };

  function shareLabel(points: number): string {
    const text = (points / 100).toFixed(2).replace(/\.?0+$/, '');
    return `${text}%`;
  }

  function within(gauge: EnvelopeGroup): number {
    const cap = gauge.targetPercent * 100;
    if (cap <= 0) return 0;
    return Math.min(gauge.shareBasisPoints, cap) / cap;
  }

  function spill(gauge: EnvelopeGroup): number {
    const cap = gauge.targetPercent * 100;
    if (!gauge.over || cap <= 0) return 0;
    return Math.min(Math.max(gauge.shareBasisPoints - cap, 1), cap) / cap;
  }

  function shiftMonth(base: string, by: number): string {
    const [year, monthNumber] = base.split('-').map(Number);
    const date = new Date(year, monthNumber - 1 + by, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function shiftDate(iso: string, days: number): string {
    const [year, monthNumber, day] = iso.split('-').map(Number);
    const date = new Date(year, monthNumber - 1, day + days);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  type Paint = { type: string; amountMinor: number; full: boolean; d: string };

  function paint(pie: DuePie): Paint[] {
    const total = pie.slices.reduce((sum, slice) => sum + slice.amountMinor, 0);
    let cursor = 0;
    return pie.slices.map((slice) => {
      const sweep = total > 0 ? slice.amountMinor / total : 0;
      const full = sweep >= 0.9999;
      const drawn = { type: slice.type, amountMinor: slice.amountMinor, full, d: full ? '' : wedge(cursor, sweep) };
      cursor += sweep;
      return drawn;
    });
  }

  function wedge(start: number, sweep: number): string {
    const cx = 50;
    const cy = 50;
    const radius = 49;
    const point = (turn: number): [number, number] => {
      const angle = (turn * 2 - 0.5) * Math.PI;
      return [cx + radius * Math.cos(angle), cy + radius * Math.sin(angle)];
    };
    const [x0, y0] = point(start);
    const [x1, y1] = point(start + sweep);
    const large = sweep > 0.5 ? 1 : 0;
    const n = (value: number) => value.toFixed(3);
    return `M ${n(cx)} ${n(cy)} L ${n(x0)} ${n(y0)} A ${radius} ${radius} 0 ${large} 1 ${n(x1)} ${n(y1)} Z`;
  }

  let data = $state<Dashboard | null>(null);
  let trips = $state<Trip[]>([]);
  let message = $state('');
  let monthOffset = $state(0);
  let gauge = $state<EnvelopeGroup['group'] | null>(null);
  let gaugeDialog: HTMLDialogElement | undefined = $state();

  let month = $derived(shiftMonth(todayISO().slice(0, 7), monthOffset));

  let upcomingStays = $derived.by(() => {
    const today = todayISO();
    const last = shiftDate(today, 2);
    return trips
      .filter((trip) => trip.arriveOn >= today && trip.arriveOn <= last)
      .sort((left, right) => left.arriveOn.localeCompare(right.arriveOn) || left.id - right.id);
  });

  let pies = $derived(data?.duePies[month] ?? []);

  let gaugeRows = $derived(
    data && gauge
      ? data.envelopes.categories.filter((category) => category.group === gauge)
      : [],
  );

  async function load(): Promise<void> {
    try {
      const params = new URLSearchParams({ today: todayISO() });
      const [sheet, tripData] = await Promise.all([
        api<Dashboard>(`/api/dashboard?${params}`),
        api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}&fuel=0`),
      ]);
      data = sheet;
      trips = tripData.trips;
    } catch (err) {
      message = err instanceof Error ? err.message : 'Could not load the planner.';
    }
  }

  function openGauge(group: EnvelopeGroup['group']): void {
    gauge = group;
    gaugeDialog?.showModal();
  }

  function closeGauge(): void {
    gaugeDialog?.close();
    gauge = null;
  }

  onMount(load);
</script>

<div class="grid gap-6">
  <h1 class="m-0 text-4xl" class:display-phrase={data?.headingItalic === true}>{data?.heading ?? 'Planner'}</h1>

  <section class="hero">
    <p class="kicker">Net worth{data ? ` · ${data.baseCurrency}` : ''}</p>
    {#if data?.netWorthMinor === null}
      <p class="figure muted">—</p>
      <p>Add exchange rates before these accounts can share one total.</p>
    {:else}
      <p class="figure">{data ? formatMinor(data.netWorthMinor ?? 0, data.baseCurrency, data.exponent) : '…'}</p>
    {/if}
    {#if data}
      <div class="grid grid-cols-2 gap-3 mt-6">
        <div class="stat">
          <p class="kicker">Assets</p>
          <p class="text-2xl font-semibold teal m-0 mt-1 money">{formatMinor(data.assetsMinor, data.baseCurrency, data.exponent)}</p>
        </div>
        <div class="stat">
          <p class="kicker">Liabilities</p>
          <p class="text-2xl font-semibold coral m-0 mt-1 money">{formatMinor(data.liabilitiesMinor, data.baseCurrency, data.exponent)}</p>
        </div>
      </div>
    {/if}
  </section>

  {#if message}<p class="banner" aria-live="polite">{message}</p>{/if}

  {#each upcomingStays as stay (stay.id)}
    <a
      class="card grid gap-2 no-underline"
      style="--tone: var(--tone-lodging)"
      href={`/trips/${stay.id}`}
      onclick={(event) => follow(event, `/trips/${stay.id}`)}
    >
      <h2 class="group-title text-xl m-0">
        <Glyph name="lodging" mini />
        Upcoming Stay
      </h2>
      <p class="m-0"><strong>{stay.placeName}</strong></p>
      <p class="muted m-0">{formatDate(stay.arriveOn)}</p>
    </a>
  {/each}

  {#if data}
    <section class="grid gap-3" data-section="envelopes">
      <h2 class="group-title text-2xl m-0" style="--tone: var(--teal)">Envelopes</h2>
      {#if data.envelopes.payors.length === 0}
        <p class="muted m-0">Add a pay cycle before assigning.</p>
      {:else}
        <div class="grid gap-3">
          {#each data.envelopes.groups as item (item.group)}
            <button class="card gauge" type="button" style={`--tone: var(--tone-${item.group})`} onclick={() => openGauge(item.group)}>
                <div class="flex items-center justify-between gap-3">
                  <span class="type-label inline-flex items-center gap-2">
                    <Glyph name={item.group} mini />
                    {groupLabel[item.group]}
                  </span>
                  <span class="muted">{item.targetPercent}%</span>
                </div>
                <div
                  class="gauge-meter"
                  role="meter"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  aria-valuenow={item.shareBasisPoints / 100}
                  aria-label={`${groupLabel[item.group]} is ${shareLabel(item.shareBasisPoints)} of the money assigned in this month. The target is ${item.targetPercent} percent.`}
                  title={`Share of the money assigned in this month. The right side is past ${item.targetPercent}%.`}
                >
                  <span class="gauge-budget">
                    <span class="gauge-fill" style={`transform: scaleX(${within(item)})`}></span>
                  </span>
                  <span class="gauge-spill">
                    <span class="gauge-over" style={`transform: scaleX(${spill(item)})`}></span>
                  </span>
                </div>
                <p class="muted m-0 text-sm">
                  {formatMinor(item.amountMinor, data.baseCurrency, data.exponent)} · {shareLabel(item.shareBasisPoints)}
                  {#if item.over}<span class="coral">Over</span>{/if}
                </p>
            </button>
          {/each}
        </div>
        <article class="card">
          <div class="flex items-start justify-between gap-3">
            <div class="grid gap-1">
              <p class="kicker m-0">Available to assign</p>
              <p class="text-4xl m-0 money" style="font-family: var(--font-display)">{formatMinor(data.envelopes.availableMinor, data.baseCurrency, data.exponent)}</p>
              <p class="muted m-0">
                {formatMinor(data.envelopes.assignedMinor, data.baseCurrency, data.exponent)} assigned of {formatMinor(data.envelopes.poolMinor, data.baseCurrency, data.exponent)}
              </p>
            </div>
            <a class="btn" href="/envelopes" onclick={(event) => follow(event, '/envelopes')}>Assign</a>
          </div>
        </article>
        {#if data.envelopes.celebrate}
          <p class="banner celebrate m-0" aria-live="polite">Every dollar for this month is assigned.</p>
        {/if}
      {/if}
    </section>

    <section class="grid gap-3" data-section="bills">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <h2 class="group-title text-2xl m-0" style="--tone: var(--lime)">Bills</h2>
        <a class="btn" href="/bills" onclick={(event) => follow(event, '/bills')}>Bills</a>
      </div>
      <p class="muted m-0">{formatMonth(month)}</p>
      <div class="bill-board">
        <div class="month-squares" role="group" aria-label="Month">
          <button class="choice month-square" class:on={monthOffset === 0} type="button" aria-pressed={monthOffset === 0} onclick={() => (monthOffset = 0)}>Current Month</button>
          <button class="choice month-square" class:on={monthOffset === 1} type="button" aria-pressed={monthOffset === 1} onclick={() => (monthOffset = 1)}>Next Month</button>
        </div>
        {#if pies.length === 0}
          <p class="muted bill-empty m-0">No bills due this month.</p>
        {:else}
          <div class="bill-charts">
            {#each pies as pie (pie.currency)}
              <div class="bill-pie-cell">
                {#if pies.length > 1}
                  <p class="kicker m-0">{pie.currency}</p>
                {/if}
                <svg class="bill-pie" viewBox="0 0 100 100" aria-hidden="true">
                  {#each paint(pie) as slice (slice.type)}
                    {#if slice.full}
                      <circle data-type={slice.type} cx="50" cy="50" r="49" style={`fill: var(--tone-${slice.type})`} />
                    {:else}
                      <path data-type={slice.type} d={slice.d} style={`fill: var(--tone-${slice.type}); stroke: var(--bg); stroke-width: 0.6`} />
                    {/if}
                  {/each}
                </svg>
              </div>
              <ul class="bill-legend" aria-label={pies.length > 1 ? `Due in ${pie.currency}` : 'Due by bill type'}>
                {#each pie.slices as slice (slice.type)}
                  <li data-type={slice.type}>
                    <span class="bill-swatch" style={`--tone: var(--tone-${slice.type})`}></span>
                    <span>{slice.type}</span>
                    <span class="money">{formatMinor(slice.amountMinor, pie.currency, pie.exponent)}</span>
                  </li>
                {/each}
                {#if pie.leftOut && pie.leftOut.length > 0}
                  <li>Left out {pie.leftOut.join(', ')}</li>
                {/if}
              </ul>
            {/each}
          </div>
        {/if}
      </div>
    </section>
  {/if}

  {#if data && data.unconverted.length > 0}
    <section class="grid gap-3">
      <h2 class="group-title text-2xl" style="--tone: var(--amber)">Left out of the total</h2>
      {#each data.unconverted as account (account.accountId)}
        <a class="row stripe warn" href={`/accounts/${account.accountId}`} onclick={(event) => follow(event, `/accounts/${account.accountId}`)}>
          <span>
            <strong>{account.name}</strong>
            <span class="block muted text-sm">No {data.baseCurrency} rate for {account.currency}</span>
          </span>
          <strong class="amber money">{formatMinor(account.balanceMinor, account.currency, account.exponent)}</strong>
        </a>
      {/each}
    </section>
  {/if}
</div>

<dialog class="modal wide" bind:this={gaugeDialog} aria-labelledby="gauge-title" onclose={() => (gauge = null)}>
  {#if gauge && data}
    <h2 id="gauge-title" class="m-0 text-2xl">{groupLabel[gauge]}</h2>
    <p class="muted">Amounts assigned in the month on screen.</p>
    <table class="w-full">
      <thead>
        <tr>
          <th class="text-left">Bucket</th>
          <th class="text-right">Assigned</th>
        </tr>
      </thead>
      <tbody>
        {#each gaugeRows as category (category.id)}
          <tr>
            <td>
              {category.name}
              {#if category.bills && category.bills.length > 0}
                <ul class="m-0 text-sm muted">
                  {#each category.bills as bill (bill.id)}
                    <li>{bill.name} · {formatMinor(bill.amountMinor, data.baseCurrency, data.exponent)}</li>
                  {/each}
                </ul>
              {/if}
            </td>
            <td class="text-right money">{formatMinor(category.amountMinor, data.baseCurrency, data.exponent)}</td>
          </tr>
        {/each}
      </tbody>
    </table>
    {#if gaugeRows.length === 0}
      <p class="muted m-0">No buckets in this group.</p>
    {/if}
    <button class="btn mt-3" type="button" onclick={closeGauge}>Close</button>
  {/if}
</dialog>
