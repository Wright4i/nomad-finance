<script lang="ts">
  import AmountInput from '../components/AmountInput.svelte'
  import Note from '../components/Note.svelte'
  import Spinner from '../components/Spinner.svelte'
  import { onMount } from 'svelte'
  import Glyph from '../components/Glyph.svelte'
  import { api, explain } from '../lib/api'
  import { billDays } from '../lib/billDays'
  import Dollars from '../components/Dollars.svelte'
  import { formatDate, todayISO } from '../lib/money'
  import { go, route } from '../lib/nav.svelte.ts'
  import { session } from '../lib/session.svelte.ts'
  import { accountLabel } from '../lib/accountLabel'
import type { Account, Bill } from '../lib/types'

  type SectionKey = 'active' | 'paused' | 'paid' | 'archived'
  const sectionOrder: SectionKey[] = ['active', 'paused', 'paid', 'archived']
  const tabs: { key: SectionKey, title: string, tone: string, unpay: boolean }[] = [
    { key: 'active', title: 'Active', tone: 'active', unpay: false },
    { key: 'paused', title: 'Paused', tone: 'paused', unpay: false },
    { key: 'paid', title: 'Paid', tone: 'paid', unpay: true },
    { key: 'archived', title: 'Archived', tone: 'archived', unpay: false },
  ]
  const billTypes = [
    'lodging', 'rent', 'electricity', 'water', 'fuel', 'propane', 'groceries', 'internet', 'cellular',
    'vehicle', 'maintenance', 'insurance', 'roadside', 'healthcare', 'mailbox', 'laundry', 'mortgage',
    'storage', 'other', 'restaurants', 'entertainment', 'subscriptions', 'shopping', 'travel',
    'memberships', 'software', 'pets', 'misc', 'debt', 'emergency', 'repairs', 'investments', 'liquidities',
  ]

  let shown = $state<SectionKey>(readFilters(route.path === '/bills' ? route.search : '').section)
  let loaded = $state<Record<SectionKey, Bill[]>>({ active: [], paused: [], paid: [], archived: [] })
  let hasMore = $state<Record<SectionKey, boolean>>({ active: false, paused: false, paid: false, archived: false })
  let chunks = $state<Record<SectionKey, number>>({ active: 1, paused: 1, paid: 1, archived: 1 })
  let message = $state('')
  let accounts = $state<Account[]>([])
  let open = $state<Record<number, boolean>>({})
  const opened = readFilters(route.path === '/bills' ? route.search : '')
  let filterName = $state(opened.name)
  let filterAccount = $state(opened.account)
  let filterType = $state(opened.type)
  let filterAmount = $state(opened.amount)
  let appliedSearch = '\u0000'

  function readFilters(search: string): { name: string, account: string, type: string, amount: string, section: SectionKey } {
    const params = new URLSearchParams(search)
    const type = params.get('type') ?? ''
    const tab = params.get('tab')
    const section: SectionKey = tab === 'paused' || tab === 'paid' || tab === 'archived' ? tab : 'active'
    return {
      name: params.get('name') ?? '',
      account: params.get('account') ?? '',
      type: billTypes.includes(type) ? type : '',
      amount: params.get('amount') ?? '',
      section,
    }
  }

  function rememberFilters(): void {
    const params = new URLSearchParams()
    if (filterName !== '') params.set('name', filterName)
    if (filterAccount !== '') params.set('account', filterAccount)
    if (filterType !== '') params.set('type', filterType)
    if (filterAmount !== '') params.set('amount', filterAmount)
    if (shown !== 'active') params.set('tab', shown)
    const next = params.size > 0 ? `?${params.toString()}` : ''
    if (route.path === '/bills' && route.search === next) return
    history.replaceState({}, '', `/bills${next}`)
    route.path = '/bills'
    route.search = next
  }
  const growing: Record<SectionKey, boolean> = { active: false, paused: false, paid: false, archived: false }
  const requested: Record<SectionKey, number> = { active: 1, paused: 1, paid: 1, archived: 1 }
  let reloadTimer: ReturnType<typeof setTimeout> | undefined
  let epoch = 0
  let booting = $state(true)
  let allOpen = $derived(rows(shown).length > 0 && rows(shown).every((bill) => open[bill.id] === true))

  function query(section: SectionKey, count: number): string {
    const params = new URLSearchParams({ section, chunks: String(count) })
    if (filterName.trim() !== '') params.set('name', filterName.trim())
    if (filterAccount !== '') params.set('accountId', filterAccount)
    if (filterType !== '') params.set('type', filterType)
    if (filterAmount.trim() !== '') params.set('amount', filterAmount.trim())
    if (session.user?.acctPeriodStart && session.user.acctPeriodEnd) {
      params.set('start', session.user.acctPeriodStart)
      params.set('end', session.user.acctPeriodEnd)
      params.set('today', todayISO())
    }
    return `/api/bills?${params.toString()}`
  }

  async function loadSection(section: SectionKey, count: number): Promise<void> {
    const generation = epoch
    requested[section] = Math.max(requested[section], count)
    const data = await api<{ bills: Bill[], hasMore: boolean }>(query(section, count))
    if (generation !== epoch || count < requested[section]) return
    loaded = { ...loaded, [section]: data.bills }
    hasMore = { ...hasMore, [section]: data.hasMore }
    chunks = { ...chunks, [section]: count }
  }

  async function load(): Promise<void> {
    try {
      await Promise.all(sectionOrder.map((section) => loadSection(section, chunks[section])))
    } finally {
      booting = false
    }
  }

  function scheduleLoad(): void {
    clearTimeout(reloadTimer)
    reloadTimer = setTimeout(() => {
      epoch += 1
      chunks = { active: 1, paused: 1, paid: 1, archived: 1 }
      requested.active = 1
      requested.paused = 1
      requested.paid = 1
      requested.archived = 1
      load().catch((err: unknown) => {
        message = err instanceof Error ? err.message : 'Could not load bills.'
      })
    }, 180)
  }

  $effect(() => {
    if (route.path !== '/bills') return
    const search = route.search
    if (search === appliedSearch) return
    appliedSearch = search
    const next = readFilters(search)
    if (filterName !== next.name) filterName = next.name
    if (filterAccount !== next.account) filterAccount = next.account
    if (filterType !== next.type) filterType = next.type
    if (filterAmount !== next.amount) filterAmount = next.amount
    if (shown !== next.section) shown = next.section
    scheduleLoad()
  })

  function grow(section: SectionKey, node: HTMLElement, deltaY: number | null): void {
    if (growing[section] || !hasMore[section]) return
    const atEnd = node.scrollTop + node.clientHeight >= node.scrollHeight - 8
    if (!atEnd) return
    if (deltaY === null && node.scrollTop <= 0 && node.scrollHeight <= node.clientHeight + 1) return
    if (deltaY !== null && deltaY <= 0) return
    const next = requested[section] + 1
    requested[section] = next
    growing[section] = true
    loadSection(section, next)
      .catch((err: unknown) => {
        message = err instanceof Error ? err.message : 'Could not load bills.'
      })
      .finally(() => {
        growing[section] = false
      })
  }

  onMount(() => {
    api<{ accounts: Account[] }>('/api/accounts?includeArchived=1')
      .then((data) => {
        accounts = data.accounts
      })
      .catch(() => {
        accounts = []
      })
  })

  function remove(id: number): void {
    if (!confirm('Delete this bill?')) return
    void act(id, 'delete', { confirm: true, today: todayISO() })
  }

  async function act(id: number, action: string, body: unknown = {}): Promise<void> {
    message = ''
    try {
      await api(`/api/bills/${id}/${action}`, { method: 'POST', body })
      await load()
    } catch (err) {
      message = explain(err).message
    }
  }

  function toggle(id: number): void {
    open = { ...open, [id]: !open[id] }
  }

  function toggleAll(): void {
    const ids = rows(shown).map((bill) => bill.id)
    const next = { ...open }
    const opening = !ids.every((id) => open[id])
    for (const id of ids) next[id] = opening
    open = next
  }

  function kind(bill: Bill): string {
    if ((bill.type === 'other' || bill.type === 'misc') && bill.otherLabel) return bill.otherLabel
    return bill.type
  }

  function recurrenceLabel(bill: Bill): string {
    if (bill.recurrence === 'once' || bill.every <= 1) return bill.recurrence
    const unit = bill.recurrence === 'daily'
      ? 'days'
      : bill.recurrence === 'weekly'
        ? 'weeks'
        : bill.recurrence === 'monthly'
          ? 'months'
          : 'years'
    return `every ${bill.every} ${unit}`
  }

  type Row = Bill & { shownOn: string }

  function paidShownOn(bill: Bill): string {
    if (bill.recurrence === 'once' && bill.status === 'paid') {
      return bill.nextDueDate
    }
    return bill.lastPaidOn ?? bill.nextDueDate
  }

  function rows(section: SectionKey): Row[] {
    const bills = loaded[section]
    if (section === 'paid') {
      return bills.map((bill) => ({ ...bill, shownOn: paidShownOn(bill) }))
    }
    return bills.map((bill) => ({ ...bill, shownOn: bill.nextDueDate }))
  }

</script>

<div class="bills-page">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-3xl m-0">Bills</h1>
    <a class="btn primary" href="/bills/new" onclick={(event) => { event.preventDefault(); go('/bills/new'); }}>New bill</a>
  </div>
  {#if booting}
    <Spinner />
  {:else}
  <div class="bill-tabs">
    <div role="tablist" aria-label="Bill status">
    {#each tabs as tab (tab.key)}
      <button
        class="bill-tab"
        type="button"
        role="tab"
        data-section={tab.key}
        aria-selected={shown === tab.key}
        style={`--tone: var(--tone-${tab.tone})`}
        onclick={() => {
          shown = tab.key;
          rememberFilters();
        }}
      >{tab.title}</button>
    {/each}
    </div>
    <button class="btn bill-expand" type="button" disabled={rows(shown).length === 0} onclick={toggleAll}>{allOpen ? 'Collapse' : 'Expand all'}</button>
  </div>
  <form class="bill-filter" role="search" aria-label="Filter bills" onsubmit={(event) => event.preventDefault()}>
    <label class="field">
      <span>Name</span>
      <input name="billName" bind:value={filterName} oninput={rememberFilters} />
    </label>
    <label class="field">
      <span>Draw from</span>
      <select name="billAccount" bind:value={filterAccount} onchange={rememberFilters}>
        <option value="">All accounts</option>
        {#each accounts as account (account.id)}
          <option value={String(account.id)}>{accountLabel(account)}</option>
        {/each}
      </select>
    </label>
    <label class="field">
      <span>Type</span>
      <select name="billType" bind:value={filterType} onchange={rememberFilters}>
        <option value="">All types</option>
        {#each billTypes as type (type)}
          <option value={type}>{type}</option>
        {/each}
      </select>
    </label>
    <label class="field">
      <span>Amount</span>
      <AmountInput name="billAmount" bind:value={filterAmount} oninput={rememberFilters} onblur={rememberFilters} />
    </label>
  </form>
  <Note bind:message />

  {#snippet group(title: string, key: SectionKey, unpay: boolean)}
    <section class="bill-section grid gap-3" data-section={key} data-label={title} hidden={shown !== key}>
      <div
        class="bill-scroll"
        onscroll={(event) => {
          if (event.currentTarget instanceof HTMLElement) grow(key, event.currentTarget, null);
        }}
        onwheel={(event) => {
          if (event.currentTarget instanceof HTMLElement) grow(key, event.currentTarget, event.deltaY);
        }}
      >
      {#if rows(key).length === 0}<p class="muted m-0">None.</p>{/if}
      {#each billDays(rows(key)) as day (day.date)}
        <div class="bill-day" data-date={day.date}>
          <h3 class="bill-date m-0">{formatDate(day.date)}</h3>
          <hr class="bill-date-rule" />
          <div class="bill-columns">
            {#each day.bills as bill (`${bill.id}-${bill.shownOn}`)}
              <article class="card stripe bill-card grid gap-0" data-open={open[bill.id] ? 'true' : 'false'} style={`--tone: var(--tone-${bill.type})`}>
                <button
                  class="bill-slim"
                  type="button"
                  aria-expanded={open[bill.id] ? 'true' : 'false'}
                  aria-label={open[bill.id] ? `Hide details for ${bill.name}` : `Show details for ${bill.name}`}
                  onclick={() => toggle(bill.id)}
                >
                  <span class="bill-slim-main">
                    <Glyph name={bill.type} />
                    <span class="bill-slim-text">
                      <strong>{bill.name}</strong>
                      {#if bill.locked}<Glyph name="lock" mini />{/if}
                      <span class="type-label">{kind(bill)}</span>
                    </span>
                  </span>
                  <Dollars minor={bill.amountMinor} currency={bill.currency} exponent={bill.exponent} owe />
                  <span class="bill-open" aria-hidden="true"><span class="bill-chevron"></span></span>
                </button>
                <div class="bill-more" hidden={!open[bill.id]}>
                  <div class="bill-detail-chips">
                    <span class="bill-detail-chip" data-chip="account" style={`--tone: var(--tone-${bill.accountType})`}>{bill.accountName}</span>
                    {#if bill.accountHolder}<span class="bill-detail-chip" data-chip="holder">{bill.accountHolder}</span>{/if}
                    {#if bill.categoryName}<span class="bill-detail-chip" data-chip="bucket">{bill.categoryName}</span>{/if}
                    <span class="bill-detail-chip" data-chip={bill.autopay ? 'autopay' : 'manual'}>{bill.autopay ? 'Autopay' : 'Manual'}</span>
                    <span class="bill-detail-chip" data-chip={bill.recurrence}>{recurrenceLabel(bill)}</span>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button class="btn" type="button" onclick={() => go(`/bills/${bill.id}`)}>Edit</button>
                    {#if !unpay && bill.status === 'active'}
                      <button class="btn" type="button" onclick={() => act(bill.id, 'paid', { paidOn: bill.nextDueDate })}>Mark paid</button>
                      {#if !bill.locked && bill.recurrence === 'once'}
                        <button class="btn" type="button" onclick={() => act(bill.id, 'archive')}>Archive</button>
                      {:else if !bill.locked}
                        <button class="btn" type="button" onclick={() => act(bill.id, 'pause')}>Pause</button>
                      {/if}
                    {/if}
                    {#if !unpay && bill.status === 'paused' && !bill.locked}
                      <button class="btn" type="button" onclick={() => act(bill.id, 'resume')}>Resume</button>
                      <button class="btn" type="button" onclick={() => act(bill.id, 'archive')}>Archive</button>
                    {/if}
                    {#if bill.status === 'archived'}
                      <button class="btn" type="button" onclick={() => act(bill.id, 'unarchive')}>Unarchive</button>
                      <button class="btn" type="button" onclick={() => remove(bill.id)}>Delete</button>
                    {/if}
                    {#if unpay}
                      <button class="btn" type="button" onclick={() => act(bill.id, 'unpay')}>Unpay</button>
                    {/if}
                  </div>
                </div>
              </article>
            {/each}
          </div>
        </div>
      {/each}
      </div>
    </section>
  {/snippet}

  {#each tabs as tab (tab.key)}
    {@render group(tab.title, tab.key, tab.unpay)}
  {/each}
  {/if}
</div>
