<script lang="ts">
  import AmountInput from '../components/AmountInput.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import DateField from '../components/DateField.svelte';
  import { onMount } from 'svelte';
  import Dollars from '../components/Dollars.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatChipDate, formatDate, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { fieldTips } from '../lib/fieldTips';
  import { go } from '../lib/nav.svelte.ts';
  import { tip } from '../lib/tip';
  import { session } from '../lib/session.svelte.ts';
  import type { PayCycle } from '../lib/types';

  type PayrollRow = {
    payday: string;
    amountMinor: number | null;
    hours: string | null;
    keyed: boolean;
    posted?: boolean;
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
  let payrollPosted = $state<Record<string, boolean>>({});
  let extraDate = $state('');
  let payrollDialog: HTMLDialogElement | undefined = $state();
  let deleteTarget = $state<PayCycle | null>(null);
  let deleteDialog: HTMLDialogElement | undefined = $state();
  let saving = $state(false);

  async function load(): Promise<void> {
    const start = session.user?.acctPeriodStart ?? '';
    const end = session.user?.acctPeriodEnd ?? '';
    const data = await api<{ payCycles: PayCycle[] }>(
      `/api/pay-cycles?today=${todayISO()}&includeArchived=${includeArchived ? '1' : '0'}&start=${start}&end=${end}`,
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

  function askDelete(cycle: PayCycle, event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    deleteTarget = cycle;
    deleteDialog?.showModal();
  }

  async function confirmDelete(): Promise<void> {
    if (!deleteTarget) return;
    saving = true;
    message = '';
    const gone = deleteTarget.id;
    try {
      await api(`/api/pay-cycles/${gone}/delete`, { method: 'POST', body: { confirm: true, today: todayISO() } });
      cycles = cycles.filter((cycle) => cycle.id !== gone);
      deleteDialog?.close();
      deleteTarget = null;
    } catch (err) {
      deleteDialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function openPayroll(cycle: PayCycle): Promise<void> {
    payrollCycle = cycle;
    const start = session.user?.acctPeriodStart ?? '';
    const end = session.user?.acctPeriodEnd ?? '';
    const data = await api<{ payroll: { rows: PayrollRow[] } }>(
      `/api/pay-cycles/${cycle.id}/payroll?today=${todayISO()}&start=${start}&end=${end}`,
    );
    payrollRows = data.payroll.rows;
    const drafts: Record<string, string> = {};
    const hours: Record<string, string> = {};
    const posted: Record<string, boolean> = {};
    for (const row of data.payroll.rows) {
      drafts[row.payday] = row.amountMinor === null ? '' : minorToInput(row.amountMinor, 2);
      hours[row.payday] = row.hours ?? '';
      posted[row.payday] = row.posted === true;
    }
    payrollDrafts = drafts;
    payrollHours = hours;
    payrollPosted = posted;
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
      posted: false,
      projectedMinor: null,
    }].sort((left, right) => left.payday.localeCompare(right.payday));
    payrollDrafts[payday] = '';
    payrollPosted[payday] = false;
  }

  async function savePayroll(): Promise<void> {
    if (!payrollCycle) return;
    const entries = payrollRows.map((row) => {
      const raw = (payrollDrafts[row.payday] ?? '').trim();
      return {
        payday: row.payday,
        amountMinor: raw === '' ? null : parseMajor(raw, 2),
        hours: payrollCycle?.payKind === 'hourly' ? (payrollHours[row.payday] ?? '') : null,
        posted: payrollPosted[row.payday] === true,
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
            <div class="pay-card-actions">
              {#if cycle.archivedAt}
                <button
                  class="btn danger"
                  type="button"
                  title="Remove this archived pay cycle."
                  disabled={saving}
                  onclick={(event) => askDelete(cycle, event)}
                >Delete</button>
              {/if}
              <button class="btn" type="button" onclick={() => void openPayroll(cycle)}>Log Payroll</button>
            </div>
          </div>
          <p class="pay-period-range m-0">{span(cycle)} | {#if cycle.paycheckMinor === null}—{:else}<Dollars minor={cycle.paycheckMinor} currency={session.user?.baseCurrency ?? 'USD'} />{/if}</p>
          <div class="pay-chips">
            <span class="pay-chip pay-chip-roll">{cycle.rollsOn ? `Roll ${formatChipDate(cycle.rollsOn)}` : 'Open'}</span>
            <span class="pay-chip pay-chip-kind">{kindLabel(cycle.payKind)}</span>
            <span class="pay-chip pay-chip-month">Monthly {#if cycle.monthlyMinor === null}—{:else}<Dollars minor={cycle.monthlyMinor} currency={session.user?.baseCurrency ?? 'USD'} />{/if}</span>
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
        <span use:tip={fieldTips.payrollDay}>{formatDate(row.payday)}{#if row.projectedMinor !== null} · <Dollars minor={row.projectedMinor} currency={session.user?.baseCurrency ?? 'USD'} />{/if}</span>
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
          <AmountInput
            name={`amount-${row.payday}`}
            bind:value={payrollDrafts[row.payday]}
          />
        </span>
        <span class="flex flex-wrap gap-2" role="group" aria-label={`Payroll ${formatDate(row.payday)}`}>
          <button
            class="choice"
            class:on={payrollPosted[row.payday] !== true}
            type="button"
            aria-pressed={payrollPosted[row.payday] !== true}
            onclick={() => (payrollPosted[row.payday] = false)}
          >Incoming</button>
          <button
            class="choice"
            class:on={payrollPosted[row.payday] === true}
            type="button"
            aria-pressed={payrollPosted[row.payday] === true}
            onclick={() => (payrollPosted[row.payday] = true)}
          >Posted</button>
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

<dialog class="modal" bind:this={deleteDialog} aria-labelledby="delete-pay-cycle-title">
  <div class="modal-body">
    <h2 id="delete-pay-cycle-title" class="text-xl m-0">Delete this pay cycle?</h2>
    <p class="m-0">This removes {deleteTarget?.payor ?? 'this pay cycle'}. It does not come back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => deleteDialog?.close()}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={() => void confirmDelete()}>Delete pay cycle</button>
  </div>
</dialog>
