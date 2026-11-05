<script lang="ts">
  import CurrencySelect from '../components/CurrencySelect.svelte';
  import { api, explain } from '../lib/api';
  import { exponentOf, minorToInput, parseMajor } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { adoptUser, session } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';

  let name = $state(session.user?.name ?? '');
  let baseCurrency = $state(session.user?.baseCurrency ?? 'USD');
  let theme = $state<'dark' | 'light'>(session.user?.theme ?? 'dark');
  let password = $state('');
  let fuelPrice = $state(
    session.user?.fuelPriceMinor == null
      ? ''
      : minorToInput(session.user.fuelPriceMinor, exponentOf(session.user.baseCurrency)),
  );
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);

  async function save(nextTheme = theme): Promise<void> {
    const priceMinor = fuelPrice.trim() === '' ? null : parseMajor(fuelPrice, exponentOf(baseCurrency));
    if (fuelPrice.trim() !== '' && (priceMinor === null || priceMinor < 1)) {
      fields = { fuelPriceMinor: 'Enter a price per gallon greater than zero, or leave it empty.' };
      return;
    }
    saving = true;
    message = '';
    fields = {};
    try {
      const data = await api<{ user: User }>(
        '/api/settings',
        {
          method: 'PATCH',
          body: {
            name,
            baseCurrency,
            theme: nextTheme,
            password,
            fuelPriceMinor: priceMinor,
            horizonDays: session.user?.horizonDays ?? 60,
          },
        },
      );
      theme = data.user.theme;
      password = '';
      fuelPrice = data.user.fuelPriceMinor == null
        ? ''
        : minorToInput(data.user.fuelPriceMinor, exponentOf(data.user.baseCurrency));
      adoptUser(data.user);
      message = 'Saved.';
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  function submit(event: SubmitEvent): void {
    event.preventDefault();
    void save();
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">Settings</h1>
  {#if session.user?.role === 'demo'}
    <p class="m-0">Demo keeps the sample name, password, and base currency. Reset demo data restores the sample books.</p>
  {/if}
  {#if message}<p class:banner={message !== 'Saved.'} class:teal={message === 'Saved.'} aria-live="polite">{message}</p>{/if}
  <a class="row" href="/settings/vehicles" onclick={(event) => follow(event, '/settings/vehicles')}>
    <span>
      <strong>Vehicles</strong>
      <span class="block muted text-sm">Named odometers, and the starred one the fuel label uses</span>
    </span>
  </a>
  <a class="row" href="/settings/rates" onclick={(event) => follow(event, '/settings/rates')}>
    <span>
      <strong>Exchange rates</strong>
      <span class="block muted text-sm">How other currencies convert into the base currency</span>
    </span>
  </a>
  {#if session.user?.role !== 'demo'}
  <label class="field">
    <span>Name</span>
    <input name="name" bind:value={name} />
    {#if fields.name}<em>{fields.name}</em>{/if}
  </label>
  <label class="field">
    <span>Base currency</span>
    <CurrencySelect name="baseCurrency" bind:value={baseCurrency} />
    {#if fields.baseCurrency}<em>{fields.baseCurrency}</em>{/if}
  </label>
  {/if}
  <div class="field">
    <span>Theme</span>
    <div class="flex gap-2">
      <button class="choice" class:on={theme === 'dark'} type="button" aria-pressed={theme === 'dark'} onclick={() => save('dark')}>Dark</button>
      <button class="choice" class:on={theme === 'light'} type="button" aria-pressed={theme === 'light'} onclick={() => save('light')}>Light</button>
    </div>
    {#if fields.theme}<em>{fields.theme}</em>{/if}
  </div>
  <label class="field">
    <span>Fallback price per gallon ({baseCurrency})</span>
    <input name="fuelPrice" inputmode="decimal" placeholder="Leave empty for miles only…" bind:value={fuelPrice} />
    {#if fields.fuelPriceMinor}<em>{fields.fuelPriceMinor}</em>{/if}
  </label>
  {#if session.user?.role !== 'demo'}
  <label class="field">
    <span>New password</span>
    <input name="password" type="password" autocomplete="new-password" placeholder="Leave blank to keep the current one…" bind:value={password} />
    {#if fields.password}<em>{fields.password}</em>{/if}
  </label>
  {/if}
  <button class="btn primary w-fit" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
</form>
