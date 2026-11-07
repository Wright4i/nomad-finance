<script lang="ts">
  import { onMount, tick } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import { api, explain } from '../lib/api';
  import { formatMinor, formatMonth, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { prefersReducedMotion } from '../lib/motion';
  import { follow, go } from '../lib/nav.svelte.ts';
  import type { Category, EnvelopeSheet, PeriodStep } from '../lib/types';

  const groups = [
    { id: 'needs', label: 'Needs' },
    { id: 'wants', label: 'Wants' },
    { id: 'savings', label: 'Savings' },
  ] as const;

  let sheet = $state<EnvelopeSheet | null>(null);
  let payCycleId = $state<number | null>(null);
  let periodId = $state<number | null>(null);
  let upcoming = $state(false);
  let drafts = $state<Record<number, string>>({});
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let celebrateEase = $state(false);
  let ready = $state(false);
  let showArchived = $state(false);
  let archived = $state<Category[]>([]);
  let sourcesDialog: HTMLDialogElement | undefined = $state();
  let recalculateDialog: HTMLDialogElement | undefined = $state();
  let saveTimer: ReturnType<typeof setTimeout> | undefined;

  let editable = $derived(sheet?.period?.editable === true);

  async function load(): Promise<void> {
    const params = new URLSearchParams({ today: todayISO() });
    if (payCycleId !== null) params.set('payCycleId', String(payCycleId));
    if (upcoming) params.set('upcoming', '1');
    else if (periodId !== null) params.set('periodId', String(periodId));
    const data = await api<EnvelopeSheet>(`/api/budget?${params}`);
    sheet = data;
    payCycleId = data.payCycleId;
    applyDrafts(data);
    ready = true;
  }

  function applyDrafts(data: EnvelopeSheet): void {
    const next: Record<number, string> = {};
    for (const category of data.categories) {
      next[category.id] = minorToInput(Math.max(0, category.amountMinor - (category.billMinor ?? 0)), data.exponent);
    }
    drafts = next;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
      ready = true;
    });
  });

  function step(target: PeriodStep | null): void {
    if (!target) return;
    upcoming = target.upcoming;
    periodId = target.upcoming ? null : target.id;
    message = '';
    void load().catch((err: unknown) => {
      message = explain(err).message;
    });
  }

  function inGroup(id: string): EnvelopeSheet['categories'] {
    return (sheet?.categories ?? [])
      .filter((category) => category.group === id)
      .sort((left, right) => Number(left.role === 'locked') - Number(right.role === 'locked') || left.name.localeCompare(right.name));
  }

  function archivedIn(id: string): Category[] {
    const shown = new Set((sheet?.categories ?? []).map((category) => category.id));
    return archived
      .filter((category) => category.group === id && category.archivedAt !== null && !shown.has(category.id))
      .sort((left, right) => left.name.localeCompare(right.name));
  }

  async function toggleArchived(): Promise<void> {
    showArchived = !showArchived;
    if (!showArchived) return;
    const data = await api<{ categories: Category[] }>(`/api/buckets?includeArchived=1&today=${todayISO()}`);
    archived = data.categories;
  }

  function autoOf(category: EnvelopeSheet['categories'][number]): number {
    return category.billMinor ?? 0;
  }

  function typedEnvelope(category: EnvelopeSheet['categories'][number]): number | null {
    if (!sheet) return null;
    const raw = (drafts[category.id] ?? '').trim();
    if (raw === '') return 0;
    return parseMajor(raw, sheet.exponent);
  }

  function targetOf(category: EnvelopeSheet['categories'][number]): number {
    const envelope = typedEnvelope(category);
    return autoOf(category) + (envelope ?? 0);
  }

  function holdOf(category: EnvelopeSheet['categories'][number]): number | null {
    const cap = category.target?.amountMinor ?? 0;
    if (cap > 0) return cap;
    if (category.role === 'locked') {
      const bills = category.billMinor ?? 0;
      return bills > 0 ? bills : null;
    }
    return null;
  }

  function levelOf(category: EnvelopeSheet['categories'][number], rows: EnvelopeSheet['categories']): number {
    const amount = category.amountMinor;
    if (amount <= 0) return 0;
    const hold = holdOf(category);
    if (hold !== null) return Math.min(1, amount / hold);
    const largest = Math.max(...rows.map((row) => row.amountMinor), 0);
    if (largest <= 0) return 0;
    return Math.min(1, amount / largest);
  }

  function queueSave(): void {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => void save(), 1000);
  }

  function saveNow(event: KeyboardEvent): void {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (saveTimer) clearTimeout(saveTimer);
    void save();
  }

  async function save(): Promise<void> {
    if (!sheet || !editable || sheet.payCycleId === null) return;
    fields = {};
    message = '';
    const open = sheet.categories.filter((category) => category.archivedAt === null);
    const amounts = open.map((category) => {
      const envelope = typedEnvelope(category);
      if (envelope === null) {
        fields[`amount-${category.id}`] = 'Enter an amount of zero or more.';
      }
      return { categoryId: category.id, amountMinor: autoOf(category) + (envelope ?? 0) };
    });
    if (Object.keys(fields).length > 0) return;

    saving = true;
    try {
      const planning = sheet.period?.upcoming === true;
      const data = await api<EnvelopeSheet>('/api/budget', {
        method: 'PUT',
        body: { today: todayISO(), payCycleId: sheet.payCycleId, amounts, upcoming: planning },
      });
      if (data.celebrate && !prefersReducedMotion()) {
        celebrateEase = true;
        await tick();
        document.querySelectorAll('.bucket-wave').forEach((node) => node.getBoundingClientRect());
      } else {
        celebrateEase = data.celebrate === true;
      }
      sheet = data;
      payCycleId = data.payCycleId;
      upcoming = data.period?.upcoming === true;
      periodId = null;
      applyDrafts(data);
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  async function confirmRecalculate(): Promise<void> {
    if (!sheet || !editable) return;
    saving = true;
    message = '';
    fields = {};
    try {
      const data = await api<EnvelopeSheet>('/api/budget/recalculate', {
        method: 'POST',
        body: {
          today: todayISO(),
          payCycleId: sheet.payCycleId,
          upcoming: sheet.period?.upcoming === true,
        },
      });
      sheet = data;
      payCycleId = data.payCycleId;
      upcoming = data.period?.upcoming === true;
      periodId = null;
      applyDrafts(data);
      recalculateDialog?.close();
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }
</script>

<div class="grid gap-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Budget</h1>
    <a class="btn primary" href="/buckets/new" onclick={(event) => { event.preventDefault(); go('/buckets/new'); }}>Add bucket</a>
  </div>
  <p class="muted m-0">Available to assign is income still coming in this month, plus every asset marked Include in Budget, minus what the buckets already hold. An assignment does not change an account balance.</p>
  <Note bind:message />

  {#if ready && sheet}
    {#if sheet.payors.length === 0}
      <p class="m-0">Add a pay cycle before assigning. <a href="/pay-cycles/new" onclick={(event) => { event.preventDefault(); go('/pay-cycles/new'); }}>New pay cycle</a></p>
    {/if}
      <section class="hero">
        <button class="available-open" type="button" data-action="available" onclick={() => sourcesDialog?.showModal()}>
          <p class="kicker m-0">Available to assign</p>
          <p class="figure">{formatMinor(sheet.availableMinor, sheet.baseCurrency, sheet.exponent)}</p>
        </button>
        <p class="muted m-0" title="Income still coming in this month, plus every asset marked Include in Budget.">
          {formatMinor(sheet.assignedMinor, sheet.baseCurrency, sheet.exponent)} assigned of {formatMinor(sheet.poolMinor, sheet.baseCurrency, sheet.exponent)}
        </p>
      </section>

      <dialog class="modal wide" bind:this={sourcesDialog} aria-labelledby="available-title">
        <div class="modal-body">
          <h2 id="available-title" class="m-0 text-2xl">Available to assign</h2>
          <section class="grid gap-2" data-source="income">
            <h3 class="m-0 text-lg">Still coming in</h3>
            {#each sheet.sources.income as row (row.id)}
              <p class="source-row" style={`--tone: var(--tone-${row.length})`}>
                <Glyph name={row.length} />
                <span>{row.payor}</span>
                <strong>{formatMinor(row.amountMinor, sheet.baseCurrency, sheet.exponent)}</strong>
              </p>
            {/each}
          </section>
          <section class="grid gap-2" data-source="accounts">
            <h3 class="m-0 text-lg">Accounts</h3>
            {#each sheet.sources.accounts as row (row.id)}
              <p class="source-row" data-type={row.type} style={`--tone: var(--tone-${row.type})`}>
                <Glyph name={row.type} />
                <span>{row.name}</span>
                <strong>{formatMinor(row.amountMinor, sheet.baseCurrency, sheet.exponent)}</strong>
              </p>
            {/each}
          </section>
        </div>
        <div class="modal-actions">
          <button class="btn" type="button" onclick={() => sourcesDialog?.close()}>Close</button>
        </div>
      </dialog>

      {#if sheet.celebrate}
        <p class="celebrate m-0" aria-live="polite">Every dollar for this month is assigned.</p>
      {/if}

      {#if sheet.billLeftOut && sheet.billLeftOut.length > 0}
        <p class="muted m-0">Left out of the fill: {sheet.billLeftOut.join(', ')}</p>
      {/if}

      <div class="flex flex-wrap items-center gap-2">
        <button class="btn" type="button" disabled={sheet.previousPeriod === null} onclick={() => step(sheet ? sheet.previousPeriod : null)}>Previous month</button>
        <button class="btn" type="button" disabled={sheet.stepNext === null} onclick={() => step(sheet ? sheet.stepNext : null)}>Next month</button>
        {#if sheet.period}
          <p class="m-0" data-month={sheet.period.start.slice(0, 7)}>{formatMonth(sheet.period.start)}</p>
        {/if}
        <button class="btn" type="button" onclick={() => void toggleArchived()}>{showArchived ? 'Hide archived' : 'Show archived'}</button>
        <button class="btn" type="button" data-action="recalculate" disabled={!editable || saving} onclick={() => recalculateDialog?.showModal()}>Recalculate</button>
      </div>
      <dialog class="modal" bind:this={recalculateDialog} aria-labelledby="recalculate-title">
        <div class="modal-body">
          <h2 id="recalculate-title" class="m-0 text-2xl">Recalculate this month?</h2>
          <p class="m-0">This replaces every bucket's assignment for {sheet.period ? formatMonth(sheet.period.start) : 'this month'} with the bills that occur in that month. Fuel also includes the estimate for each stay that is still open and arrives in the month. Amounts you typed are replaced. Account balances stay as you typed them.</p>
        </div>
        <div class="modal-actions">
          <button class="btn danger" type="button" disabled={saving} aria-busy={saving} onclick={() => void confirmRecalculate()}>Recalculate this month</button>
          <button class="btn" type="button" onclick={() => recalculateDialog?.close()}>Cancel</button>
        </div>
      </dialog>
      {#if sheet.period?.upcoming}
        <p class="muted m-0">These amounts are in place when this month opens. Available to assign still follows the current month.</p>
      {:else if !editable && sheet.period}
        <p class="muted m-0">A closed month can be read. A correction belongs in the current month.</p>
      {/if}

      {#each groups as group (group.id)}
        {@const rows = inGroup(group.id)}
        {@const hidden = showArchived ? archivedIn(group.id) : []}
        {@const heading = sheet.groups.find((item) => item.group === group.id)}
        {#if rows.length > 0 || hidden.length > 0}
          <section class="grid gap-3">
            <h2 class="group-title text-2xl" data-group={group.id} style={`--tone: var(--tone-${group.id})`}>
              <Glyph name={group.id} />
              <span class="group-name">{group.label}</span>
              {#if heading}
                <span class="group-sums">
                  <span data-figure="auto"><span>Auto-assigned</span> <strong>{formatMinor(heading.autoMinor, sheet.baseCurrency, sheet.exponent)}</strong></span>
                  <span data-figure="envelope"><span>Envelope</span> <strong>{formatMinor(heading.envelopeMinor, sheet.baseCurrency, sheet.exponent)}</strong></span>
                  <span data-figure="target"><span>Target</span> <strong>{formatMinor(heading.assignedMinor, sheet.baseCurrency, sheet.exponent)}</strong></span>
                  <span data-figure="goal" class:over={heading.goalOver}>({heading.targetPercent}% goal - {formatMinor(heading.targetMinor, sheet.baseCurrency, sheet.exponent)})</span>
                </span>
              {/if}
            </h2>
            <div class="bucket-board" class:celebrate-ease={celebrateEase}>
            {#each rows as category, index (category.id)}
              {@const hold = holdOf(category)}
              {@const level = levelOf(category, rows)}
              {@const over = hold !== null && category.amountMinor > hold}
              {@const href = category.role === 'locked' && category.billType
                ? `/bills?type=${encodeURIComponent(category.billType)}`
                : `/buckets/${category.id}`}
              <div class="bucket-cell">
              <article
                class="bucket-tile envelope-tile"
                class:over
                style={`--tone: var(--tone-${group.id}); --level: ${level}; --stagger: ${index}`}
              >
                <span class="bucket-water" aria-hidden="true">
                  {#if level > 0}
                    <span class="bucket-wave"></span>
                    <span class="bucket-wave"></span>
                    <span class="bucket-wave"></span>
                  {/if}
                </span>
                <a class="bucket-name" href={href} onclick={(event) => follow(event, href)}>
                  <Glyph name={category.role === 'locked' ? 'lock' : group.id} mini />
                  <strong>{category.name}</strong>
                </a>
                <div class="bucket-field">
                  <span>Auto-assigned</span>
                  <strong class="bucket-locked" data-field="auto">{formatMinor(autoOf(category), sheet.baseCurrency, sheet.exponent)}</strong>
                </div>
                <label class="bucket-field">
                  <span>Envelope</span>
                  <span class="money-input">
                    <span class="money-mark" aria-hidden="true">$</span>
                    <input
                      name={`amount-${category.id}`}
                      aria-label={`Envelope ${category.name}`}
                      inputmode="decimal"
                      disabled={!editable || category.archivedAt !== null}
                      value={drafts[category.id] ?? ''}
                      oninput={(event) => {
                        drafts[category.id] = (event.currentTarget as HTMLInputElement).value;
                        queueSave();
                      }}
                      onkeydown={saveNow}
                    />
                  </span>
                </label>
                <div class="bucket-field">
                  <span>Target</span>
                  <strong class="bucket-locked" data-field="target">{formatMinor(targetOf(category), sheet.baseCurrency, sheet.exponent)}</strong>
                </div>
                {#if fields[`amount-${category.id}`]}<em>{fields[`amount-${category.id}`]}</em>{/if}
              </article>
              {#if over}
                <p class="bucket-overage">Over by {formatMinor(category.amountMinor - hold, sheet.baseCurrency, sheet.exponent)}</p>
              {/if}
              </div>
            {/each}
            </div>
            {#each hidden as category (category.id)}
              <a class="row" href={`/buckets/${category.id}`} onclick={(event) => follow(event, `/buckets/${category.id}`)}>
                <strong>{category.name}</strong>
                <span class="muted">archived</span>
              </a>
            {/each}
          </section>
        {/if}
      {/each}
  {/if}
</div>
