<script lang="ts">
  import { onMount } from 'svelte';
  import { api, explain } from '../lib/api';
  import CurrencySelect from '../components/CurrencySelect.svelte';
  import { session } from '../lib/session.svelte.ts';
  import type { Rate } from '../lib/types';

  let rates = $state<Rate[]>([]);
  let currency = $state('JPY');
  let rate = $state('');
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);

  let base = $derived(session.user?.baseCurrency ?? 'USD');
  let current = $derived(rates.filter((item) => item.baseCurrency === base));
  let unused = $derived(rates.filter((item) => item.baseCurrency !== base));

  async function load(): Promise<void> {
    const data = await api<{ rates: Rate[] }>('/api/rates');
    rates = data.rates;
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
    });
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    fields = {};
    message = '';
    try {
      await api('/api/rates', {
        method: 'POST',
        body: { currency, rate },
      });
      rate = '';
      await load();
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  async function remove(id: number): Promise<void> {
    message = '';
    try {
      await api(`/api/rates/${id}`, { method: 'DELETE' });
      await load();
    } catch (err) {
      message = explain(err).message;
    }
  }
</script>

<div class="grid gap-6 max-w-2xl">
  <h1 class="text-3xl m-0">Exchange rates</h1>
  <p class="muted m-0">A fallback is how many {base} equal 1 unit of another currency. Change it here. An open account uses a live quote from the last day when one is available, and the fallback otherwise.</p>
  {#if message}<p class="banner">{message}</p>{/if}
  <form class="card grid gap-3" onsubmit={submit}>
    <div class="grid md:grid-cols-2 gap-3">
      <label class="field">
        <span>Currency</span>
        <CurrencySelect name="currency" bind:value={currency} exclude={base} />
        {#if fields.currency}<em>{fields.currency}</em>{/if}
      </label>
      <label class="field">
        <span>Fallback, 1 unit in {base}</span>
        <input name="rate" inputmode="decimal" placeholder="0.0067" bind:value={rate} />
        {#if fields.rate}<em>{fields.rate}</em>{/if}
      </label>
    </div>
    <button class="btn primary w-fit" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save fallback'}</button>
  </form>

  <section class="grid gap-2">
    {#if current.length === 0}<p class="muted">No fallback rates for {base} yet.</p>{/if}
    {#each current as item (`${item.baseCurrency}:${item.currency}`)}
      <article class="row">
        <span>
          <strong>1 {item.currency}</strong>
          {#if item.fallbackRate}
            <span class="block muted text-sm">Fallback {item.fallbackRate} {item.baseCurrency}</span>
          {/if}
        </span>
        <span class="flex items-center gap-3">
          {#if item.live && item.liveRate}
            <span class="live-dot" role="img" aria-label="Live"></span>
            <strong class="teal">{item.liveRate} {item.baseCurrency}</strong>
          {:else if item.used}
            <span class="muted text-sm">No live quote</span>
          {/if}
          {#if item.id !== null}
            <button class="btn danger" type="button" onclick={() => item.id !== null && remove(item.id)}>Delete</button>
          {/if}
        </span>
      </article>
    {/each}
  </section>

  {#if unused.length > 0}
    <section class="grid gap-2">
      <h2 class="text-lg m-0">Saved for another base currency</h2>
      {#each unused as item (`${item.baseCurrency}:${item.currency}`)}
        <article class="row">
          <span class="muted">1 {item.currency} = {item.fallbackRate} {item.baseCurrency}</span>
          {#if item.id !== null}
            <button class="btn" type="button" onclick={() => item.id !== null && remove(item.id)}>Delete</button>
          {/if}
        </article>
      {/each}
    </section>
  {/if}
</div>
