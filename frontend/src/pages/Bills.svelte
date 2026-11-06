<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { billDays } from '../lib/billDays';
  import { formatDate, formatMinor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Bill } from '../lib/types';

  let bills = $state<Bill[]>([]);
  let message = $state('');

  async function load(): Promise<void> {
    const data = await api<{ bills: Bill[] }>('/api/bills');
    bills = data.bills;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = err instanceof Error ? err.message : 'Could not load bills.';
    });
  });

  async function act(id: number, action: string, body: unknown = {}): Promise<void> {
    message = '';
    try {
      await api(`/api/bills/${id}/${action}`, { method: 'POST', body });
      await load();
    } catch (err) {
      message = explain(err).message;
    }
  }

  type Row = Bill & { shownOn: string };

  let active = $derived(bills.filter((bill) => bill.status === 'active').map((bill) => ({ ...bill, shownOn: bill.nextDueDate })));
  let paused = $derived(bills.filter((bill) => bill.status === 'paused').map((bill) => ({ ...bill, shownOn: bill.nextDueDate })));
  let archived = $derived(bills.filter((bill) => bill.status === 'archived').map((bill) => ({ ...bill, shownOn: bill.nextDueDate })));
  let paid = $derived([
    ...bills.filter((bill) => bill.status === 'paid').map((bill) => ({ ...bill, shownOn: bill.lastPaidOn ?? bill.nextDueDate })),
    ...bills
      .filter((bill) => bill.status !== 'paid' && bill.recurrence !== 'once' && bill.lastPaidOn)
      .map((bill) => ({ ...bill, shownOn: bill.lastPaidOn ?? bill.nextDueDate })),
  ]);

</script>

<div class="grid gap-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Bills</h1>
    <a class="btn primary" href="/bills/new" onclick={(event) => { event.preventDefault(); go('/bills/new'); }}>New bill</a>
  </div>
  <p class="muted m-0">A bill is a reminder. Marking it paid rolls the date and leaves the account balance alone.</p>
  {#if message}<p class="banner" aria-live="polite">{message}</p>{/if}

  {#snippet group(title: string, tone: string, rows: Row[], unpay: boolean)}
    <section class="grid gap-3">
      <h2 class="group-title text-2xl" style={`--tone: var(--tone-${tone})`}>
        <Glyph name={tone} />
        {title}
      </h2>
      {#if rows.length === 0}<p class="muted m-0">None.</p>{/if}
      {#each billDays(rows) as day (day.date)}
        <div class="bill-day">
          <h3 class="bill-date m-0">{formatDate(day.date)}</h3>
          <hr class="bill-date-rule" />
          <div class="bill-columns">
            {#each day.bills as bill (`${bill.id}-${bill.shownOn}`)}
              <article class="card stripe grid gap-3" style={`--tone: var(--tone-${bill.type})`}>
                <a class="flex justify-between gap-3 no-underline items-center" href={`/bills/${bill.id}`} onclick={(event) => { event.preventDefault(); go(`/bills/${bill.id}`); }}>
                  <span class="flex items-center gap-3 min-w-0">
                    <Glyph name={bill.type} />
                    <span class="min-w-0">
                      <strong>{bill.name}</strong>
                      {#if bill.locked}<Glyph name="lock" mini />{/if}
                      <span class="block muted text-sm">
                        <span class="type-label">{(bill.type === 'other' || bill.type === 'misc') && bill.otherLabel ? bill.otherLabel : bill.type}</span> · {bill.accountName} · {bill.recurrence}{bill.categoryName ? ` · ${bill.categoryName}` : ''}{bill.autopay ? ' · autopay' : ''}
                      </span>
                    </span>
                  </span>
                  <strong class="money">{formatMinor(bill.amountMinor, bill.currency, bill.exponent)}</strong>
                </a>
                <div class="flex flex-wrap gap-2">
                  {#if !unpay && bill.status === 'active'}
                    <button class="btn" type="button" onclick={() => act(bill.id, 'paid', { paidOn: todayISO() })}>Mark paid</button>
                    {#if !bill.locked}
                      <button class="btn" type="button" onclick={() => act(bill.id, 'pause')}>Pause</button>
                    {/if}
                  {/if}
                  {#if !unpay && bill.status === 'paused' && !bill.locked}
                    <button class="btn" type="button" onclick={() => act(bill.id, 'resume')}>Resume</button>
                    <button class="btn" type="button" onclick={() => act(bill.id, 'archive')}>Archive</button>
                  {/if}
                  {#if bill.status === 'archived'}
                    <button class="btn" type="button" onclick={() => act(bill.id, 'unarchive')}>Unarchive</button>
                  {/if}
                  {#if unpay}
                    <button class="btn" type="button" onclick={() => act(bill.id, 'unpay')}>Unpay</button>
                  {/if}
                </div>
              </article>
            {/each}
          </div>
        </div>
      {/each}
    </section>
  {/snippet}

  {@render group('Active', 'active', active, false)}
  {@render group('Paused', 'paused', paused, false)}
  {@render group('Paid', 'paid', paid, true)}
  {@render group('Archived', 'archived', archived, false)}
</div>
