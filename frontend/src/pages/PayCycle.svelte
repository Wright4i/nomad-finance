<script lang="ts">
  import { onMount } from 'svelte';
  import DateField from '../components/DateField.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { priorMonthWeekdays, readDateInput } from '../lib/dates';
  import { exponentOf, formatDate, formatMinor, formatMonth, minorToInput, parseMajor, todayISO } from '../lib/money';
  import { go } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { HoursMode, PayCycle, PayKind } from '../lib/types';

  let { id = null }: { id?: string | null } = $props();

  const lengths = [
    { id: 'weekly', label: 'Weekly' },
    { id: 'biweekly', label: 'Biweekly' },
    { id: 'semimonthly', label: 'Semimonthly' },
    { id: 'monthly', label: 'Monthly' },
    { id: 'irregular', label: 'Irregular' },
  ] as const;

  const kinds = [
    { id: 'salary', label: 'Salary' },
    { id: 'fixed', label: 'Fixed' },
    { id: 'hourly', label: 'Hourly' },
  ] as const;

  let payor = $state('');
  let payday = $state('');
  let length = $state<(typeof lengths)[number]['id']>('monthly');
  let secondDay = $state('');
  let nextPayday = $state('');
  let payKind = $state<PayKind>('salary');
  let rate = $state('');
  let hours = $state('');
  let hoursMode = $state<HoursMode>('typed');
  let averageOn = $state(false);
  let cycle = $state<PayCycle | null>(null);
  let fields = $state<Record<string, string>>({});
  let message = $state('');
  let saving = $state(false);
  let ready = $state(false);
  let dialog: HTMLDialogElement | undefined = $state();

  function apply(next: PayCycle): void {
    cycle = next;
    payor = next.payor;
    payday = next.payday;
    length = next.length;
    secondDay = next.secondDay === null ? '' : String(next.secondDay);
    nextPayday = next.nextPayday ?? '';
    payKind = next.payKind;
    rate = minorToInput(next.rateMinor, exponentOf(session.user?.baseCurrency ?? 'USD'));
    hours = next.hours ?? '';
    hoursMode = next.hoursMode ?? 'typed';
    averageOn = next.averageOn === true;
  }

  let currency = $derived(session.user?.baseCurrency ?? 'USD');
  let exponent = $derived(exponentOf(currency));
  let paycheck = $derived.by(() => {
    const rateMinor = parseMajor(rate, exponent);
    if (rateMinor === null || rateMinor < 1) return null;
    if (payKind === 'fixed') return rateMinor;
    if (payKind === 'hourly' && length === 'monthly' && hoursMode === 'weekdays') {
      return monthlyWeekdayAmount(rateMinor);
    }
    if (payKind === 'hourly') {
      const hundredths = parseHours(hours);
      if (hundredths === null) return null;
      return Math.floor((rateMinor * hundredths + 50) / 100);
    }
    const perYear: Record<string, number> = { weekly: 52, biweekly: 26, semimonthly: 24, monthly: 12 };
    const periods = perYear[length];
    if (!periods) return null;
    return Math.floor((rateMinor + Math.floor(periods / 2)) / periods);
  });

  let monthlyWeekdayPreview = $derived.by(() => {
    if (payKind !== 'hourly' || length !== 'monthly' || hoursMode !== 'weekdays') return null;
    const perDay = parseHours(hours);
    const prior = priorMonthWeekdays(payday);
    if (perDay === null || prior === null) return null;
    const total = prior.weekdays * perDay;
    const rateMinor = parseMajor(rate, exponent);
    return {
      weekdays: prior.weekdays,
      month: prior.month,
      hours: `${Math.floor(total / 100)}.${String(total % 100).padStart(2, '0')}`,
      paycheckMinor: rateMinor === null || rateMinor < 1 ? null : Math.floor((rateMinor * total + 50) / 100),
    };
  });

  function monthlyWeekdayAmount(rateMinor: number): number | null {
    const perDay = parseHours(hours);
    const prior = priorMonthWeekdays(payday);
    if (perDay === null || prior === null) return null;
    return Math.floor((rateMinor * prior.weekdays * perDay + 50) / 100);
  }

  function parseHours(input: string): number | null {
    const trimmed = input.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;
    const [whole, frac = ''] = trimmed.split('.');
    const hundredths = Number(whole) * 100 + Number(frac.padEnd(2, '0'));
    if (hundredths < 1 || hundredths > 100000) return null;
    return hundredths;
  }

  onMount(() => {
    if (!id) {
      ready = true;
      return;
    }
    api<{ payCycle: PayCycle }>(`/api/pay-cycles/${id}?today=${todayISO()}`)
      .then((data) => {
        apply(data.payCycle);
        ready = true;
      })
      .catch((err: unknown) => {
        message = explain(err).message;
      });
  });

  async function submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    fields = {};
    message = '';
    const paydayIso = readDateInput('payday');
    const nextIso = length === 'irregular' ? readDateInput('nextPayday') : null;
    if (paydayIso) payday = paydayIso;
    if (nextIso) nextPayday = nextIso;
    saving = true;
    try {
      const semimonthlyWeekdays = payKind === 'hourly' && length === 'semimonthly' && hoursMode === 'weekdays';
      const monthlyWeekday = payKind === 'hourly' && length === 'monthly' && hoursMode === 'weekdays';
      const body = {
        payor,
        payday: paydayIso ?? '',
        length,
        secondDay: length === 'semimonthly' ? Number(secondDay) : null,
        nextPayday: length === 'irregular' ? nextIso : null,
        payKind,
        rateMinor: parseMajor(rate, exponent),
        hoursMode: semimonthlyWeekdays || monthlyWeekday ? 'weekdays' : 'typed',
        hours: payKind === 'hourly' && !semimonthlyWeekdays ? hours.trim() : null,
        averageOn,
        today: todayISO(),
      };
      const path = id ? `/api/pay-cycles/${id}` : '/api/pay-cycles';
      await api(path, { method: id ? 'PATCH' : 'POST', body });
      go('/pay-cycles');
    } catch (err) {
      const problem = explain(err);
      message = problem.message;
      fields = problem.fields;
    } finally {
      saving = false;
    }
  }

  function askArchive(): void {
    dialog?.showModal();
  }

  function closeArchive(): void {
    dialog?.close();
  }

  async function archive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      await api(`/api/pay-cycles/${id}/archive`, { method: 'POST', body: { today: todayISO() } });
      dialog?.close();
      go('/pay-cycles');
    } catch (err) {
      dialog?.close();
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }

  async function unarchive(): Promise<void> {
    if (!id) return;
    saving = true;
    message = '';
    try {
      const data = await api<{ payCycle: PayCycle }>(`/api/pay-cycles/${id}/unarchive`, {
        method: 'POST',
        body: { today: todayISO() },
      });
      apply(data.payCycle);
    } catch (err) {
      message = explain(err).message;
    } finally {
      saving = false;
    }
  }
</script>

<form class="grid gap-4 max-w-xl" onsubmit={submit}>
  <h1 class="text-3xl m-0">{id ? payor || 'Edit pay cycle' : 'New pay cycle'}</h1>
  <p class="muted m-0">One payor, one cycle. There is no close button.</p>
  {#if message}<p class="banner m-0">{message}</p>{/if}
  {#if cycle}
    <div class="grid gap-1">
      <p class="group-title text-2xl m-0" style={`--tone: var(--tone-${cycle.length})`}>
        {#if cycle.periodEnd}
          {formatDate(cycle.periodStart)} – {formatDate(cycle.periodEnd)}
        {:else}
          Open since {formatDate(cycle.periodStart)}
        {/if}
      </p>
      <p class="muted m-0">Based on payday {formatDate(cycle.payday)}.</p>
      {#if cycle.rollsOn}
        <p class="muted m-0">Rolls on {formatDate(cycle.rollsOn)}.</p>
      {:else}
        <p class="muted m-0">Stays open until you type the next payday.</p>
      {/if}
    </div>
  {/if}
  <label class="field">
    <span>Payor</span>
    <input name="payor" bind:value={payor} />
    {#if fields.payor}<em>{fields.payor}</em>{/if}
  </label>
  <label class="field">
    <span>Payday</span>
    <DateField name="payday" bind:value={payday} />
    {#if fields.payday}<em>{fields.payday}</em>{/if}
  </label>
  <div class="field">
    <span>Length</span>
    <div class="flex flex-wrap gap-2">
      {#each lengths as option}
        <button
          class="choice"
          class:on={length === option.id}
          type="button"
          style={`--tone: var(--tone-${option.id})`}
          aria-pressed={length === option.id}
          onclick={() => {
            length = option.id;
            if (hoursMode === 'weekdays' && option.id !== 'semimonthly' && option.id !== 'monthly') {
              hoursMode = 'typed';
            }
          }}
        >
          <Glyph name={option.id} mini />
          {option.label}
        </button>
      {/each}
    </div>
    {#if fields.length}<em>{fields.length}</em>{/if}
  </div>
  {#if length === 'semimonthly'}
    <label class="field">
      <span>Second day</span>
      <input name="secondDay" inputmode="numeric" bind:value={secondDay} />
      {#if fields.secondDay}<em>{fields.secondDay}</em>{/if}
    </label>
  {/if}
  {#if length === 'irregular'}
    <label class="field">
      <span>Next payday</span>
      <DateField name="nextPayday" bind:value={nextPayday} />
      {#if fields.nextPayday}<em>{fields.nextPayday}</em>{/if}
    </label>
  {/if}
  <div class="field">
    <span>Pay</span>
    <div class="flex flex-wrap gap-2">
      {#each kinds as option}
        <button
          class="choice"
          class:on={payKind === option.id}
          type="button"
          aria-pressed={payKind === option.id}
          onclick={() => (payKind = option.id)}
        >{option.label}</button>
      {/each}
    </div>
    {#if fields.payKind}<em>{fields.payKind}</em>{/if}
  </div>
  <label class="field">
    <span>
      {#if payKind === 'salary'}Annual salary{:else if payKind === 'hourly'}Hourly rate{:else}Paycheck{/if}
    </span>
    <input name="rate" inputmode="decimal" bind:value={rate} />
    {#if fields.rateMinor}<em>{fields.rateMinor}</em>{/if}
  </label>
  {#if payKind === 'hourly'}
    <div class="field">
      <span>Hours</span>
      <div class="flex flex-wrap gap-2">
        <button class="choice" class:on={hoursMode === 'typed'} type="button" aria-pressed={hoursMode === 'typed'} onclick={() => (hoursMode = 'typed')}>Typed</button>
        {#if length === 'semimonthly'}
          <button class="choice" class:on={hoursMode === 'weekdays'} type="button" aria-pressed={hoursMode === 'weekdays'} onclick={() => (hoursMode = 'weekdays')}>Weekdays</button>
        {/if}
        {#if length === 'monthly'}
          <button class="choice" class:on={hoursMode === 'weekdays'} type="button" aria-pressed={hoursMode === 'weekdays'} onclick={() => (hoursMode = 'weekdays')}>Monthly Weekday</button>
        {/if}
      </div>
      {#if fields.hoursMode}<em>{fields.hoursMode}</em>{/if}
    </div>
    {#if hoursMode === 'weekdays' && length === 'semimonthly'}
      <p class="muted m-0">Weekdays in the month before the payday, eight hours a day, split across the two paydays.</p>
    {:else}
      <label class="field">
        <span>{length === 'monthly' && hoursMode === 'weekdays' ? 'Hours per weekday' : 'Hours in the period'}</span>
        <input name="hours" inputmode="decimal" bind:value={hours} />
        {#if fields.hours}<em>{fields.hours}</em>{/if}
      </label>
    {/if}
  {/if}
  <label class="flex items-center gap-2">
    <input class="w-auto" name="averageOn" type="checkbox" bind:checked={averageOn} />
    <span>Average payroll</span>
  </label>
  {#if payKind === 'hourly' && length === 'semimonthly' && hoursMode === 'weekdays' && cycle?.weekdayPay}
    <p class="m-0">
      Weekday paycheck {formatMinor(cycle.weekdayPay.earlierMinor, currency, exponent)} on day {cycle.weekdayPay.earlierDay}
      and {formatMinor(cycle.weekdayPay.laterMinor, currency, exponent)} on day {cycle.weekdayPay.laterDay}.
    </p>
  {:else if monthlyWeekdayPreview}
    <p class="m-0">{monthlyWeekdayPreview.weekdays} weekdays in {formatMonth(monthlyWeekdayPreview.month)} · {monthlyWeekdayPreview.hours} hours.</p>
    {#if monthlyWeekdayPreview.paycheckMinor !== null}
      <p class="m-0">Paycheck this period {formatMinor(monthlyWeekdayPreview.paycheckMinor, currency, exponent)}.</p>
    {/if}
  {:else if paycheck !== null && !(payKind === 'hourly' && hoursMode === 'weekdays')}
    <p class="m-0">Paycheck this period {formatMinor(paycheck, currency, exponent)}.</p>
  {:else if payKind === 'salary' && length === 'irregular' && rate.trim() !== ''}
    <p class="muted m-0">A salary on an irregular cycle is not split into a paycheck.</p>
  {/if}
  <div class="flex flex-wrap gap-2">
    <button class="btn primary w-fit" type="submit" disabled={saving || !ready}>{saving ? 'Saving…' : 'Save pay cycle'}</button>
    <a class="btn" href="/pay-cycles" onclick={(event) => { event.preventDefault(); go('/pay-cycles'); }}>Cancel</a>
    {#if id && cycle && !cycle.archivedAt}
      <button class="btn danger" type="button" title="Hide this payor. The cycle stays in the books." disabled={saving} onclick={askArchive}>Archive</button>
    {/if}
    {#if id && cycle?.archivedAt}
      <button class="btn" type="button" title="Put this payor back on the list." disabled={saving} onclick={unarchive}>Unarchive</button>
    {/if}
  </div>
</form>

<dialog class="modal" bind:this={dialog} aria-labelledby="archive-title">
  <div class="modal-body">
    <h2 id="archive-title" class="text-xl m-0">Archive this pay cycle?</h2>
    <p class="m-0">Archiving hides this payor. The cycle stays in the books.</p>
  </div>
  <div class="modal-actions">
    <button class="btn" type="button" onclick={closeArchive}>Cancel</button>
    <button class="btn danger" type="button" disabled={saving} onclick={archive}>Archive pay cycle</button>
  </div>
</dialog>
