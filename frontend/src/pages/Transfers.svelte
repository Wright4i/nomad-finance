<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { api, explain } from '../lib/api';
  import { formatMinor, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { session } from '../lib/session.svelte.ts';
  import { accountLabel } from '../lib/accountLabel';
import type { Account } from '../lib/types';

  type Charge = {
    id: number;
    name: string;
    categoryId: number;
    categoryName: string;
    amountMinor: number;
    dueOn: string;
  };

  type CardPreview = {
    card: AccountView;
    paymentMinor: number;
    charges: Charge[];
    unassignedMinor: number;
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
    leavesPool: boolean;
    unassignedMinor: number;
    fromUnassignedMinor: number;
    buckets: { categoryId: number; name: string; amountMinor: number }[];
    gapMinor: number;
    ready: boolean;
  };

  let accounts = $state<Account[]>([]);
  let loading = $state(true);
  let message = $state('');
  let mode = $state<'card' | 'move'>('card');
  let cardId = $state('');
  let sourceId = $state('');
  let destinationId = $state('');
  let amountText = $state('');
  let preview = $state<CardPreview | null>(null);
  let movePreview = $state<MovePreview | null>(null);
  let selected = $state<number[]>([]);
  let confirming = $state(false);
  let acceptBuckets = $state(false);
  let saving = $state(false);

  let currency = $derived(session.user?.baseCurrency ?? 'USD');
  let cards = $derived(
    accounts.filter((account) =>
      account.archivedAt === null
      && (account.type === 'credit' || account.type === 'fuel')
      && account.autopayChoice === 'balance'
      && account.bankAutopay,
    ),
  );
  let assets = $derived(accounts.filter((account) => account.archivedAt === null && account.side === 'asset'));

  function money(minor: number): string {
    return formatMinor(minor, currency, 2);
  }

  function amountMinor(): number {
    const parsed = parseMajor(amountText.trim() === '' ? '0' : amountText, 2);
    return parsed ?? 0;
  }

  let selectedCharges = $derived((preview?.charges ?? []).filter((charge) => selected.includes(charge.id)));
  let selectedMinor = $derived(selectedCharges.reduce((sum, charge) => sum + charge.amountMinor, 0));
  let remainderMinor = $derived(Math.max(0, amountMinor() - selectedMinor));
  let gapMinor = $derived(Math.max(0, remainderMinor - (preview?.unassignedMinor ?? 0)));
  let cardReady = $derived(confirming && mode === 'card' && gapMinor === 0 && amountMinor() >= 1 && sourceId !== '');
  let moveReady = $derived(
    confirming
    && mode === 'move'
    && movePreview !== null
    && movePreview.gapMinor === 0
    && (movePreview.buckets.length === 0 || acceptBuckets),
  );

  async function loadAccounts(): Promise<void> {
    const data = await api<{ accounts: Account[] }>('/api/accounts');
    accounts = data.accounts;
  }

  async function loadCard(): Promise<void> {
    preview = null;
    selected = [];
    confirming = false;
    if (cardId === '') return;
    const data = await api<CardPreview>(`/api/transfers/card?cardId=${cardId}&today=${todayISO()}`);
    preview = data;
    amountText = minorToInput(data.paymentMinor, 2);
  }

  function fits(charge: Charge, already: number[]): boolean {
    const used = (preview?.charges ?? [])
      .filter((row) => already.includes(row.id))
      .reduce((sum, row) => sum + row.amountMinor, 0);
    return used + charge.amountMinor <= amountMinor();
  }

  function toggle(charge: Charge): void {
    if (selected.includes(charge.id)) {
      selected = selected.filter((id) => id !== charge.id);
      return;
    }
    if (!fits(charge, selected)) return;
    selected = [...selected, charge.id];
  }

  async function openConfirm(): Promise<void> {
    message = '';
    if (mode === 'card') {
      confirming = true;
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
      acceptBuckets = false;
      confirming = true;
    } catch (err) {
      message = explain(err).message;
    }
  }

  async function confirmCard(): Promise<void> {
    if (!cardReady || saving) return;
    saving = true;
    message = '';
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
      confirming = false;
      await loadAccounts();
      await loadCard();
      message = 'Paid.';
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function confirmMove(): Promise<void> {
    if (!moveReady || saving) return;
    saving = true;
    message = '';
    try {
      await api('/api/transfers', {
        method: 'POST',
        body: {
          today: todayISO(),
          sourceId: Number(sourceId),
          destinationId: Number(destinationId),
          amountMinor: amountMinor(),
          acceptBuckets,
        },
      });
      confirming = false;
      movePreview = null;
      await loadAccounts();
      message = 'Transferred.';
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
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

<div class="grid gap-4">
  <h1 class="m-0 text-4xl">Transfers</h1>
  <Note bind:message />
  {#if loading}
    <Spinner />
  {:else}
    <div class="flex flex-wrap gap-2" role="group" aria-label="Transfer kind">
      <button class="btn" class:primary={mode === 'card'} type="button" onclick={() => { mode = 'card'; confirming = false; }}>Pay a card</button>
      <button class="btn" class:primary={mode === 'move'} type="button" onclick={() => { mode = 'move'; confirming = false; }}>Between accounts</button>
    </div>

    {#if mode === 'card'}
      <label class="field">
        <span>Card</span>
        <select name="cardId" bind:value={cardId} onchange={() => void loadCard()}>
          <option value="">Choose a paid-in-full card</option>
          {#each cards as card (card.id)}
            <option value={String(card.id)}>{accountLabel(card)}</option>
          {/each}
        </select>
      </label>
      {#if cards.length === 0}
        <p class="muted m-0">No paid-in-full card is on these books.</p>
      {/if}
      {#if preview}
        <label class="field">
          <span>Pay from</span>
          <select name="sourceId" bind:value={sourceId}>
            <option value="">Choose an account</option>
            {#each assets as account (account.id)}
              <option value={String(account.id)}>{accountLabel(account)}</option>
            {/each}
          </select>
        </label>
        <label class="field">
          <span>Amount</span>
          <input name="amount" inputmode="decimal" bind:value={amountText} />
        </label>
        <section class="grid gap-2">
          <h2 class="m-0 text-2xl">Past charges</h2>
          {#if preview.charges.length === 0}
            <p class="muted m-0">No past unpaid bill is drawn from this card.</p>
          {/if}
          {#each preview.charges as charge (charge.id)}
            <label class="source-row">
              <input
                type="checkbox"
                checked={selected.includes(charge.id)}
                disabled={!selected.includes(charge.id) && !fits(charge, selected)}
                onchange={() => toggle(charge)}
              />
              <span>{charge.categoryName || charge.name}</span>
              <strong>{money(charge.amountMinor)}</strong>
            </label>
          {/each}
        </section>
        {#if !confirming}
          <button class="btn primary" type="button" disabled={sourceId === '' || amountMinor() < 1} onclick={() => void openConfirm()}>Review</button>
        {:else}
          <section class="transfer-move" aria-label="Payment">
            <p class="m-0">Unassigned cash <strong class="money">{money(remainderMinor)}</strong></p>
            {#if gapMinor > 0}
              <p class="m-0" style="color: var(--coral)">Short {money(gapMinor)}. Unassigned cash cannot cover the rest.</p>
            {/if}
          </section>
          <div class="flex gap-2">
            <button class="btn primary" type="button" disabled={!cardReady || saving} onclick={() => void confirmCard()}>{saving ? 'Paying…' : 'Confirm'}</button>
            <button class="btn" type="button" onclick={() => (confirming = false)}>Back</button>
          </div>
        {/if}
      {/if}
    {:else}
      <label class="field">
        <span>From</span>
        <select name="moveSource" bind:value={sourceId} onchange={() => (confirming = false)}>
          <option value="">Choose an account</option>
          {#each assets as account (account.id)}
            <option value={String(account.id)}>{accountLabel(account)}</option>
          {/each}
        </select>
      </label>
      <label class="field">
        <span>To</span>
        <select name="moveDestination" bind:value={destinationId} onchange={() => (confirming = false)}>
          <option value="">Choose an account</option>
          {#each assets as account (account.id)}
            <option value={String(account.id)}>{accountLabel(account)}</option>
          {/each}
        </select>
      </label>
      <label class="field">
        <span>Amount</span>
        <input name="moveAmount" inputmode="decimal" bind:value={amountText} oninput={() => (confirming = false)} />
      </label>
      {#if !confirming}
        <button
          class="btn primary"
          type="button"
          disabled={sourceId === '' || destinationId === '' || sourceId === destinationId || amountMinor() < 1}
          onclick={() => void openConfirm()}
        >Review</button>
      {:else if movePreview}
        <section class="transfer-move" aria-label="Transfer">
          <div class="transfer-end" data-type={movePreview.source.type}>
            <Glyph name={movePreview.source.type} />
            <strong>{movePreview.source.name}</strong>
          </div>
          <p class="transfer-amount money m-0">{money(movePreview.amountMinor)}</p>
          <div class="transfer-end" data-type={movePreview.destination.type}>
            <Glyph name={movePreview.destination.type} />
            <strong>{movePreview.destination.name}</strong>
          </div>
        </section>
        {#if movePreview.leavesPool}
          <p class="m-0">Unassigned cash covers {money(movePreview.fromUnassignedMinor)}.</p>
        {/if}
        {#if movePreview.buckets.length > 0}
          <section class="grid gap-2">
            <h2 class="m-0 text-xl">Buckets this would reduce</h2>
            {#each movePreview.buckets as bucket (bucket.categoryId)}
              <p class="source-row m-0"><span>{bucket.name}</span><strong>{money(bucket.amountMinor)}</strong></p>
            {/each}
            <label class="field">
              <span>Accept these buckets</span>
              <input type="checkbox" bind:checked={acceptBuckets} />
            </label>
          </section>
        {/if}
        {#if movePreview.gapMinor > 0}
          <p class="m-0" style="color: var(--coral)">Short {money(movePreview.gapMinor)}.</p>
        {/if}
        <div class="flex gap-2">
          <button class="btn primary" type="button" disabled={!moveReady || saving} onclick={() => void confirmMove()}>{saving ? 'Moving…' : 'Confirm'}</button>
          <button class="btn" type="button" onclick={() => (confirming = false)}>Back</button>
        </div>
      {/if}
    {/if}
  {/if}
</div>
