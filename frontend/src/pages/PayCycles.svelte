<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatDate, formatMinor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { PayCycle } from '../lib/types';

  let cycles = $state<PayCycle[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);

  async function load(): Promise<void> {
    const data = await api<{ payCycles: PayCycle[] }>(
      `/api/pay-cycles?today=${todayISO()}&includeArchived=${includeArchived ? '1' : '0'}`,
    );
    cycles = data.payCycles;
    ready = true;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
    });
  });

  function toggle(): void {
    includeArchived = !includeArchived;
    void load();
  }

  function span(cycle: PayCycle): string {
    if (cycle.periodEnd) return `${formatDate(cycle.periodStart)} – ${formatDate(cycle.periodEnd)}`;
    return `Open since ${formatDate(cycle.periodStart)}`;
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Income</h1>
    <a
      class="btn primary"
      href="/pay-cycles/new"
      title="Add a payor and a pay cycle"
      onclick={(event) => { event.preventDefault(); go('/pay-cycles/new'); }}
    >New pay cycle</a>
  </div>
  <p class="muted m-0">Each payor has one pay cycle. A second paycheck from that payor stays in the same period.</p>
  <button
    class="btn w-fit"
    type="button"
    title={includeArchived ? 'Leave archived pay cycles off this list' : 'Archived pay cycles stay stored until you ask for them'}
    onclick={toggle}
  >{includeArchived ? 'Hide archived' : 'Show archived'}</button>
  {#if message}<p class="banner">{message}</p>{/if}
  {#if ready && cycles.length === 0}
    <p class="muted m-0">No pay cycles yet.</p>
  {/if}
  {#each cycles as cycle (cycle.id)}
    <article class="card stripe grid gap-1" style={`--tone: var(--tone-${cycle.length})`}>
      <a
        class="flex items-center gap-3 no-underline"
        href={`/pay-cycles/${cycle.id}`}
        onclick={(event) => { event.preventDefault(); go(`/pay-cycles/${cycle.id}`); }}
      >
        <Glyph name={cycle.length} />
        <span>
          <strong>{cycle.payor}</strong>
          {#if cycle.archivedAt}<span class="block muted text-sm">archived</span>{/if}
        </span>
      </a>
      <p class="group-title text-xl m-0" style={`--tone: var(--tone-${cycle.length})`}>{span(cycle)}</p>
      <p class="m-0">
        {cycle.payKind === 'salary' ? 'Salary' : cycle.payKind === 'hourly' ? 'Hourly' : 'Fixed'}
        {#if cycle.paycheckMinor !== null}
          · {formatMinor(cycle.paycheckMinor, session.user?.baseCurrency ?? 'USD')}
        {:else if cycle.payKind === 'salary'}
          · not split into a paycheck
        {:else}
          · enter a rate
        {/if}
      </p>
      <p class="muted m-0">Based on payday {formatDate(cycle.payday)}.</p>
      {#if cycle.rollsOn}
        <p class="muted m-0">Rolls on {formatDate(cycle.rollsOn)}.</p>
      {:else}
        <p class="muted m-0">Stays open until you type the next payday.</p>
      {/if}
    </article>
  {/each}
</div>
