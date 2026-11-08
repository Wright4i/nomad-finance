<script lang="ts">
  import { onMount, tick } from 'svelte';
  import Celebrate from '../components/Celebrate.svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { api, explain } from '../lib/api';
  import { formatBps, formatMinor, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { session } from '../lib/session.svelte.ts';
  import { accountLabel } from '../lib/accountLabel';
  import { payFromAccount } from '../lib/payFrom';
  import { fieldTips } from '../lib/fieldTips';
  import { tip } from '../lib/tip';
  import type { Account } from '../lib/types';

  type Charge = {
    id: number;
    name: string;
    categoryId: number;
    categoryName: string;
    group: string;
    amountMinor: number;
    dueOn: string;
  };

  type CardPreview = {
    card: AccountView;
    paymentMinor: number;
    charges: Charge[];
  };

  type AccountView = {
    id: number;
    name: string;
    type: string;
    balanceMinor: number;
    inBudget: boolean;
  };

  type MovePreview = {
    source: AccountView;
    destination: AccountView;
    amountMinor: number;
  };

  let accounts = $state<Account[]>([]);
  let loading = $state(true);
  let message = $state('');
  let modalMessage = $state('');
  let mode = $state<'card' | 'move'>('card');
  let cardId = $state('');
  let sourceId = $state('');
  let destinationId = $state('');
  let amountText = $state('');
  let preview = $state<CardPreview | null>(null);
  let movePreview = $state<MovePreview | null>(null);
  let selected = $state<number[]>([]);
  let coverGap = $state(false);
  let confirming = $state(false);
  let saving = $state(false);
  let confirmDialog = $state<HTMLDialogElement | undefined>();
  let posted = $state<{ amount: number; card: string; from: string } | null>(null);
  let postedDialog = $state<HTMLDialogElement | undefined>();

  let currency = $derived(session.user?.baseCurrency ?? 'USD');
  let cards = $derived(
    accounts.filter((account) =>
      account.archivedAt === null
      && (account.type === 'credit' || account.type === 'fuel')
      && account.autopayChoice === 'balance'
      && account.bankAutopay
      && account.balanceMinor > 0,
    ),
  );
  let assets = $derived(accounts.filter((account) => account.archivedAt === null && account.side === 'asset'));
  let fundedAssets = $derived(assets.filter((account) => account.balanceMinor > 0));
  let cardAccount = $derived(accounts.find((account) => String(account.id) === cardId) ?? null);
  let sourceAccount = $derived(accounts.find((account) => String(account.id) === sourceId) ?? null);
  let destinationAccount = $derived(accounts.find((account) => String(account.id) === destinationId) ?? null);

  function money(minor: number): string {
    return formatMinor(minor, currency, 2);
  }

  function optionLabel(account: Account): string {
    return `${accountLabel(account)} - ${money(account.balanceMinor)}`;
  }

  function amountMinor(): number {
    const parsed = parseMajor(amountText.trim() === '' ? '0' : amountText, 2);
    return parsed ?? 0;
  }

  function chipsFor(account: Account): { key: string; label: string }[] {
    const chips: { key: string; label: string }[] = [];
    if ((account.type === 'credit' || account.type === 'fuel') && account.creditLimitMinor !== null) {
      chips.push({
        key: 'limit',
        label: `Credit limit ${formatMinor(account.creditLimitMinor, account.currency, account.exponent)}`,
      });
    }
    if (account.interestBps !== null) {
      chips.push({ key: 'apr', label: `APR ${formatBps(account.interestBps)}%` });
    }
    if (account.side === 'liability' && account.minimumDueMinor !== null) {
      chips.push({
        key: 'minimum',
        label: `Minimum ${formatMinor(account.minimumDueMinor, account.currency, account.exponent)}`,
      });
    }
    if (account.bankAutopay && account.autopayChoice === 'minimum') {
      chips.push({ key: 'autopay-min', label: 'Autopay min' });
    } else if (account.bankAutopay && account.autopayChoice === 'balance') {
      chips.push({ key: 'autopay-full', label: 'Autopay full' });
    }
    if (account.holder) {
      chips.push({ key: 'holder', label: account.holder });
    }
    return chips;
  }

  function budgetLabel(account: Account): string {
    return account.inBudget ? 'Included in available to assign' : 'Left out of available to assign';
  }

  let selectedCharges = $derived((preview?.charges ?? []).filter((charge) => selected.includes(charge.id)));
  let selectedMinor = $derived(selectedCharges.reduce((sum, charge) => sum + charge.amountMinor, 0));
  let dueMinor = $derived(cardAccount?.balanceMinor ?? preview?.card.balanceMinor ?? 0);
  let paymentMinor = $derived(Math.max(0, amountMinor()));
  let beyondMinor = $derived(Math.max(0, paymentMinor - selectedMinor));
  let coveredMinor = $derived(selectedMinor + (coverGap ? beyondMinor : 0));
  let waterLevel = $derived(paymentMinor > 0 ? Math.min(1, coveredMinor / paymentMinor) : 0);
  let waterFull = $derived(paymentMinor > 0 && coveredMinor >= paymentMinor);
  let waterFill = $derived(paymentMinor < 1 ? 'empty' : waterFull ? 'full' : 'short');
  let waterState = $derived(
    paymentMinor < 1
      ? 'Enter the payment'
      : waterFull && selectedMinor >= paymentMinor
        ? 'Buckets cover this payment'
        : waterFull
          ? 'This payment is covered'
          : 'Buckets cover part of this payment',
  );
  let overSource = $derived(sourceAccount !== null && paymentMinor > sourceAccount.balanceMinor);
  let overCard = $derived(dueMinor > 0 && paymentMinor > dueMinor);
  let cardBlocked = $derived(paymentMinor < 1 || overSource || overCard || selectedMinor > paymentMinor);
  let cardChosen = $derived(selected.length > 0 || (coverGap && beyondMinor >= 1));
  let cardReady = $derived(
    confirming
    && mode === 'card'
    && sourceId !== ''
    && cardChosen
    && !cardBlocked,
  );
  let accountShort = $derived(
    sourceAccount !== null && amountMinor() > sourceAccount.balanceMinor
      ? amountMinor() - sourceAccount.balanceMinor
      : 0,
  );
  let moveReady = $derived(
    confirming
    && mode === 'move'
    && movePreview !== null
    && accountShort === 0,
  );

  async function loadAccounts(): Promise<void> {
    const data = await api<{ accounts: Account[] }>('/api/accounts');
    accounts = data.accounts;
    if (sourceId === '') {
      const asset = payFromAccount(accounts);
      if (asset) sourceId = String(asset.id);
    }
  }

  async function loadCard(): Promise<void> {
    const requested = cardId;
    if (message !== 'Paid.' && message !== 'Transferred.') message = '';
    closeConfirm();
    if (requested === '') {
      preview = null;
      selected = [];
      amountText = '';
      coverGap = false;
      return;
    }
    if (preview && String(preview.card.id) !== requested) {
      preview = null;
      selected = [];
    }
    const data = await api<CardPreview>(`/api/transfers/card?cardId=${requested}&today=${todayISO()}`);
    if (cardId !== requested) return;
    preview = data;
    selected = [];
    coverGap = false;
    const due = accounts.find((account) => String(account.id) === requested)?.balanceMinor ?? data.card.balanceMinor;
    amountText = minorToInput(Math.max(0, due), 2);
  }

  function fitSelection(): void {
    const goal = Math.max(0, amountMinor());
    let used = 0;
    const kept: number[] = [];
    for (const id of selected) {
      const charge = (preview?.charges ?? []).find((item) => item.id === id);
      if (!charge || used + charge.amountMinor > goal) continue;
      kept.push(id);
      used += charge.amountMinor;
    }
    if (kept.length !== selected.length) selected = kept;
  }

  function toggle(charge: Charge): void {
    if (selected.includes(charge.id)) {
      selected = selected.filter((id) => id !== charge.id);
    } else if (paymentMinor >= 1 && selectedMinor + charge.amountMinor <= paymentMinor) {
      selected = [...selected, charge.id];
    }
    closeConfirm();
  }

  function chooseMode(next: 'card' | 'move'): void {
    mode = next;
    message = '';
    closeConfirm();
    movePreview = null;
  }

  function closeConfirm(): void {
    if (saving) return;
    modalMessage = '';
    if (confirmDialog?.open) confirmDialog.close();
    confirming = false;
  }

  async function openConfirm(): Promise<void> {
    message = '';
    modalMessage = '';
    if (mode === 'move') {
      if (sourceAccount !== null && amountMinor() > sourceAccount.balanceMinor) {
        movePreview = null;
        confirming = true;
        await tick();
        if (confirmDialog && !confirmDialog.open) confirmDialog.showModal();
        return;
      }
      try {
        movePreview = await api<MovePreview>('/api/transfers/preview', {
          method: 'POST',
          body: {
            today: todayISO(),
            sourceId: Number(sourceId),
            destinationId: Number(destinationId),
            amountMinor: amountMinor(),
          },
        });
      } catch (err) {
        message = explain(err).message;
        return;
      }
    }
    if (mode === 'card' && !cardChosen) return;
    confirming = true;
    await tick();
    if (confirmDialog && !confirmDialog.open) confirmDialog.showModal();
  }

  async function confirmCard(): Promise<void> {
    if (!cardReady || saving) return;
    saving = true;
    modalMessage = '';
    try {
      await api('/api/transfers/card', {
        method: 'POST',
        body: {
          today: todayISO(),
          cardId: Number(cardId),
          sourceId: Number(sourceId),
          amountMinor: amountMinor(),
          billIds: selected,
        },
      });
      saving = false;
      const receipt = cardAccount && sourceAccount
        ? { amount: amountMinor(), card: cardAccount.name, from: sourceAccount.name }
        : null;
      closeConfirm();
      await loadAccounts();
      await loadCard();
      posted = receipt;
      message = 'Paid.';
    } catch (err) {
      const text = explain(err).message;
      if (confirmDialog?.open) modalMessage = text;
      else message = text;
    } finally {
      saving = false;
    }
  }

  async function confirmMove(): Promise<void> {
    if (!moveReady || saving) return;
    saving = true;
    modalMessage = '';
    try {
      await api('/api/transfers', {
        method: 'POST',
        body: {
          today: todayISO(),
          sourceId: Number(sourceId),
          destinationId: Number(destinationId),
          amountMinor: amountMinor(),
        },
      });
      saving = false;
      closeConfirm();
      movePreview = null;
      await loadAccounts();
      message = 'Transferred.';
    } catch (err) {
      const text = explain(err).message;
      if (confirmDialog?.open) modalMessage = text;
      else message = text;
    } finally {
      saving = false;
    }
  }

  function celebrateDone(): void {
    if (!posted || confirmDialog?.open || !postedDialog || postedDialog.open) return;
    postedDialog.showModal();
  }

  function afterMinor(balance: number, delta: number): number {
    return Math.max(0, balance + delta);
  }

  onMount(() => {
    loadAccounts()
      .catch((err: unknown) => {
        message = explain(err).message;
      })
      .finally(() => {
        loading = false;
      });
  });
</script>

{#snippet waves()}
  <span class="bucket-wave"></span>
  <span class="bucket-wave"></span>
  <span class="bucket-wave"></span>
{/snippet}

{#snippet accountCard(account: Account)}
  {@const chips = chipsFor(account)}
  <article class="transfer-account" data-account={account.name} data-in-budget={account.inBudget ? 'yes' : 'no'} style={`--tone: var(--tone-${account.type})`}>
    <span class="account-id">
      <Glyph name={account.type} mini />
      <span class="account-id-text">
        <strong>{account.name}</strong>
        {#if account.institution}
          <span class="account-institution">{account.institution}</span>
        {/if}
      </span>
      {#if account.side === 'asset' && account.inBudget}
        <span class="account-budget" role="img" title={budgetLabel(account)} aria-label={budgetLabel(account)}>
          <Glyph name="envelope" mini />
        </span>
      {/if}
    </span>
    <span class="muted text-sm">
      <span class="type-label">{account.type}</span>
    </span>
    {#if chips.length > 0}
      <span class="bill-detail-chips">
        {#each chips as chip (chip.key)}
          <span class="bill-detail-chip" data-chip={chip.key}>{chip.label}</span>
        {/each}
      </span>
    {/if}
    <strong class="money" class:coral={account.side === 'liability'}>{money(account.balanceMinor)}</strong>
  </article>
{/snippet}

{#snippet flowStream(amount: number)}
  <div class="transfer-stream">
    <div class="transfer-stream-body">
      <span class="transfer-arrow-slot" aria-hidden="true">
        <svg class="transfer-arrow" viewBox="0 0 148 80" fill="none">
          <defs>
            <linearGradient id="transfer-arrow-fill" x1="0" y1="0" x2="148" y2="0" gradientUnits="userSpaceOnUse">
              <stop offset="0%" style="stop-color: var(--from, var(--teal))" />
              <stop offset="100%" style="stop-color: var(--to, var(--coral))" />
            </linearGradient>
          </defs>
          <path d="M18 40 H96" stroke="url(#transfer-arrow-fill)" stroke-width="16" stroke-linecap="round" />
          <path d="M62 12 L112 40 L62 68" stroke="url(#transfer-arrow-fill)" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </span>
      <p class="transfer-amount money m-0">{money(amount)}</p>
    </div>
  </div>
{/snippet}

{#snippet flowEnd(account: Account, nextBalance: number | null)}
  <div class="transfer-pole">
    <div class="transfer-end" data-type={account.type}>
      <Glyph name={account.type} />
      <strong>{account.name}</strong>
      <span class="account-institution">{account.institution ?? ''}</span>
      <span class="money">{money(account.balanceMinor)}</span>
    </div>
    {#if nextBalance !== null}
      <p class="transfer-after">After {money(nextBalance)}</p>
    {/if}
  </div>
{/snippet}

<div class="grid gap-4">
  <h1 class="m-0 text-4xl">Transfers</h1>
  <Celebrate bind:message ondone={celebrateDone} />
  {#if message !== 'Paid.' && message !== 'Transferred.'}
    <Note bind:message />
  {/if}
  {#if loading}
    <Spinner />
  {:else}
    <div class="flex flex-wrap gap-2" role="group" aria-label="Transfer kind">
      <button class="btn" class:primary={mode === 'card'} type="button" onclick={() => chooseMode('card')}>Pay a card</button>
      <button class="btn" class:primary={mode === 'move'} type="button" onclick={() => chooseMode('move')}>Between accounts</button>
    </div>

    {#if mode === 'card'}
      <label class="field">
        <span use:tip={fieldTips.card}>Card</span>
        <select name="cardId" bind:value={cardId} onchange={() => void loadCard()}>
          <option value="">Choose a paid-in-full card</option>
          {#each cards as card (card.id)}
            <option value={String(card.id)}>{optionLabel(card)}</option>
          {/each}
        </select>
      </label>
      {#if cards.length === 0}
        <p class="muted m-0">No paid-in-full card is on these books.</p>
      {/if}
      {#if preview && cardAccount}
        <section class="grid gap-2">
          <h2 class="m-0 text-2xl">Card balance</h2>
          <div class="pay-stage">
            {@render accountCard(cardAccount)}
            <article
              class="card-tank"
              data-fill={waterFill}
              data-level={waterLevel.toFixed(2)}
              style={`--level: ${waterLevel}`}
              aria-label="Card bucket"
            >
              <span class="bucket-water" aria-hidden="true">
                {@render waves()}
              </span>
              <span class="tank-copy tank-kicker">
                <Glyph name={cardAccount.type} mini />
                Card
              </span>
              <span class="tank-copy tank-figures">
                <span>Payment</span>
                <strong class="money">{money(paymentMinor)}</strong>
                <span>Buckets {money(selectedMinor)}</span>
                <span aria-live="polite">{waterState}</span>
              </span>
            </article>
          </div>
        </section>
        <label class="field">
          <span use:tip={fieldTips.payFrom}>Pay from</span>
          <select name="sourceId" bind:value={sourceId} onchange={() => { message = ''; closeConfirm(); }}>
            <option value="">Choose an account</option>
            {#each assets as account (account.id)}
              <option value={String(account.id)}>{accountLabel(account)}</option>
            {/each}
          </select>
        </label>
        {#if sourceAccount}
          <div class="pay-stage">
            {@render accountCard(sourceAccount)}
            <button
              class="transfer-account uncovered-card"
              type="button"
              data-uncovered
              aria-pressed={coverGap}
              disabled={beyondMinor < 1}
              onclick={() => { coverGap = !coverGap; closeConfirm(); }}
            >
              <strong use:tip={fieldTips.uncovered}>Not covered by buckets</strong>
              <span class="muted text-sm">Calculated from Total payment</span>
              <strong class="money">{money(beyondMinor)}</strong>
            </button>
          </div>
        {/if}
        <section class="grid gap-2">
          <h2 class="m-0 text-2xl">Bills Buckets</h2>
          {#if preview.charges.length === 0}
            <p class="muted m-0">No unpaid bill is drawn from this card.</p>
          {/if}
          <div class="pull-board">
            {#each preview.charges as charge (charge.id)}
              {@const on = selected.includes(charge.id)}
              <button
                class="pull-bucket"
                type="button"
                data-bill={charge.name}
                data-bucket={charge.categoryName || charge.name}
                style={`--tone: var(--tone-${charge.group || 'needs'}); --level: ${on ? 1 : 0}`}
                aria-pressed={on}
                disabled={!on && (paymentMinor < 1 || selectedMinor + charge.amountMinor > paymentMinor)}
                onclick={() => toggle(charge)}
              >
                <span class="bucket-water" aria-hidden="true">
                  {@render waves()}
                </span>
                <strong>{charge.categoryName || charge.name}</strong>
                {#if charge.categoryName && charge.categoryName !== charge.name}
                  <span class="tank-copy">{charge.name}</span>
                {/if}
                <span class="tank-copy money">{money(charge.amountMinor)}</span>
              </button>
            {/each}
          </div>
        </section>
        <label class="field">
          <span use:tip={fieldTips.totalPayment}>Total payment</span>
          <span class="money-input">
            <span class="money-mark" aria-hidden="true">$</span>
            <input name="amount" inputmode="decimal" bind:value={amountText} oninput={() => { fitSelection(); closeConfirm(); }} />
          </span>
        </label>
        <button class="btn primary" type="button" disabled={sourceId === '' || paymentMinor < 1 || !cardChosen} onclick={() => void openConfirm()}>Review</button>
      {/if}
    {:else}
      <label class="field">
        <span use:tip={fieldTips.transferFrom}>From</span>
        <select name="moveSource" bind:value={sourceId} onchange={() => closeConfirm()}>
          <option value="">Choose an account</option>
          {#each fundedAssets as account (account.id)}
            <option value={String(account.id)}>{optionLabel(account)}</option>
          {/each}
        </select>
      </label>
      {#if sourceAccount}
        {@render accountCard(sourceAccount)}
      {/if}
      <label class="field">
        <span use:tip={fieldTips.transferTo}>To</span>
        <select name="moveDestination" bind:value={destinationId} onchange={() => closeConfirm()}>
          <option value="">Choose an account</option>
          {#each assets as account (account.id)}
            <option value={String(account.id)}>{optionLabel(account)}</option>
          {/each}
        </select>
      </label>
      {#if destinationAccount}
        {@render accountCard(destinationAccount)}
      {/if}
      <label class="field">
        <span use:tip={fieldTips.transferAmount}>Amount</span>
        <span class="money-input">
          <span class="money-mark" aria-hidden="true">$</span>
          <input name="moveAmount" inputmode="decimal" bind:value={amountText} oninput={() => closeConfirm()} />
        </span>
      </label>
      <button
        class="btn primary"
        type="button"
        disabled={sourceId === '' || destinationId === '' || sourceId === destinationId || amountMinor() < 1}
        onclick={() => void openConfirm()}
      >Review</button>
    {/if}
  {/if}
</div>

<dialog
  class="modal wide"
  bind:this={confirmDialog}
  aria-labelledby="transfer-confirm-title"
  oncancel={(event) => { if (saving) event.preventDefault(); }}
  onclose={() => { confirming = false; }}
>
  <div class="modal-body">
    <h2 id="transfer-confirm-title" class="m-0 text-2xl">{mode === 'card' ? 'Pay this card?' : 'Move this money?'}</h2>
    {#if mode === 'card' && cardAccount && sourceAccount}
      <p class="m-0">You may now post a payment of {money(paymentMinor)} to {cardAccount.name} from {sourceAccount.name} after hitting Confirm.</p>
      <div class="pay-review" style={`--from: var(--tone-${sourceAccount.type}); --to: var(--tone-${cardAccount.type})`}>
      <section
        class="transfer-scene"
        aria-label="Payment"
      >
        {@render flowEnd(sourceAccount, cardBlocked ? null : afterMinor(sourceAccount.balanceMinor, -paymentMinor))}
        {@render flowStream(paymentMinor)}
        {@render flowEnd(cardAccount, cardBlocked ? null : afterMinor(cardAccount.balanceMinor, -paymentMinor))}
      </section>
      {#if !cardBlocked}
        <div class="pay-split" data-buckets={selectedMinor} data-beyond={beyondMinor}>
          <div class="pay-split-track" aria-hidden="true">
            {#if selectedMinor > 0}
              <span class="pay-split-buckets" style={`width: ${(selectedMinor / paymentMinor) * 100}%`}></span>
            {/if}
            {#if beyondMinor > 0}
              <span class="pay-split-beyond" style={`width: ${(beyondMinor / paymentMinor) * 100}%`}></span>
            {/if}
          </div>
          {#if selectedMinor > 0}
            <p class="pay-note m-0"><span class="pay-swatch buckets" aria-hidden="true"></span>Buckets {money(selectedMinor)}. These bills are marked paid and their buckets drop. That does not move more cash.</p>
          {/if}
          {#if beyondMinor > 0}
            <p class="pay-note m-0"><span class="pay-swatch beyond" aria-hidden="true"></span>Beyond the buckets {money(beyondMinor)}. This is the rest of the same payment.</p>
          {:else}
            <p class="pay-note m-0">The buckets cover this payment. Available to assign stays the same.</p>
          {/if}
        </div>
      {/if}
      </div>
      {#if selectedCharges.length > 0}
        <section class="grid gap-2">
          <h3 class="m-0 text-xl">Marked paid</h3>
          {#each selectedCharges as charge (charge.id)}
            <p class="source-row pay-note m-0"><span>{charge.categoryName || charge.name} · {charge.name}</span><strong>{money(charge.amountMinor)}</strong></p>
          {/each}
        </section>
      {/if}
      {#if cardBlocked && paymentMinor >= 1}
        <p class="m-0" style="color: var(--coral)">The payment cannot be more than either balance.</p>
      {/if}
    {:else if mode === 'move' && sourceAccount && destinationAccount && (movePreview || accountShort > 0)}
      <section
        class="transfer-scene"
        aria-label="Transfer"
        style={`--from: var(--tone-${sourceAccount.type}); --to: var(--tone-${destinationAccount.type})`}
      >
        {@render flowEnd(sourceAccount, accountShort > 0 || !movePreview ? null : afterMinor(sourceAccount.balanceMinor, -movePreview.amountMinor))}
        {@render flowStream(movePreview ? movePreview.amountMinor : amountMinor())}
        {@render flowEnd(destinationAccount, accountShort > 0 || !movePreview ? null : destinationAccount.balanceMinor + movePreview.amountMinor)}
      </section>
      {#if accountShort > 0}
        <p class="m-0" style="color: var(--coral)">Short {money(accountShort)}. You are asking for more than this account holds.</p>
      {/if}
    {/if}
    {#if modalMessage}
      <p class="m-0" style="color: var(--coral)">{modalMessage}</p>
    {/if}
  </div>
  <div class="modal-actions">
    {#if mode === 'card'}
      <button class="btn primary" type="button" disabled={!cardReady || saving} onclick={() => void confirmCard()}>{saving ? 'Paying…' : 'Confirm'}</button>
    {:else}
      <button class="btn primary" type="button" disabled={!moveReady || saving} onclick={() => void confirmMove()}>{saving ? 'Moving…' : 'Confirm'}</button>
    {/if}
    <button class="btn" type="button" disabled={saving} onclick={closeConfirm}>Back</button>
  </div>
</dialog>

<dialog class="modal" bind:this={postedDialog} data-posted aria-label="Payment posted" onclose={() => { posted = null; }}>
  <div class="modal-body">
    {#if posted}
      <p class="m-0">You may now post a payment of {money(posted.amount)} to {posted.card} from {posted.from} after hitting Confirm.</p>
    {/if}
  </div>
  <div class="modal-actions">
    <button class="btn primary" type="button" onclick={() => postedDialog?.close()}>Ok</button>
  </div>
</dialog>

<style>
  .uncovered-card {
    width: 100%;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: transform 160ms var(--ease-out), box-shadow 160ms var(--ease-out);
  }

  .uncovered-card[aria-pressed='true'] {
    box-shadow: inset 0 0 0 2px var(--teal);
  }

  .uncovered-card:active:not(:disabled) {
    transform: scale(0.97);
  }

  .uncovered-card:disabled {
    cursor: default;
    opacity: 0.55;
  }

  @media (prefers-reduced-motion: reduce) {
    .uncovered-card:active:not(:disabled) {
      transform: none;
    }
  }

  .pay-split {
    display: grid;
    gap: 0.45rem;
  }

  .pay-split-track {
    display: flex;
    height: 0.85rem;
    overflow: hidden;
    border-radius: 999px;
    background: color-mix(in oklab, var(--line) 80%, transparent);
  }

  .pay-split-buckets,
  .pay-split-beyond {
    height: 100%;
    transform-origin: left center;
    animation: pay-fill 240ms var(--ease-out) both;
  }

  .pay-split-buckets {
    background: #3cbf6e;
  }

  .pay-split-beyond {
    background: linear-gradient(90deg, var(--from, var(--teal)), var(--to, var(--coral)));
    animation-delay: 40ms;
  }

  .pay-note {
    display: flex;
    align-items: flex-start;
    gap: 0.45rem;
    animation: pay-note-in 200ms var(--ease-out) both;
  }

  .pay-note + .pay-note {
    animation-delay: 40ms;
  }

  .pay-swatch {
    flex: none;
    width: 0.7rem;
    height: 0.7rem;
    margin-top: 0.2rem;
    border-radius: 999px;
  }

  .pay-swatch.buckets {
    background: #3cbf6e;
  }

  .pay-swatch.beyond {
    background: linear-gradient(90deg, var(--from, var(--teal)), var(--to, var(--coral)));
  }

  .pay-review {
    display: grid;
    gap: 0.85rem;
  }

  @keyframes pay-fill {
    from { transform: scaleX(0.08); }
    to { transform: scaleX(1); }
  }

  @keyframes pay-note-in {
    from {
      opacity: 0;
      transform: translateY(6px);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .pay-split-buckets,
    .pay-split-beyond,
    .pay-note {
      animation: none;
    }
  }

  .transfer-account {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0.35rem;
    min-width: 0;
    padding: 0.85rem;
    border: 1px solid var(--line);
    border-radius: 0.85rem;
    background: linear-gradient(
      155deg,
      color-mix(in oklab, var(--tone, var(--stone)) 34%, var(--bg-raise)),
      color-mix(in oklab, var(--tone, var(--stone)) 12%, var(--bg-raise))
    );
  }

  .account-id {
    display: flex;
    align-items: flex-start;
    gap: 0.35rem;
    min-width: 0;
  }

  .account-id-text {
    display: flex;
    flex-direction: column;
    gap: 0.05rem;
    flex: 1;
    min-width: 0;
  }

  .account-id-text strong,
  .account-institution {
    overflow-wrap: anywhere;
  }

  .account-institution {
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 500;
    line-height: 1.25;
  }

  .account-budget {
    flex: none;
    width: 1.15rem;
    height: 1.15rem;
    margin-top: 0.12rem;
    color: var(--text);
  }

  .account-budget :global(.glyph) {
    width: 1.15rem;
    height: 1.15rem;
    background: none;
  }

  .account-budget :global(svg) {
    width: 1.15rem;
    height: 1.15rem;
  }

  .bill-detail-chips {
    min-width: 0;
  }

  .bill-detail-chip {
    overflow-wrap: anywhere;
    color: var(--tone, var(--text));
    background: color-mix(in oklab, var(--tone, var(--teal)) 24%, transparent);
  }

  .bill-detail-chip[data-chip='limit'] { --tone: var(--iris); }
  .bill-detail-chip[data-chip='apr'] { --tone: var(--amber); }
  .bill-detail-chip[data-chip='minimum'] { --tone: var(--rose); }
  .bill-detail-chip[data-chip='autopay-full'] { --tone: var(--teal); }
  .bill-detail-chip[data-chip='autopay-min'] { --tone: var(--lime); }
  .bill-detail-chip[data-chip='holder'] { --tone: var(--plum); }

  :global(html.light) .bill-detail-chip[data-chip],
  :global(:root.light) .bill-detail-chip[data-chip] {
    color: color-mix(in oklab, var(--tone) 62%, #14211c);
  }

  .money {
    margin-top: auto;
  }
</style>
