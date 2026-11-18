<script lang="ts">
  import AmountInput from '../components/AmountInput.svelte'
  import Note from '../components/Note.svelte'
  import Spinner from '../components/Spinner.svelte'
  import { onMount } from 'svelte'
  import DateField from '../components/DateField.svelte'
  import Globe from '../components/Globe.svelte'
  import { api, explain } from '../lib/api'
  import { DATE_INPUT_ERROR, readDateInput } from '../lib/dates'
  import { minorToInput, parseMajor, todayISO } from '../lib/money'
  import { go } from '../lib/nav.svelte.ts'
  import { tripsView } from '../lib/tripsView.svelte.ts'
  import { accountLabel } from '../lib/accountLabel'
  import { stayAccount } from '../lib/payFrom'
  import { fieldTips } from '../lib/fieldTips'
  import { tip } from '../lib/tip'
import type { Account, Trip } from '../lib/types'

  let { id = null }: { id?: string | null } = $props()

  function dayAfter(iso: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
    if (!match) return iso
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
    date.setUTCDate(date.getUTCDate() + 1)
    return date.toISOString().slice(0, 10)
  }

  function newStayArriveOn(): string {
    const month = tripsView.calendarMonth
    if (/^\d{4}-\d{2}$/.test(month)) return `${month}-01`
    return todayISO()
  }

  let placeName = $state('')
  let note = $state('')
  let arriveOn = $state(id == null ? newStayArriveOn() : todayISO())
  let departOn = $state(id == null ? dayAfter(newStayArriveOn()) : todayISO())
  let departFollows = $state(id == null)
  let balanceDueOn = $state(todayISO())
  let balanceDueTouched = $state(false)
  let travel = $state<'drive' | 'fly'>('drive')
  let latitude = $state('')
  let longitude = $state('')
  let city = $state<string | null>(null)
  let region = $state<string | null>(null)
  let address = $state<string | null>(null)
  let located = $state(false)
  let recordReady = $state(false)
  let accountId = $state('')
  let total = $state('')
  let amountDue = $state('')
  let accounts = $state<Account[]>([])
  let search = $state('')
  let places = $state<{ name: string, latitude: string, longitude: string }[]>([])
  let fields = $state<Record<string, string>>({})
  let message = $state('')
  let saving = $state(false)

  let account = $derived(accounts.find((item) => String(item.id) === accountId) ?? null)
  let exponent = $derived(account ? account.exponent : 2)

  onMount(async () => {
    try {
      const accountData = await api<{ accounts: Account[] }>('/api/accounts')
      accounts = accountData.accounts
      const open = stayAccount(accounts) ?? accounts[0]
      if (!id && open) accountId = String(open.id)
      if (!id) return
      const data = await api<{ trip: Trip }>(`/api/trips/${id}?today=${todayISO()}`)
      const trip = data.trip
      placeName = trip.placeName
      note = trip.note ?? ''
      arriveOn = trip.arriveOn
      departOn = trip.departOn
      departFollows = false
      travel = trip.travel === 'fly' ? 'fly' : 'drive'
      balanceDueOn = trip.balanceDueOn ?? trip.arriveOn
      balanceDueTouched = true
      latitude = trip.latitude
      longitude = trip.longitude
      city = trip.city
      region = trip.region
      address = trip.address
      accountId = trip.accountId === null ? '' : String(trip.accountId)
      total = minorToInput(trip.totalMinor, trip.exponent)
      amountDue = minorToInput(trip.amountDueMinor, trip.exponent)
      recordReady = true
      if (!trip.address) {
        const locatedTrip = await api<{ trip: Trip }>(`/api/trips/${id}/locate`, {
          method: 'POST',
          body: { today: todayISO() },
        })
        city = locatedTrip.trip.city
        region = locatedTrip.trip.region
        address = locatedTrip.trip.address
      }
    } catch (err) {
      message = explain(err).message
    } finally {
      located = true
      recordReady = true
    }
  })

  $effect(() => {
    if (!balanceDueTouched) balanceDueOn = arriveOn
  })

  $effect(() => {
    if (!departFollows) return
    const next = dayAfter(arriveOn)
    if (departOn !== next) departOn = next
  })

  $effect(() => {
    if (!located || address || !latitude || !longitude) return
    const lat = latitude
    const lng = longitude
    const handle = window.setTimeout(() => {
      void lookup(lat, lng)
    }, 350)
    return () => window.clearTimeout(handle)
  })

  async function lookup(lat: string, lng: string): Promise<void> {
    try {
      const data = await api<{ place: { city: string, region: string | null, address: string } | null }>(
        `/api/places/reverse?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`,
      )
      if (!data.place || latitude !== lat || longitude !== lng) return
      city = data.place.city
      region = data.place.region
      address = data.place.address
    } catch {
    }
  }

  async function findPlace(): Promise<void> {
    places = []
    const data = await api<{ places: { name: string, latitude: string, longitude: string }[] }>(
      `/api/places?q=${encodeURIComponent(search)}`,
    )
    places = data.places
  }

  function usePlace(place: {
    name: string
    latitude: string
    longitude: string
    city?: string | null
    region?: string | null
    address?: string | null
  }): void {
    if (placeName.trim() === '') placeName = place.name.slice(0, 80)
    latitude = place.latitude
    longitude = place.longitude
    city = place.city ?? null
    region = place.region ?? null
    address = place.address ?? null
    places = []
  }

  function body(extra: Record<string, unknown> = {}): Record<string, unknown> {
    return {
      placeName,
      note,
      arriveOn,
      departOn,
      balanceDueOn,
      latitude,
      longitude,
      city,
      region,
      address,
      accountId: accountId === '' ? null : Number(accountId),
      totalMinor: parseMajor(total || '0', exponent),
      amountDueMinor: parseMajor(amountDue || '0', exponent),
      travel,
      today: todayISO(),
      ...extra,
    }
  }

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault()
    fields = {}
    message = ''
    const arrive = readDateInput('arriveOn')
    const depart = readDateInput('departOn')
    const dueField = document.querySelector<HTMLInputElement>('input[name="balanceDueOn"]')
    const dueRaw = dueField ? dueField.value.trim() : ''
    const due = dueRaw === '' ? '' : readDateInput('balanceDueOn')
    const problems: Record<string, string> = {}
    if (parseMajor(total || '0', exponent) === null || parseMajor(amountDue || '0', exponent) === null) {
      problems.totalMinor = 'Use an amount.'
    }
    if (!arrive) problems.arriveOn = DATE_INPUT_ERROR
    if (!depart) problems.departOn = DATE_INPUT_ERROR
    if (dueRaw !== '' && !due) problems.balanceDueOn = DATE_INPUT_ERROR
    if (!arrive || !depart || (dueRaw !== '' && !due) || Object.keys(problems).length > 0) {
      fields = problems
      return
    }
    arriveOn = arrive
    departOn = depart
    balanceDueOn = due || ''
    saving = true
    try {
      const path = id ? `/api/trips/${id}` : '/api/trips'
      const method = id ? 'PATCH' : 'POST'
      await api<{ trip: Trip }>(path, { method, body: body() })
      go('/trips')
    } catch (err) {
      const problem = explain(err)
      message = problem.message
      fields = problem.fields
    } finally {
      saving = false
    }
  }

  async function remove(): Promise<void> {
    if (!id) return
    if (!confirm('Delete this stay?')) return
    await api(`/api/trips/${id}/delete`, { method: 'POST', body: { confirm: true } })
    go('/trips')
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="m-0 text-4xl">{id ? 'Edit stay' : 'New stay'}</h1>
  <Note bind:message />
  <div class="form-body grid gap-4" aria-busy={id != null && !recordReady} inert={id != null && !recordReady}>
  <label class="field">
    <span>Place</span>
    <input name="placeName" bind:value={placeName} />
    {#if fields.placeName}<em>{fields.placeName}</em>{/if}
  </label>
  <label class="field">
    <span>Note</span>
    <input name="note" bind:value={note} placeholder="Site 34" />
    {#if fields.note}<em>{fields.note}</em>{/if}
  </label>
  <div class="flex gap-2">
    <label class="field flex-1">
      <span use:tip={fieldTips.stayArrival}>Arrival</span>
      <DateField name="arriveOn" bind:value={arriveOn} oninput={() => { if (!id) departFollows = true; }} />
      {#if fields.arriveOn}<em>{fields.arriveOn}</em>{/if}
    </label>
    <label class="field flex-1">
      <span use:tip={fieldTips.stayDeparture}>Departure</span>
      <DateField name="departOn" bind:value={departOn} oninput={() => (departFollows = false)} />
      {#if fields.departOn}<em>{fields.departOn}</em>{/if}
    </label>
  </div>
  <div class="field">
    <span use:tip={fieldTips.stayTravel}>Travel</span>
    <div class="flex gap-2" role="group" aria-label="Travel">
      <button class="choice" class:on={travel === 'drive'} type="button" onclick={() => (travel = 'drive')}>drive</button>
      <button class="choice" class:on={travel === 'fly'} type="button" onclick={() => (travel = 'fly')}>fly</button>
    </div>
    {#if fields.travel}<em>{fields.travel}</em>{/if}
  </div>
  <label class="field">
    <span use:tip={fieldTips.stayBalanceDue}>Balance due</span>
    <DateField name="balanceDueOn" bind:value={balanceDueOn} oninput={() => (balanceDueTouched = true)} />
    {#if fields.balanceDueOn}<em>{fields.balanceDueOn}</em>{/if}
  </label>
  <label class="field">
    <span>Search</span>
    <span class="flex gap-2">
      <input name="search" bind:value={search} />
      <button class="btn" type="button" onclick={findPlace}>Search</button>
    </span>
  </label>
  {#each places as place (place.latitude + place.longitude)}
    <button class="btn text-left" type="button" onclick={() => usePlace(place)}>{place.name}</button>
  {/each}
  <div class="flex gap-2">
    <label class="field flex-1">
      <span>Latitude</span>
      <input name="latitude" bind:value={latitude} />
      {#if fields.latitude}<em>{fields.latitude}</em>{/if}
    </label>
    <label class="field flex-1">
      <span>Longitude</span>
      <input name="longitude" bind:value={longitude} />
      {#if fields.longitude}<em>{fields.longitude}</em>{/if}
    </label>
  </div>
  {#if latitude && longitude}
    <Globe
      satellite
      marker={{ latitude, longitude, name: placeName, address }}
      onMarker={(lat, lng) => {
        latitude = lat;
        longitude = lng;
        city = null;
        region = null;
        address = null;
      }}
    />
  {/if}
  <label class="field">
    <span use:tip={fieldTips.stayAccount}>Account</span>
    <select name="accountId" bind:value={accountId}>
      <option value="">None</option>
      {#each accounts as item (item.id)}
        <option value={String(item.id)}>{accountLabel(item)}</option>
      {/each}
    </select>
    {#if fields.accountId}<em>{fields.accountId}</em>{/if}
  </label>
  <label class="field">
    <span use:tip={fieldTips.stayTotal}>Total</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <AmountInput
        name="total"
        {exponent}
        bind:value={total}
        oninput={(event) => {
          const input = event.currentTarget;
          if (input instanceof HTMLInputElement) amountDue = input.value;
        }}
      />
    </span>
    {#if fields.totalMinor}<em>{fields.totalMinor}</em>{/if}
  </label>
  <label class="field">
    <span use:tip={fieldTips.stayAmountDue}>Amount due</span>
    <span class="money-input">
      <span class="money-mark" aria-hidden="true">$</span>
      <AmountInput name="amountDue" {exponent} bind:value={amountDue} />
    </span>
    {#if fields.amountDueMinor}<em>{fields.amountDueMinor}</em>{/if}
  </label>
  <div class="flex flex-wrap gap-2">
    <button class="btn primary" type="submit" disabled={saving}>Save stay</button>
    <a class="btn" href="/trips" onclick={(event) => { event.preventDefault(); go('/trips'); }}>Cancel</a>
    {#if id}<button class="btn danger" type="button" onclick={remove}>Delete</button>{/if}
  </div>
  {#if id && !recordReady}
    <div class="form-cover" aria-busy="true">
      <Spinner />
    </div>
  {/if}
  </div>
</form>
