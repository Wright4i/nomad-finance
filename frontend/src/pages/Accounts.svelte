<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api } from '../lib/api';
  import { formatBps, formatMinor } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Account } from '../lib/types';

  let accounts = $state<Account[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);

  async function load(): Promise<void> {
    const data = await api<{ accounts: Account[] }>(`/api/accounts?includeArchived=${includeArchived ? '1' : '0'}`);
    accounts = data.accounts;
    ready = true;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = err instanceof Error ? err.message : 'Could not load accounts.';
    });
  });

  function toggle(): void {
    includeArchived = !includeArchived;
    void load();
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Accounts</h1>
    <a class="btn primary" href="/accounts/new" onclick={(event) => { event.preventDefault(); go('/accounts/new'); }}>New account</a>
  </div>
  <button class="btn w-fit" type="button" onclick={toggle}>{includeArchived ? 'Hide archived' : 'Show archived'}</button>
  {#if message}<p class="banner">{message}</p>{/if}
  {#if ready && accounts.length === 0}
    <p class="muted">No accounts yet. A balance is a number you type.</p>
  {/if}
  {#if accounts.length > 0}
  <div class="bucket-board">
    {#each accounts as account (account.id)}
      <a
        class="bucket-tile"
        style={`--tone: var(--tone-${account.type})`}
        href={`/accounts/${account.id}`}
        onclick={(event) => { event.preventDefault(); go(`/accounts/${account.id}`); }}
      >
        <span class="flex items-center gap-1">
          <Glyph name={account.type} mini />
          <strong>{account.name}</strong>
        </span>
        <span class="muted text-sm">
          <span class="type-label">{account.type}</span>
          {#if account.interestBps !== null} · {formatBps(account.interestBps)}%{/if}
          {#if account.archivedAt} · archived{/if}
        </span>
        <strong class="money" class:coral={account.side === 'liability'}>{formatMinor(account.balanceMinor, account.currency, account.exponent)}</strong>
      </a>
    {/each}
  </div>
  {/if}
</div>
