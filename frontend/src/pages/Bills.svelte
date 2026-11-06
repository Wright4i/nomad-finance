<script lang="ts">
  import Note from '../components/Note.svelte';
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { billDays } from '../lib/billDays';
  import { formatDate, formatMinor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import type { Bill } from '../lib/types';

  type SectionKey = 'active' | 'paused' | 'paid' | 'archived';
  const sectionOrder: SectionKey[] = ['active', 'paused', 'paid', 'archived'];

  let loaded = $state<Record<SectionKey, Bill[]>>({ active: [], paused: [], paid: [], archived: [] });
  let hasMore = $state<Record<SectionKey, boolean>>({ active: false, paused: false, paid: false, archived: false });
  let chunks = $state<Record<SectionKey, number>>({ active: 1, paused: 1, paid: 1, archived: 1 });
  let message = $state('');
  const growing: Record<SectionKey, boolean> = { active: false, paused: false, paid: false, archived: false };
  const requested: Record<SectionKey, number> = { active: 1, paused: 1, paid: 1, archived: 1 };

  async function loadSection(section: SectionKey, count: number): Promise<void> {
    requested[section] = Math.max(requested[section], count);
    const data = await api<{ bills: Bill[]; hasMore: boolean }>(`/api/bills?section=${section}&chunks=${count}`);
    if (count < requested[section]) return;
    loaded = { ...loaded, [section]: data.bills };
    hasMore = { ...hasMore, [section]: data.hasMore };
    chunks = { ...chunks, [section]: count };
  }

  async function load(): Promise<void> {
    await Promise.all(sectionOrder.map((section) => loadSection(section, chunks[section])));
  }

  /**
   * A short section is already at its end, so a downward wheel loads the next days.
   * A scrollbar drag loads more only after the person has moved down into the list.
   */
  function grow(section: SectionKey, node: HTMLElement, deltaY: number | null): void {
    if (growing[section] || !hasMore[section]) return;
    const atEnd = node.scrollTop + node.clientHeight >= node.scrollHeight - 8;
    if (!atEnd) return;
    if (deltaY === null && node.scrollTop <= 0 && node.scrollHeight <= node.clientHeight + 1) return;
    if (deltaY !== null && deltaY <= 0) return;
    const next = requested[section] + 1;
    requested[section] = next;
    growing[section] = true;
    loadSection(section, next)
      .catch((err: unknown) => {
        message = err instanceof Error ? err.message : 'Could not load bills.';
      })
      .finally(() => {
        growing[section] = false;
      });
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

  function rows(section: SectionKey): Row[] {
    const bills = loaded[section];
    if (section === 'paid') {
      return bills.map((bill) => ({ ...bill, shownOn: bill.lastPaidOn ?? bill.nextDueDate }));
    }
    return bills.map((bill) => ({ ...bill, shownOn: bill.nextDueDate }));
  }

</script>

<div class="grid gap-5">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Bills</h1>
    <a class="btn primary" href="/bills/new" onclick={(event) => { event.preventDefault(); go('/bills/new'); }}>New bill</a>
  </div>
  <p class="muted m-0">A bill is a reminder. Marking it paid rolls the date and leaves the account balance alone.</p>
  <Note bind:message />

  {#snippet group(title: string, key: SectionKey, tone: string, unpay: boolean)}
    <section class="bill-section grid gap-3" data-section={key}>
      <h2 class="group-title text-2xl" style={`--tone: var(--tone-${tone})`}>
        <Glyph name={tone} />
        {title}
      </h2>
      <div
        class="bill-scroll"
        onscroll={(event) => {
          if (event.currentTarget instanceof HTMLElement) grow(key, event.currentTarget, null);
        }}
        onwheel={(event) => {
          if (event.currentTarget instanceof HTMLElement) grow(key, event.currentTarget, event.deltaY);
        }}
      >
      {#if rows(key).length === 0}<p class="muted m-0">None.</p>{/if}
      {#each billDays(rows(key)) as day (day.date)}
        <div class="bill-day" data-date={day.date}>
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
      </div>
    </section>
  {/snippet}

  {@render group('Active', 'active', 'active', false)}
  {@render group('Paused', 'paused', 'paused', false)}
  {@render group('Paid', 'paid', 'paid', true)}
  {@render group('Archived', 'archived', 'archived', false)}
</div>
