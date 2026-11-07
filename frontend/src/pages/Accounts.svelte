<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import Spinner from '../components/Spinner.svelte';
  import Note from '../components/Note.svelte';
  import { api } from '../lib/api';
  import { formatBps, formatMinor } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account } from '../lib/types';

  let accounts = $state<Account[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);
  let hasStar = $derived(accounts.some((account) => account.starred));
  let starredAccount = $derived(accounts.find((account) => account.starred && account.archivedAt === null) ?? null);
  let boardAccounts = $derived(accounts.filter((account) => account !== starredAccount));

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

  async function star(account: Account): Promise<void> {
    message = '';
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
          starred: !account.starred,
        },
      });
      await load();
    } catch (err) {
      message = err instanceof Error ? err.message : 'Could not star that account.';
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
              <Glyph name="dollar" mini />
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
      {#if !account.archivedAt && (account.starred || !hasStar)}
        <button class="btn account-star" type="button" aria-pressed={account.starred} aria-label={account.starred ? `${account.name} is the default` : `Star ${account.name}`} onclick={() => star(account)}>
          {account.starred ? '★' : '☆'}
        </button>
      {/if}
    </div>
  {/snippet}
  <div class="bucket-board account-board">
    {#if starredAccount}
      <div class="account-starred-row">
        {@render accountTile(starredAccount, 0)}
        <hr class="account-star-rule" />
      </div>
    {/if}
    {#each boardAccounts as account, index (account.id)}
      {@render accountTile(account, starredAccount ? index + 1 : index)}
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

  .account-starred-row {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: subgrid;
    row-gap: 0.55rem;
  }

  .account-starred-row > .account-tile {
    grid-row: 1;
    grid-column: 1;
  }

  .account-star-rule {
    grid-row: 2;
    grid-column: 1 / -1;
    width: 100%;
    height: 0;
    margin: 0;
    border: 0;
    border-top: 1px solid var(--line);
  }
</style>
