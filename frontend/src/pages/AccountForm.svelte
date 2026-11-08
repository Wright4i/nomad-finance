<script lang="ts">
  import AmountInput from '../components/AmountInput.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatBps, minorToInput, parseMajor, parsePercent } from '../lib/money';
  import { fieldTips } from '../lib/fieldTips';
  import { go } from '../lib/nav.svelte.ts';
  import { tip } from '../lib/tip';
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
  let holder = $state('');
  let notes = $state('');
  let autopayChoice = $state<'none' | 'minimum' | 'balance'>('none');
  let bankAutopay = $state(false);
  let billRecurrence = $state<'daily' | 'monthly' | 'yearly'>('monthly');
  let billEvery = $state(1);
  let gasFavorite = $state(false);
  let shoppingFavorite = $state(false);
  let assetFavorite = $state(false);
  let inBudget = $state(true);
  let budgetTouched = $state(false);
  let othersGas = $state(false);
  let othersShopping = $state(false);
  let othersAsset = $state(false);
  let archivedAt = $state<string | null>(null);
  let deleteDialog: HTMLDialogElement | undefined = $state();
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let loading = $state(id !== null);

  let showLiability = $derived(type === 'credit' || type === 'loan' || type === 'fuel');
  let showLimit = $derived(type === 'credit' || type === 'fuel');
  let showSide = $derived(type === 'other');
  let showBudget = $derived(type === 'checking' || type === 'savings' || type === 'cash' || (type === 'other' && side === 'asset'));
  let markFree = $derived(!gasFavorite && !shoppingFavorite && !assetFavorite);
  let showGas = $derived(type === 'fuel' && (gasFavorite || (markFree && !othersGas)));
  let showShopping = $derived(type === 'credit' && (shoppingFavorite || (markFree && !othersShopping)));
  let showAsset = $derived((type === 'checking' || type === 'savings' || type === 'cash' || (type === 'other' && side === 'asset')) && (assetFavorite || (markFree && !othersAsset)));
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
      const others = listed.accounts.filter((account) => String(account.id) !== (id ?? ''));
      othersGas = others.some((account) => account.gasFavorite);
      othersShopping = others.some((account) => account.shoppingFavorite);
      othersAsset = others.some((account) => account.assetFavorite);
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
      holder = account.holder ?? '';
      notes = account.notes ?? '';
      archivedAt = account.archivedAt;
      autopayChoice = account.autopayChoice ?? 'none';
      bankAutopay = account.bankAutopay;
      billRecurrence = account.billRecurrence === 'daily' || account.billRecurrence === 'yearly' ? account.billRecurrence : 'monthly';
      billEvery = account.billEvery >= 1 && account.billEvery <= 11 ? account.billEvery : 1;
      gasFavorite = account.gasFavorite;
      shoppingFavorite = account.shoppingFavorite;
      assetFavorite = account.assetFavorite;
      budgetTouched = true;
      inBudget = account.inBudget;
    } catch (err) {
      message = explain(err).message;
    } finally {
      loading = false;
    }
  });

  function chooseType(option: string): void {
    type = option;
    if (option !== 'fuel') gasFavorite = false;
    if (option !== 'credit') shoppingFavorite = false;
    if (option !== 'checking' && option !== 'savings' && option !== 'cash' && option !== 'other') assetFavorite = false;
    if (budgetTouched) return;
    const asset = option === 'checking' || option === 'savings' || option === 'cash' || (option === 'other' && side === 'asset');
    inBudget = asset && option !== 'savings';
  }

  function chooseFavorite(kind: 'gas' | 'shopping' | 'asset'): void {
    const current = kind === 'gas' ? gasFavorite : kind === 'shopping' ? shoppingFavorite : assetFavorite;
    const next = !current;
    gasFavorite = kind === 'gas' && next;
    shoppingFavorite = kind === 'shopping' && next;
    assetFavorite = kind === 'asset' && next;
  }

  function chooseSide(next: string): void {
    side = next;
    if (next !== 'asset') assetFavorite = false;
    if (budgetTouched) return;
    inBudget = next === 'asset' && type !== 'savings';
  }

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
      holder,
      notes,
      gasFavorite,
      shoppingFavorite,
      assetFavorite,
      inBudget: showBudget && inBudget,
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

  function askDelete(): void {
    deleteDialog?.showModal();
  }

  async function remove(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/accounts/${id}/delete`, { method: 'POST', body: { confirm: true } });
      deleteDialog?.close();
      go('/accounts');
    } catch (err) {
      deleteDialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" aria-busy={loading} onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? name || 'Edit account' : 'New account'}</h1>
  <Note bind:message />
  <div class="form-body grid gap-4" aria-busy={loading} inert={loading}>
    {#if loading}
      <div class="form-cover">
        <Spinner label="Loading account" />
      </div>
    {/if}
    <div class="grid gap-4">
  {#if showGas}
    <button class="choice w-fit" class:on={gasFavorite} type="button" data-favorite="gas" aria-pressed={gasFavorite} disabled={archivedAt !== null} use:tip={fieldTips.accountGas} onclick={() => chooseFavorite('gas')}>
      <Glyph name="fuel" mini />
      {gasFavorite ? 'Gas favorite' : 'Make this the gas favorite'}
    </button>
  {/if}
  {#if showShopping}
    <button class="choice w-fit" class:on={shoppingFavorite} type="button" data-favorite="shopping" aria-pressed={shoppingFavorite} disabled={archivedAt !== null} use:tip={fieldTips.accountShopping} onclick={() => chooseFavorite('shopping')}>
      <Glyph name="shopping" mini />
      {shoppingFavorite ? 'Shopping favorite' : 'Make this the shopping favorite'}
    </button>
  {/if}
  {#if showAsset}
    <button class="choice w-fit" class:on={assetFavorite} type="button" data-favorite="asset" aria-pressed={assetFavorite} disabled={archivedAt !== null} use:tip={fieldTips.accountAsset} onclick={() => chooseFavorite('asset')}>
      <Glyph name="vault" mini />
      {assetFavorite ? 'Asset favorite' : 'Make this the asset favorite'}
    </button>
  {/if}
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <div class="field">
    <span use:tip={fieldTips.accountType}>Type</span>
    <div class="flex flex-wrap gap-2">
      {#each types as option}
        <button class="choice" class:on={type === option} type="button" style={`--tone: var(--tone-${option})`} onclick={() => chooseType(option)}>
          <Glyph name={option} mini />
          {option}
        </button>
      {/each}
    </div>
    {#if fields.type}<em>{fields.type}</em>{/if}
  </div>
  {#if showSide}
    <div class="field">
      <span use:tip={fieldTips.accountSide}>Side</span>
      <div class="flex gap-2">
        <button class="choice" class:on={side === 'asset'} type="button" onclick={() => chooseSide('asset')}>asset</button>
        <button class="choice" class:on={side === 'liability'} type="button" onclick={() => chooseSide('liability')}>liability</button>
      </div>
      {#if fields.side}<em>{fields.side}</em>{/if}
    </div>
  {/if}
  <label class="field">
    <span use:tip={fieldTips.accountBalance}>Balance</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <AmountInput name="balance" bind:value={balance} />
    </span>
    {#if fields.balanceMinor}<em>{fields.balanceMinor}</em>{/if}
  </label>
  {#if showBudget}
    <label class="flex items-center gap-2">
      <input class="w-auto" type="checkbox" name="inBudget" bind:checked={inBudget} onchange={() => (budgetTouched = true)} />
      <span use:tip={fieldTips.accountBudget}>Include in Budget</span>
    </label>
  {/if}
  <label class="field">
    <span use:tip={fieldTips.accountInterest}>Annual interest percent</span>
    <input name="interest" inputmode="decimal" placeholder="19.99" bind:value={interest} />
    {#if fields.interestBps}<em>{fields.interestBps}</em>{/if}
  </label>
  {#if showLiability}
    <label class="flex items-center gap-2">
      <input class="w-auto" type="checkbox" name="bankAutopay" bind:checked={bankAutopay} onchange={(event) => { if (event.currentTarget.checked && autopayChoice === 'none') autopayChoice = 'minimum'; }} />
      <span use:tip={fieldTips.accountBankAutopay}>Autopay is on at the bank</span>
    </label>
    {#if bankAutopay}
      <label class="field">
        <span use:tip={fieldTips.accountRecurrence}>Recurrence</span>
        <select name="billRecurrence" bind:value={billRecurrence}>
          <option value="daily">daily</option>
          <option value="monthly">monthly</option>
          <option value="yearly">yearly</option>
        </select>
      </label>
      <label class="span-slider" style={`--span: ${billEvery}; --span-steps: 10`}>
        <span class="span-slider-readout">
          <strong>{billEvery}</strong>
          <span use:tip={fieldTips.accountBillEvery}>{everyLabel}</span>
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
        <span use:tip={fieldTips.accountBillAmount}>Bill amount</span>
        <select name="autopayChoice" bind:value={autopayChoice}>
          <option value="minimum">Minimum</option>
          <option value="balance">Current balance</option>
        </select>
        {#if fields.autopayChoice}<em>{fields.autopayChoice}</em>{/if}
      </label>
    {/if}
    <label class="field">
      <span use:tip={fieldTips.accountMinimum}>Minimum due</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <AmountInput name="minimumDue" bind:value={minimumDue} />
      </span>
      {#if fields.minimumDueMinor}<em>{fields.minimumDueMinor}</em>{/if}
    </label>
    {#if bankAutopay && billRecurrence !== 'daily'}
      <label class="field">
        <span use:tip={fieldTips.accountDueDay}>Due day</span>
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
        <AmountInput name="creditLimit" bind:value={creditLimit} />
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
    <span use:tip={fieldTips.accountHolder}>Holder</span>
    <input name="holder" bind:value={holder} />
    {#if fields.holder}<em>{fields.holder}</em>{/if}
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
      <button class="btn danger" type="button" title="Remove this archived account." disabled={saving} onclick={askDelete}>Delete</button>
    {/if}
  </div>
  </div>
  </div>
</form>

<dialog class="modal" bind:this={deleteDialog} aria-labelledby="delete-account-title">
  <div class="modal-body">
    <h2 id="delete-account-title" class="text-xl m-0">Delete this account?</h2>
    <p class="m-0">This removes the archived account. It does not come back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={() => deleteDialog?.close()}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={remove}>Delete account</button>
  </div>
</dialog>
