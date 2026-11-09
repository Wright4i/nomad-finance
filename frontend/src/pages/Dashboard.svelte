<script lang="ts">
  import { onMount, tick } from 'svelte';
  import CalendarEntryDialog from '../components/CalendarEntryDialog.svelte';
  import Globe from '../components/Globe.svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { api } from '../lib/api';
  import { dayChips, filterPlannerDayChips, plannerDayDates, type DayBill, type DayPayday, type DayStay } from '../lib/daySquare';
  import { fitThree } from '../lib/fitThree';
  import Dollars from '../components/Dollars.svelte';
  import { dollarClass, formatChipDate, formatDate, formatMinor, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import { wantGaugeGoalPercent, wantGaugeLevels } from '../lib/wantGauge';
  import type { CalendarEntry, Dashboard, DuePie, EnvelopeCategory, EnvelopeGroup, PlannerLayoutId, PlannerStay, PlannerWidget } from '../lib/types';

  const WIDGETS = ['income', 'calendar', 'trips', 'accounts', 'envelopes', 'bills'] as const;
  const LAYOUT_ORDER = ['net', ...WIDGETS] as const;
  const SPAN_LAYOUT = LAYOUT_ORDER;
  const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  type PlannerSpan = 1 | 2 | 3 | 4 | 5 | 6;
  type PlannerSpans = Record<PlannerLayoutId, PlannerSpan>;

  // Income shares the top row with net worth. Calendar, Trips, and Accounts sit three across.
  // Budget and Bills each take the row.
  const DEFAULT_SPANS: PlannerSpans = {
    net: 3,
    income: 3,
    calendar: 2,
    trips: 2,
    accounts: 2,
    envelopes: 6,
    bills: 6,
  };

  const layoutLabel: Record<PlannerLayoutId, string> = {
    net: 'Net worth',
    income: 'Income',
    calendar: 'Calendar',
    trips: 'Trips',
    accounts: 'Accounts',
    envelopes: 'Budget',
    bills: 'Bills',
  };

  const widgetPage: Record<PlannerWidget, string> = {
    income: '/pay-cycles',
    calendar: '/calendar',
    trips: '/trips',
    accounts: '/accounts',
    envelopes: '/budget',
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

  function normalize(input: readonly string[] | undefined): PlannerLayoutId[] {
    if (!input || input.length !== LAYOUT_ORDER.length) return [...LAYOUT_ORDER];
    const seen = new Set<string>();
    const next: PlannerLayoutId[] = [];
    for (const value of input) {
      if (!isLayoutId(value) || seen.has(value)) return [...LAYOUT_ORDER];
      seen.add(value);
      next.push(value);
    }
    return next;
  }

  function isSpan(value: unknown): value is PlannerSpan {
    return value === 1 || value === 2 || value === 3 || value === 4 || value === 5 || value === 6;
  }

  function isLayoutId(value: string): value is PlannerLayoutId {
    return (SPAN_LAYOUT as readonly string[]).includes(value);
  }

  function spanKey(value: PlannerSpans): string {
    return SPAN_LAYOUT.map((id) => `${id}:${value[id]}`).join(',');
  }

  function normalizeSpans(input: unknown): PlannerSpans {
    if (!input || typeof input !== 'object' || Array.isArray(input)) return { ...DEFAULT_SPANS };
    const record = input as Record<string, unknown>;
    if (Object.keys(record).length !== SPAN_LAYOUT.length) return { ...DEFAULT_SPANS };
    const next: PlannerSpans = { ...DEFAULT_SPANS };
    for (const id of SPAN_LAYOUT) {
      const value = record[id];
      if (!isSpan(value)) return { ...DEFAULT_SPANS };
      next[id] = value;
    }
    return next;
  }

  function spansFromKey(key: string): PlannerSpans {
    const next: PlannerSpans = { ...DEFAULT_SPANS };
    const parts = key.split(',');
    if (parts.length !== SPAN_LAYOUT.length) return { ...DEFAULT_SPANS };
    const seen = new Set<string>();
    for (const part of parts) {
      const splitAt = part.lastIndexOf(':');
      if (splitAt <= 0) return { ...DEFAULT_SPANS };
      const id = part.slice(0, splitAt);
      const raw = Number(part.slice(splitAt + 1));
      if (!isLayoutId(id) || seen.has(id) || !isSpan(raw)) return { ...DEFAULT_SPANS };
      seen.add(id);
      next[id] = raw;
    }
    return seen.size === SPAN_LAYOUT.length ? next : { ...DEFAULT_SPANS };
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
  let gauge = $state<EnvelopeGroup['group'] | null>(null);
  let gaugeDialog: HTMLDialogElement | undefined = $state();
  let gaugeToken = 0;
  let order = $state<PlannerLayoutId[]>([...LAYOUT_ORDER]);
  let saved = $state(LAYOUT_ORDER.join(','));
  let spans = $state<PlannerSpans>({ ...DEFAULT_SPANS });
  let savedSpans = $state(spanKey(DEFAULT_SPANS));
  let sizing = $state<PlannerLayoutId | null>(null);
  let spanDialog: HTMLDialogElement | undefined = $state();
  let spanChain: Promise<void> = Promise.resolve();
  let orderChain: Promise<void> = Promise.resolve();
  let phone = $state(false);
  let dragId = $state<PlannerLayoutId | null>(null);
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

  let pies = $derived(data?.duePies ?? []);
  let isPayday = $derived(Boolean(data?.payday && data.payday.on === data.today));
  let gaugeRows = $derived(
    data && gauge
      ? data.envelopes.categories.filter((category) => category.group === gauge)
      : [],
  );

  let stays = $state<DayStay[]>([]);
  let paydays = $state<DayPayday[]>([]);
  let dayBills = $state<DayBill[]>([]);
  let entries = $state<CalendarEntry[]>([]);
  let entryOpen = $state(false);
  let editing = $state<CalendarEntry | null>(null);
  let plannerShowStays = $state(true);
  let plannerShowBills = $state(true);
  let plannerShowIncome = $state(true);
  let firstLiabilityAccountIndex = $derived(
    data ? data.accountTotals.findIndex((column) => column.side === 'liability') : -1,
  );
  let calendarSquares = $derived(
    data
      ? plannerDayDates(data.today, spans.calendar, data.windowStart, data.windowEnd).map((date) => ({
          date,
          chips: filterPlannerDayChips(
            dayChips(date, stays, paydays, dayBills, data.baseCurrency, data.exponent, entries),
            { stays: plannerShowStays, bills: plannerShowBills, income: plannerShowIncome },
          ),
        }))
      : [],
  );

  async function load(): Promise<void> {
    try {
      const today = todayISO();
      const start = session.user?.acctPeriodStart ?? `${today.slice(0, 7)}-01`;
      const end = session.user?.acctPeriodEnd ?? today;
      const range = `start=${start}&end=${end}`;
      const [sheet, tripData, payData, billData, entryData] = await Promise.all([
        api<Dashboard>(`/api/dashboard?today=${today}`),
        api<{ trips: DayStay[] }>(`/api/trips?today=${today}&fuel=0`),
        api<{ paydays: DayPayday[] }>(`/api/pay-cycles/month?${range}&today=${today}`),
        api<{ occurrences: DayBill[] }>(`/api/bills/calendar?${range}`),
        api<{ entries: CalendarEntry[] }>(`/api/calendar-entries?${range}`),
      ]);
      stays = tripData.trips;
      paydays = payData.paydays;
      dayBills = billData.occurrences;
      entries = entryData.entries;
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

  function persist(next: PlannerLayoutId[]): void {
    const key = next.join(',');
    orderChain = orderChain
      .catch(() => undefined)
      .then(async () => {
        if (key === saved) return;
        try {
          await api('/api/settings', { method: 'PATCH', body: { plannerOrder: next } });
          if (order.join(',') !== key) return;
          saved = key;
        } catch (err) {
          if (order.join(',') !== key) return;
          message = err instanceof Error ? err.message : 'Could not save the layout.';
          order = normalize(saved.split(','));
        }
      });
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
    if (!over || !isLayoutId(over)) return;
    const from = order.indexOf(drag.id as PlannerLayoutId);
    const to = order.indexOf(over as PlannerLayoutId);
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
    if (phone || !sizing || spans[sizing] === span) return;
    const next = { ...spans, [sizing]: span };
    spans = next;
    persistSpans(next);
  }

  function stepWidget(by: -1 | 1): void {
    if (!sizing) return;
    const from = order.indexOf(sizing);
    const to = from + by;
    if (from < 0 || to < 0 || to >= order.length) return;
    const next = order.slice();
    const item = next[from];
    const neighbor = next[to];
    if (!item || !neighbor) return;
    next[from] = neighbor;
    next[to] = item;
    order = next;
    persist(next);
  }

  function openSpan(id: PlannerLayoutId): void {
    sizing = id;
    void tick().then(() => {
      if (!spanDialog) return;
      sizing = id;
      if (!spanDialog.open) spanDialog.showModal();
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

  function openPlannerDay(event: MouseEvent): void {
    const target = event.target;
    if (target instanceof Element && target.closest('.calendar-entry, [data-calendar-entry], .planner-calendar-filter')) {
      event.preventDefault();
      return;
    }
    follow(event, widgetPage.calendar);
  }

  function togglePlannerCalendarFilter(event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    const button = event.currentTarget;
    if (!(button instanceof HTMLButtonElement)) return;
    const filter = button.dataset.filter;
    if (filter === 'stays') plannerShowStays = !plannerShowStays;
    if (filter === 'bills') plannerShowBills = !plannerShowBills;
    if (filter === 'income') plannerShowIncome = !plannerShowIncome;
  }

  function openEntry(event: MouseEvent, id: number): void {
    event.preventDefault();
    event.stopPropagation();
    const found = entries.find((item) => item.id === id);
    if (!found) return;
    editing = found;
    entryOpen = true;
  }

  onMount(() => {
    const query = window.matchMedia('(max-width: 767px)');
    const applyPhone = () => {
      phone = query.matches;
    };
    applyPhone();
    query.addEventListener('change', applyPhone);
    window.addEventListener('resize', applyPhone);
    void load();
    return () => {
      query.removeEventListener('change', applyPhone);
      window.removeEventListener('resize', applyPhone);
    };
  });
</script>

<div class="grid gap-4">
  <h1 class="m-0 text-3xl" class:display-phrase={data?.headingItalic === true}>{data?.heading ?? 'Planner'}</h1>
  <Note bind:message />

  {#if !data && message === ''}
    <Spinner />
  {:else if data}
    <div class="planner-board" data-order={order.join(',')} data-saved={saved} data-spans={savedSpans}>
      {#each order as id (id)}
        {#if id === 'net'}
          <section
            class="hero planner-net card"
            class:dragging={dragId === id}
            data-widget="net"
            data-section="net"
            data-span={spans.net}
          >
            <button
              class="planner-grab"
              type="button"
              aria-label={`Move ${layoutLabel.net}`}
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
              aria-label={`Size ${layoutLabel.net}`}
              onpointerdown={keepDragOff}
              onclick={() => openSpan('net')}
            >
              <Glyph name="wrench" mini />
            </button>
            <p class="kicker">Net worth</p>
            <p class="figure {dollarClass(data.netWorthMinor ?? 0)}">{formatMinor(data.netWorthMinor ?? 0, data.baseCurrency, data.exponent)}</p>
            <p class="muted planner-sides m-0">
              <span class="planner-assets">
                <span>Assets</span>
                <span class="planner-side-amount-line">
                  <Dollars class="planner-assets-amount" minor={data.assetsMinor} currency={data.baseCurrency} exponent={data.exponent} />
                  <span
                    class="planner-side-interest"
                    aria-label="Interest gained per month"
                  >
                    ({#if (data.interestGainedMinor ?? 0) > 0}+{/if}<Dollars
                      minor={data.interestGainedMinor ?? 0}
                      currency={data.baseCurrency}
                      exponent={data.exponent}
                      suffix="/mn"
                    />)
                  </span>
                </span>
              </span>
              <span class="planner-liabilities">
                <span>Liabilities</span>
                <span class="planner-side-amount-line">
                  <Dollars class="planner-liabilities-amount" minor={data.liabilitiesMinor} currency={data.baseCurrency} exponent={data.exponent} owe />
                  <span
                    class="planner-side-interest"
                    aria-label="Interest lost per month"
                  >
                    (<Dollars
                      minor={data.interestLostMinor ?? 0}
                      currency={data.baseCurrency}
                      exponent={data.exponent}
                      owe
                      suffix="/mn"
                    />)
                  </span>
                </span>
              </span>
            </p>
            <div class="planner-interest">
              <p class="planner-interest-gained m-0" data-interest="gained">
                <span class="planner-interest-label">Interest gained</span>
                <span class="planner-interest-amount"><Dollars minor={data.interestGainedMinor ?? 0} currency={data.baseCurrency} exponent={data.exponent} suffix="/mn" /></span>
              </p>
              <p class="planner-interest-lost m-0" data-interest="lost">
                <span class="planner-interest-label">Interest lost</span>
                <span class="planner-interest-amount"><Dollars minor={data.interestLostMinor ?? 0} currency={data.baseCurrency} exponent={data.exponent} owe suffix="/mn" /></span>
              </p>
            </div>
          </section>
        {:else}
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
            aria-label={`Move ${layoutLabel[id]}`}
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
            aria-label={`Size ${layoutLabel[id]}`}
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
                      {#if data.payday.paycheckMinor === null}
                        —
                      {:else}
                        <Dollars minor={data.payday.paycheckMinor} currency={data.baseCurrency} exponent={data.exponent} />
                      {/if}
                    </p>
                  {:else}
                    <p class="muted m-0">No payday yet</p>
                  {/if}
                </a>
                <p class="planner-income-coming muted m-0">
                  Still coming in <Dollars minor={data.incomeComing} currency={data.baseCurrency} exponent={data.exponent} />
                </p>
              </div>
            {:else if id === 'calendar'}
              <div class="planner-calendar">
                <div class="planner-calendar-head">
                  <a class="planner-widget-title" href={widgetPage.calendar} onclick={(event) => follow(event, widgetPage.calendar)}>Calendar</a>
                  <div class="planner-calendar-filters" role="group" aria-label="Show on the calendar">
                    <button
                      class="planner-calendar-filter"
                      type="button"
                      data-filter="stays"
                      title="Show Stays"
                      aria-pressed={plannerShowStays}
                      style="--tone: var(--sky)"
                      onclick={togglePlannerCalendarFilter}
                    >
                      <Glyph name="tree" mini />
                    </button>
                    <button
                      class="planner-calendar-filter"
                      type="button"
                      data-filter="bills"
                      title="Show Bills"
                      aria-pressed={plannerShowBills}
                      style="--tone: var(--coral)"
                      onclick={togglePlannerCalendarFilter}
                    >
                      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M7 4.5h7l4 4V19.5H7V4.5zM14 4.5v4h4M9.5 12.5h5M9.5 16h3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                      </svg>
                    </button>
                    <button
                      class="planner-calendar-filter"
                      type="button"
                      data-filter="income"
                      title="Show Income"
                      aria-pressed={plannerShowIncome}
                      style="--tone: var(--teal)"
                      onclick={togglePlannerCalendarFilter}
                    >
                      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 19V7M8 11l4-4 4 4M6 19.5h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                      </svg>
                    </button>
                  </div>
                </div>
                <div class="planner-days">
                  {#each calendarSquares as square (square.date)}
                    <a
                      class="planner-day day"
                      class:today={square.date === data.today}
                      href={widgetPage.calendar}
                      data-date={square.date}
                      data-today={square.date === data.today ? data.today : undefined}
                      onclick={openPlannerDay}
                    >
                      <strong><span class="day-week">{weekdayName(square.date)}&nbsp;</span>{Number(square.date.slice(8))}</strong>
                      <span class="day-items" class:day-scroll={square.chips.length > 3} use:fitThree={square.chips.length}>
                        {#each square.chips as chip (chip.key)}
                          {#if chip.kind === 'stay'}
                            <span class="bill-chip stay-chip" title={chip.name}>
                              <span class="bill-chip-line">
                                <Glyph name="tree" mini />
                                <span class="bill-chip-name">{chip.name}</span>
                              </span>
                              <span class="bill-chip-amount">{chip.caption}</span>
                            </span>
                          {:else if chip.kind === 'entry'}
                            <button
                              class="bill-chip entry-chip"
                              type="button"
                              data-calendar-entry={chip.id}
                              title={chip.name}
                              onclick={(event) => openEntry(event, chip.id)}
                              onkeydown={(event) => event.stopPropagation()}
                            >
                              <span class="bill-chip-line">
                                <Glyph name="entry" mini />
                                <span class="bill-chip-name">{chip.name}</span>
                              </span>
                            </button>
                          {:else if chip.kind === 'income'}
                            <span class="bill-chip income-chip" title={chip.name}>
                              <span class="bill-chip-line">
                                <Glyph name={chip.glyph} mini />
                                <span class="bill-chip-name">{chip.name}</span>
                              </span>
                              <span class="bill-chip-amount money {dollarClass(chip.amountMinor ?? 0)}">{formatMinor(chip.amountMinor ?? 0, chip.currency, chip.exponent)}</span>
                            </span>
                          {:else}
                            <span class="bill-chip" class:paid-chip={chip.paid} title={chip.name}>
                              <span class="bill-chip-line">
                                <Glyph name={chip.glyph} mini />
                                <span class="bill-chip-name">{chip.name}</span>
                              </span>
                              <span class="bill-chip-amount money {dollarClass(chip.amountMinor ?? 0)}">{formatMinor(chip.amountMinor ?? 0, chip.currency, chip.exponent)}</span>
                            </span>
                          {/if}
                        {/each}
                      </span>
                      {#if square.chips.length > 3}
                        <span class="day-more" aria-hidden="true">
                          <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 5v11M7.5 12.5 12 17.5 16.5 12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                          </svg>
                        </span>
                      {/if}
                    </a>
                  {/each}
                </div>
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
                      hideNames
                      hideLocations
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
              <div class="planner-accounts">
                <a class="planner-widget-title" href={widgetPage.accounts} onclick={(event) => follow(event, widgetPage.accounts)}>Accounts</a>
                <a class="account-totals" href={widgetPage.accounts} onclick={(event) => follow(event, widgetPage.accounts)}>
                {#each data.accountTotals as column, index (`${column.type}:${column.side}`)}
                  {#if index === firstLiabilityAccountIndex && firstLiabilityAccountIndex > 0}
                    <hr class="account-totals-split" aria-hidden="true" />
                  {/if}
                  <span class="account-total" data-account-type={column.type} data-side={column.side}>
                    <span class="kicker">{typeLabel[column.type] ?? column.type}</span>
                    <Dollars minor={column.balanceMinor} currency={data.baseCurrency} exponent={data.exponent} owe={column.side === 'liability'} />
                  </span>
                {/each}
                </a>
              </div>
            {:else if id === 'envelopes'}
              <a class="planner-widget-title" href={widgetPage.envelopes} onclick={(event) => follow(event, widgetPage.envelopes)}>Budget</a>
              {#if data.envelopes.payors.length === 0}
                <p class="muted m-0">Add a pay cycle before assigning.</p>
              {:else}
                <div class="planner-gauges">
                  {#each data.envelopes.groups as item (item.group)}
                    {@const levels = wantGaugeLevels(item.assignedMinor, item.targetMinor, 0)}
                    {@const goalPercent = wantGaugeGoalPercent(item.assignedMinor, item.targetMinor)}
                    <button
                      class="want-gauge"
                      class:over={levels.over}
                      class:neutral={levels.neutral}
                      type="button"
                      style={`--tone: var(--tone-${item.group}); --assigned: ${levels.assignedLevel}; --preview: 0`}
                      onclick={() => openGauge(item.group)}
                    >
                      <div class="want-gauge-meter">
                        <p class="want-gauge-current m-0" aria-hidden="true">
                          {#if goalPercent === null}—{:else}{goalPercent}%{/if}
                        </p>
                        <div class="want-gauge-track">
                          <span class="want-gauge-assigned"></span>
                          {#if levels.over}
                            <span class="want-gauge-over"></span>
                          {/if}
                        </div>
                      </div>
                      <div class="want-gauge-readout">
                        <p class="want-gauge-name m-0">{groupLabel[item.group]} · {item.targetPercent}%</p>
                        <p class="want-gauge-line m-0"><span>Assigned</span> <Dollars minor={item.assignedMinor} currency={data.baseCurrency} exponent={data.exponent} /></p>
                        <p class="want-gauge-line m-0"><span>Goal</span> <Dollars minor={item.targetMinor} currency={data.baseCurrency} exponent={data.exponent} /></p>
                        {#if levels.over}
                          <p class="want-gauge-warning m-0">Over <Dollars minor={levels.overMinor} currency={data.baseCurrency} exponent={data.exponent} owe /></p>
                        {/if}
                      </div>
                    </button>
                  {/each}
                </div>
                {#if data.windowEnd < data.today}
                  <p class="m-0">This period has closed.</p>
                {:else}
                <p class="envelope-available">
                  <span>Available to assign</span>
                  <Dollars minor={data.envelopes.availableMinor} currency={data.baseCurrency} exponent={data.exponent} plain />
                </p>
                {/if}
                {#if data.envelopes.celebrate}
                  <p class="celebrate m-0" aria-live="polite">Every dollar for this period is assigned.</p>
                {/if}
              {/if}
            {:else}
              <div class="planner-bills">
                <div class="planner-bills-head">
                  <a class="planner-widget-title" href={widgetPage.bills} onclick={(event) => follow(event, widgetPage.bills)}>Bills</a>
                </div>
                <p class="muted m-0" data-period={data.windowStart}>{formatChipDate(data.windowStart)} – {formatChipDate(data.windowEnd)}</p>
                {#if pies.length === 0}
                  <p class="muted m-0">No bills due this period.</p>
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
                              <Dollars minor={slice.amountMinor} currency={data.baseCurrency} exponent={data.exponent} owe />
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
        {/if}
      {/each}
    </div>
  {/if}
</div>

<dialog class="modal wide gauge-modal" bind:this={gaugeDialog} aria-labelledby="gauge-title" onclose={onGaugeClose}>
  {#if gauge && data}
    <div class="modal-body">
      <h2 id="gauge-title" class="m-0 text-2xl">{groupLabel[gauge]}</h2>
      <p class="muted">Amounts assigned in the period on screen.</p>
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
                      <li>{bill.name} · <Dollars minor={bill.amountMinor} currency={data.baseCurrency} exponent={data.exponent} /></li>
                    {/each}
                  </ul>
                {/if}
              </td>
              <td class="text-right"><Dollars minor={category.amountMinor} currency={data.baseCurrency} exponent={data.exponent} /></td>
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

<dialog class="modal" bind:this={spanDialog} aria-labelledby="span-title" onclose={() => { if (!spanDialog?.open) sizing = null; }}>
  {#if sizing}
    <div class="modal-body">
      <h2 id="span-title" class="m-0 text-2xl">Edit {layoutLabel[sizing]} layout</h2>
      <div class="span-moves">
        <button
          class="btn"
          type="button"
          disabled={order.indexOf(sizing) <= 0}
          onpointerdown={keepDragOff}
          onclick={() => stepWidget(-1)}
        >Move back</button>
        <button
          class="btn"
          type="button"
          disabled={order.indexOf(sizing) >= order.length - 1}
          onpointerdown={keepDragOff}
          onclick={() => stepWidget(1)}
        >Move forward</button>
      </div>
      <label class="span-slider" style={`--span: ${spans[sizing]}`}>
        <span class="span-slider-readout">
          <strong>{spans[sizing]}</strong>
          <span>of 6 columns</span>
        </span>
        <input
          type="range"
          min="1"
          max="6"
          step="1"
          value={spans[sizing]}
          aria-label={`Width of ${layoutLabel[sizing]}`}
          aria-valuemin={1}
          aria-valuemax={6}
          aria-valuenow={spans[sizing]}
          aria-valuetext={`${spans[sizing]} of 6 columns`}
          disabled={phone}
          oninput={(event) => {
            const input = event.currentTarget as HTMLInputElement;
            if (phone) {
              if (sizing) input.value = String(spans[sizing]);
              return;
            }
            const raw = Number(input.value);
            if (isSpan(raw)) chooseSpan(raw);
          }}
        />
        <span class="span-slider-ticks" aria-hidden="true">
          {#each [1, 2, 3, 4, 5, 6] as stop (stop)}
            <span class:on={spans[sizing] === stop}>{stop}</span>
          {/each}
        </span>
      </label>
    </div>
    <div class="modal-actions">
      <button class="btn" type="button" onclick={() => spanDialog?.close()}>Close</button>
    </div>
  {/if}
</dialog>

<CalendarEntryDialog bind:open={entryOpen} entry={editing} onsaved={load} />
