<script lang="ts">
  import type { Snippet } from 'svelte';
  import { api } from '../lib/api';
  import { follow, route } from '../lib/nav.svelte.ts';
  import { adoptUser, loadSession, session } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';
  import Glyph from './Glyph.svelte';

  let { children }: { children: Snippet } = $props();
  let open = $state(false);
  let resetting = $state(false);
  let resetDialog: HTMLDialogElement | undefined = $state();

  const items = [
    { href: '/', label: 'Planner', mark: 'needs' },
    { href: '/pay-cycles', label: 'Income', mark: 'cash' },
    { href: '/bills', label: 'Bills', mark: 'debt' },
    { href: '/calendar', label: 'Calendar', mark: 'monthly' },
    { href: '/trips', label: 'Trips', mark: 'transport' },
    { href: '/envelopes', label: 'Envelopes', mark: 'envelope' },
    { href: '/accounts', label: 'Accounts', mark: 'vault' },
  ];

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
    if (href === '/settings') {
      return route.path === '/settings'
        || route.path.startsWith('/settings/');
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
    <div class="px-2 pt-2 pb-3 hidden md:block">
      <p class="brand text-5xl m-0 text-[var(--teal)]">Nomad</p>
      <p class="kicker mt-2">Finance</p>
    </div>
    <hr class="nav-rule" />
    <a class="nav-log" href="/log" class:active={active('/log')} onclick={(event) => visit(event, '/log')}>
      <Glyph name="log" mini />
      New Log
    </a>
    <hr class="nav-rule" />
    {#each items as item}
      <a href={item.href} class:active={active(item.href)} onclick={(event) => visit(event, item.href)}>
        <Glyph name={item.mark} mini />
        {item.label}
      </a>
    {/each}
    <div class="nav-foot">
      <p class="px-3 text-sm muted">{session.user?.name}</p>
      {#if session.user?.role === 'demo'}
        <button class="linkish" type="button" disabled={resetting} aria-busy={resetting} onclick={askReset}>
          {resetting ? 'Resetting…' : 'Reset demo data'}
        </button>
      {/if}
      <hr class="nav-rule" />
      <a class="linkish" href="/settings" class:active={active('/settings')} onclick={(event) => visit(event, '/settings')}>
        <Glyph name="settings" mini />
        Settings
      </a>
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
