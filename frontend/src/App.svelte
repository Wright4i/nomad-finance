<script lang="ts">
  import { onMount } from 'svelte';
  import { match, route, startRouter } from './lib/nav.svelte.ts';
  import { loadSession, session } from './lib/session.svelte.ts';
  import Shell from './components/Shell.svelte';
  import Setup from './pages/Setup.svelte';
  import Login from './pages/Login.svelte';
  import Dashboard from './pages/Dashboard.svelte';
  import Accounts from './pages/Accounts.svelte';
  import AccountForm from './pages/AccountForm.svelte';
  import Bills from './pages/Bills.svelte';
  import BillForm from './pages/BillForm.svelte';
  import CategoryForm from './pages/CategoryForm.svelte';
  import PayCycles from './pages/PayCycles.svelte';
  import PayCycle from './pages/PayCycle.svelte';
  import Envelopes from './pages/Envelopes.svelte';
  import Trips from './pages/Trips.svelte';
  import TripForm from './pages/TripForm.svelte';
  import Log from './pages/Log.svelte';
  import Calendar from './pages/Calendar.svelte';
  import Vehicles from './pages/Vehicles.svelte';
  import VehicleForm from './pages/VehicleForm.svelte';

  const view = $derived(match(route.path));

  $effect(() => {
    const path = route.path;
    if (path === '/credit' || path === '/plans' || path.startsWith('/plans/') || path === '/settings' || path === '/settings/rates') {
      history.replaceState({}, '', '/');
      route.path = '/';
      return;
    }
    if (path === '/categories' || path === '/envelopes' || path === '/buckets') {
      history.replaceState({}, '', '/budget');
      route.path = '/budget';
      return;
    }
    if (path.startsWith('/categories/')) {
      const next = '/buckets' + path.slice('/categories'.length);
      history.replaceState({}, '', next);
      route.path = next;
      return;
    }
    if (path === '/settings/vehicles' || path.startsWith('/settings/vehicles/')) {
      const next = '/vehicles' + path.slice('/settings/vehicles'.length);
      history.replaceState({}, '', next);
      route.path = next;
    }
  });

  onMount(() => {
    startRouter();
    void loadSession();
  });
</script>

{#if !session.ready}
  <div class="gate"><p class="muted">Opening the books…</p></div>
{:else if session.needsSetup}
  <Setup />
{:else if !session.user}
  <Login />
{:else}
  <Shell>
    {#key `${route.path}:${session.books}`}
      {#if view.name === 'dashboard'}
        <Dashboard />
      {:else if view.name === 'accounts'}
        <Accounts />
      {:else if view.name === 'account-form'}
        <AccountForm id={view.id} />
      {:else if view.name === 'bills'}
        <Bills />
      {:else if view.name === 'bill-form'}
        <BillForm id={view.id} />
      {:else if view.name === 'category-form'}
        <CategoryForm id={view.id} />
      {:else if view.name === 'pay-cycles'}
        <PayCycles />
      {:else if view.name === 'pay-cycle-form'}
        <PayCycle id={view.id} />
      {:else if view.name === 'envelopes'}
        <Envelopes />
      {:else if view.name === 'trips'}
        <Trips />
      {:else if view.name === 'trip-form'}
        <TripForm id={view.id} />
      {:else if view.name === 'log'}
        <Log />
      {:else if view.name === 'calendar'}
        <Calendar />
      {:else if view.name === 'vehicles'}
        <Vehicles />
      {:else if view.name === 'vehicle-form'}
        <VehicleForm id={view.id} />
      {/if}
    {/key}
  </Shell>
{/if}
