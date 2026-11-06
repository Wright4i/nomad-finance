<script lang="ts">
  import { onMount, tick } from 'svelte';
  import Globe from '../components/Globe.svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import { api } from '../lib/api';
  import { formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { wantGaugeLevels } from '../lib/wantGauge';
  import type { Dashboard, DuePie, EnvelopeCategory, EnvelopeGroup, PlannerStay, PlannerWidget } from '../lib/types';

  const WIDGETS = ['income', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'] as const;
  const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const SPAN_OPTIONS = [
    { span: 2, label: 'Single' },
    { span: 3, label: 'Half' },
    { span: 6, label: 'Full' },
  ] as const;

  type PlannerSpan = 2 | 3 | 6;
  type PlannerSpans = Record<PlannerWidget, PlannerSpan>;

  // Income shares the top row with net worth. Calendar, Trips, and Accounts sit three across.
  // Envelopes and Bills each take the row.
  const DEFAULT_SPANS: PlannerSpans = {
    income: 3,
    calendar: 2,
    trips: 2,
    accounts: 2,
    envelopes: 6,
    bills: 6,
  };

  const widgetLabel: Record<PlannerWidget, string> = {
    income: 'Income',
    calendar: 'Calendar',
    trips: 'Trips',
    accounts: 'Accounts',
    envelopes: 'Envelopes',
    bills: 'Bills',
  };

  const widgetPage: Record<PlannerWidget, string> = {
    income: '/pay-cycles',
    calendar: '/calendar',
    trips: '/trips',
    accounts: '/accounts',
    envelopes: '/envelopes',
    bills: '/bills',
  };

  const typeLabel: Record<string, string> = {
    checking: 'Checking',
    savings: 'Savings',
    cash: 'Cash',
    credit: 'Credit',
    loan: 'Loan',
    fuel: 'Fuel',
    other: 'Other',
  };

  const groupLabel: Record<EnvelopeGroup['group'], string> = {
    needs: 'Needs',
    wants: 'Wants',
    savings: 'Savings',
  };

  function isWidget(value: string): value is PlannerWidget {
    return (WIDGETS as readonly string[]).includes(value);
  }

  function normalize(input: readonly string[] | undefined): PlannerWidget[] {
    if (!input || input.length !== WIDGETS.length) return [...WIDGETS];
    const seen = new Set<string>();
    const next: PlannerWidget[] = [];
    for (const value of input) {
      if (!isWidget(value) || seen.has(value)) return [...WIDGETS];
      seen.add(value);
      next.push(value);
    }
    return next;
  }

  function isSpan(value: unknown): value is PlannerSpan {
    return value === 2 || value === 3 || value === 6;
  }

  function spanKey(value: PlannerSpans): string {
    return WIDGETS.map((id) => `${id}:${value[id]}`).join(',');
  }

  function normalizeSpans(input: unknown): PlannerSpans {
    if (!input || typeof input !== 'object' || Array.isArray(input)) return { ...DEFAULT_SPANS };
    const record = input as Record<string, unknown>;
    if (Object.keys(record).length !== WIDGETS.length) return { ...DEFAULT_SPANS };
    const next: PlannerSpans = { ...DEFAULT_SPANS };
    for (const id of WIDGETS) {
      const value = record[id];
      if (!isSpan(value)) return { ...DEFAULT_SPANS };
      next[id] = value;
    }
    return next;
  }

  function spansFromKey(key: string): PlannerSpans {
    const next: PlannerSpans = { ...DEFAULT_SPANS };
    const parts = key.split(',');
    if (parts.length !== WIDGETS.length) return { ...DEFAULT_SPANS };
    const seen = new Set<string>();
    for (const part of parts) {
      const splitAt = part.lastIndexOf(':');
      if (splitAt <= 0) return { ...DEFAULT_SPANS };
      const id = part.slice(0, splitAt);
      const raw = Number(part.slice(splitAt + 1));
      if (!isWidget(id) || seen.has(id) || !isSpan(raw)) return { ...DEFAULT_SPANS };
      seen.add(id);
      next[id] = raw;
    }
    return seen.size === WIDGETS.length ? next : { ...DEFAULT_SPANS };
  }

  function shiftMonth(base: string, by: number): string {
    const [year, monthNumber] = base.split('-').map(Number);
    const date = new Date(year, monthNumber - 1 + by, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function weekdayName(iso: string): string {
    const [year, monthNumber, dayNumber] = iso.split('-').map(Number);
    return weekdays[new Date(year, monthNumber - 1, dayNumber).getDay()] ?? '';
  }

  function placeLine(stay: PlannerStay): string {
    return [stay.city, stay.region].filter((part): part is string => Boolean(part)).join(', ');
  }

  function bucketMark(category: EnvelopeCategory, group: EnvelopeGroup['group']): string {
    if (category.role === 'locked' && category.billType) return category.billType;
    return group;
  }

  type Paint = { type: string; amountMinor: number; full: boolean; d: string; pullX: string; pullY: string };

  function paint(pie: DuePie): Paint[] {
    const total = pie.slices.reduce((sum, slice) => sum + slice.amountMinor, 0);
    let cursor = 0;
    return pie.slices.map((slice) => {
      const sweep = total > 0 ? slice.amountMinor / total : 0;
      const full = sweep >= 0.9999;
      const pull = outward(cursor + sweep / 2);
      const drawn = {
        type: slice.type,
        amountMinor: slice.amountMinor,
        full,
        d: full ? '' : wedge(cursor, sweep),
        pullX: pull.x,
        pullY: pull.y,
      };
      cursor += sweep;
      return drawn;
    });
  }

  function outward(turn: number): { x: string; y: string } {
    const angle = (turn * 2 - 0.5) * Math.PI;
    const distance = 6;
    const px = (value: number): string => {
      const rounded = Math.round(value * 100) / 100;
      return `${Math.abs(rounded) < 0.005 ? 0 : rounded}px`;
    };
    return { x: px(Math.cos(angle) * distance), y: px(Math.sin(angle) * distance) };
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
  let message = $state('');
  let monthOffset = $state(0);
  let gauge = $state<EnvelopeGroup['group'] | null>(null);
  let gaugeDialog: HTMLDialogElement | undefined = $state();
  let gaugeToken = 0;
  let order = $state<PlannerWidget[]>([...WIDGETS]);
  let saved = $state(WIDGETS.join(','));
  let spans = $state<PlannerSpans>({ ...DEFAULT_SPANS });
  let savedSpans = $state(spanKey(DEFAULT_SPANS));
  let sizing = $state<PlannerWidget | null>(null);
  let spanDialog: HTMLDialogElement | undefined = $state();
  let spanChain: Promise<void> = Promise.resolve();
  let dragId = $state<string | null>(null);
  let drag: { id: string; pointerId: number; dx: number; dy: number } | null = null;
  let pieFocus = $state<{ currency: string; type: string; pull: boolean } | null>(null);

  function fineHover(): boolean {
    return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  }

  function focusSlice(currency: string, type: string, pull: boolean): void {
    if (!fineHover()) return;
    pieFocus = { currency, type, pull };
  }

  function clearSlice(): void {
    pieFocus = null;
  }

  function sliceOn(currency: string, type: string): boolean {
    return pieFocus?.currency === currency && pieFocus.type === type;
  }

  function slicePulled(currency: string, type: string): boolean {
    return sliceOn(currency, type) && pieFocus?.pull === true;
  }

  function sliceStyle(slice: Paint, stroked: boolean): string {
    const tone = `fill: var(--tone-${slice.type}); --pull-x: ${slice.pullX}; --pull-y: ${slice.pullY}`;
    return stroked ? `${tone}; stroke: var(--bg); stroke-width: 0.6` : tone;
  }

  let month = $derived(shiftMonth(todayISO().slice(0, 7), monthOffset));
  let pies = $derived(data?.duePies[month] ?? []);
  let isPayday = $derived(Boolean(data?.payday && data.payday.on === data.today));
  let gaugeRows = $derived(
    data && gauge
      ? data.envelopes.categories.filter((category) => category.group === gauge)
      : [],
  );

  async function load(): Promise<void> {
    try {
      const params = new URLSearchParams({ today: todayISO() });
      const sheet = await api<Dashboard>(`/api/dashboard?${params}`);
      data = sheet;
      const next = normalize(sheet.plannerOrder);
      order = next;
      saved = next.join(',');
      const nextSpans = normalizeSpans(sheet.plannerSpans);
      spans = nextSpans;
      savedSpans = spanKey(nextSpans);
    } catch (err) {
      message = err instanceof Error ? err.message : 'Could not load the planner.';
    }
  }

  async function persist(next: PlannerWidget[]): Promise<void> {
    const key = next.join(',');
    if (key === saved) return;
    try {
      await api('/api/settings', { method: 'PATCH', body: { plannerOrder: next } });
      saved = key;
    } catch (err) {
      message = err instanceof Error ? err.message : 'Could not save the layout.';
      order = normalize(saved.split(','));
    }
  }

  function widgetUnder(x: number, y: number, selfId: string): string | null {
    for (const node of document.elementsFromPoint(x, y)) {
      const host = node.closest<HTMLElement>('[data-widget]');
      const id = host?.dataset.widget;
      if (id && id !== selfId) return id;
    }
    return null;
  }

  function place(x: number, y: number, hit: boolean): void {
    if (!drag) return;
    const widget = document.querySelector<HTMLElement>(`[data-widget="${drag.id}"]`);
    if (!widget) return;
    widget.style.transform = 'none';
    const laid = widget.getBoundingClientRect();
    widget.style.transform = `translate(${x - drag.dx - laid.left}px, ${y - drag.dy - laid.top}px)`;
    if (!hit) return;
    const over = widgetUnder(x, y, drag.id);
    if (!over || !isWidget(over)) return;
    const from = order.indexOf(drag.id as PlannerWidget);
    const to = order.indexOf(over);
    if (from < 0 || to < 0 || from === to) return;
    const next = order.slice();
    const item = next[from];
    if (!item) return;
    next.splice(from, 1);
    next.splice(to, 0, item);
    order = next;
    const id = drag.id;
    void tick().then(() => {
      if (drag && drag.id === id) place(x, y, false);
    });
  }

  function grabDown(event: PointerEvent): void {
    if (event.button !== 0) return;
    const button = event.currentTarget;
    if (!(button instanceof HTMLElement)) return;
    const widget = button.closest<HTMLElement>('[data-widget]');
    const id = widget?.dataset.widget;
    if (!widget || !id) return;
    const rect = widget.getBoundingClientRect();
    drag = { id, pointerId: event.pointerId, dx: event.clientX - rect.left, dy: event.clientY - rect.top };
    dragId = id;
    widget.classList.add('dragging');
    window.addEventListener('pointermove', grabMove);
    window.addEventListener('pointerup', grabUp);
    window.addEventListener('pointercancel', grabUp);
    try {
      button.setPointerCapture(event.pointerId);
    } catch {
      // Window listeners still follow the pointer when capture is unavailable.
    }
  }

  function grabMove(event: PointerEvent): void {
    if (!drag || event.pointerId !== drag.pointerId) return;
    event.preventDefault();
    place(event.clientX, event.clientY, true);
  }

  function grabUp(event: PointerEvent): void {
    if (!drag || event.pointerId !== drag.pointerId) return;
    window.removeEventListener('pointermove', grabMove);
    window.removeEventListener('pointerup', grabUp);
    window.removeEventListener('pointercancel', grabUp);
    const widget = document.querySelector<HTMLElement>(`[data-widget="${drag.id}"]`);
    widget?.classList.remove('dragging');
    if (widget) widget.style.transform = '';
    const next = order.slice();
    drag = null;
    dragId = null;
    void persist(next);
  }

  function persistSpans(next: PlannerSpans): void {
    const key = spanKey(next);
    spanChain = spanChain
      .catch(() => undefined)
      .then(async () => {
        if (key === savedSpans) return;
        try {
          await api('/api/settings', { method: 'PATCH', body: { plannerSpans: next } });
          savedSpans = key;
        } catch (err) {
          if (spanKey(spans) !== key) return;
          message = err instanceof Error ? err.message : 'Could not save the layout.';
          spans = spansFromKey(savedSpans);
        }
      });
  }

  function chooseSpan(span: PlannerSpan): void {
    if (!sizing || spans[sizing] === span) return;
    const next = { ...spans, [sizing]: span };
    spans = next;
    persistSpans(next);
  }

  function openSpan(id: PlannerWidget): void {
    sizing = id;
    void tick().then(() => {
      if (!spanDialog || spanDialog.open) return;
      spanDialog.showModal();
    });
  }

  function keepDragOff(event: PointerEvent): void {
    event.stopPropagation();
  }

  function openGauge(group: EnvelopeGroup['group']): void {
    gaugeToken += 1;
    gauge = group;
    gaugeDialog?.showModal();
  }

  function closeGauge(): void {
    gaugeDialog?.close();
  }

  function onGaugeClose(): void {
    const token = gaugeToken;
    const dialog = gaugeDialog;
    let settled = false;
    const finish = () => {
      if (settled || token !== gaugeToken || dialog?.open) return;
      settled = true;
      gauge = null;
    };
    if (!dialog) {
      finish();
      return;
    }
    const onEnd = (event: TransitionEvent) => {
      if (event.target !== dialog || event.propertyName !== 'opacity') return;
      dialog.removeEventListener('transitionend', onEnd);
      finish();
    };
    dialog.addEventListener('transitionend', onEnd);
    window.setTimeout(finish, 260);
  }

  function money(minor: number): string {
    return formatMinor(minor, data?.baseCurrency ?? '', data?.exponent ?? 2);
  }

  onMount(load);
</script>

<div class="grid gap-4">
  <h1 class="m-0 text-3xl" class:display-phrase={data?.headingItalic === true}>{data?.heading ?? 'Planner'}</h1>
  <Note bind:message />

  {#if data}
    <div class="planner-board" data-order={order.join(',')} data-saved={saved} data-spans={savedSpans}>
      <section class="hero planner-net">
        <p class="kicker">Net worth</p>
        <p class="figure">{money(data.netWorthMinor ?? 0)}</p>
        <p class="muted planner-sides m-0">
          Assets {money(data.assetsMinor)}
          <span aria-hidden="true">·</span>
          Liabilities <span class="coral">{money(data.liabilitiesMinor)}</span>
        </p>
      </section>

      {#each order as id (id)}
        <article
          class="planner-widget card"
          class:dragging={dragId === id}
          data-widget={id}
          data-section={id}
          data-span={spans[id]}
        >
          <button
            class="planner-grab"
            type="button"
            aria-label={`Move ${widgetLabel[id]}`}
            onpointerdown={grabDown}
          >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true">
              <circle cx="4" cy="3" r="1.15" /><circle cx="10" cy="3" r="1.15" />
              <circle cx="4" cy="7" r="1.15" /><circle cx="10" cy="7" r="1.15" />
              <circle cx="4" cy="11" r="1.15" /><circle cx="10" cy="11" r="1.15" />
            </svg>
          </button>
          <button
            class="planner-size"
            type="button"
            aria-label={`Size ${widgetLabel[id]}`}
            onpointerdown={keepDragOff}
            onclick={() => openSpan(id)}
          >
            <Glyph name="wrench" mini />
          </button>

          <div class="planner-widget-body">
            {#if id === 'income'}
              <div class="planner-income" class:payday-mark={isPayday}>
                <a class="planner-widget-title" href={widgetPage.income} onclick={(event) => follow(event, widgetPage.income)}>Income</a>
                <a class="planner-link" href={widgetPage.income} onclick={(event) => follow(event, widgetPage.income)}>
                  {#if data.payday}
                    <p class="m-0"><strong>{data.payday.payor}</strong></p>
                    <p class="muted m-0" data-payday={data.payday.on}>{formatDate(data.payday.on)}</p>
                    <p class="payday-amount m-0">
                      {data.payday.paycheckMinor === null ? '—' : money(data.payday.paycheckMinor)}
                    </p>
                    {#if data.payorCount > 1}
                      <p class="muted m-0">{data.payorCount} payors</p>
                    {/if}
                  {:else}
                    <p class="muted m-0">No payday yet</p>
                  {/if}
                  <p class="muted m-0">Still coming in <span class="money">{money(data.incomeComing[month] ?? 0)}</span></p>
                </a>
              </div>
            {:else if id === 'calendar'}
              <div class="planner-calendar">
                <a class="planner-widget-title" href={widgetPage.calendar} onclick={(event) => follow(event, widgetPage.calendar)}>Calendar</a>
                <a
                  class="planner-day day today"
                  href={widgetPage.calendar}
                  data-today={data.today}
                  onclick={(event) => follow(event, widgetPage.calendar)}
                >
                  <strong><span class="day-week">{weekdayName(data.today)}&nbsp;</span>{Number(data.today.slice(8))}</strong>
                </a>
              </div>
            {:else if id === 'trips'}
              <a class="planner-widget-title" href={widgetPage.trips} onclick={(event) => follow(event, widgetPage.trips)}>Trips</a>
              <a
                class="planner-stay"
                href={data.stay ? `/trips/${data.stay.id}` : widgetPage.trips}
                onclick={(event) => follow(event, data?.stay ? `/trips/${data.stay.id}` : widgetPage.trips)}
              >
                {#if data.stay}
                  <p class="m-0 type-label">
                    <Glyph name="lodging" mini />
                    <strong>{data.stay.placeName}</strong>
                  </p>
                  {#if placeLine(data.stay)}
                    <p class="muted m-0">{placeLine(data.stay)}</p>
                  {/if}
                  {#if data.stay.latitude && data.stay.longitude}
                    <Globe
                      compact
                      flat
                      quiet
                      pins={[{
                        id: data.stay.id,
                        latitude: data.stay.latitude,
                        longitude: data.stay.longitude,
                        placeName: data.stay.placeName,
                        city: data.stay.city,
                        region: data.stay.region,
                      }]}
                      selectedId={data.stay.id}
                    />
                  {/if}
                {:else}
                  <p class="muted m-0">No current stay</p>
                {/if}
              </a>
            {:else if id === 'accounts'}
              <a class="planner-widget-title" href={widgetPage.accounts} onclick={(event) => follow(event, widgetPage.accounts)}>Accounts</a>
              <a class="account-totals" href={widgetPage.accounts} onclick={(event) => follow(event, widgetPage.accounts)}>
                {#each data.accountTotals as column (`${column.type}:${column.side}`)}
                  <span class="account-total" data-account-type={column.type} data-side={column.side}>
                    <span class="kicker">{typeLabel[column.type] ?? column.type}</span>
                    <span class="money" class:teal={column.side === 'asset'} class:coral={column.side === 'liability'}>
                      {money(column.balanceMinor)}
                    </span>
                  </span>
                {/each}
              </a>
            {:else if id === 'envelopes'}
              <a class="planner-widget-title" href={widgetPage.envelopes} onclick={(event) => follow(event, widgetPage.envelopes)}>Envelopes</a>
              {#if data.envelopes.payors.length === 0}
                <p class="muted m-0">Add a pay cycle before assigning.</p>
              {:else}
                <div class="planner-gauges">
                  {#each data.envelopes.groups as item (item.group)}
                    {@const levels = wantGaugeLevels(item.assignedMinor, item.targetMinor, 0)}
                    <button
                      class="want-gauge"
                      class:over={levels.over}
                      class:neutral={levels.neutral}
                      type="button"
                      style={`--tone: var(--tone-${item.group}); --assigned: ${levels.assignedLevel}; --preview: 0`}
                      onclick={() => openGauge(item.group)}
                    >
                      <div class="want-gauge-meter">
                        <div class="want-gauge-track">
                          <span class="want-gauge-assigned"></span>
                        </div>
                        {#if levels.over}
                          <span class="want-gauge-over"></span>
                        {/if}
                      </div>
                      <div class="want-gauge-readout">
                        <p class="want-gauge-name m-0">{groupLabel[item.group]} · {item.targetPercent}%</p>
                        <p class="want-gauge-line m-0"><span>Assigned</span> <strong>{money(item.assignedMinor)}</strong></p>
                        <p class="want-gauge-line m-0"><span>Max</span> <strong>{money(item.targetMinor)}</strong></p>
                        {#if levels.over}
                          <p class="want-gauge-warning m-0">Over <strong>{money(levels.overMinor)}</strong></p>
                        {/if}
                      </div>
                    </button>
                  {/each}
                </div>
                <p class="envelope-available">
                  <span>Available to assign</span>
                  <span class="money">{money(data.envelopes.availableMinor)}</span>
                  <span class="muted">
                    {money(data.envelopes.assignedMinor)} assigned of {money(data.envelopes.poolMinor)}
                  </span>
                </p>
                {#if data.envelopes.celebrate}
                  <p class="celebrate m-0" aria-live="polite">Every dollar for this month is assigned.</p>
                {/if}
              {/if}
            {:else}
              <div class="planner-bills">
                <div class="planner-bills-head">
                  <a class="planner-widget-title" href={widgetPage.bills} onclick={(event) => follow(event, widgetPage.bills)}>Bills</a>
                  <div class="month-squares" role="group" aria-label="Month">
                    <button class="choice month-square" class:on={monthOffset === 0} type="button" aria-pressed={monthOffset === 0} onclick={() => (monthOffset = 0)}>Current Month</button>
                    <button class="choice month-square" class:on={monthOffset === 1} type="button" aria-pressed={monthOffset === 1} onclick={() => (monthOffset = 1)}>Next Month</button>
                  </div>
                </div>
                <p class="muted m-0">{formatMonth(month)}</p>
                {#if pies.length === 0}
                  <p class="muted m-0">No bills due this month.</p>
                {:else}
                  <div class="bill-charts">
                    {#each pies as pie (pie.currency)}
                      <div class="bill-chart">
                        <div class="bill-pie-cell">
                          <svg class="bill-pie" viewBox="0 0 100 100" aria-hidden="true">
                            {#each paint(pie) as slice (slice.type)}
                              {#if slice.full}
                                <circle
                                  class="bill-slice"
                                  role="presentation"
                                  data-type={slice.type}
                                  data-on={sliceOn(pie.currency, slice.type) ? 'true' : undefined}
                                  data-pulled={slicePulled(pie.currency, slice.type) ? 'true' : undefined}
                                  cx="50"
                                  cy="50"
                                  r="49"
                                  style={sliceStyle(slice, false)}
                                  onmouseenter={() => focusSlice(pie.currency, slice.type, false)}
                                  onmouseleave={clearSlice}
                                />
                              {:else}
                                <path
                                  class="bill-slice"
                                  role="presentation"
                                  data-type={slice.type}
                                  data-on={sliceOn(pie.currency, slice.type) ? 'true' : undefined}
                                  data-pulled={slicePulled(pie.currency, slice.type) ? 'true' : undefined}
                                  d={slice.d}
                                  style={sliceStyle(slice, true)}
                                  onmouseenter={() => focusSlice(pie.currency, slice.type, false)}
                                  onmouseleave={clearSlice}
                                />
                              {/if}
                            {/each}
                          </svg>
                        </div>
                        <ul class="bill-legend" aria-label="Due by bill type">
                          {#each pie.slices as slice (slice.type)}
                            <li
                              data-type={slice.type}
                              data-on={sliceOn(pie.currency, slice.type) ? 'true' : undefined}
                              onmouseenter={() => focusSlice(pie.currency, slice.type, true)}
                              onmouseleave={clearSlice}
                            >
                              <span class="bill-swatch" style={`--tone: var(--tone-${slice.type})`}></span>
                              <span>{slice.type}</span>
                              <span class="money">{money(slice.amountMinor)}</span>
                            </li>
                          {/each}
                        </ul>
                      </div>
                    {/each}
                  </div>
                {/if}
              </div>
            {/if}
          </div>
        </article>
      {/each}
    </div>
  {/if}
</div>

<dialog class="modal wide gauge-modal" bind:this={gaugeDialog} aria-labelledby="gauge-title" onclose={onGaugeClose}>
  {#if gauge && data}
    <div class="modal-body">
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
            {@const mark = bucketMark(category, gauge)}
            <tr data-role={category.role ?? 'goal'}>
              <td>
                <span class="bucket-mark" data-glyph={mark} style={`--tone: var(--tone-${mark})`}>
                  <Glyph name={mark} mini />
                  <span>{category.name}</span>
                </span>
                {#if category.bills && category.bills.length > 0}
                  <ul class="m-0 text-sm muted">
                    {#each category.bills as bill (bill.id)}
                      <li>{bill.name} · {money(bill.amountMinor)}</li>
                    {/each}
                  </ul>
                {/if}
              </td>
              <td class="text-right money">{money(category.amountMinor)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
      {#if gaugeRows.length === 0}
        <p class="muted m-0">No buckets in this group.</p>
      {/if}
    </div>
    <div class="modal-actions">
      <button class="btn" type="button" onclick={closeGauge}>Close</button>
    </div>
  {/if}
</dialog>

<dialog class="modal" bind:this={spanDialog} aria-labelledby="span-title" onclose={() => (sizing = null)}>
  {#if sizing}
    <div class="modal-body">
      <h2 id="span-title" class="m-0 text-2xl">Size {widgetLabel[sizing]}</h2>
      <div class="span-toggle" role="radiogroup" aria-label={`Size ${widgetLabel[sizing]}`}>
        {#each SPAN_OPTIONS as option (option.span)}
          <label class="choice" class:on={spans[sizing] === option.span}>
            <input
              type="radio"
              name="planner-span"
              value={option.span}
              checked={spans[sizing] === option.span}
              onchange={() => chooseSpan(option.span)}
            />
            {option.label}
          </label>
        {/each}
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn" type="button" onclick={() => spanDialog?.close()}>Close</button>
    </div>
  {/if}
</dialog>
