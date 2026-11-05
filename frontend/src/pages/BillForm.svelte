<script lang="ts">
  import { onMount } from 'svelte';
  import DateField from '../components/DateField.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import CurrencySelect from '../components/CurrencySelect.svelte';
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates';
  import { exponentOf, minorToInput, parseMajor, todayISO } from '../lib/money';
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
  const types = Object.keys(groups);
  const recurrences = ['once', 'weekly', 'monthly', 'yearly'];

  let accounts = $state<Account[]>([]);
  let categories = $state<Category[]>([]);
  let categoryId = $state('');
  let name = $state('');
  let type = $state('rent');
  let otherLabel = $state('');
  let accountId = $state('');
  let amount = $state('');
  let currency = $state('');
  let currencyTouched = $state(false);
  let recurrence = $state('monthly');
  let nextDueDate = $state(todayISO());
  let autopay = $state(false);
  let notes = $state('');
  let status = $state<Bill['status']>('active');
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let ready = $state(false);

  let account = $derived(accounts.find((item) => String(item.id) === accountId) ?? null);
  let exponent = $derived(exponentOf(currency || account?.currency || 'USD'));
  let bucketChoices = $derived.by(() => {
    const group = groups[type] ?? 'needs';
    return categories.filter((category) => {
      if (category.archivedAt !== null) return false;
      if (type === 'fuel') return category.role === 'fuel';
      return category.group === group && category.role !== 'fuel';
    });
  });
  let named = $derived(type === 'other' || type === 'misc');

  $effect(() => {
    if (id || currencyTouched || !account) return;
    currency = account.currency;
  });

  $effect(() => {
    if (!ready) return;
    const open = bucketChoices;
    if (open.some((category) => String(category.id) === categoryId)) return;
    const fallback = type === 'fuel' ? open[0] : open.find((category) => category.role === 'default');
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
      amount = minorToInput(bill.amountMinor, bill.exponent);
      currency = bill.currency;
      currencyTouched = true;
      recurrence = bill.recurrence;
      nextDueDate = bill.nextDueDate;
      autopay = bill.autopay;
      categoryId = bill.categoryId === null ? '' : String(bill.categoryId);
      notes = bill.notes ?? '';
      status = bill.status;
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
    if (!/^[A-Za-z]{3}$/.test(currency.trim())) fields.currency = 'Use a three-letter currency code.';
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
        currency: currency.trim().toUpperCase(),
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
  <p class="muted m-0">One amount in the currency you choose. Saving writes that amount into the bucket on the oldest pay cycle. Marking paid leaves the account and the bucket as they are.</p>
  {#if ready && accounts.length === 0 && !id}
    <p>Create an account first. <a href="/accounts/new" onclick={(event) => { event.preventDefault(); go('/accounts/new'); }}>New account</a></p>
  {/if}
  {#if message}<p class="banner m-0">{message}</p>{/if}
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <div class="field">
    <span>Type</span>
    <div class="flex flex-wrap gap-2">
      {#each types as option}
        <button class="choice" class:on={type === option} type="button" style={`--tone: var(--tone-${option})`} onclick={() => (type = option)}>
          <Glyph name={option} mini />
          {option}
        </button>
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
        <option value={String(item.id)}>{item.name} · {item.currency}</option>
      {/each}
    </select>
    {#if fields.accountId}<em>{fields.accountId}</em>{/if}
  </label>
  <div class="grid grid-cols-2 gap-3">
    <label class="field">
      <span>Amount</span>
      <input name="amount" inputmode="decimal" bind:value={amount} />
      {#if fields.amountMinor}<em>{fields.amountMinor}</em>{/if}
    </label>
    <label class="field">
      <span>Currency</span>
      <CurrencySelect
        name="currency"
        bind:value={currency}
        onchange={() => (currencyTouched = true)}
      />
      {#if fields.currency}<em>{fields.currency}</em>{/if}
    </label>
  </div>
  {#if type === 'fuel'}
    <p class="m-0">Bucket <strong data-bucket="fuel">{bucketChoices[0]?.name ?? 'Fuel Bucket'}</strong></p>
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
    {#if status === 'active' || status === 'paused' || !id}
      <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save bill'}</button>
    {/if}
    <a class="btn" href="/bills" onclick={(event) => { event.preventDefault(); go('/bills'); }}>Back</a>
    {#if id && status === 'active'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('paid', { paidOn: todayISO() })}>Mark paid</button>
      <button class="btn" type="button" disabled={saving} onclick={() => act('pause')}>Pause</button>
    {/if}
    {#if id && status === 'paused'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('resume')}>Resume</button>
    {/if}
    {#if id && status === 'paid'}
      <button class="btn" type="button" disabled={saving} onclick={() => act('unpay')}>Unpay</button>
    {/if}
  </div>
</form>
