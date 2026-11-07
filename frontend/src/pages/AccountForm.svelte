<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatBps, minorToInput, parseMajor, parsePercent } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const types = ['checking', 'savings', 'cash', 'credit', 'loan', 'fuel', 'other'] as const;

  let name = $state('');
  let type = $state<string>('checking');
  let side = $state('');
  let balance = $state('');
  let interest = $state('');
  let minimumDue = $state('');
  let dueDay = $state('');
  let creditLimit = $state('');
  let institution = $state('');
  let notes = $state('');
  let autopayChoice = $state<'none' | 'minimum' | 'balance'>('none');
  let bankAutopay = $state(false);
  let billRecurrence = $state<'daily' | 'monthly' | 'yearly'>('monthly');
  let billEvery = $state(1);
  let starred = $state(false);
  let othersStarred = $state(false);
  let archivedAt = $state<string | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);

  let showLiability = $derived(type === 'credit' || type === 'loan' || type === 'fuel');
  let showLimit = $derived(type === 'credit' || type === 'fuel');
  let showSide = $derived(type === 'other');
  let showStar = $derived(starred || !othersStarred);
  const everyWord: Record<string, [string, string]> = {
    daily: ['day', 'days'],
    monthly: ['month', 'months'],
    yearly: ['year', 'years'],
  };
  let everyLabel = $derived.by(() => {
    const pair = everyWord[billRecurrence] ?? ['time', 'times'];
    const word = billEvery === 1 ? pair[0] : pair[1];
    return `Every ${billEvery} ${word}`;
  });
  const exponent = 2;

  onMount(async () => {
    try {
      const listed = await api<{ accounts: Account[] }>('/api/accounts');
      othersStarred = listed.accounts.some((account) => account.starred && String(account.id) !== (id ?? ''));
      if (!id) return;
      const data = await api<{ account: Account }>(`/api/accounts/${id}`);
      const account = data.account;
      name = account.name;
      type = account.type;
      side = account.side;
      balance = minorToInput(account.balanceMinor, 2);
      interest = account.interestBps === null ? '' : formatBps(account.interestBps);
      minimumDue = account.minimumDueMinor === null ? '' : minorToInput(account.minimumDueMinor, 2);
      dueDay = account.dueDay === null ? '' : String(account.dueDay);
      creditLimit = account.creditLimitMinor === null ? '' : minorToInput(account.creditLimitMinor, 2);
      institution = account.institution ?? '';
      notes = account.notes ?? '';
      archivedAt = account.archivedAt;
      autopayChoice = account.autopayChoice ?? 'none';
      bankAutopay = account.bankAutopay;
      billRecurrence = account.billRecurrence === 'daily' || account.billRecurrence === 'yearly' ? account.billRecurrence : 'monthly';
      billEvery = account.billEvery >= 1 && account.billEvery <= 11 ? account.billEvery : 1;
      starred = account.starred;
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
      balanceMinor,
      interestBps,
      minimumDueMinor,
      dueDay: showLiability && bankAutopay && billRecurrence !== 'daily' && dueDay !== '' ? Number(dueDay) : null,
      creditLimitMinor,
      autopayChoice: showLiability ? (bankAutopay && autopayChoice === 'none' ? 'balance' : autopayChoice) : 'none',
      bankAutopay: showLiability && bankAutopay,
      billRecurrence: showLiability ? billRecurrence : 'monthly',
      billEvery: showLiability ? billEvery : 1,
      institution,
      notes,
      starred,
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
  {#if showStar}
    <button class="choice w-fit" class:on={starred} type="button" aria-pressed={starred} disabled={archivedAt !== null} onclick={() => (starred = !starred)}>{starred ? '★ Default account' : '☆ Make this the default'}</button>
  {/if}
  <Note bind:message />
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
    <span>Balance</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <input name="balance" inputmode="decimal" bind:value={balance} />
    </span>
    {#if fields.balanceMinor}<em>{fields.balanceMinor}</em>{/if}
  </label>
  <label class="field">
    <span>Annual interest percent</span>
    <input name="interest" inputmode="decimal" placeholder="19.99" bind:value={interest} />
    {#if fields.interestBps}<em>{fields.interestBps}</em>{/if}
  </label>
  {#if showLiability}
    <label class="flex items-center gap-2">
      <input class="w-auto" type="checkbox" name="bankAutopay" bind:checked={bankAutopay} onchange={(event) => { if (event.currentTarget.checked && autopayChoice === 'none') autopayChoice = 'minimum'; }} />
      <span>Autopay is on at the bank</span>
    </label>
    {#if bankAutopay}
      <label class="field">
        <span>Recurrence</span>
        <select name="billRecurrence" bind:value={billRecurrence}>
          <option value="daily">daily</option>
          <option value="monthly">monthly</option>
          <option value="yearly">yearly</option>
        </select>
      </label>
      <label class="span-slider" style={`--span: ${billEvery}; --span-steps: 10`}>
        <span class="span-slider-readout">
          <strong>{billEvery}</strong>
          <span>{everyLabel}</span>
        </span>
        <input
          type="range"
          name="billEvery"
          min="1"
          max="11"
          step="1"
          value={billEvery}
          aria-label={everyLabel}
          oninput={(event) => {
            const raw = Number(event.currentTarget.value);
            if (raw >= 1 && raw <= 11) billEvery = raw;
          }}
        />
        <span class="span-slider-ticks" aria-hidden="true">
          {#each [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] as stop (stop)}
            <span class:on={billEvery === stop}>{stop}</span>
          {/each}
        </span>
      </label>
      {#if fields.billEvery}<em>{fields.billEvery}</em>{/if}
      <label class="field">
        <span>Bill amount</span>
        <select name="autopayChoice" bind:value={autopayChoice}>
          <option value="minimum">Minimum</option>
          <option value="balance">Current balance</option>
        </select>
        {#if fields.autopayChoice}<em>{fields.autopayChoice}</em>{/if}
      </label>
    {/if}
    <label class="field">
      <span>Minimum due</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <input name="minimumDue" inputmode="decimal" bind:value={minimumDue} />
      </span>
      {#if fields.minimumDueMinor}<em>{fields.minimumDueMinor}</em>{/if}
    </label>
    {#if bankAutopay && billRecurrence !== 'daily'}
      <label class="field">
        <span>Due day</span>
        <input name="dueDay" inputmode="numeric" placeholder="1–31" bind:value={dueDay} />
        {#if fields.dueDay}<em>{fields.dueDay}</em>{/if}
      </label>
    {/if}
  {/if}
  {#if showLimit}
    <label class="field">
      <span>Credit limit</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <input name="creditLimit" inputmode="decimal" bind:value={creditLimit} />
      </span>
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
