<script lang="ts">
  import { onDestroy, onMount } from 'svelte';
  import Globe from '../components/Globe.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { exponentOf, formatChipDate, formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { follow } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { Category, ChargeQuote, DriveLeg, Trip } from '../lib/types';

  let trips = $state<Trip[]>([]);
  let legs = $state<DriveLeg[]>([]);
  let categories = $state<Category[]>([]);
  let index = $state(0);
  let message = $state('');
  let month = $state(todayISO().slice(0, 7));
  let quote = $state<ChargeQuote | null>(null);
  let part = $state<'deposit' | 'due'>('deposit');
  let categoryId = $state('');
  let dialog: HTMLDialogElement | undefined = $state();
  let baseCurrency = $derived(session.user?.baseCurrency ?? 'USD');
  let baseExponent = $derived(exponentOf(baseCurrency));

  /** Same order as StayTone::SEQUENCE. */
  const stayColors = [
    'poppy',
    'lagoon',
    'berry',
    'citrus',
    'violet',
    'apricot',
    'tide',
    'blossom',
    'fern',
    'lilac',
    'lemon',
    'sea',
  ] as const;

  let selected = $derived(trips[index] ?? null);
  let selectedLeg = $derived(selected ? legs.find((item) => item.toId === selected.id) : undefined);

  function stayColor(trip: Trip, tripIndex: number): string {
    if ((stayColors as readonly string[]).includes(trip.color)) return trip.color;
    return stayColors[((tripIndex % stayColors.length) + stayColors.length) % stayColors.length];
  }

  /**
   * Same split as StayDayFill.
   * Arrival is the upper-right half. Departure is the lower-left half.
   * A day strictly inside the stay fills both. An earlier stay keeps a half.
   */
  function dayFill(date: string): { arrive: string | null; depart: string | null } {
    let arrive: string | null = null;
    let depart: string | null = null;
    trips.forEach((trip, tripIndex) => {
      if (date < trip.arriveOn || date > trip.departOn) return;
      const color = stayColor(trip, tripIndex);
      if (date === trip.arriveOn && arrive === null) arrive = color;
      if (date === trip.departOn && depart === null) depart = color;
      if (date !== trip.arriveOn && date !== trip.departOn) {
        arrive = color;
        depart = color;
      }
    });
    return { arrive, depart };
  }

  /**
   * Same choice as StayDayChoice.
   * A handoff opens the stay that is arriving.
   * One stay on the day opens that stay.
   * A day inside a stay opens the stay that covers it.
   */
  function stayOn(date: string): Trip | undefined {
    const covering = trips.filter((trip) => trip.arriveOn <= date && trip.departOn >= date);
    return covering.find((trip) => trip.arriveOn === date) ?? covering[0];
  }

  function pickInitial(rows: Trip[]): number {
    const today = todayISO();
    const open = rows.findIndex((trip) => trip.arriveOn <= today && trip.departOn >= today);
    if (open >= 0) return open;
    const upcoming = rows.findIndex((trip) => trip.arriveOn > today);
    return upcoming >= 0 ? upcoming : Math.max(0, rows.length - 1);
  }

  async function load(): Promise<void> {
    const [tripData, categoryData] = await Promise.all([
      api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}`),
      api<{ categories: Category[] }>('/api/categories'),
    ]);
    trips = tripData.trips;
    categories = categoryData.categories;
    if (categoryId === '' && categories[0]) categoryId = String(categories[0].id);
    index = pickInitial(trips);
    await Promise.all([fillPlaces(), loadRoute()]);
  }

  async function fillPlaces(): Promise<void> {
    for (const trip of trips) {
      if (trip.city) continue;
      try {
        const data = await api<{ trip: Trip }>(`/api/trips/${trip.id}/locate`, {
          method: 'POST',
          body: { today: todayISO() },
        });
        trips = trips.map((item) =>
          item.id === data.trip.id ? { ...data.trip, color: data.trip.color || item.color } : item,
        );
      } catch {
        // The pin still shows the stay number when the city lookup is unavailable.
      }
    }
  }

  async function loadRoute(): Promise<void> {
    try {
      const data = await api<{ legs: DriveLeg[] }>(`/api/trips/route?today=${todayISO()}`);
      legs = data.legs;
    } catch {
      legs = [];
    }
  }

  onMount(() => {
    load().catch((err: unknown) => {
      message = explain(err).message;
    });
  });

  function step(direction: number): void {
    if (trips.length === 0) return;
    index = (index + direction + trips.length) % trips.length;
    const arrival = trips[index]?.arriveOn.slice(0, 7);
    if (arrival && arrival !== month) month = arrival;
  }

  function choose(id: number): void {
    const next = trips.findIndex((trip) => trip.id === id);
    if (next >= 0) index = next;
  }

  function shiftMonth(by: number): void {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(year, monthNumber - 1 + by, 1);
    month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function daysInMonth(value: string): number {
    const [year, monthNumber] = value.split('-').map(Number);
    return new Date(year, monthNumber, 0).getDate();
  }

  function overlaps(trip: Trip): boolean {
    return trip.arriveOn.slice(0, 7) <= month && trip.departOn.slice(0, 7) >= month;
  }

  async function ask(next: 'deposit' | 'due'): Promise<void> {
    if (!selected) return;
    part = next;
    try {
      const data = await api<{ quote: ChargeQuote }>(`/api/trips/${selected.id}/quote`, {
        method: 'POST',
        body: { part: next },
      });
      quote = data.quote;
      dialog?.showModal();
    } catch (err) {
      message = explain(err).message;
    }
  }

  async function apply(): Promise<void> {
    if (!selected) return;
    try {
      await api(`/api/trips/${selected.id}/apply`, {
        method: 'POST',
        body: { part, categoryId: Number(categoryId), today: todayISO() },
      });
      dialog?.close();
      quote = null;
      await load();
    } catch (err) {
      message = explain(err).message;
    }
  }

  function skip(): void {
    dialog?.close();
    quote = null;
  }

  let commentDraft = $state('');
  let commentFor = $state<number | null>(null);
  let savedComment = $state('');
  let pickedFor = $state<number | null>(null);
  let pickedRating = $state<number | null>(null);
  let ratingQueue = Promise.resolve();
  let ratingEpoch = 0;
  let commentTimer: ReturnType<typeof setTimeout> | undefined;

  const commentSaveMs = 1000;

  const starSlots = [1, 2, 3, 4, 5] as const;
  const starPath = 'M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z';

  $effect(() => {
    if (!selected) return;
    if (commentFor === selected.id) return;
    clearCommentTimer();
    commentFor = selected.id;
    commentDraft = selected.ratingComment ?? '';
    savedComment = selected.ratingComment ?? '';
    pickedFor = null;
    pickedRating = null;
  });

  function clearCommentTimer(): void {
    if (commentTimer !== undefined) {
      clearTimeout(commentTimer);
      commentTimer = undefined;
    }
  }

  onDestroy(clearCommentTimer);

  function shownRating(trip: Trip): number | null {
    const value = pickedFor === trip.id ? pickedRating : trip.ratingHalfStars;
    if (value === null || value < 1) return null;
    return value;
  }

  function starFill(rating: number | null, star: number): number {
    if (rating === null) return 0;
    if (rating >= star * 2) return 1;
    if (rating === star * 2 - 1) return 0.5;
    return 0;
  }

  function ratingText(halfStars: number): string {
    return (halfStars / 2).toFixed(1);
  }

  function starLabel(halfStars: number): string {
    return `${ratingText(halfStars)} stars`;
  }

  function tripBody(trip: Trip, halfStars: number, comment: string): Record<string, unknown> {
    return {
      placeName: trip.placeName,
      note: trip.note,
      arriveOn: trip.arriveOn,
      departOn: trip.departOn,
      balanceDueOn: trip.balanceDueOn,
      latitude: trip.latitude,
      longitude: trip.longitude,
      accountId: trip.accountId,
      totalMinor: trip.totalMinor,
      amountDueMinor: trip.amountDueMinor,
      ratingHalfStars: halfStars,
      ratingComment: comment,
      today: todayISO(),
    };
  }

  function persistRating(trip: Trip, halfStars: number, comment: string): Promise<void> {
    const epoch = ++ratingEpoch;
    const job = ratingQueue.then(async () => {
      const data = await api<{ trip: Trip }>(`/api/trips/${trip.id}`, {
        method: 'PATCH',
        body: tripBody(trip, halfStars, comment),
      });
      if (epoch !== ratingEpoch) return;
      trips = trips.map((item) => (item.id === data.trip.id ? data.trip : item));
      if (commentFor !== trip.id) return;
      savedComment = data.trip.ratingComment ?? '';
      if (pickedFor === trip.id && pickedRating === halfStars) {
        pickedFor = null;
        pickedRating = null;
      }
    });
    ratingQueue = job.then(
      () => undefined,
      () => undefined,
    );
    return job;
  }

  function rate(halfStars: number): void {
    if (!selected) return;
    const trip = selected;
    pickedFor = trip.id;
    pickedRating = halfStars;
    const comment = trip.ratingHalfStars !== null && trip.ratingHalfStars > 0 ? commentDraft : '';
    persistRating(trip, halfStars, comment).catch((err: unknown) => {
      if (pickedFor === trip.id && pickedRating === halfStars) {
        pickedFor = null;
        pickedRating = null;
      }
      message = explain(err).message;
    });
  }

  function scheduleCommentSave(): void {
    clearCommentTimer();
    const trip = selected;
    if (!trip || shownRating(trip) === null) return;
    const tripId = trip.id;
    commentTimer = setTimeout(() => {
      commentTimer = undefined;
      const current = trips.find((item) => item.id === tripId);
      const rating = current ? shownRating(current) : null;
      if (!current || rating === null) return;
      persistRating(current, rating, commentDraft).catch((err: unknown) => {
        message = explain(err).message;
      });
    }, commentSaveMs);
  }
</script>

<section class="grid gap-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="m-0 text-4xl">Trips</h1>
    <a class="btn primary" href="/trips/new" onclick={(event) => follow(event, '/trips/new')}>Add a stay</a>
  </div>
  {#if selected}
    <article
      class="card stay-fill stay-card"
      data-stay-color={stayColor(selected, index)}
      style="--stay: var(--{stayColor(selected, index)})"
    >
      <p class="m-0 text-2xl">{selected.placeName}</p>
      {#if selected.note}<p class="m-0 text-xl">{selected.note}</p>{/if}
      <p class="muted m-0">{formatDate(selected.arriveOn)} – {formatDate(selected.departOn)} · {selected.status}</p>
      {#if selected.balanceDueOn || selected.fuel?.milesPerGallon || selected.fuel?.miles != null || (selected.fuel && (selected.fuel.costMinor !== null || selected.fuel.priceMinor != null))}
        <div class="stay-amount-chips">
          {#if selected.balanceDueOn}
            <span class="stay-amount-chip balance-due-chip">
              <Glyph name="balance" mini />
              <span>{formatMinor(selected.amountDueMinor, selected.currency ?? baseCurrency, selected.exponent)} Due {formatChipDate(selected.balanceDueOn)}</span>
            </span>
          {/if}
          {#if selected.fuel && selected.fuel.costMinor !== null}
            <span class="stay-amount-chip fuel-amount-chip">
              <Glyph name="fuel" mini />
              <span>Fuel {formatMinor(selected.fuel.costMinor, baseCurrency, baseExponent)}</span>
            </span>
          {/if}
          {#if selected.fuel?.milesPerGallon}
            <span class="stay-amount-chip mpg-chip">
              <Glyph name="vehicle" mini />
              <span>{selected.fuel.milesPerGallon} mpg</span>
            </span>
          {/if}
          {#if selected.fuel && selected.fuel.priceMinor != null}
            <span class="stay-amount-chip fuel-price-chip">
              <Glyph name="cash" mini />
              <span>{formatMinor(selected.fuel.priceMinor, baseCurrency, baseExponent)}/gal</span>
            </span>
          {/if}
          {#if selected.fuel?.miles != null}
            <span class="stay-amount-chip miles-chip">
              <Glyph name="travel" mini />
              <span>{selected.fuel.miles} miles</span>
            </span>
          {/if}
        </div>
      {/if}
      {#if selectedLeg && !selectedLeg.driving}
        <p class="muted m-0">The road route is unavailable.</p>
      {/if}
    </article>
  {:else}
    <p class="m-0">No stays yet</p>
  {/if}

  {#if message}<p class="banner m-0" aria-live="polite">{message}</p>{/if}

  <div class="stay-toolbar">
    <div class="stay-toolbar-nav">
      <button class="btn" type="button" onclick={() => step(-1)} disabled={trips.length < 2}>Previous</button>
      <button class="btn" type="button" onclick={() => step(1)} disabled={trips.length < 2}>Next</button>
      {#if selected}
        {@const rating = shownRating(selected)}
        <a class="btn" href={`/trips/${selected.id}`} onclick={(event) => follow(event, `/trips/${selected.id}`)}>Edit</a>
        <div class="stay-rating" role="group" aria-label="Rating">
          {#if rating !== null}
            <span class="stay-rating-value" data-rating={ratingText(rating)}>
              <svg class="stay-rating-mark" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d={starPath} />
              </svg>
              {ratingText(rating)}
            </span>
          {/if}
          {#each starSlots as star (star)}
            {@const fill = starFill(rating, star)}
            <span class="stay-star" data-star={star} data-fill={String(fill)}>
              <svg class="stay-star-glyph" viewBox="0 0 24 24" aria-hidden="true">
                <path class="stay-star-edge" d={starPath} />
                <path class="stay-star-line" d={starPath} />
              </svg>
              <svg class="stay-star-preview" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d={starPath} />
              </svg>
              <svg class="stay-star-fill" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d={starPath} />
              </svg>
              <button
                class="stay-star-hit left"
                type="button"
                aria-label={starLabel(star * 2 - 1)}
                aria-pressed={rating === star * 2 - 1}
                onclick={() => rate(star * 2 - 1)}
              ></button>
              <button
                class="stay-star-hit right"
                type="button"
                aria-label={starLabel(star * 2)}
                aria-pressed={rating === star * 2}
                onclick={() => rate(star * 2)}
              ></button>
            </span>
          {/each}
        </div>
      {/if}
    </div>
    {#if selected && shownRating(selected) !== null}
      <div class="stay-comment-line">
        <label class="field stay-comment">
          <span>Comment</span>
          <input
            name="ratingComment"
            autocomplete="off"
            maxlength="2000"
            data-saved={savedComment}
            value={commentDraft}
            oninput={(event) => {
              commentDraft = event.currentTarget.value;
              scheduleCommentSave();
            }}
          />
        </label>
      </div>
    {/if}
  </div>

  <Globe
    pins={trips.map((trip, tripIndex) => ({
      id: trip.id,
      latitude: trip.latitude,
      longitude: trip.longitude,
      placeName: trip.placeName,
      stayNumber: tripIndex + 1,
      city: trip.city,
      region: trip.region,
    }))}
    legs={legs.map((leg) => ({ miles: leg.miles, coordinates: leg.coordinates }))}
    selectedId={selected?.id ?? null}
    onSelect={choose}
  />

  {#if selected && selected.currency}
    <section class="grid gap-2">
      <h2 class="m-0 text-2xl">Lodging</h2>
      <p class="m-0">
        Total {formatMinor(selected.totalMinor, selected.currency, selected.exponent)}
        · Deposit {formatMinor(selected.depositMinor, selected.currency, selected.exponent)}
        {selected.depositCharged ? 'charged' : 'planned'}
        · Due {formatMinor(selected.amountDueMinor, selected.currency, selected.exponent)}
        {selected.dueCharged ? 'charged' : 'planned'}
      </p>
      <p class="muted m-0">Other money on this stay {formatMinor(selected.otherSpentMinor, selected.currency, selected.exponent)}</p>
      <div class="flex flex-wrap gap-2">
        {#if selected.depositMinor > 0 && !selected.depositCharged}
          <button class="btn primary" type="button" onclick={() => ask('deposit')}>Charge deposit</button>
        {/if}
        {#if selected.amountDueMinor > 0 && !selected.dueCharged}
          <button class="btn primary" type="button" onclick={() => ask('due')}>Charge amount due</button>
        {/if}
      </div>
    </section>
  {/if}

  <section class="grid gap-2">
    <div class="flex items-center justify-between gap-3">
      <button class="btn" type="button" onclick={() => shiftMonth(-1)}>Previous month</button>
      <h2 class="m-0 text-2xl" data-month={month}>{formatMonth(month)}</h2>
      <button class="btn" type="button" onclick={() => shiftMonth(1)}>Next month</button>
    </div>
    <div class="grid grid-cols-7 gap-1 text-center text-sm">
      {#each Array.from({ length: daysInMonth(month) }, (_, day) => day + 1) as day (day)}
        {@const date = `${month}-${String(day).padStart(2, '0')}`}
        {@const fill = dayFill(date)}
        {@const painted = fill.arrive !== null || fill.depart !== null}
        <button
          class="btn"
          class:stay-day={painted}
          class:stay-covered={fill.arrive !== null && fill.depart !== null}
          class:stay-half={(fill.arrive === null) !== (fill.depart === null)}
          type="button"
          data-date={date}
          data-stay-arrive={fill.arrive ?? ''}
          data-stay-depart={fill.depart ?? ''}
          style:--stay-in-color={fill.arrive ? `var(--${fill.arrive})` : 'transparent'}
          style:--stay-out-color={fill.depart ? `var(--${fill.depart})` : 'transparent'}
          style:--stay-edge={`var(--${fill.arrive ?? fill.depart ?? 'line'})`}
          onclick={() => {
            const trip = stayOn(date);
            if (trip) choose(trip.id);
          }}
        >{day}</button>
      {/each}
    </div>
    {#each trips.filter(overlaps) as trip (trip.id)}
      {@const tripIndex = trips.findIndex((item) => item.id === trip.id)}
      <button
        class="btn stay-fill w-full justify-start text-left"
        type="button"
        data-stay-color={stayColor(trip, tripIndex)}
        style="--stay: var(--{stayColor(trip, tripIndex)})"
        onclick={() => choose(trip.id)}
      >
        <strong>{trip.placeName}</strong>
        {#if trip.note}<span> · {trip.note}</span>{/if}
        <span class="muted"> · {formatDate(trip.arriveOn)} – {formatDate(trip.departOn)}</span>
        {#if trip.balanceDueOn}<span class="muted"> · balance due {formatDate(trip.balanceDueOn)}</span>{/if}
        {#if trip.fuel?.miles !== null && trip.fuel}
          <span class="muted">
            · Fuel · {trip.fuel.miles} miles from previous stay
            {#if trip.fuel.milesPerGallon}· {trip.fuel.milesPerGallon} mpg{/if}
            {#if trip.fuel.costMinor !== null}· {formatMinor(trip.fuel.costMinor, baseCurrency, baseExponent)}{/if}
          </span>
        {:else if legs.find((leg) => leg.toId === trip.id)}
          <span class="muted"> · {legs.find((leg) => leg.toId === trip.id)?.miles} miles from previous stay</span>
        {/if}
      </button>
    {/each}
  </section>
</section>

<dialog class="modal" bind:this={dialog}>
  {#if quote}
    <h2 class="m-0 text-2xl">Charge {part === 'deposit' ? 'the deposit' : 'the amount due'}?</h2>
    <p>{quote.accountName}</p>
    <p>{formatMinor(quote.oldBalanceMinor, quote.currency, quote.exponent)} → {formatMinor(quote.newBalanceMinor, quote.currency, quote.exponent)}</p>
    {#if quote.overLimit}<p>This would put the card over its limit.</p>{/if}
    {#if quote.refused}<p>{quote.refused}</p>{/if}
    <label class="field">
      <span>Bucket</span>
      <select name="categoryId" bind:value={categoryId}>
        {#each categories as category (category.id)}
          <option value={String(category.id)}>{category.name}</option>
        {/each}
      </select>
    </label>
    <div class="flex flex-wrap gap-2">
      {#if !quote.refused}
        <button class="btn primary" type="button" onclick={apply}>Apply</button>
      {/if}
      <button class="btn" type="button" onclick={skip}>Skip</button>
    </div>
  {/if}
</dialog>
