<script lang="ts">
  import { api, explain } from '../lib/api';
  import { adoptUser } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';
  import Setup from './Setup.svelte';

  let name = $state('');
  let creating = $state(false);
  let password = $state('');
  let message = $state('');
  let saving = $state(false);

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    saving = true;
    message = '';
    try {
      const data = await api<{ user: User }>('/api/login', { method: 'POST', body: { name, password } });
      adoptUser(data.user);
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function useDemo(): Promise<void> {
    saving = true;
    message = '';
    try {
      const data = await api<{ user: User }>('/api/demo/enter', { method: 'POST', body: {} });
      adoptUser(data.user);
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

{#if creating}
  <Setup additional onBack={() => (creating = false)} />
{:else}
<div class="gate">
  <form class="panel grid gap-4" onsubmit={submit}>
    <div>
      <p class="brand text-6xl m-0 text-[var(--teal)]">Nomad</p>
      <p class="kicker mt-3">Welcome back</p>
    </div>
    {#if message}<p class="banner m-0">{message}</p>{/if}
    <label class="field">
      <span>Name</span>
      <input name="name" autocomplete="username" bind:value={name} />
    </label>
    <label class="field">
      <span>Password</span>
      <input name="password" type="password" autocomplete="current-password" bind:value={password} />
    </label>
    <button class="btn primary" type="submit" disabled={saving}>{saving ? 'Checking…' : 'Unlock'}</button>
    <div class="grid grid-cols-2 gap-3">
      <button class="btn" type="button" disabled={saving} onclick={useDemo}>Use demo</button>
      <button class="btn" type="button" disabled={saving} onclick={() => (creating = true)}>New user</button>
    </div>
  </form>
</div>
{/if}
