<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import CurrencySelect from '../components/CurrencySelect.svelte';
  import { exponentOf, formatBps, minorToInput, parseMajor, parsePercent } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const types = ['checking', 'savings', 'cash', 'credit', 'loan', 'other'] as const;

  let name = $state('');
  let type = $state<string>('checking');
  let side = $state('');
  let currency = $state('USD');
  let balance = $state('');
  let interest = $state('');
  let minimumDue = $state('');
  let dueDay = $state('');
  let creditLimit = $state('');
  let institution = $state('');
  let notes = $state('');
  let archivedAt = $state<string | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);

  let showLiability = $derived(type === 'credit' || type === 'loan');
  let showLimit = $derived(type === 'credit');
  let showSide = $derived(type === 'other');
  let exponent = $derived(exponentOf(currency));

  onMount(async () => {
    if (!id) return;
    try {
      const data = await api<{ account: Account }>(`/api/accounts/${id}`);
      const account = data.account;
      name = account.name;
      type = account.type;
      side = account.side;
      currency = account.currency;
      balance = minorToInput(account.balanceMinor, account.exponent);
      interest = account.interestBps === null ? '' : formatBps(account.interestBps);
      minimumDue = account.minimumDueMinor === null ? '' : minorToInput(account.minimumDueMinor, account.exponent);
      dueDay = account.dueDay === null ? '' : String(account.dueDay);
      creditLimit = account.creditLimitMinor === null ? '' : minorToInput(account.creditLimitMinor, account.exponent);
      institution = account.institution ?? '';
      notes = account.notes ?? '';
      archivedAt = account.archivedAt;
    } catch (err) {
      message = explain(err).message;
    }
  });

  function blankMinor(input: string, key: string): number | null {
    if (input.trim() === '') return null;
    const minor = parseMajor(input, exponent);
    if (minor === null) {
      fields[key] = exponent === 0 ? 'Use a whole number.' : `Use up to ${exponent} decimal places.`;
      return null;
    }
    return minor;
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    const balanceMinor = parseMajor(balance, exponent);
    if (balanceMinor === null) {
      fields.balanceMinor = exponent === 0 ? 'Use a whole number.' : `Use up to ${exponent} decimal places.`;
    }
    let interestBps: number | null = null;
    if (interest.trim() !== '') {
      interestBps = parsePercent(interest);
      if (interestBps === null) fields.interestBps = 'Use a percent like 19.99.';
    }
    const minimumDueMinor = showLiability ? blankMinor(minimumDue, 'minimumDueMinor') : null;
    const creditLimitMinor = showLimit ? blankMinor(creditLimit, 'creditLimitMinor') : null;
    if (Object.keys(fields).length > 0) return;

    const body = {
      name,
      type,
      side: showSide ? side : undefined,
      currency,
      balanceMinor,
      interestBps,
      minimumDueMinor,
      dueDay: showLiability && dueDay !== '' ? Number(dueDay) : null,
      creditLimitMinor,
      institution,
      notes,
    };
    saving = true;
    try {
      const path = id ? `/api/accounts/${id}` : '/api/accounts';
      const method = id ? 'PATCH' : 'POST';
      await api(path, { method, body });
      go('/accounts');
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  async function archive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/accounts/${id}/archive`, { method: 'POST', body: {} });
      go('/accounts');
    } catch (err) {
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
      await api(`/api/accounts/${id}/unarchive`, { method: 'POST', body: {} });
      go('/accounts');
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? name || 'Edit account' : 'New account'}</h1>
  <p class="muted m-0">The balance is the amount on deposit, or the amount owed on a card or loan. It stays until you type a new one.</p>
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
  {#if showSide}
    <div class="field">
      <span>Side</span>
      <div class="flex gap-2">
        <button class="choice" class:on={side === 'asset'} type="button" onclick={() => (side = 'asset')}>asset</button>
        <button class="choice" class:on={side === 'liability'} type="button" onclick={() => (side = 'liability')}>liability</button>
      </div>
      {#if fields.side}<em>{fields.side}</em>{/if}
    </div>
  {/if}
  <label class="field">
    <span>Currency</span>
    <CurrencySelect name="currency" bind:value={currency} />
    {#if fields.currency}<em>{fields.currency}</em>{/if}
  </label>
  <label class="field">
    <span>Balance ({currency.toUpperCase() || 'currency'})</span>
    <input name="balance" inputmode="decimal" bind:value={balance} />
    {#if fields.balanceMinor}<em>{fields.balanceMinor}</em>{/if}
  </label>
  <label class="field">
    <span>Annual interest percent</span>
    <input name="interest" inputmode="decimal" placeholder="19.99" bind:value={interest} />
    {#if fields.interestBps}<em>{fields.interestBps}</em>{/if}
  </label>
  {#if showLiability}
    <label class="field">
      <span>Minimum due</span>
      <input name="minimumDue" inputmode="decimal" bind:value={minimumDue} />
      {#if fields.minimumDueMinor}<em>{fields.minimumDueMinor}</em>{/if}
    </label>
    <label class="field">
      <span>Due day</span>
      <input name="dueDay" inputmode="numeric" placeholder="1–31" bind:value={dueDay} />
      {#if fields.dueDay}<em>{fields.dueDay}</em>{/if}
    </label>
  {/if}
  {#if showLimit}
    <label class="field">
      <span>Credit limit</span>
      <input name="creditLimit" inputmode="decimal" bind:value={creditLimit} />
      {#if fields.creditLimitMinor}<em>{fields.creditLimitMinor}</em>{/if}
    </label>
  {/if}
  <label class="field">
    <span>Institution</span>
    <input name="institution" bind:value={institution} />
    {#if fields.institution}<em>{fields.institution}</em>{/if}
  </label>
  <label class="field">
    <span>Notes</span>
    <textarea name="notes" bind:value={notes}></textarea>
    {#if fields.notes}<em>{fields.notes}</em>{/if}
  </label>
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save account'}</button>
    <a class="btn" href="/accounts" onclick={(event) => { event.preventDefault(); go('/accounts'); }}>Cancel</a>
    {#if id && !archivedAt}
      <button class="btn danger" type="button" disabled={saving} onclick={archive}>Archive</button>
    {/if}
    {#if id && archivedAt}
      <button class="btn" type="button" title="Put this account back on the list." disabled={saving} onclick={unarchive}>Unarchive</button>
    {/if}
  </div>
</form>
