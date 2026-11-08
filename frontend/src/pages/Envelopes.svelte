<script lang="ts">
  import { onDestroy, onMount, tick } from 'svelte';
  import AmountInput from '../components/AmountInput.svelte';
  import Dollars from '../components/Dollars.svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { api, explain } from '../lib/api';
  import { periodShift, periodWithinReach, type PeriodLength } from '../lib/acctPeriod';
  import { balanceGoalMinor, bucketMark, fillFor, type FillBucket } from '../lib/bucketFill';
  import { formatChipDate, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { prefersReducedMotion } from '../lib/motion';
  import { follow, go } from '../lib/nav.svelte.ts';
  import { adoptUser, session } from '../lib/session.svelte.ts';
  import type { Category, EnvelopeSheet, User } from '../lib/types';

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
  let deleteTarget = $state<Category | null>(null);
  let deleteDialog: HTMLDialogElement | undefined = $state();
  let sourcesDialog: HTMLDialogElement | undefined = $state();
  let saveTimer: ReturnType<typeof setTimeout> | undefined;
  let alive = true;

  let editable = $derived(sheet?.period?.editable === true);
  let closed = $derived(sheet?.acctPeriod?.phase === 'closed');

  function periodOpen(steps: number): boolean {
    if (!session.user) return false;
    const length = session.user.acctPeriodLength;
    const next = periodShift(session.user.acctPeriodStart, length as PeriodLength, steps);
    return periodWithinReach(next, length, todayISO());
  }

  async function nudgePeriod(steps: number): Promise<void> {
    if (!session.user || saving || !periodOpen(steps)) return;
    const length = session.user.acctPeriodLength;
    saving = true;
    try {
      const data = await api<{ user: User }>('/api/settings', {
        method: 'PATCH',
        body: {
          acctPeriodStart: periodShift(session.user.acctPeriodStart, length as PeriodLength, steps),
          acctPeriodLength: length,
        },
      });
      adoptUser(data.user);
    } finally {
      saving = false;
    }
  }

  async function load(): Promise<void> {
    const params = new URLSearchParams({ today: todayISO(), acctPeriod: '1' });
    if (payCycleId !== null) params.set('payCycleId', String(payCycleId));
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

  function askDelete(category: Category): void {
    deleteTarget = category;
    deleteDialog?.showModal();
  }

  async function confirmDelete(): Promise<void> {
    if (!deleteTarget) return;
    saving = true;
    message = '';
    const gone = deleteTarget.id;
    try {
      await api(`/api/buckets/${gone}/delete`, { method: 'POST', body: { confirm: true } });
      archived = archived.filter((category) => category.id !== gone);
      deleteDialog?.close();
      deleteTarget = null;
    } catch (err) {
      deleteDialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
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

  function asFill(category: EnvelopeSheet['categories'][number]): FillBucket {
    return {
      role: category.role,
      amountMinor: targetOf(category),
      envelopeMinor: typedEnvelope(category) ?? 0,
      target: category.target ? { kind: category.target.kind, amountMinor: category.target.amountMinor } : null,
    };
  }

  function shownTarget(category: EnvelopeSheet['categories'][number]): number {
    return balanceGoalMinor(asFill(category)) ?? targetOf(category);
  }

  function queueSave(): void {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      saveTimer = undefined;
      void save();
    }, 1000);
  }

  onDestroy(() => {
    alive = false;
    if (!saveTimer) return;
    clearTimeout(saveTimer);
    saveTimer = undefined;
    void save();
  });

  function saveNow(event: KeyboardEvent): void {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    if (saveTimer) clearTimeout(saveTimer);
    void save();
  }

  async function save(): Promise<void> {
    if (!sheet || !editable || closed || sheet.payCycleId === null) return;
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
    const periodStart = sheet.acctPeriod?.start ?? '';
    const planning = sheet.period?.upcoming === true;
    try {
      const data = await api<EnvelopeSheet>('/api/budget', {
        method: 'PUT',
        body: {
          today: todayISO(),
          payCycleId: sheet.payCycleId,
          amounts,
          upcoming: planning,
          acctPeriod: '1',
          acctPeriodStart: periodStart,
        },
      });
      if (!alive) return;
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
</script>

<div class="grid gap-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Budget</h1>
    <a class="btn primary" href="/buckets/new" onclick={(event) => { event.preventDefault(); go('/buckets/new'); }}>Add bucket</a>
  </div>
  <Note bind:message />
  {#if !ready}
    <Spinner />
  {:else if sheet}
    {#if sheet.payors.length === 0}
      <p class="m-0">Add a pay cycle before assigning. <a href="/pay-cycles/new" onclick={(event) => { event.preventDefault(); go('/pay-cycles/new'); }}>New pay cycle</a></p>
    {/if}
      {#if closed}
        <section class="hero">
          <p class="m-0 text-3xl">This period has closed.</p>
        </section>
      {:else}
      <section class="hero">
        <button class="available-open" type="button" data-action="available" onclick={() => sourcesDialog?.showModal()}>
          <p class="kicker m-0">Available to assign</p>
          <p class="figure"><Dollars minor={sheet.availableMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} /></p>
        </button>
        <p class="muted m-0" title="Income still coming in this month, plus every asset marked Include in Budget.">
          <Dollars minor={sheet.assignedMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} /> assigned of <Dollars minor={sheet.poolMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
        </p>
      </section>
      {/if}

      {#if !closed}
      <dialog class="modal available-modal" bind:this={sourcesDialog} aria-labelledby="available-title">
        <div class="modal-body">
          <h2 id="available-title" class="m-0 text-2xl">Available to assign</h2>
          {#if sheet.sources.income.length > 0}
            <section class="grid gap-2" data-source="income">
              <h3 class="m-0 text-lg">Still coming in</h3>
              {#each sheet.sources.income as row (`${row.id}-${row.on}`)}
                <p class="source-row" style={`--tone: var(--tone-${row.length})`}>
                  <Glyph name={row.length} />
                  <span class="source-name">{row.payor}</span>
                  <span class="source-date">{formatChipDate(row.on)}</span>
                  <Dollars minor={row.amountMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
                </p>
              {/each}
            </section>
          {/if}
          {#if sheet.sources.accounts.length > 0}
            <section class="grid gap-2" data-source="accounts">
              <h3 class="m-0 text-lg">Accounts</h3>
              {#each sheet.sources.accounts as row (row.id)}
                <p class="source-row" data-type={row.type} style={`--tone: var(--tone-${row.type})`}>
                  <Glyph name={row.type} />
                  <span class="source-name">{row.name}</span>
                  {#if row.institution}<span class="source-institution">{row.institution}</span>{/if}
                  {#if row.holder}<span class="bill-detail-chip" data-chip="holder">{row.holder}</span>{/if}
                  <Dollars minor={row.amountMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
                </p>
              {/each}
            </section>
          {/if}
          {#if sheet.sources.floats && sheet.sources.floats.some((row) => row.uncoveredMinor !== 0)}
            <section class="grid gap-2" data-source="floats">
              <h3 class="m-0 text-lg">Paid in full</h3>
              {#each sheet.sources.floats as row (row.id)}
                {#if row.uncoveredMinor !== 0}
                  <p class="source-row" data-type={row.type} style={`--tone: var(--tone-${row.type})`}>
                    <Glyph name={row.type} />
                    <span class="source-name">{row.name}</span>
                    {#if row.institution}<span class="source-institution">{row.institution}</span>{/if}
                    {#if row.holder}<span class="bill-detail-chip" data-chip="holder">{row.holder}</span>{/if}
                    <Dollars class="source-debit" minor={row.uncoveredMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} owe />
                  </p>
                {/if}
              {/each}
            </section>
          {/if}
        </div>
        <div class="modal-actions">
          <button class="btn" type="button" onclick={() => sourcesDialog?.close()}>Close</button>
        </div>
      </dialog>
      {/if}

      {#if sheet.celebrate}
        <p class="celebrate m-0" aria-live="polite">Every dollar for this period is assigned.</p>
      {/if}

      {#if sheet.billLeftOut && sheet.billLeftOut.length > 0}
        <p class="muted m-0">Left out of the fill: {sheet.billLeftOut.join(', ')}</p>
      {/if}

      <div class="flex flex-wrap items-center gap-2">
        {#if sheet.acctPeriod}
          <button class="btn" type="button" disabled={saving || !periodOpen(-1)} onclick={() => void nudgePeriod(-1)}>Previous period</button>
          <p class="m-0" data-period={sheet.acctPeriod.start}>{formatChipDate(sheet.acctPeriod.start)} – {formatChipDate(sheet.acctPeriod.end)}</p>
          <button class="btn" type="button" disabled={saving || !periodOpen(1)} onclick={() => void nudgePeriod(1)}>Next period</button>
        {/if}
        <button class="btn" type="button" onclick={() => void toggleArchived()}>{showArchived ? 'Hide archived' : 'Show archived'}</button>
      </div>

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
                  <span data-figure="auto"><span>Auto-assigned</span> <Dollars minor={heading.autoMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} /></span>
                  <span data-figure="envelope"><span>Envelope</span> <Dollars minor={heading.envelopeMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} /></span>
                  <span data-figure="target"><span>Target</span> <Dollars minor={heading.assignedMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} /></span>
                  <span data-figure="goal" class:over={heading.goalOver}>(<span class="muted">{heading.targetPercent}%</span> goal - <Dollars minor={heading.targetMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />)</span>
                </span>
              {/if}
            </h2>
            <div class="bucket-board" class:celebrate-ease={celebrateEase}>
            {#each rows as category, index (category.id)}
              {@const mark = bucketMark(asFill(category))}
              {@const fill = fillFor(asFill(category), {
                goalMinor: heading?.targetMinor ?? 0,
                buckets: rows.map((row) => asFill(row)),
              })}
              {@const href = category.role === 'locked' && category.billType
                ? `/bills?type=${encodeURIComponent(category.billType)}`
                : `/buckets/${category.id}`}
              <div class="bucket-cell">
              <article
                class="bucket-tile envelope-tile"
                class:over={fill.over}
                style={`--tone: var(--tone-${group.id}); --level: ${fill.level}; --stagger: ${index}`}
              >
                <span class="bucket-water" aria-hidden="true">
                  <span class="bucket-wave"></span>
                  <span class="bucket-wave"></span>
                  <span class="bucket-wave"></span>
                </span>
                <a class="bucket-name" href={href} onclick={(event) => follow(event, href)}>
                  <Glyph name={category.role === 'locked' ? 'lock' : group.id} mini />
                  <strong>{category.name}</strong>
                </a>
                <div class="bucket-field bucket-status" class:marked={mark !== 'auto'}>
                  {#if mark === 'balance'}
                    <span class="goal-chip">Goal based</span>
                  {:else if mark === 'none'}
                    <span class="goal-chip plain">No goal</span>
                  {/if}
                  {#if mark === 'auto'}
                    <span class="bill-chip">Bill assignments</span>
                    <strong class="bucket-locked" data-field="auto"><Dollars minor={autoOf(category)} currency={sheet.baseCurrency} exponent={sheet.exponent} /></strong>
                  {:else}
                    <span class="bill-chip status-spacer" aria-hidden="true">Bill assignments</span>
                    <strong class="bucket-locked status-spacer" aria-hidden="true">$0.00</strong>
                  {/if}
                </div>
                <label class="bucket-field">
                  <span>Envelope</span>
                  <span class="money-input">
                    <span class="money-mark" aria-hidden="true">$</span>
                    <AmountInput
                      name={`amount-${category.id}`}
                      aria-label={`Envelope ${category.name}`}
                      exponent={sheet.exponent}
                      disabled={closed || !editable || category.archivedAt !== null}
                      bind:value={drafts[category.id]}
                      oninput={() => queueSave()}
                      onkeydown={saveNow}
                    />
                  </span>
                  {#if fields[`amount-${category.id}`]}<em>{fields[`amount-${category.id}`]}</em>{/if}
                </label>
                {#if mark !== 'none'}
                  <div class="bucket-field">
                    <span>Target</span>
                    <strong class="bucket-locked" data-field="target"><Dollars minor={shownTarget(category)} currency={sheet.baseCurrency} exponent={sheet.exponent} /></strong>
                  </div>
                {/if}
              </article>
              {#if fill.over && fill.overBy > 0}
                <p class="bucket-overage">Over by <Dollars minor={fill.overBy} currency={sheet.baseCurrency} exponent={sheet.exponent} owe /></p>
              {/if}
              </div>
            {/each}
            </div>
            {#each hidden as category (category.id)}
              <div class="row">
                <a class="flex min-w-0 flex-1 items-center justify-between gap-4 no-underline" href={`/buckets/${category.id}`} style="color: inherit" onclick={(event) => follow(event, `/buckets/${category.id}`)}>
                  <strong>{category.name}</strong>
                  <span class="muted">archived</span>
                </a>
                <button class="btn danger" type="button" disabled={saving} onclick={() => askDelete(category)}>Delete</button>
              </div>
            {/each}
          </section>
        {/if}
      {/each}
  {/if}
</div>

<dialog class="modal" bind:this={deleteDialog} aria-labelledby="delete-bucket-title">
  <div class="modal-body">
    <h2 id="delete-bucket-title" class="text-xl m-0">Delete this bucket?</h2>
    <p class="m-0">This removes {deleteTarget?.name ?? 'this bucket'}. It does not come back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => deleteDialog?.close()}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={() => void confirmDelete()}>Delete bucket</button>
  </div>
</dialog>
