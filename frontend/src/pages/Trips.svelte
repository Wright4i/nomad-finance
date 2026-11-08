<script lang="ts">
  import Note from '../components/Note.svelte';
  import Spinner from '../components/Spinner.svelte';
  import { onDestroy, onMount } from 'svelte';
  import Globe from '../components/Globe.svelte';
  import Glyph from '../components/Glyph.svelte';
  import { api, explain } from '../lib/api';
  import { paintLeg } from '../lib/legPaint';
  import { monthCells } from '../lib/dates';
  import { exponentOf, formatChipDate, formatDate, formatMinor, formatMonth, todayISO } from '../lib/money';
  import { PAST_MONTHS_DEFAULT, pastMonthChoices, stayDrawn } from '../lib/pastMonths';
  import { follow, go } from '../lib/nav.svelte.ts';
  import { session } from '../lib/session.svelte.ts';
  import type { DriveLeg, Trip } from '../lib/types';

  let trips = $state<Trip[]>([]);
  let legs = $state<DriveLeg[]>([]);
  let pastMonths = $state(PAST_MONTHS_DEFAULT);
  let index = $state(0);
  let message = $state('');
  let loading = $state(true);
  let month = $state(todayISO().slice(0, 7));
  let days = $derived.by(() => {
    const [year, mon] = month.split('-').map(Number);
    return monthCells(year, mon);
  });
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
  let drawnTrips = $derived(trips.filter((trip) => stayDrawn(trip.departOn, todayISO(), pastMonths)));
  let drawnIds = $derived(new Set(drawnTrips.map((trip) => trip.id)));
  let drawnLegs = $derived(
    legs
      .filter((leg) => drawnIds.has(leg.fromId) && drawnIds.has(leg.toId))
      .map((leg) => {
        const fromTrip = trips.find((trip) => trip.id === leg.fromId);
        const toTrip = trips.find((trip) => trip.id === leg.toId);
        const from = fromTrip ? ([Number(fromTrip.longitude), Number(fromTrip.latitude)] as [number, number]) : null;
        const to = toTrip ? ([Number(toTrip.longitude), Number(toTrip.latitude)] as [number, number]) : null;
        return paintLeg(leg, from, to);
      }),
  );

  function stayColor(trip: Trip, tripIndex: number): string {
    if ((stayColors as readonly string[]).includes(trip.color)) return trip.color;
    return stayColors[((tripIndex % stayColors.length) + stayColors.length) % stayColors.length];
  }

  /**
   * Same split as StayDayFill.
   * Arrival is the upper-right half. Departure is the lower-left half.
   * A day strictly inside one stay fills both.
   * A stay that arrives while another is still there takes the upper-right half.
   */
  function dayFill(date: string): { arrive: string | null; depart: string | null } {
    const covering: { arriveOn: string; departOn: string; color: string }[] = [];
    trips.forEach((trip, tripIndex) => {
      if (date < trip.arriveOn || date > trip.departOn) return;
      covering.push({
        arriveOn: trip.arriveOn,
        departOn: trip.departOn,
        color: stayColor(trip, tripIndex),
      });
    });
    let ongoing: string | null = null;
    for (const stay of covering) {
      if (date !== stay.arriveOn && date !== stay.departOn) {
        ongoing = stay.color;
        break;
      }
    }
    let arrive: string | null = null;
    let depart: string | null = null;
    for (const stay of covering) {
      if (date === stay.arriveOn && arrive === null) arrive = stay.color;
      const sameDay = date === stay.arriveOn && date === stay.departOn;
      if (date === stay.departOn && depart === null && !(sameDay && ongoing !== null)) {
        depart = stay.color;
      }
    }
    if (arrive !== null && depart === null && ongoing !== null) depart = ongoing;
    if (depart !== null && arrive === null && ongoing !== null) arrive = ongoing;
    if (arrive === null && depart === null && ongoing !== null) {
      arrive = ongoing;
      depart = ongoing;
    }
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
    const covering = rows.filter((trip) => trip.arriveOn <= today && trip.departOn >= today);
    const arriving = covering.find((trip) => trip.arriveOn === today);
    const open = arriving ?? covering[0];
    if (open) return rows.indexOf(open);
    const upcoming = rows
      .filter((trip) => trip.departOn >= today)
      .sort((left, right) => left.arriveOn.localeCompare(right.arriveOn))[0];
    if (!upcoming) return 0;
    return rows.indexOf(upcoming);
  }

  async function load(): Promise<void> {
    const tripData = await api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}`);
    trips = tripData.trips;
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
    load()
      .catch((err: unknown) => {
        message = explain(err).message;
      })
      .finally(() => {
        loading = false;
      });
  });

  function step(direction: number): void {
    if (trips.length === 0) return;
    const next = index + direction;
    if (next < 0 || next >= trips.length) return;
    index = next;
    stepToken += 1;
    const arrival = trips[index]?.arriveOn.slice(0, 7);
    if (arrival && arrival !== month) month = arrival;
  }

  function shiftMonth(by: number): void {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(year, monthNumber - 1 + by, 1);
    month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function choose(id: number): void {
    const next = trips.findIndex((trip) => trip.id === id);
    if (next >= 0) index = next;
  }

  function editStay(id: number): void {
    go(`/trips/${id}`);
  }

  function locate(id: number): void {
    choose(id);
    locateToken += 1;
  }

  async function moveStay(id: number, direction: 'earlier' | 'later'): Promise<void> {
    if (moving) return;
    moving = true;
    const selectedId = trips[index]?.id;
    try {
      await api(`/api/trips/${id}/route`, { method: 'POST', body: { direction } });
      const tripData = await api<{ trips: Trip[] }>(`/api/trips?today=${todayISO()}`);
      trips = tripData.trips;
      if (selectedId != null) {
        const kept = trips.findIndex((trip) => trip.id === selectedId);
        if (kept >= 0) index = kept;
      }
      await loadRoute();
    } catch (err: unknown) {
      message = explain(err).message;
    } finally {
      moving = false;
    }
  }

  function overlaps(trip: Trip): boolean {
    return trip.arriveOn.slice(0, 7) <= month && trip.departOn.slice(0, 7) >= month;
  }

  let locateToken = $state(0);
  let stepToken = $state(0);
  let moving = $state(false);
  let monthStays = $derived(
    trips
      .filter(overlaps)
      .slice()
      .sort((left, right) => left.routeOrder - right.routeOrder || left.id - right.id),
  );
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
      travel: trip.travel,
      ratingHalfStars: halfStars,
      ratingComment: comment,
      today: todayISO(),
    };
  }

  function persistRating(trip: Trip, halfStars: number, comment: string): Promise<void> {
    const epoch = ++ratingEpoch;
    const job = ratingQueue.then(async () => {
      if (epoch !== ratingEpoch) return;
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
      <button class="stay-card-hit" type="button" aria-label="Edit stay" onclick={() => editStay(selected.id)}></button>
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
      {#if selectedLeg && selectedLeg.travel !== 'fly' && !selectedLeg.driving}
        <p class="muted m-0">The road route is unavailable.</p>
      {/if}
    </article>
  {:else if !loading}
    <p class="m-0">No stays yet</p>
  {/if}

  <Note bind:message />
  {#if loading}
    <Spinner />
  {:else}

  <div class="stay-toolbar">
    <div class="stay-toolbar-nav">
      <button class="btn" type="button" onclick={() => step(-1)} disabled={index <= 0}>Previous</button>
      <button class="btn" type="button" onclick={() => step(1)} disabled={index >= trips.length - 1}>Next</button>
      {#if selected}
        {@const rating = shownRating(selected)}
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

  <div class="globe-stage">
    <label class="globe-past">
      <span>Past months</span>
      <select
        name="pastMonths"
        aria-label="Past months"
        value={pastMonths}
        onchange={(event) => {
          pastMonths = Number(event.currentTarget.value);
        }}
      >
        {#each pastMonthChoices as months (months)}
          <option value={months}>{months}</option>
        {/each}
      </select>
    </label>
    <Globe
      pins={drawnTrips.map((trip) => {
        const tripIndex = trips.findIndex((item) => item.id === trip.id);
        return {
          id: trip.id,
          latitude: trip.latitude,
          longitude: trip.longitude,
          placeName: trip.placeName,
          stayNumber: tripIndex + 1,
          city: trip.city,
          region: trip.region,
        };
      })}
      legs={drawnLegs}
      selectedId={selected?.id ?? null}
      locateToken={locateToken}
      stepToken={stepToken}
      onSelect={choose}
    />
  </div>

  {#if selected}
    <section class="grid gap-2">
      <h2 class="m-0 text-2xl">Lodging</h2>
      <p class="m-0">
        Total {formatMinor(selected.totalMinor, selected.currency ?? baseCurrency, selected.exponent)}
        · Deposit {formatMinor(selected.depositMinor, selected.currency ?? baseCurrency, selected.exponent)}
        · Due {formatMinor(selected.amountDueMinor, selected.currency ?? baseCurrency, selected.exponent)}
      </p>
    </section>
  {/if}

  <section class="grid gap-2">
    <div class="flex items-center justify-between gap-3">
      <button class="btn" type="button" onclick={() => shiftMonth(-1)}>Previous month</button>
      <h2 class="m-0 text-2xl" data-month={month}>{formatMonth(month)}</h2>
      <button class="btn" type="button" onclick={() => shiftMonth(1)}>Next month</button>
    </div>
    <div class="grid grid-cols-7 gap-1 text-center text-sm">
      {#each ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as name (name)}
        <p class="kicker m-0">{name}</p>
      {/each}
      {#each days as date, cell (date ?? `pad-${cell}`)}
        {#if date}
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
        >{Number(date.slice(8))}</button>
        {:else}
          <span></span>
        {/if}
      {/each}
    </div>
    {#each monthStays as trip (trip.id)}
      {@const tripIndex = trips.findIndex((item) => item.id === trip.id)}
      <div
        class="stay-list stay-fill"
        data-stay-color={stayColor(trip, tripIndex)}
        style="--stay: var(--{stayColor(trip, tripIndex)})"
      >
        <div class="stay-list-copy">
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
          {:else if legs.find((leg) => leg.toId === trip.id && leg.driving)}
            <span class="muted"> · {legs.find((leg) => leg.toId === trip.id)?.miles} miles from previous stay</span>
          {/if}
        </div>
        <div class="stay-list-actions">
          {#if trip.moveEarlier}
            <button class="icon-btn" type="button" aria-label="Earlier on the route" disabled={moving} onclick={() => moveStay(trip.id, 'earlier')}>
              <Glyph name="up" mini />
            </button>
          {/if}
          {#if trip.moveLater}
            <button class="icon-btn" type="button" aria-label="Later on the route" disabled={moving} onclick={() => moveStay(trip.id, 'later')}>
              <Glyph name="down" mini />
            </button>
          {/if}
          <button class="icon-btn" type="button" aria-label="Locate stay" onclick={() => locate(trip.id)}>
            <Glyph name="crosshair" mini />
          </button>
          <button class="icon-btn" type="button" aria-label="Edit stay" onclick={() => editStay(trip.id)}>
            <Glyph name="pencil" mini />
          </button>
        </div>
      </div>
    {/each}
  </section>
  {/if}
</section>
