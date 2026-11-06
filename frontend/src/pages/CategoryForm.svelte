<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import DateField from '../components/DateField.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates';
  import { exponentOf, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import { targetHint } from '../lib/target';
  import type { Category, CategoryTarget, PayCycle } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const groups = [
    { id: 'needs', label: 'Needs' },
    { id: 'wants', label: 'Wants' },
    { id: 'savings', label: 'Savings' },
  ] as const;

  const targetKinds = [
    { id: 'none', label: 'None', title: 'This bucket has no target.' },
    { id: 'needed', label: 'Needed by date', title: 'The gap to the amount, divided by the months still left.' },
    { id: 'balance', label: 'Target balance', title: 'An amount to hold, with no end date.' },
  ] as const;

  let name = $state('');
  let group = $state('needs');
  let role = $state<'goal' | 'locked'>('goal');
  let locked = $derived(role === 'locked');
  let targetKind = $state<'none' | CategoryTarget['kind']>('none');
  let targetAmount = $state('');
  let targetDate = $state('');
  let payCycleId = $state('');
  let loadedTarget = $state<CategoryTarget | null>(null);
  let cycles = $state<PayCycle[]>([]);
  let archivedAt = $state<string | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let dialog: HTMLDialogElement | undefined = $state();

  onMount(async () => {
    try {
      const cycleData = await api<{ payCycles: PayCycle[] }>(`/api/pay-cycles?today=${todayISO()}`);
      cycles = cycleData.payCycles.filter((cycle) => cycle.archivedAt === null);
      if (!id) return;
      const data = await api<{ category: Category }>(`/api/categories/${id}?today=${todayISO()}`);
      name = data.category.name;
      group = data.category.group;
      role = data.category.role;
      archivedAt = data.category.archivedAt;
      loadedTarget = data.category.target;
      if (data.category.target) {
        targetKind = data.category.target.kind;
        targetAmount = data.category.target.amountMinor === null
          ? ''
          : minorToInput(data.category.target.amountMinor, exponentOf(session.user?.baseCurrency ?? 'USD'));
        targetDate = data.category.target.targetDate ?? '';
        payCycleId = data.category.target.payCycleId === null ? '' : String(data.category.target.payCycleId);
      }
    } catch (err) {
      message = explain(err).message;
    }
  });

  function targetBody(): Record<string, unknown> | null {
    if (targetKind === 'none') return null;
    const amountMinor = parseMajor(targetAmount, exponentOf(session.user?.baseCurrency ?? 'USD'));
    if (targetKind === 'balance') return { kind: 'balance', amountMinor };
    return {
      kind: 'needed',
      amountMinor,
      targetDate,
      payCycleId: payCycleId === '' ? null : Number(payCycleId),
    };
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    if (targetKind === 'needed') {
      const date = readDateInput('targetDate');
      if (!date) {
        fields = { targetDate: DATE_INPUT_ERROR };
        return;
      }
      targetDate = date;
    }
    saving = true;
    try {
      const path = id ? `/api/categories/${id}` : '/api/categories';
      const method = id ? 'PATCH' : 'POST';
      const target = locked ? (targetAmount.trim() === '' ? null : { kind: 'balance', amountMinor: parseMajor(targetAmount, exponentOf(session.user?.baseCurrency ?? 'USD')) }) : targetBody();
      const body = locked
        ? { today: todayISO(), target }
        : { name, group, today: todayISO(), target };
      await api(path, { method, body });
      go('/categories');
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  function askArchive(): void {
    dialog?.showModal();
  }

  function closeArchive(): void {
    dialog?.close();
  }

  async function archive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/categories/${id}/archive`, { method: 'POST', body: {} });
      dialog?.close();
      go('/categories');
    } catch (err) {
      dialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function unarchive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/categories/${id}/unarchive`, { method: 'POST', body: {} });
      go('/categories');
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? name || 'Edit bucket' : 'New bucket'}</h1>
  <p class="muted m-0">Name the bucket and put it in Needs, Wants, or Savings. This screen does not assign money.</p>
  <Note bind:message />
  {#if locked}
    <p class="m-0">Name <strong>{name}</strong></p>
  {:else}
    <label class="field">
      <span>Name</span>
      <input name="name" bind:value={name} />
      {#if fields.name}<em>{fields.name}</em>{/if}
    </label>
  {/if}
  {#if !locked}
  <div class="field">
    <span>Group</span>
    <div class="flex flex-wrap gap-2">
      {#each groups as option}
        <button
          class="choice"
          class:on={group === option.id}
          type="button"
          style={`--tone: var(--tone-${option.id})`}
          aria-pressed={group === option.id}
          onclick={() => (group = option.id)}
        >
          <Glyph name={option.id} mini />
          {option.label}
        </button>
      {/each}
    </div>
    {#if fields.group}<em>{fields.group}</em>{/if}
  </div>
  {/if}
  {#if !locked}
  <div class="field">
    <span>Target</span>
    <div class="flex flex-wrap gap-2">
      {#each targetKinds as option}
        <button
          class="choice"
          class:on={targetKind === option.id}
          type="button"
          aria-pressed={targetKind === option.id}
          title={option.title}
          onclick={() => (targetKind = option.id)}
        >{option.label}</button>
      {/each}
    </div>
    {#if fields.target}<em>{fields.target}</em>{/if}
  </div>
  {/if}
  {#if !locked && (targetKind === 'needed' || targetKind === 'balance')}
    <label class="field">
      <span>Amount</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <input name="targetAmount" inputmode="decimal" bind:value={targetAmount} />
      </span>
      {#if fields.targetAmount}<em>{fields.targetAmount}</em>{/if}
    </label>
  {/if}
  {#if !locked && targetKind === 'needed'}
    <label class="field">
      <span>Date</span>
      <DateField name="targetDate" bind:value={targetDate} />
      {#if fields.targetDate}<em>{fields.targetDate}</em>{/if}
    </label>
    <label class="field">
      <span>Pay cycle</span>
      <select name="payCycleId" bind:value={payCycleId}>
        <option value="">Choose a pay cycle</option>
        {#each cycles as cycle (cycle.id)}
          <option value={String(cycle.id)}>{cycle.payor}</option>
        {/each}
      </select>
      {#if fields.payCycleId}<em>{fields.payCycleId}</em>{/if}
    </label>
  {/if}
  {#if loadedTarget && targetKind === loadedTarget.kind}
    <p class="muted m-0">{targetHint(loadedTarget, session.user?.baseCurrency ?? 'USD')}</p>
  {/if}
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save bucket'}</button>
    <a class="btn" href="/categories" onclick={(event) => { event.preventDefault(); go('/categories'); }}>Cancel</a>
    {#if id && !archivedAt && !locked}
      <button class="btn danger" type="button" title="Hide this bucket. Refused while it holds an amount." disabled={saving} onclick={askArchive}>Archive</button>
    {/if}
    {#if id && archivedAt}
      <button class="btn" type="button" title="Put this bucket back on the list." disabled={saving} onclick={unarchive}>Unarchive</button>
    {/if}
  </div>
</form>

<dialog class="modal" bind:this={dialog} aria-labelledby="archive-title">
  <div class="modal-body">
    <h2 id="archive-title" class="text-xl m-0">Archive this bucket?</h2>
    <p class="m-0">Archiving hides the name. The bucket stays in the books.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={closeArchive}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={archive}>Archive bucket</button>
  </div>
</dialog>
