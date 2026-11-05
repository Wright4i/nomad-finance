<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatMinor, formatMonth, minorToInput, parseSignedMajor, todayISO } from '../lib/money';
  import { targetHint } from '../lib/target';
  import { follow, go } from '../lib/nav.svelte.ts';
  import type { EnvelopeSheet, PeriodStep } from '../lib/types';

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
  let ready = $state(false);

  let editable = $derived(sheet?.period?.editable === true);

  async function load(): Promise<void> {
    const params = new URLSearchParams({ today: todayISO() });
    if (payCycleId !== null) params.set('payCycleId', String(payCycleId));
    if (upcoming) params.set('upcoming', '1');
    else if (periodId !== null) params.set('periodId', String(periodId));
    const data = await api<EnvelopeSheet>(`/api/envelopes?${params}`);
    sheet = data;
    payCycleId = data.payCycleId;
    const next: Record<number, string> = {};
    for (const category of data.categories) {
      next[category.id] = minorToInput(category.amountMinor, data.exponent);
    }
    drafts = next;
    ready = true;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
      ready = true;
    });
  });

  function choosePayor(event: Event): void {
    const value = (event.currentTarget as HTMLSelectElement).value;
    payCycleId = Number(value);
    periodId = null;
    upcoming = false;
    message = '';
    void load().catch((err: unknown) => {
      message = explain(err).message;
    });
  }

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
    return (sheet?.categories ?? []).filter((category) => category.group === id);
  }

  function remainFill(assigned: number, remaining: number): { scale: number; over: boolean } {
    if (remaining < 0) return { scale: 1, over: true };
    if (remaining === 0) return { scale: 0, over: assigned > 0 };
    if (assigned <= 0) return { scale: 1, over: false };
    const ratio = Math.min(remaining / assigned, 1);
    return { scale: Math.round(ratio * 1000) / 1000, over: false };
  }

  async function save(): Promise<void> {
    if (!sheet || !editable || sheet.payCycleId === null) return;
    fields = {};
    message = '';
    const amounts = sheet.categories
      .filter((category) => category.archivedAt === null)
      .map((category) => {
        const raw = (drafts[category.id] ?? '').trim();
        const amountMinor = raw === '' ? 0 : parseSignedMajor(raw, sheet.exponent);
        if (amountMinor === null) {
          fields[`amount-${category.id}`] = 'Enter an amount.';
        }
        return { categoryId: category.id, amountMinor: amountMinor ?? 0 };
      });
    if (Object.keys(fields).length > 0) return;

    saving = true;
    try {
      const planning = sheet.period?.upcoming === true;
      const data = await api<EnvelopeSheet>('/api/envelopes', {
        method: 'PUT',
        body: { today: todayISO(), payCycleId: sheet.payCycleId, amounts, upcoming: planning },
      });
      sheet = data;
      payCycleId = data.payCycleId;
      upcoming = data.period?.upcoming === true;
      periodId = null;
      const next: Record<number, string> = {};
      for (const category of data.categories) {
        next[category.id] = minorToInput(category.amountMinor, data.exponent);
      }
      drafts = next;
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
    <h1 class="text-3xl m-0">Envelopes</h1>
    <a class="btn primary" href="/categories/new" onclick={(event) => { event.preventDefault(); go('/categories/new'); }}>Add Bucket</a>
  </div>
  <p class="muted m-0">Assign cash from checking, savings, and cash. An assignment does not change an account balance.</p>
  {#if message}<p class="banner m-0">{message}</p>{/if}

  {#if ready && sheet}
    {#if sheet.payors.length === 0}
      <p class="m-0">Add a pay cycle before assigning. <a href="/pay-cycles/new" onclick={(event) => { event.preventDefault(); go('/pay-cycles/new'); }}>New pay cycle</a></p>
    {:else}
      <label class="field max-w-xl">
        <span>Payor</span>
        <select name="payCycleId" value={sheet.payCycleId ?? ''} onchange={choosePayor}>
          {#each sheet.payors as payor (payor.id)}
            <option value={payor.id}>{payor.payor}</option>
          {/each}
        </select>
      </label>

      <section class="hero">
        <p class="kicker m-0">Available to assign</p>
        <p class="figure">{formatMinor(sheet.availableMinor, sheet.baseCurrency, sheet.exponent)}</p>
        <p class="muted m-0" title="Checking, savings, and cash, converted, minus what is assigned in open months.">
          {formatMinor(sheet.assignedMinor, sheet.baseCurrency, sheet.exponent)} assigned of {formatMinor(sheet.poolMinor, sheet.baseCurrency, sheet.exponent)}
        </p>
      </section>

      {#if sheet.celebrate}
        <p class="banner celebrate m-0">Every dollar for this month is assigned.</p>
      {/if}

      {#if sheet.leftOut.length > 0}
        <section class="grid gap-2">
          <h2 class="group-title text-2xl" style="--tone: var(--amber)">Left out of the pool</h2>
          {#each sheet.leftOut as account (account.accountId)}
            <a class="row stripe warn" href={`/accounts/${account.accountId}`} onclick={(event) => follow(event, `/accounts/${account.accountId}`)}>
              <span>
                <strong>{account.name}</strong>
                <span class="block muted text-sm">No {sheet.baseCurrency} rate for {account.currency}</span>
              </span>
              <strong class="amber money">{formatMinor(account.balanceMinor, account.currency, account.exponent)}</strong>
            </a>
          {/each}
        </section>
      {/if}

      <div class="flex flex-wrap items-center gap-2">
        <button class="btn" type="button" disabled={sheet.previousPeriod === null} onclick={() => step(sheet ? sheet.previousPeriod : null)}>Previous month</button>
        <button class="btn" type="button" disabled={sheet.stepNext === null} onclick={() => step(sheet ? sheet.stepNext : null)}>Next month</button>
        {#if sheet.period}
          <p class="m-0" data-month={sheet.period.start.slice(0, 7)}>{formatMonth(sheet.period.start)}</p>
        {/if}
      </div>
      {#if sheet.period?.upcoming}
        <p class="muted m-0">These amounts are in place when this month opens. Available to assign still follows the current month.</p>
      {:else if !editable && sheet.period}
        <p class="muted m-0">A closed month can be read. A correction belongs in the current month.</p>
      {/if}

      {#each groups as group (group.id)}
        {@const rows = inGroup(group.id)}
        {#if rows.length > 0}
          <section class="grid gap-3">
            <h2 class="group-title text-2xl" style={`--tone: var(--tone-${group.id})`}>
              <Glyph name={group.id} />
              {group.label}
            </h2>
            {#each rows as category (category.id)}
              {@const remaining = category.remainingMinor ?? category.amountMinor}
              {@const fill = remainFill(category.amountMinor, remaining)}
              <label class="field" style={`--tone: var(--tone-${group.id})`}>
                <span class="flex items-center justify-between gap-3">
                  <span>
                    {category.name}
                    <span class="muted"> · {category.overspend === 'carry' ? 'Carry' : 'Cover now'}</span>
                    {#if category.target}
                      <span class="block muted text-sm">{targetHint(category.target, sheet.baseCurrency, sheet.exponent)}</span>
                    {/if}
                  </span>
                  <strong>{formatMinor(category.amountMinor, sheet.baseCurrency, sheet.exponent)}</strong>
                </span>
                <span
                  class="remain-meter"
                  class:over={fill.over}
                  role="meter"
                  aria-valuemin="0"
                  aria-valuemax={Math.max(category.amountMinor, 0)}
                  aria-valuenow={Math.max(remaining, 0)}
                  aria-label={`${category.name} has ${formatMinor(remaining, sheet.baseCurrency, sheet.exponent)} remaining of ${formatMinor(category.amountMinor, sheet.baseCurrency, sheet.exponent)}`}
                >
                  <span class={fill.over && fill.scale > 0 ? 'gauge-over' : 'gauge-fill'} style={`transform: scaleX(${fill.scale})`}></span>
                </span>
                <span class="muted text-sm">{formatMinor(remaining, sheet.baseCurrency, sheet.exponent)} remaining</span>
                <input
                  name={`amount-${category.id}`}
                  inputmode="decimal"
                  disabled={!editable || category.archivedAt !== null}
                  value={drafts[category.id] ?? ''}
                  oninput={(event) => {
                    drafts[category.id] = (event.currentTarget as HTMLInputElement).value;
                  }}
                />
                {#if fields[`amount-${category.id}`]}<em>{fields[`amount-${category.id}`]}</em>{/if}
              </label>
            {/each}
          </section>
        {/if}
      {/each}

      {#if editable}
        <button
          class="btn primary w-fit"
          type="button"
          title="Save these amounts in the current month. Account balances stay as you typed them."
          disabled={saving}
          onclick={save}
        >{saving ? 'Saving…' : 'Save assignments'}</button>
      {/if}

    {/if}
  {/if}
</div>
