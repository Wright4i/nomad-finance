<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import DateField from '../components/DateField.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates';
  import { minorToInput, parseMajor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account, Bill, Category } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const groups: Record<string, 'needs' | 'wants' | 'savings'> = {
    lodging: 'needs', rent: 'needs', electricity: 'needs', water: 'needs', fuel: 'needs',
    propane: 'needs', groceries: 'needs', internet: 'needs', cellular: 'needs', vehicle: 'needs',
    maintenance: 'needs', insurance: 'needs', roadside: 'needs', healthcare: 'needs', mailbox: 'needs',
    laundry: 'needs', mortgage: 'needs', storage: 'needs', other: 'needs',
    restaurants: 'wants', entertainment: 'wants', subscriptions: 'wants', shopping: 'wants',
    travel: 'wants', memberships: 'wants', software: 'wants', pets: 'wants', misc: 'wants',
    debt: 'savings', emergency: 'savings', repairs: 'savings', investments: 'savings', liquidities: 'savings',
  };
  const typeGroups = [
    { id: 'needs', label: 'Needs', types: typesIn('needs') },
    { id: 'wants', label: 'Wants', types: typesIn('wants') },
    { id: 'savings', label: 'Savings', types: typesIn('savings') },
  ] as const;

  function typesIn(group: 'needs' | 'wants' | 'savings'): string[] {
    return Object.keys(groups).filter((key) => groups[key] === group);
  }
  const recurrences = ['once', 'weekly', 'monthly', 'yearly'];

  let accounts = $state<Account[]>([]);
  let categories = $state<Category[]>([]);
  let categoryId = $state('');
  let name = $state('');
  let type = $state('rent');
  let otherLabel = $state('');
  let accountId = $state('');
  let amount = $state('');
  let recurrence = $state('monthly');
  let nextDueDate = $state(todayISO());
  let autopay = $state(false);
  let notes = $state('');
  let status = $state<Bill['status']>('active');
  let locked = $state(false);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let ready = $state(false);

  const exponent = 2;
  let bucketChoices = $derived.by(() => {
    const group = groups[type] ?? 'needs';
    return categories.filter((category) => {
      if (category.archivedAt !== null) return false;
      if (category.locked) return category.billType === type;
      if (type === 'fuel') return false;
      return category.group === group && category.role === 'goal';
    });
  });
  let named = $derived(type === 'other' || type === 'misc');

  $effect(() => {
    if (!ready || locked) return;
    const open = bucketChoices;
    if (open.some((category) => String(category.id) === categoryId)) return;
    const fallback = open.find((category) => category.locked && category.billType === type) ?? open[0];
    categoryId = fallback ? String(fallback.id) : '';
  });

  onMount(async () => {
    try {
      const [data, categoryData] = await Promise.all([
        api<{ accounts: Account[] }>('/api/accounts?includeArchived=1'),
        api<{ categories: Category[] }>('/api/categories'),
      ]);
      categories = categoryData.categories;
      accounts = data.accounts.filter((item) => item.archivedAt === null || (id && String(item.id) === accountId));
      if (!id && accounts.length > 0) {
        const open = data.accounts.find((item) => item.archivedAt === null);
        if (open) accountId = String(open.id);
      }
      if (!id) return;
      const billData = await api<{ bill: Bill }>(`/api/bills/${id}`);
      const bill = billData.bill;
      name = bill.name;
      type = bill.type;
      otherLabel = bill.otherLabel ?? '';
      accountId = String(bill.accountId);
      amount = minorToInput(bill.amountMinor, 2);
      recurrence = bill.recurrence;
      nextDueDate = bill.nextDueDate;
      autopay = bill.autopay;
      categoryId = bill.categoryId === null ? '' : String(bill.categoryId);
      notes = bill.notes ?? '';
      status = bill.status;
      locked = bill.locked;
      if (!accounts.some((item) => item.id === bill.accountId)) {
        accounts = [...accounts, {
          id: bill.accountId,
          name: bill.accountName,
          type: 'other',
          side: 'asset',
          currency: bill.currency,
          exponent: bill.exponent,
          balanceMinor: 0,
          interestBps: null,
          minimumDueMinor: null,
          dueDay: null,
          creditLimitMinor: null,
          autopayChoice: 'none',
          institution: null,
          notes: null,
          archivedAt: 'archived',
        }];
      }
    } catch (err) {
      message = explain(err).message;
    } finally {
      ready = true;
    }
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    const amountMinor = parseMajor(amount, exponent);
    if (amountMinor === null || amountMinor < 1) {
      fields.amountMinor = 'Enter an amount greater than zero.';
    }
    if (!accountId) fields.accountId = 'Choose the account this bill is drawn from.';
    if (!categoryId) fields.categoryId = 'Choose a bucket.';
    const dueOn = readDateInput('nextDueDate');
    if (!dueOn) fields.nextDueDate = DATE_INPUT_ERROR;
    if (Object.keys(fields).length > 0) return;
    nextDueDate = dueOn;

    saving = true;
    try {
      const body = {
        name,
        type,
        otherLabel: named ? otherLabel : null,
        accountId: Number(accountId),
        amountMinor,
        recurrence,
        nextDueDate,
        autopay,
        notes,
        categoryId: Number(categoryId),
      };
      const path = id ? `/api/bills/${id}` : '/api/bills';
      await api(path, { method: id ? 'PATCH' : 'POST', body });
      go('/bills');
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = { ...fields, ...problem.fields };
    } finally {
      saving = false;
    }
  }

  async function act(action: string, body: unknown = {}): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/bills/${id}/${action}`, { method: 'POST', body });
      go('/bills');
    } catch (err) {
      message = explain(err).message;
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? name || 'Edit bill' : 'New bill'}</h1>
  <p class="muted m-0">One amount. Saving writes that amount into the bucket on the oldest pay cycle. Marking paid leaves the account and the bucket as they are.</p>
  {#if ready && accounts.length === 0 && !id}
    <p>Create an account first. <a href="/accounts/new" onclick={(event) => { event.preventDefault(); go('/accounts/new'); }}>New account</a></p>
  {/if}
  <Note bind:message />
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <div class="field">
    <span>Type</span>
    <div class="grid gap-3">
      {#each typeGroups as band, bandIndex (band.id)}
        {#if bandIndex > 0}<hr class="m-0" />{/if}
        <div class="flex flex-wrap items-center gap-2">
          <span class="group-title" style={`--tone: var(--tone-${band.id})`}>
            <Glyph name={band.id} mini />
            {band.label}
          </span>
          {#each band.types as option}
            <button class="choice" class:on={type === option} type="button" style={`--tone: var(--tone-${option})`} disabled={locked} onclick={() => (type = option)}>
              <Glyph name={option} mini />
              {option}
            </button>
          {/each}
        </div>
      {/each}
    </div>
    {#if fields.type}<em>{fields.type}</em>{/if}
  </div>
  {#if named}
    <label class="field">
      <span>{type === 'misc' ? 'Misc name' : 'Other name'}</span>
      <input name="otherLabel" bind:value={otherLabel} />
      {#if fields.otherLabel}<em>{fields.otherLabel}</em>{/if}
    </label>
  {/if}
  <label class="field">
    <span>Draw from</span>
    <select name="accountId" bind:value={accountId}>
      <option value="">Choose an account</option>
      {#each accounts as item}
        <option value={String(item.id)}>{item.name}</option>
      {/each}
    </select>
    {#if fields.accountId}<em>{fields.accountId}</em>{/if}
  </label>
  <label class="field">
    <span>Amount</span>
    <input name="amount" inputmode="decimal" bind:value={amount} />
    {#if fields.amountMinor}<em>{fields.amountMinor}</em>{/if}
  </label>
  {#if type === 'fuel' || locked}
    <p class="m-0">Bucket <strong data-bucket={type}>{bucketChoices.find((category) => category.locked)?.name ?? 'Fuel'}</strong>
      {#if locked}<Glyph name="lock" mini />{/if}
    </p>
  {:else}
    <label class="field">
      <span>Bucket</span>
      <select name="categoryId" bind:value={categoryId}>
        {#each bucketChoices as category (category.id)}
          <option value={String(category.id)}>{category.name}</option>
        {/each}
      </select>
      {#if fields.categoryId}<em>{fields.categoryId}</em>{/if}
    </label>
  {/if}
  <label class="field">
    <span>Recurrence</span>
    <select name="recurrence" bind:value={recurrence}>
      {#each recurrences as option}<option value={option}>{option}</option>{/each}
    </select>
  </label>
  <label class="field">
    <span>Next due date</span>
    <DateField name="nextDueDate" bind:value={nextDueDate} />
    {#if fields.nextDueDate}<em>{fields.nextDueDate}</em>{/if}
  </label>
  <label class="flex items-center gap-2">
    <input class="w-auto" type="checkbox" bind:checked={autopay} />
    <span>Autopay is on at the bank</span>
  </label>
  <label class="field">
    <span>Notes</span>
    <textarea name="notes" bind:value={notes}></textarea>
    {#if fields.notes}<em>{fields.notes}</em>{/if}
  </label>
  <div class="flex flex-wrap gap-2">
    {#if !locked && (status === 'active' || status === 'paused' || status === 'paid' || !id)}
      <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save bill'}</button>
    {/if}
    <a class="btn" href="/bills" onclick={(event) => { event.preventDefault(); go('/bills'); }}>Back</a>
    {#if id && status === 'active'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('paid', { paidOn: todayISO() })}>Mark paid</button>
      {#if !locked}
        <button class="btn" type="button" disabled={saving} onclick={() => act('pause')}>Pause</button>
      {/if}
    {/if}
    {#if id && status === 'paused' && !locked}
      <button class="btn" type="button" disabled={saving} onclick={() => act('resume')}>Resume</button>
      <button class="btn" type="button" disabled={saving} onclick={() => act('archive')}>Archive</button>
    {/if}
    {#if id && status === 'archived'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('unarchive')}>Unarchive</button>
    {/if}
    {#if id && status === 'paid'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('unpay')}>Unpay</button>
    {/if}
  </div>
</form>
