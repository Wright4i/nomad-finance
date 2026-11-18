<script lang="ts">
  import AmountInput from '../components/AmountInput.svelte'
  import Note from '../components/Note.svelte'
  import { onMount } from 'svelte'
  import { session } from '../lib/session.svelte.ts'
  import DateField from '../components/DateField.svelte'
  import Dollars from '../components/Dollars.svelte'
  import Glyph from '../components/Glyph.svelte'
  import { api, explain } from '../lib/api'
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates'
  import { minorToInput, parseMajor, todayISO } from '../lib/money'
  import { accountLabel } from '../lib/accountLabel'
  import { fieldTips } from '../lib/fieldTips'
  import { tip } from '../lib/tip'
import type { Account, Category, EnvelopeCategory, EnvelopeSheet, Vehicle } from '../lib/types'
  import { wantGaugeSpendLevels } from '../lib/wantGauge'

  const typesByKind = {
    need: [
      'lodging', 'rent', 'electricity', 'water', 'propane', 'groceries', 'internet', 'cellular',
      'vehicle', 'maintenance', 'insurance', 'roadside', 'healthcare', 'mailbox', 'laundry',
      'mortgage', 'storage', 'other',
    ],
    want: [
      'restaurants', 'entertainment', 'subscriptions', 'shopping', 'travel',
      'memberships', 'software', 'pets', 'misc',
    ],
  }

  let kind = $state<'fuel' | 'need' | 'want'>('fuel')
  let accounts = $state<Account[]>([])
  let vehicles = $state<Vehicle[]>([])
  let categories = $state<Category[]>([])
  let accountId = $state('')
  let vehicleId = $state('')
  let spendType = $state('restaurants')
  let name = $state('')
  let amount = $state('')
  let postedOn = $state(todayISO())
  let odometer = $state('')
  let price = $state('')
  let odometerTouched = $state(false)
  let priceTouched = $state(false)
  let notes = $state('')
  let fields = $state<Record<string, string>>({})
  let message = $state('')
  let saving = $state(false)

  let vehicle = $derived(vehicles.find((item) => String(item.id) === vehicleId) ?? null)
  let entered = $derived(Number(odometer))
  let miles = $derived(
    vehicle && odometer.trim() !== '' && Number.isInteger(entered) && entered >= vehicle.currentOdometerMiles
      ? entered - vehicle.currentOdometerMiles
      : null,
  )
  let fuelAccounts = $derived(accounts.filter((item) => item.type === 'fuel' && item.archivedAt === null))
  let mpg = $derived(liveMpg(miles, amount, price))
  let sheet = $state<EnvelopeSheet | null>(null)

  let spendTypes = $derived(kind === 'need' ? typesByKind.need : typesByKind.want)
  let logBucket = $derived.by((): EnvelopeCategory | null => {
    if (!sheet) return null
    const match = categories.find((item) => item.locked && item.billType === spendType)
    if (!match) return null
    return sheet.categories.find((item) => item.id === match.id) ?? null
  })
  let logAutoMinor = $derived(logBucket?.billMinor ?? 0)
  let logEnvelopeMinor = $derived(
    logBucket ? Math.max(0, (logBucket.amountMinor ?? 0) - logAutoMinor) : 0,
  )
  let logTypedMinor = $derived.by(() => {
    if (!sheet) return 0
    const parsed = parseMajor(amount, sheet.exponent)
    return parsed !== null && parsed > 0 ? parsed : 0
  })
  let logSpendLevels = $derived(wantGaugeSpendLevels(logEnvelopeMinor, logTypedMinor))
  let logTone = $derived(kind === 'need' ? 'needs' : 'wants')

  function applyVehicle(current: Vehicle | null): void {
    if (!current) return
    if (!odometerTouched) odometer = String(current.currentOdometerMiles)
    if (!priceTouched) {
      price = current.suggestedPricePerGallonMinor == null
        ? ''
        : minorToInput(current.suggestedPricePerGallonMinor, 2)
    }
  }

  function chooseVehicle(event: Event): void {
    const select = event.currentTarget
    if (!(select instanceof HTMLSelectElement)) return
    applyVehicle(vehicles.find((item) => String(item.id) === select.value) ?? null)
  }

  function liveMpg(traveled: number | null, amountText: string, priceText: string): string | null {
    if (traveled === null) return null
    const amountMinor = parseMajor(amountText, 2)
    const priceMinor = parseMajor(priceText, 2)
    if (amountMinor === null || amountMinor < 1 || priceMinor === null || priceMinor < 1) return null
    if (traveled === 0) return '0.0'
    const gallonsMilli = Math.trunc((amountMinor * 1000 + Math.trunc(priceMinor / 2)) / priceMinor)
    if (gallonsMilli <= 0) return null
    const tenths = Math.trunc((traveled * 10000 + Math.trunc(gallonsMilli / 2)) / gallonsMilli)
    return `${Math.trunc(tenths / 10)}.${tenths % 10}`
  }

  async function loadBudget(): Promise<void> {
    sheet = await api<EnvelopeSheet>(`/api/budget?today=${todayISO()}&acctPeriod=1`).catch(() => null)
  }

  onMount(async () => {
    try {
      const [accountData, vehicleData, categoryData] = await Promise.all([
        api<{ accounts: Account[] }>('/api/accounts'),
        api<{ vehicles: Vehicle[] }>('/api/vehicles'),
        api<{ categories: Category[] }>('/api/buckets'),
      ])
      accounts = accountData.accounts.filter((item) => item.archivedAt === null)
      vehicles = vehicleData.vehicles
      categories = categoryData.categories
      const starred = vehicles.find((item) => item.starred) ?? vehicles[0]
      if (starred) {
        vehicleId = String(starred.id)
        applyVehicle(starred)
      }
      pickAccount('fuel')
      await loadBudget()
    } catch (err) {
      message = explain(err).message
    }
  })

  $effect(() => {
    if (!session.user) return
    session.user.acctPeriodStart
    session.user.acctPeriodLength
    void loadBudget()
  })

  function chooseKind(next: 'fuel' | 'need' | 'want'): void {
    kind = next
    if (next === 'need') spendType = 'groceries'
    if (next === 'want') spendType = 'restaurants'
    pickAccount(next)
  }

  function pickAccount(next: 'fuel' | 'need' | 'want'): void {
    if (next === 'fuel') {
      const gas = accounts.find((item) => item.gasFavorite && item.type === 'fuel' && item.archivedAt === null)
        ?? accounts.find((item) => item.type === 'fuel' && item.archivedAt === null)
      accountId = gas ? String(gas.id) : ''
      return
    }
    const shopping = accounts.find((item) => item.shoppingFavorite && item.type === 'credit' && item.archivedAt === null)
    accountId = shopping ? String(shopping.id) : ''
  }

  async function resetForAnotherLog(): Promise<void> {
    name = ''
    amount = ''
    notes = ''
    fields = {}
    message = ''
    postedOn = todayISO()
    odometerTouched = false
    priceTouched = false
    saving = false
    try {
      const vehicleData = await api<{ vehicles: Vehicle[] }>('/api/vehicles')
      vehicles = vehicleData.vehicles
      applyVehicle(vehicle)
      await loadBudget()
    } catch (err) {
      message = explain(err).message
    }
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault()
    fields = {}
    message = ''
    const date = readDateInput('date')
    if (!date) fields.date = DATE_INPUT_ERROR
    else if (date > todayISO()) fields.date = 'The date cannot be after today.'
    const amountMinor = parseMajor(amount, 2)
    if (amountMinor === null || amountMinor < 1) fields.amountMinor = 'Enter an amount greater than zero.'
    if (accountId === '') fields.accountId = 'Choose an account.'
    const body: Record<string, unknown> = {
      kind,
      accountId: Number(accountId),
      amountMinor,
      date,
      notes: notes.trim() === '' ? null : notes,
      today: todayISO(),
    }
    if (kind === 'fuel') {
      if (vehicleId === '') fields.vehicleId = 'Choose a vehicle.'
      const reading = Number(odometer)
      if (odometer.trim() === '' || !Number.isInteger(reading) || reading < 0) {
        fields.odometerMiles = 'Enter the odometer.'
      }
      body.vehicleId = Number(vehicleId)
      body.odometerMiles = reading
      if (price.trim() !== '') {
        const priceMinor = parseMajor(price, 2)
        if (priceMinor === null || priceMinor < 1) fields.pricePerGallonMinor = 'Enter a price per gallon greater than zero, or leave it blank.'
        body.pricePerGallonMinor = priceMinor
      }
    } else {
      if (name.trim() === '') fields.name = 'Use a name of 1 to 80 characters.'
      body.type = spendType
      body.name = name.trim()
      if (spendType === 'misc' || spendType === 'other') body.otherLabel = name.trim()
    }
    if (Object.keys(fields).length > 0) return
    saving = true
    try {
      await api('/api/logs', { method: 'POST', body })
      await resetForAnotherLog()
    } catch (err) {
      const problem = explain(err)
      message = problem.message
      fields = problem.fields
      saving = false
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">New log</h1>
  <Note bind:message />
  <div class="field">
    <span use:tip={fieldTips.logKind}>Kind</span>
    <div class="flex gap-2">
      <button class="choice" class:on={kind === 'fuel'} type="button" onclick={() => chooseKind('fuel')}>
        <Glyph name="fuel" mini /> Fuel
      </button>
      <button class="choice" class:on={kind === 'need'} type="button" onclick={() => chooseKind('need')}>
        <Glyph name="needs" mini /> Need
      </button>
      <button class="choice" class:on={kind === 'want'} type="button" onclick={() => chooseKind('want')}>
        <Glyph name="wants" mini /> Want
      </button>
    </div>
  </div>
  <label class="field">
    <span use:tip={fieldTips.logAccount}>Account</span>
    <select name="accountId" bind:value={accountId}>
      <option value="">Choose an account</option>
      {#each (kind === 'fuel' && fuelAccounts.length > 0 ? fuelAccounts : accounts) as item}
        <option value={String(item.id)}>{accountLabel(item)}</option>
      {/each}
    </select>
    {#if fields.accountId}<em>{fields.accountId}</em>{/if}
  </label>
  {#if kind === 'fuel'}
    <label class="field">
      <span>Vehicle</span>
      <select name="vehicleId" bind:value={vehicleId} onchange={chooseVehicle}>
        <option value="">Choose a vehicle</option>
        {#each vehicles as item}
          <option value={String(item.id)}>{item.name}</option>
        {/each}
      </select>
      {#if fields.vehicleId}<em>{fields.vehicleId}</em>{/if}
    </label>
    <label class="field">
      <span use:tip={fieldTips.logOdometer}>Odometer</span>
      <input name="odometer" inputmode="numeric" bind:value={odometer} oninput={() => (odometerTouched = true)} />
      {#if fields.odometerMiles}<em>{fields.odometerMiles}</em>{/if}
    </label>
    {#if miles !== null}
      <div class="log-stats">
        <div class="log-stat log-stat-miles" data-miles={miles}>
          <strong>{miles} mi.</strong>
          <span>traveled</span>
        </div>
        {#if mpg !== null}
          <div class="log-stat log-stat-mpg" data-mpg={mpg}>
            <strong>{mpg}</strong>
            <span>mpg</span>
          </div>
        {/if}
      </div>
    {/if}
    <label class="field">
      <span use:tip={fieldTips.logPrice}>Price per gallon</span>
      <span class="money-input">
        <span class="money-mark" aria-hidden="true">$</span>
        <AmountInput name="price" bind:value={price} oninput={() => (priceTouched = true)} />
      </span>
      {#if fields.pricePerGallonMinor}<em>{fields.pricePerGallonMinor}</em>{/if}
    </label>
  {:else}
    <div class="field">
      <span use:tip={fieldTips.logType}>Type</span>
      <div class="flex flex-wrap gap-2">
        {#each spendTypes as option}
          <button class="choice" class:on={spendType === option} type="button" data-log-type={option} style={`--tone: var(--tone-${option})`} onclick={() => (spendType = option)}>
            <Glyph name={option} mini />
            {option}
          </button>
        {/each}
      </div>
    </div>
    <label class="field">
      <span>Name</span>
      <input name="name" bind:value={name} />
      {#if fields.name}<em>{fields.name}</em>{/if}
    </label>
    {#if logBucket && sheet}
      <div class="bucket-cell log-bucket-cell">
        <article
          class="bucket-tile envelope-tile log-bucket-tile"
          class:over={logSpendLevels.over}
          data-log-bucket
          data-bucket={logBucket.name}
          data-envelope-minor={logEnvelopeMinor}
          data-auto-minor={logAutoMinor}
          data-after-minor={logSpendLevels.afterMinor}
          data-over={logSpendLevels.over ? '1' : '0'}
          style={`--tone: var(--tone-${logTone}); --level: ${logSpendLevels.remainingLevel}`}
        >
          <span class="bucket-water" aria-hidden="true">
            <span class="bucket-wave"></span>
            <span class="bucket-wave"></span>
            <span class="bucket-wave"></span>
          </span>
          <span class="bucket-name">
            <Glyph name="lock" mini />
            <strong>{logBucket.name}</strong>
          </span>
          <div class="bucket-field bucket-status">
            <span class="bill-chip">Bill assignments</span>
            <strong class="bucket-locked" data-field="auto">
              <Dollars minor={logAutoMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
            </strong>
          </div>
          <div class="bucket-field">
            <span>Envelope</span>
            <strong class="bucket-locked" data-field="envelope">
              <Dollars minor={logEnvelopeMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
            </strong>
          </div>
          <div class="bucket-field" data-field="after">
            <span>After</span>
            <strong class="bucket-locked">
              <Dollars minor={logSpendLevels.afterMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} />
            </strong>
          </div>
        </article>
        {#if logSpendLevels.over && logSpendLevels.overMinor > 0}
          <p class="bucket-overage">
            Over by <Dollars minor={logSpendLevels.overMinor} currency={sheet.baseCurrency} exponent={sheet.exponent} owe />
          </p>
        {/if}
      </div>
    {/if}
  {/if}
  <label class="field">
    <span use:tip={fieldTips.logAmount}>Amount</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <AmountInput name="amount" bind:value={amount} />
    </span>
    {#if fields.amountMinor}<em>{fields.amountMinor}</em>{/if}
  </label>
  <label class="field">
    <span use:tip={fieldTips.logDate}>Date</span>
    <DateField name="date" bind:value={postedOn} />
    {#if fields.date}<em>{fields.date}</em>{/if}
  </label>
  <label class="field">
    <span>Notes</span>
    <textarea name="notes" bind:value={notes}></textarea>
  </label>
  <button class="btn primary w-fit" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save log'}</button>
</form>
