<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import Spinner from '../components/Spinner.svelte';
  import Note from '../components/Note.svelte';
  import { api } from '../lib/api';
  import { fieldTips } from '../lib/fieldTips';
  import { formatBps, formatMinor } from '../lib/money';
  import { tip } from '../lib/tip';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account } from '../lib/types';

  let accounts = $state<Account[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);
  let hasGas = $derived(accounts.some((account) => account.gasFavorite));
  let hasShopping = $derived(accounts.some((account) => account.shoppingFavorite));
  let hasAsset = $derived(accounts.some((account) => account.assetFavorite));
  let favoriteAccounts = $derived(accounts.filter((account) => account.archivedAt === null && held(account)));
  let boardAccounts = $derived(accounts.filter((account) => !favoriteAccounts.includes(account)));

  function held(account: Account): boolean {
    return account.gasFavorite || account.shoppingFavorite || account.assetFavorite;
  }

  async function load(): Promise<void> {
    const data = await api<{ accounts: Account[] }>(`/api/accounts?includeArchived=${includeArchived ? '1' : '0'}`);
    accounts = data.accounts;
    ready = true;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = err instanceof Error ? err.message : 'Could not load accounts.';
      ready = true;
    });
  });

  function toggle(): void {
    includeArchived = !includeArchived;
    void load();
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

  function showGas(account: Account): boolean {
    if (account.archivedAt || account.type !== 'fuel') return false;
    if (account.gasFavorite) return true;
    return !hasGas && !held(account);
  }

  function showShopping(account: Account): boolean {
    if (account.archivedAt || account.type !== 'credit') return false;
    if (account.shoppingFavorite) return true;
    return !hasShopping && !held(account);
  }

  function showAsset(account: Account): boolean {
    if (account.archivedAt || account.side !== 'asset') return false;
    if (account.assetFavorite) return true;
    return !hasAsset && !held(account);
  }

  async function mark(account: Account, kind: 'gas' | 'shopping' | 'asset'): Promise<void> {
    message = '';
    const next = kind === 'gas'
      ? !account.gasFavorite
      : kind === 'shopping'
        ? !account.shoppingFavorite
        : !account.assetFavorite;
    try {
      await api(`/api/accounts/${account.id}`, {
        method: 'PATCH',
        body: {
          name: account.name,
          type: account.type,
          side: account.side,
          balanceMinor: account.balanceMinor,
          interestBps: account.interestBps,
          minimumDueMinor: account.minimumDueMinor,
          dueDay: account.dueDay,
          creditLimitMinor: account.creditLimitMinor,
          autopayChoice: account.autopayChoice,
          bankAutopay: account.bankAutopay,
          billRecurrence: account.billRecurrence,
          billEvery: account.billEvery,
          institution: account.institution,
          holder: account.holder,
          notes: account.notes,
          inBudget: account.inBudget,
          gasFavorite: kind === 'gas' && next,
          shoppingFavorite: kind === 'shopping' && next,
          assetFavorite: kind === 'asset' && next,
        },
      });
      await load();
    } catch (err) {
      message = err instanceof Error ? err.message : 'Could not change that favorite.';
    }
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Accounts</h1>
    <a class="btn primary" href="/accounts/new" onclick={(event) => { event.preventDefault(); go('/accounts/new'); }}>New account</a>
  </div>
  <button class="btn w-fit" type="button" onclick={toggle}>{includeArchived ? 'Hide archived' : 'Show archived'}</button>
  <Note bind:message />
  {#if !ready}
    <Spinner />
  {:else if accounts.length === 0}
    <p class="muted">No accounts yet. A balance is a number you type.</p>
  {:else}
  {#snippet accountTile(account: Account, index: number)}
    {@const chips = chipsFor(account)}
    <div class="account-tile" style={`--stagger: ${index}`}>
      <a
        class="bucket-tile"
        style={`--tone: var(--tone-${account.type})`}
        href={`/accounts/${account.id}`}
        onclick={(event) => { event.preventDefault(); go(`/accounts/${account.id}`); }}
      >
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
          {#if account.archivedAt} · archived{/if}
        </span>
        {#if chips.length > 0}
          <span class="bill-detail-chips">
            {#each chips as chip (chip.key)}
              <span class="bill-detail-chip" data-chip={chip.key}>{chip.label}</span>
            {/each}
          </span>
        {/if}
        <strong class="money" class:coral={account.side === 'liability'}>{formatMinor(account.balanceMinor, account.currency, account.exponent)}</strong>
      </a>
      {#if showGas(account) || showShopping(account) || showAsset(account)}
        <span class="account-marks">
          {#if showGas(account)}
            <button class="btn account-mark" class:on={account.gasFavorite} type="button" data-favorite="gas" aria-pressed={account.gasFavorite} aria-label={account.gasFavorite ? `${account.name} is the gas favorite` : `Make ${account.name} the gas favorite`} use:tip={fieldTips.accountGas} onclick={() => mark(account, 'gas')}>
              <Glyph name="fuel" mini />
            </button>
          {/if}
          {#if showShopping(account)}
            <button class="btn account-mark" class:on={account.shoppingFavorite} type="button" data-favorite="shopping" aria-pressed={account.shoppingFavorite} aria-label={account.shoppingFavorite ? `${account.name} is the shopping favorite` : `Make ${account.name} the shopping favorite`} use:tip={fieldTips.accountShopping} onclick={() => mark(account, 'shopping')}>
              <Glyph name="shopping" mini />
            </button>
          {/if}
          {#if showAsset(account)}
            <button class="btn account-mark" class:on={account.assetFavorite} type="button" data-favorite="asset" aria-pressed={account.assetFavorite} aria-label={account.assetFavorite ? `${account.name} is the asset favorite` : `Make ${account.name} the asset favorite`} use:tip={fieldTips.accountAsset} onclick={() => mark(account, 'asset')}>
              <Glyph name="vault" mini />
            </button>
          {/if}
        </span>
      {/if}
    </div>
  {/snippet}
  {#if favoriteAccounts.length > 0}
    <div class="bucket-board account-board">
      {#each favoriteAccounts as account, index (account.id)}
        {@render accountTile(account, index)}
      {/each}
    </div>
    <hr class="account-star-rule" />
  {/if}
  <div class="bucket-board account-board">
    {#each boardAccounts as account, index (account.id)}
      {@render accountTile(account, favoriteAccounts.length + index)}
    {/each}
  </div>
  {/if}
</div>

<style>
  a.bucket-tile {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 0.35rem;
    height: 100%;
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

  .account-id-text strong {
    overflow-wrap: anywhere;
  }

  .account-institution {
    color: var(--muted);
    font-size: 0.82rem;
    font-weight: 500;
    line-height: 1.25;
    overflow-wrap: anywhere;
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

  .account-star-rule {
    width: 100%;
    height: 0;
    margin: 0;
    border: 0;
    border-top: 1px solid var(--line);
  }
</style>
