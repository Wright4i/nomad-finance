<script lang="ts">
  import CurrencySelect from '../components/CurrencySelect.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { exponentOf, parseMajor } from '../lib/money';
  import { adoptUser } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';

  let { additional = false, onBack }: { additional?: boolean; onBack?: () => void } = $props();

  const kinds = ['car', 'truck', 'motorcycle', 'rv', 'semi'] as const;

  function parseTenths(input: string): number | null {
    const trimmed = input.trim();
    if (!/^\d+(\.\d)?$/.test(trimmed)) return null;
    const [whole, frac = '0'] = trimmed.split('.');
    const tenths = Number(whole) * 10 + Number(frac);
    return tenths > 0 ? tenths : null;
  }

  let name = $state('');
  let password = $state('');
  let baseCurrency = $state('USD');
  let vehicleName = $state('Vehicle 1');
  let kind = $state<(typeof kinds)[number]>('rv');
  let mpgMin = $state('6.0');
  let mpgMax = $state('12.0');
  let fuelPrice = $state('');
  let message = $state('');
  let fields = $state<Record<string, string>>({});
  let saving = $state(false);

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    const min = parseTenths(mpgMin);
    const max = parseTenths(mpgMax);
    const priceMinor = fuelPrice.trim() === '' ? null : parseMajor(fuelPrice, exponentOf(baseCurrency));
    const problems: Record<string, string> = {};
    if (vehicleName.trim() === '') problems.vehicleName = 'Use a name of 1 to 80 characters.';
    if (min === null) problems.mpgMinTenths = 'Enter a minimum miles per gallon greater than zero.';
    if (max === null) problems.mpgMaxTenths = 'Enter a maximum miles per gallon greater than zero.';
    if (min !== null && max !== null && min >= max) problems.mpgMaxTenths = 'The maximum is above the minimum.';
    if (fuelPrice.trim() !== '' && (priceMinor === null || priceMinor < 1)) {
      problems.fuelPriceMinor = 'Enter a price per gallon greater than zero, or leave it empty.';
    }
    fields = problems;
    message = '';
    if (Object.keys(problems).length > 0) return;

    saving = true;
    try {
      const data = await api<{ user: User }>(additional ? '/api/users' : '/api/setup', {
        method: 'POST',
        body: {
          name,
          password,
          baseCurrency,
          fuelPriceMinor: priceMinor,
          vehicleName,
          kind,
          mpgMinTenths: min,
          mpgMaxTenths: max,
        },
      });
      adoptUser(data.user);
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }
</script>

<div class="gate">
  <form class="panel grid gap-4" onsubmit={submit}>
    <div>
      <p class="brand text-6xl m-0 text-[var(--teal)]">Nomad</p>
      <p class="kicker mt-3">Your books, wherever you are</p>
    </div>
    {#if additional}
      <p class="m-0">This creates another login and empty books. Pick the currency you want totals in, and the vehicle the fuel label uses. You can change these later.</p>
    {:else}
      <p class="m-0">This creates the only login. Pick the currency you want totals in, and the vehicle the fuel label uses. You can change these later.</p>
    {/if}
    {#if message}<p class="banner m-0">{message}</p>{/if}
    <label class="field">
      <span>Your name</span>
      <input name="name" autocomplete="name" bind:value={name} />
      {#if fields.name}<em>{fields.name}</em>{/if}
    </label>
    <label class="field">
      <span>Password</span>
      <input name="password" type="password" autocomplete="new-password" bind:value={password} />
      {#if fields.password}<em>{fields.password}</em>{/if}
    </label>
    <label class="field">
      <span>Base currency</span>
      <CurrencySelect name="baseCurrency" bind:value={baseCurrency} />
      {#if fields.baseCurrency}<em>{fields.baseCurrency}</em>{/if}
    </label>
    <label class="field">
      <span>Vehicle name</span>
      <input name="vehicleName" bind:value={vehicleName} />
      {#if fields.vehicleName}<em>{fields.vehicleName}</em>{/if}
    </label>
    <p class="m-0 text-sm muted">This vehicle is starred. The fuel label uses it.</p>
    <div class="field">
      <span>Kind</span>
      <div class="flex flex-wrap gap-2">
        {#each kinds as option}
          <button class="choice" class:on={kind === option} type="button" style={`--tone: var(--tone-${option})`} aria-pressed={kind === option} onclick={() => (kind = option)}>
            <Glyph name={option} mini />
            {option}
          </button>
        {/each}
      </div>
      {#if fields.kind}<em>{fields.kind}</em>{/if}
    </div>
    <div class="grid grid-cols-2 gap-3">
      <label class="field">
        <span>Minimum mpg</span>
        <input name="mpgMin" inputmode="decimal" bind:value={mpgMin} />
        {#if fields.mpgMinTenths}<em>{fields.mpgMinTenths}</em>{/if}
      </label>
      <label class="field">
        <span>Maximum mpg</span>
        <input name="mpgMax" inputmode="decimal" bind:value={mpgMax} />
        {#if fields.mpgMaxTenths}<em>{fields.mpgMaxTenths}</em>{/if}
      </label>
    </div>
    <label class="field">
      <span>Fallback price per gallon ({baseCurrency})</span>
      <input name="fuelPrice" inputmode="decimal" placeholder="Leave empty for miles only…" bind:value={fuelPrice} />
      {#if fields.fuelPriceMinor}<em>{fields.fuelPriceMinor}</em>{/if}
    </label>
    <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Create my login'}</button>
    {#if onBack}
      <button class="btn" type="button" disabled={saving} onclick={onBack}>Back</button>
    {/if}
  </form>
</div>
