<script lang="ts">
  import { onMount } from 'svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { formatMinor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import { targetHint } from '../lib/target';
  import { session } from '../lib/session.svelte.ts';
  import type { Category } from '../lib/types';

  const groups = [
    { id: 'needs', label: 'Needs' },
    { id: 'wants', label: 'Wants' },
    { id: 'savings', label: 'Savings' },
  ] as const;

  let categories = $state<Category[]>([]);
  let includeArchived = $state(false);
  let message = $state('');
  let ready = $state(false);

  async function load(): Promise<void> {
    const data = await api<{ categories: Category[] }>(
      `/api/categories?includeArchived=${includeArchived ? '1' : '0'}&today=${todayISO()}`,
    );
    categories = data.categories;
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

  function inGroup(id: string): Category[] {
    return categories.filter((category) => category.group === id);
  }
</script>

<div class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Buckets</h1>
    <a
      class="btn primary"
      href="/categories/new"
      title="Add a bucket"
      onclick={(event) => { event.preventDefault(); go('/categories/new'); }}
    >New bucket</a>
  </div>
  <p class="muted m-0">A bucket sits in Needs, Wants, or Savings. Archiving hides the name.</p>
  <button
    class="btn w-fit"
    type="button"
    title={includeArchived ? 'Leave archived buckets off this list' : 'Archived buckets stay stored until you ask for them'}
    onclick={toggle}
  >{includeArchived ? 'Hide archived' : 'Show archived'}</button>
  {#if message}<p class="banner">{message}</p>{/if}
  {#each categories as category (category.id)}
    {#if category.target?.celebrate}
      <p class="banner celebrate m-0">{category.name} reached its target.</p>
    {/if}
  {/each}
  {#if ready}
    {#each groups as group (group.id)}
      <section class="grid gap-3">
        <h2 class="group-title text-2xl" style={`--tone: var(--tone-${group.id})`}>
          <Glyph name={group.id} />
          {group.label}
        </h2>
        {#if inGroup(group.id).length === 0}
          <p class="muted m-0">None.</p>
        {/if}
        {#each inGroup(group.id) as category (category.id)}
          <a
            class="row stripe"
            style={`--tone: var(--tone-${group.id})`}
            href={`/categories/${category.id}`}
            onclick={(event) => { event.preventDefault(); go(`/categories/${category.id}`); }}
          >
            <span class="flex items-center gap-3">
              <Glyph name={group.id} />
              <span>
                <strong>{category.name}</strong>
                <span class="block muted text-sm">
                  {category.overspend === 'carry' ? 'Carry' : 'Cover now'}
                  {#if category.amountMinor !== 0}
                    · {formatMinor(category.amountMinor, session.user?.baseCurrency ?? 'USD')}
                  {/if}
                  {#if category.target}
                    · {targetHint(category.target, session.user?.baseCurrency ?? 'USD')}
                  {/if}
                </span>
                {#if category.archivedAt}<span class="block muted text-sm">archived</span>{/if}
              </span>
            </span>
          </a>
        {/each}
      </section>
    {/each}
  {/if}
</div>
