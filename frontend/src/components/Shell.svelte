<script lang="ts">
  import type { Snippet } from 'svelte';
  import { api, explain } from '../lib/api';
  import { follow, route } from '../lib/nav.svelte.ts';
  import { resetTripsGlobeWindow } from '../lib/tripsView.svelte.ts';
  import { adoptUser, applyTheme, loadSession, session } from '../lib/session.svelte.ts';
  import type { User } from '../lib/types';
  import DateField from './DateField.svelte';
  import Glyph from './Glyph.svelte';
  import { formatChipDate, formatSlashSpan } from '../lib/dates';
  import { fieldTips } from '../lib/fieldTips';
  import { todayISO } from '../lib/money';
  import { tip } from '../lib/tip';
  import { PERIOD_LENGTHS, periodEnd, periodShift, periodWithinReach, type PeriodLength } from '../lib/acctPeriod';

  let { children }: { children: Snippet } = $props();
  let open = $state(false);
  let collapsed = $state(false);
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
  let acctPeriodStart = $state('');
  let acctPeriodLength = $state<PeriodLength>('1 month');
  let spanStop = $state(3);
  let savingSpan = $state(false);

  let spanLabel = $derived(
    session.user?.acctPeriodStart && session.user.acctPeriodEnd
      ? `${formatChipDate(session.user.acctPeriodStart)} – ${formatChipDate(session.user.acctPeriodEnd)}`
      : '',
  );
  let compactSpan = $derived(
    session.user?.acctPeriodStart && session.user.acctPeriodEnd
      ? formatSlashSpan(session.user.acctPeriodStart, session.user.acctPeriodEnd)
      : '',
  );
  let draftEnd = $derived(acctPeriodStart ? periodEnd(acctPeriodStart, acctPeriodLength) : '');

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
      { href: '/trips', label: 'Trips', mark: 'briefcase' },
    ],
    [
      { href: '/bills', label: 'Bills', mark: 'debt' },
      { href: '/budget', label: 'Budget', mark: 'envelope' },
      { href: '/transfers', label: 'Transfers', mark: 'transfers' },
    ],
    [
      { href: '/accounts', label: 'Accounts', mark: 'vault' },
      { href: '/pay-cycles', label: 'Income', mark: 'cash' },
      { href: '/vehicles', label: 'Vehicles', mark: 'vehicle' },
    ],
  ];

  let theme = $derived(session.user?.theme === 'light' ? 'light' : 'dark');

  function active(href: string): boolean {
    if (href === '/') {
      return route.path === '/';
    }
    if (href === '/budget') {
      return route.path === '/budget'
        || route.path === '/envelopes'
        || route.path.startsWith('/envelopes/')
        || route.path === '/buckets'
        || route.path.startsWith('/buckets/')
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
    if (href === '/trips') {
      resetTripsGlobeWindow();
    }
    follow(event, href);
  }

  async function logout(): Promise<void> {
    await api('/api/logout', { method: 'POST', body: {} });
    await loadSession();
    open = false;
    follow(new MouseEvent('click'), '/');
  }

  function loadSpanDraft(): void {
    if (!session.user) return;
    acctPeriodStart = session.user.acctPeriodStart;
    const length = PERIOD_LENGTHS.find((item) => item === session.user?.acctPeriodLength) ?? '1 month';
    acctPeriodLength = length;
    spanStop = Math.max(0, PERIOD_LENGTHS.indexOf(length));
  }

  function setStop(value: number): void {
    const next = PERIOD_LENGTHS[value] ?? '1 month';
    spanStop = PERIOD_LENGTHS.indexOf(next);
    acctPeriodLength = next;
  }

  function canNudge(steps: number): boolean {
    if (!session.user) return false;
    const length = session.user.acctPeriodLength;
    const next = periodShift(session.user.acctPeriodStart, length, steps);
    return periodWithinReach(next, length, todayISO());
  }

  async function nudgeSpan(steps: number): Promise<void> {
    if (!session.user || savingSpan || !canNudge(steps)) return;
    savingSpan = true;
    try {
      const data = await api<{ user: User }>('/api/settings', {
        method: 'PATCH',
        body: {
          acctPeriodStart: periodShift(session.user.acctPeriodStart, session.user.acctPeriodLength, steps),
          acctPeriodLength: session.user.acctPeriodLength,
        },
      });
      adoptUser(data.user);
    } catch {
      // The arrows stay on the saved period when the request fails.
    } finally {
      savingSpan = false;
    }
  }

  function askReset(): void {
    resetDialog?.showModal();
  }

  async function setTheme(next: 'light' | 'dark'): Promise<void> {
    if (!session.user || savingTheme) return;
    const previous = session.user.theme === 'light' ? 'light' : 'dark';
    if (previous === next) return;
    savingTheme = true;
    try {
      const data = await api<{ user: User }>('/api/settings', {
        method: 'PATCH',
        body: { theme: next },
      });
      adoptUser(data.user);
    } catch {
      // The pill stays on the saved theme when the request fails.
    } finally {
      savingTheme = false;
    }
  }

  function askAccount(): void {
    if (!session.user) return;
    accountName = session.user.name;
    accountPassword = '';
    accountMessage = '';
    accountFields = {};
    loadSpanDraft();
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
          acctPeriodStart,
          acctPeriodLength,
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

  <nav class="nav drawer md:static" class:open class:collapsed aria-label="Primary">
    <div class="nav-brand">
      <button
        class="nav-collapse"
        type="button"
        aria-expanded={!collapsed}
        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        use:tip={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        onclick={() => (collapsed = !collapsed)}
      >
        <Glyph name={collapsed ? 'expand' : 'collapse'} mini />
      </button>
      <div class="nav-brand-copy">
        <p class="brand text-5xl m-0 text-[var(--teal)]" use:tip={collapsed ? 'Nomad Finance' : ''}>
          <span class="brand-mark">N</span><span class="brand-word">omad</span>
        </p>
        <p class="kicker mt-2">Finance</p>
      </div>
    </div>
    <hr class="nav-rule" />
    <a
      class="nav-log"
      href="/log"
      class:active={active('/log')}
      use:tip={collapsed ? 'New log' : ''}
      onclick={(event) => visit(event, '/log')}
    >
      <span class="nav-log-label">
        <Glyph name="log" mini />
        <span class="nav-label">New log</span>
      </span>
    </a>
    <hr class="nav-rule" />
    {#each groups as group, index}
      {#if index > 0}<hr class="nav-rule nav-group" />{/if}
      {#each group as item}
        <a
          href={item.href}
          class:active={active(item.href)}
          use:tip={collapsed ? item.label : ''}
          onclick={(event) => visit(event, item.href)}
        >
          <Glyph name={item.mark} mini />
          <span class="nav-label">{item.label}</span>
        </a>
      {/each}
    {/each}
    <div class="nav-foot">
      {#if spanLabel}
        <div class="period-step" role="group" aria-label={spanLabel}>
          <button type="button" aria-label="Previous" disabled={savingSpan || !canNudge(-1)} onclick={() => void nudgeSpan(-1)}>
            <Glyph name="collapse" mini />
          </button>
          <span class="period-dates">{collapsed ? compactSpan : spanLabel}</span>
          <button type="button" aria-label="Next" disabled={savingSpan || !canNudge(1)} onclick={() => void nudgeSpan(1)}>
            <Glyph name="expand" mini />
          </button>
        </div>
      {/if}
      <hr class="nav-rule" />
      {#if session.user}
        <button class="linkish nav-account" type="button" use:tip={collapsed ? session.user.name : ''} onclick={askAccount}>
          <span class="nav-name">{session.user.name}</span>
        </button>
      {/if}
      {#if session.user?.role === 'demo'}
        <button class="linkish nav-reset" type="button" disabled={resetting} aria-busy={resetting} onclick={askReset}>
          {resetting ? 'Resetting…' : 'Reset demo data'}
        </button>
      {/if}
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
        <button
          class="theme-toggle"
          type="button"
          aria-label={theme === 'light' ? 'Day' : 'Night'}
          use:tip={collapsed ? (theme === 'light' ? 'Day' : 'Night') : ''}
          onclick={() => setTheme(theme === 'light' ? 'dark' : 'light')}
        >
          <Glyph name={theme === 'light' ? 'sun' : 'moon'} mini />
        </button>
      </div>
      <button class="linkish nav-logout" type="button" use:tip={collapsed ? 'Log out' : ''} onclick={logout}>
        <span class="glyph mini" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none">
            <path d="M10 5.5H6.8A1.8 1.8 0 0 0 5 7.3v9.4a1.8 1.8 0 0 0 1.8 1.8H10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M10 12h9M15.8 8.2 19.6 12l-3.8 3.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        <span class="nav-label">Log out</span>
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
        <label class="field">
          <span use:tip={fieldTips.periodStart}>Period starts</span>
          <DateField name="acctPeriodStart" bind:value={acctPeriodStart} />
          {#if accountFields.acctPeriodStart}<em>{accountFields.acctPeriodStart}</em>{/if}
        </label>
        <label class="field">
          <span use:tip={fieldTips.periodLength}>{acctPeriodLength}</span>
          <input
            name="acctPeriodLength"
            type="range"
            min="0"
            max="5"
            step="1"
            value={spanStop}
            aria-valuetext={acctPeriodLength}
            oninput={(event) => setStop(Number(event.currentTarget.value))}
          />
        </label>
        <p class="m-0">Through {draftEnd ? formatChipDate(draftEnd) : ''}</p>
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
