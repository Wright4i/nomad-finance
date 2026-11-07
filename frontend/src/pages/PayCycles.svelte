<script lang="ts">
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import DateField from '../components/DateField.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatChipDate, formatDate, formatMinor, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { PayCycle } from '../lib/types';

  type PayrollRow = {
    payday: string;
    amountMinor: number | null;
    hours: string | null;
    keyed: boolean;
    projectedMinor: number | null;
  };

  let cycles = $state<PayCycle[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);
  let payrollCycle = $state<PayCycle | null>(null);
  let payrollRows = $state<PayrollRow[]>([]);
  let payrollDrafts = $state<Record<string, string>>({});
  let payrollHours = $state<Record<string, string>>({});
  let extraDate = $state('');
  let payrollDialog: HTMLDialogElement | undefined = $state();

  async function load(): Promise<void> {
    const data = await api<{ payCycles: PayCycle[] }>(
      `/api/pay-cycles?today=${todayISO()}&includeArchived=${includeArchived ? '1' : '0'}`,
    );
    cycles = data.payCycles;
    ready = true;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
      ready = true;
    });
  });

  function toggle(): void {
    includeArchived = !includeArchived;
    void load();
  }

  async function openPayroll(cycle: PayCycle): Promise<void> {
    payrollCycle = cycle;
    const data = await api<{ payroll: { rows: PayrollRow[] } }>(
      `/api/pay-cycles/${cycle.id}/payroll?today=${todayISO()}`,
    );
    payrollRows = data.payroll.rows;
    const drafts: Record<string, string> = {};
    const hours: Record<string, string> = {};
    for (const row of data.payroll.rows) {
      drafts[row.payday] = row.amountMinor === null ? '' : minorToInput(row.amountMinor, 2);
      hours[row.payday] = row.hours ?? '';
    }
    payrollDrafts = drafts;
    payrollHours = hours;
    extraDate = '';
    payrollDialog?.showModal();
  }

  function fillFromHours(payday: string): void {
    if (!payrollCycle || payrollCycle.payKind !== 'hourly') return;
    const raw = (payrollHours[payday] ?? '').trim();
    if (!/^\d+(\.\d{1,2})?$/.test(raw)) return;
    const [whole, frac = ''] = raw.split('.');
    const hundredths = Number(whole) * 100 + Number(frac.padEnd(2, '0').slice(0, 2));
    const amount = Math.round((payrollCycle.rateMinor * hundredths) / 100);
    payrollDrafts[payday] = minorToInput(amount, 2);
  }

  function addDate(): void {
    const payday = extraDate.trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(payday)) return;
    if (payrollRows.some((row) => row.payday === payday)) return;
    payrollRows = [...payrollRows, {
      payday,
      amountMinor: null,
      hours: null,
      keyed: false,
      projectedMinor: null,
    }].sort((left, right) => left.payday.localeCompare(right.payday));
    payrollDrafts[payday] = '';
  }

  async function savePayroll(): Promise<void> {
    if (!payrollCycle) return;
    const entries = payrollRows.map((row) => {
      const raw = (payrollDrafts[row.payday] ?? '').trim();
      return {
        payday: row.payday,
        amountMinor: raw === '' ? null : parseMajor(raw, 2),
        hours: payrollCycle?.payKind === 'hourly' ? (payrollHours[row.payday] ?? '') : null,
      };
    });
    await api(`/api/pay-cycles/${payrollCycle.id}/payroll`, {
      method: 'PUT',
      body: { today: todayISO(), entries },
    });
    payrollDialog?.close();
    await load();
  }

  function span(cycle: PayCycle): string {
    if (cycle.periodEnd) return `${formatChipDate(cycle.periodStart)} - ${formatChipDate(cycle.periodEnd)}`;
    return `Open since ${formatChipDate(cycle.periodStart)}`;
  }

  function kindLabel(kind: PayCycle['payKind']): string {
    if (kind === 'salary') return 'Salary';
    if (kind === 'hourly') return 'Hourly';
    return 'Fixed';
  }

  function moneyOrDash(minor: number | null): string {
    if (minor === null) return '—';
    return formatMinor(minor, session.user?.baseCurrency ?? 'USD');
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Income</h1>
    <a
      class="btn primary"
      href="/pay-cycles/new"
      title="Add a payor and a pay cycle"
      onclick={(event) => { event.preventDefault(); go('/pay-cycles/new'); }}
    >New pay cycle</a>
  </div>
  <p class="muted m-0">Each payor has one pay cycle. A second paycheck from that payor stays in the same period.</p>
  <button
    class="btn w-fit"
    type="button"
    title={includeArchived ? 'Leave archived pay cycles off this list' : 'Archived pay cycles stay stored until you ask for them'}
    onclick={toggle}
  >{includeArchived ? 'Hide archived' : 'Show archived'}</button>
  <Note bind:message />
  {#if !ready}
    <Spinner />
  {:else if cycles.length === 0}
    <p class="muted m-0">No pay cycles yet.</p>
  {:else}
    <div class="payor-columns">
      {#each cycles as cycle (cycle.id)}
        <article class="card stripe grid gap-1" style={`--tone: var(--tone-${cycle.length})`}>
          <div class="pay-card-head">
            <a
              class="flex items-center gap-3 no-underline"
              href={`/pay-cycles/${cycle.id}`}
              onclick={(event) => { event.preventDefault(); go(`/pay-cycles/${cycle.id}`); }}
            >
              <Glyph name={cycle.length} />
              <span>
                <strong>{cycle.payor}</strong>
                {#if cycle.archivedAt}<span class="block muted text-sm">archived</span>{/if}
              </span>
            </a>
            <button class="btn" type="button" onclick={() => void openPayroll(cycle)}>Log Payroll</button>
          </div>
          <p class="pay-period-range m-0">{span(cycle)} | {moneyOrDash(cycle.paycheckMinor)}</p>
          <div class="pay-chips">
            <span class="pay-chip pay-chip-roll">{cycle.rollsOn ? `Roll ${formatChipDate(cycle.rollsOn)}` : 'Open'}</span>
            <span class="pay-chip pay-chip-kind">{kindLabel(cycle.payKind)}</span>
            <span class="pay-chip pay-chip-month">Monthly {moneyOrDash(cycle.monthlyMinor)}</span>
          </div>
        </article>
      {/each}
    </div>
  {/if}
</div>

<dialog class="modal wide" bind:this={payrollDialog} aria-labelledby="payroll-title">
  <div class="modal-body">
    <h2 id="payroll-title" class="m-0 text-2xl">Payroll{payrollCycle ? ` · ${payrollCycle.payor}` : ''}</h2>
    <p class="m-0">One amount per payday. A blank amount is not keyed. A keyed amount is the paycheck on that date.</p>
    {#each payrollRows as row (row.payday)}
      <label class="field">
        <span>{formatDate(row.payday)}{row.projectedMinor !== null ? ` · ${formatMinor(row.projectedMinor, session.user?.baseCurrency ?? 'USD')}` : ''}</span>
        {#if payrollCycle?.payKind === 'hourly'}
          <input
            name={`hours-${row.payday}`}
            inputmode="decimal"
            placeholder="Hours"
            value={payrollHours[row.payday] ?? ''}
            oninput={(event) => {
              payrollHours[row.payday] = (event.currentTarget as HTMLInputElement).value;
              fillFromHours(row.payday);
            }}
          />
        {/if}
        <span class="money-input">
          <span class="money-mark" aria-hidden="true">$</span>
          <input
            name={`amount-${row.payday}`}
            inputmode="decimal"
            value={payrollDrafts[row.payday] ?? ''}
            oninput={(event) => {
              payrollDrafts[row.payday] = (event.currentTarget as HTMLInputElement).value;
            }}
          />
        </span>
      </label>
    {/each}
    {#if payrollCycle?.length === 'irregular'}
      <label class="field">
        <span>Add a date</span>
        <DateField name="extraDate" bind:value={extraDate} />
      </label>
    {/if}
  </div>
  <div class="modal-actions">
    {#if payrollCycle?.length === 'irregular'}
      <button class="btn" type="button" onclick={addDate}>Add a date</button>
    {/if}
    <button class="btn" type="button" onclick={() => payrollDialog?.close()}>Close</button>
    <button class="btn primary" type="button" onclick={() => void savePayroll()}>Save payroll</button>
  </div>
</dialog>
