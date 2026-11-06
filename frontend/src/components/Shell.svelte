<script lang="ts">
  import type { Snippet } from 'svelte';
  import { api, explain } from '../lib/api';
  import { follow, route } from '../lib/nav.svelte.ts';
  import { adoptUser, applyTheme, loadSession, session } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';
  import Glyph from './Glyph.svelte';

  let { children }: { children: Snippet } = $props();
  let open = $state(false);
  let resetting = $state(false);
  let savingTheme = $state(false);
  let resetDialog: HTMLDialogElement | undefined = $state();
  let accountDialog: HTMLDialogElement | undefined = $state();
  let accountOpen = $state(false);
  let accountName = $state('');
  let accountPassword = $state('');
  let accountMessage = $state('');
  let accountFields = $state<Record<string, string>>({});
  let savingAccount = $state(false);

  function openAccountDialog(node: HTMLDialogElement): { destroy: () => void } {
    node.showModal();
    const onClose = (): void => {
      accountOpen = false;
    };
    node.addEventListener('close', onClose);
    return {
      destroy() {
        node.removeEventListener('close', onClose);
      },
    };
  }

  const groups = [
    [
      { href: '/', label: 'Planner', mark: 'needs' },
      { href: '/calendar', label: 'Calendar', mark: 'monthly' },
      { href: '/trips', label: 'Trips', mark: 'transport' },
    ],
    [
      { href: '/pay-cycles', label: 'Income', mark: 'cash' },
      { href: '/bills', label: 'Bills', mark: 'debt' },
      { href: '/envelopes', label: 'Envelopes', mark: 'envelope' },
    ],
    [
      { href: '/accounts', label: 'Accounts', mark: 'vault' },
      { href: '/vehicles', label: 'Vehicles', mark: 'vehicle' },
    ],
  ];

  let theme = $derived(session.user?.theme === 'light' ? 'light' : 'dark');

  function active(href: string): boolean {
    if (href === '/') {
      return route.path === '/';
    }
    if (href === '/envelopes') {
      return route.path === '/envelopes'
        || route.path.startsWith('/envelopes/')
        || route.path === '/categories'
        || route.path.startsWith('/categories/');
    }
    if (href === '/vehicles') {
      return route.path === '/vehicles'
        || route.path.startsWith('/vehicles/')
        || route.path.startsWith('/settings/vehicles');
    }
    return route.path === href || route.path.startsWith(`${href}/`);
  }

  function visit(event: MouseEvent, href: string): void {
    const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
    if (!modified) open = false;
    follow(event, href);
  }

  async function logout(): Promise<void> {
    await api('/api/logout', { method: 'POST', body: {} });
    await loadSession();
    open = false;
    follow(new MouseEvent('click'), '/');
  }

  function askReset(): void {
    resetDialog?.showModal();
  }

  async function setTheme(next: 'light' | 'dark'): Promise<void> {
    if (!session.user || savingTheme) return;
    const previous = session.user.theme === 'light' ? 'light' : 'dark';
    if (previous === next) return;
    applyTheme(next);
    session.user = { ...session.user, theme: next };
    savingTheme = true;
    try {
      const data = await api<{ user: User }>('/api/settings', {
        method: 'PATCH',
        body: { theme: next },
      });
      adoptUser(data.user);
    } catch {
      applyTheme(previous);
      if (session.user) session.user = { ...session.user, theme: previous };
    } finally {
      savingTheme = false;
    }
  }

  function askAccount(): void {
    if (!session.user || session.user.role === 'demo') return;
    accountName = session.user.name;
    accountPassword = '';
    accountMessage = '';
    accountFields = {};
    accountOpen = true;
  }

  async function saveAccount(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!session.user || savingAccount) return;
    savingAccount = true;
    accountMessage = '';
    accountFields = {};
    try {
      const data = await api<{ user: User }>('/api/settings', {
        method: 'PATCH',
        body: {
          name: accountName,
          password: accountPassword,
          horizonDays: session.user.horizonDays,
        },
      });
      adoptUser(data.user);
      accountPassword = '';
      accountDialog?.close();
    } catch (err) {
      const problem = explain(err);
      accountMessage = problem.message;
      accountFields = problem.fields;
    } finally {
      savingAccount = false;
    }
  }

  async function resetDemo(): Promise<void> {
    if (resetting) return;
    resetting = true;
    try {
      const data = await api<{ user: User }>('/api/demo/reset', { method: 'POST', body: {} });
      adoptUser(data.user);
      session.books += 1;
      resetDialog?.close();
    } finally {
      resetting = false;
    }
  }

  $effect(() => {
    route.path;
    open = false;
  });
</script>

<a class="skip" href="#main">Skip to content</a>

<div class="shell">
  <header class="topbar md:hidden">
    <button class="icon-btn" type="button" aria-expanded={open} aria-label="Open menu" onclick={() => (open = true)}>
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M2 4.5h14M2 9h14M2 13.5h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
    </button>
    <p class="brand text-3xl m-0">Nomad</p>
    <span class="topbar-balance" aria-hidden="true"></span>
  </header>

  {#if open}
    <button class="scrim md:hidden" type="button" aria-label="Close menu" onclick={() => (open = false)}></button>
  {/if}

  <nav class="nav drawer md:static" class:open aria-label="Primary">
    <div class="px-2 pt-2 pb-3">
      <p class="brand text-5xl m-0 text-[var(--teal)]">Nomad</p>
      <p class="kicker mt-2">Finance</p>
    </div>
    <hr class="nav-rule" />
    <a class="nav-log" href="/log" class:active={active('/log')} onclick={(event) => visit(event, '/log')}>
      <span class="nav-log-label">
        <Glyph name="log" mini />
        <span>New log</span>
      </span>
    </a>
    <hr class="nav-rule" />
    {#each groups as group, index}
      {#if index > 0}<hr class="nav-rule" />{/if}
      {#each group as item}
        <a href={item.href} class:active={active(item.href)} onclick={(event) => visit(event, item.href)}>
          <Glyph name={item.mark} mini />
          {item.label}
        </a>
      {/each}
    {/each}
    <div class="nav-foot">
      <hr class="nav-rule" />
      {#if session.user && session.user.role !== 'demo'}
        <button class="linkish" type="button" onclick={askAccount}>{session.user.name}</button>
      {:else}
        <p class="px-3 text-sm muted">{session.user?.name}</p>
      {/if}
      {#if session.user?.role === 'demo'}
        <button class="linkish" type="button" disabled={resetting} aria-busy={resetting} onclick={askReset}>
          {resetting ? 'Resetting…' : 'Reset demo data'}
        </button>
      {/if}
      <hr class="nav-rule" />
      <div class="theme-pill" class:light={theme === 'light'} role="group" aria-label="Theme">
        <span class="theme-slant night" aria-hidden="true"></span>
        <span class="theme-slant light" aria-hidden="true"></span>
        <button
          type="button"
          aria-label="Night"
          aria-pressed={theme !== 'light'}
          onclick={() => setTheme('dark')}
        >
          <Glyph name="moon" mini />
        </button>
        <button
          type="button"
          aria-label="Day"
          aria-pressed={theme === 'light'}
          onclick={() => setTheme('light')}
        >
          <Glyph name="sun" mini />
        </button>
      </div>
      <button class="linkish" type="button" onclick={logout}>
        <span class="glyph mini" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M10 5.5H6.8A1.8 1.8 0 0 0 5 7.3v9.4a1.8 1.8 0 0 0 1.8 1.8H10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M10 12h9M15.8 8.2 19.6 12l-3.8 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        Log out
      </button>
    </div>
  </nav>

  <main id="main" class="main px-4 py-6 md:px-10 md:py-10">
    {@render children()}
  </main>
</div>

{#if accountOpen}
  <dialog class="modal" bind:this={accountDialog} use:openAccountDialog aria-labelledby="account-title">
    <form onsubmit={saveAccount}>
      <div class="modal-body">
        <h2 id="account-title" class="m-0 text-2xl">Name and password</h2>
        {#if accountMessage}<p class="banner m-0">{accountMessage}</p>{/if}
        <label class="field">
          <span>Name</span>
          <input name="accountName" bind:value={accountName} />
          {#if accountFields.name}<em>{accountFields.name}</em>{/if}
        </label>
        <label class="field">
          <span>New password</span>
          <input name="accountPassword" type="password" autocomplete="new-password" placeholder="Leave blank to keep the current one…" bind:value={accountPassword} />
          {#if accountFields.password}<em>{accountFields.password}</em>{/if}
        </label>
      </div>
      <div class="modal-actions">
        <button class="btn primary" type="submit" disabled={savingAccount}>{savingAccount ? 'Saving…' : 'Save'}</button>
        <button class="btn" type="button" onclick={() => accountDialog?.close()}>Cancel</button>
      </div>
    </form>
  </dialog>
{/if}

<dialog class="modal" bind:this={resetDialog} aria-labelledby="reset-title">
  <div class="modal-body">
    <h2 id="reset-title" class="m-0 text-2xl">Reset the sample books?</h2>
    <p class="m-0">This puts Demo's sample accounts, bills, and buckets back.</p>
  </div>
  <div class="modal-actions">
    <button class="btn danger" type="button" disabled={resetting} aria-busy={resetting} onclick={resetDemo}>Reset the sample books</button>
    <button class="btn" type="button" onclick={() => resetDialog?.close()}>Cancel</button>
  </div>
</dialog>
