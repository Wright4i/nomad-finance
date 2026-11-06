<script lang="ts">
  import { tick } from 'svelte';
  import { formatDate, formatMonth, isoToInput, maskDateInput, monthCells, monthOf, parseDateInput, shiftYearMonth } from '../lib/dates';

  let {
    name,
    value = $bindable(''),
    oninput,
  }: {
    name: string;
    value?: string;
    oninput?: () => void;
  } = $props();

  const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  let inputId = $derived(`date-${name}`);
  let panelId = $derived(`date-panel-${name}`);

  let text = $state(isoToInput(value));
  let focused = false;
  let open = $state(false);
  let viewYear = $state(new Date().getFullYear());
  let viewMonth = $state(new Date().getMonth() + 1);
  let root: HTMLDivElement | undefined = $state();
  let opener: HTMLButtonElement | undefined = $state();
  let caption = $state('');

  let cells = $derived(monthCells(viewYear, viewMonth));
  let heading = $derived(formatMonth(`${viewYear}-${String(viewMonth).padStart(2, '0')}`));
  let todayIso = $derived(localToday());

  $effect(() => {
    const iso = value;
    if (focused) return;
    const formatted = isoToInput(iso);
    if (formatted !== '') {
      text = formatted;
      return;
    }
    if (text !== '' && parseDateInput(text) === null) return;
    text = '';
  });

  $effect(() => {
    const field = root;
    if (!field) return;
    const span = field.closest('label')?.querySelector(':scope > span');
    caption = span instanceof HTMLElement ? (span.textContent?.replace(/\s+/g, ' ').trim() ?? '') : '';
  });

  $effect(() => {
    if (!open) return;

    const onPointer = (event: PointerEvent): void => {
      const target = event.target;
      if (target instanceof Node && root?.contains(target)) return;
      open = false;
    };

    const shut = (event: Event): void => {
      if (event.type === 'keydown' && (!(event instanceof KeyboardEvent) || event.key !== 'Escape')) return;
      event.preventDefault();
      event.stopPropagation();
      open = false;
      opener?.focus({ preventScroll: true });
    };

    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', shut, true);
    document.addEventListener('cancel', shut, true);
    return () => {
      document.removeEventListener('pointerdown', onPointer);
      document.removeEventListener('keydown', shut, true);
      document.removeEventListener('cancel', shut, true);
    };
  });

  function handle(event: Event): void {
    const raw = event.target instanceof HTMLInputElement ? event.target.value : '';
    text = maskDateInput(raw);
    value = parseDateInput(text) ?? '';
    oninput?.();
  }

  function localToday(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${now.getFullYear()}-${month}-${day}`;
  }

  async function show(): Promise<void> {
    const shown = monthOf(value) ?? monthOf(localToday());
    if (shown) {
      viewYear = shown.year;
      viewMonth = shown.month;
    }
    open = true;
    await tick();
    const panel = root?.querySelector<HTMLElement>('.date-picker');
    if (!panel) return;
    const selected = panel.querySelector<HTMLButtonElement>('button[aria-pressed="true"]');
    const today = panel.querySelector<HTMLButtonElement>('button.today');
    const first = panel.querySelector<HTMLButtonElement>('button[data-date]');
    (selected ?? today ?? first)?.focus({ preventScroll: true });
    panel.scrollIntoView({ block: 'nearest', behavior: 'auto' });
  }

  function toggle(): void {
    if (open) {
      open = false;
      return;
    }
    void show();
  }

  function pick(iso: string): void {
    text = isoToInput(iso);
    value = iso;
    open = false;
    oninput?.();
  }

  function move(by: number): void {
    const next = shiftYearMonth(viewYear, viewMonth, by);
    viewYear = next.year;
    viewMonth = next.month;
  }

  function quiet(event: MouseEvent): void {
    event.preventDefault();
  }

  function inside(event: Event): void {
    event.stopPropagation();
  }
</script>

<div class="date-field" bind:this={root}>
  <input
    id={inputId}
    {name}
    type="text"
    inputmode="numeric"
    autocomplete="off"
    spellcheck="false"
    placeholder="MM / DD / YYYY"
    aria-label={caption === '' ? undefined : caption}
    value={text}
    onfocus={() => (focused = true)}
    onblur={() => (focused = false)}
    oninput={handle}
  />
  <button
    class="icon-btn date-picker-open"
    type="button"
    aria-label="Choose date"
    aria-controls={inputId}
    aria-expanded={open}
    aria-haspopup="dialog"
    bind:this={opener}
    onmousedown={quiet}
    onpointerdown={inside}
    onclick={(event) => { inside(event); toggle(); }}
  >
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M7 4.5v2.5M17 4.5v2.5M5.5 9.5h13M6.5 6.5h11a1.5 1.5 0 0 1 1.5 1.5v10a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 18V8a1.5 1.5 0 0 1 1.5-1.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  </button>
  {#if open}
    <div id={panelId} class="date-picker" role="dialog" aria-label="Choose date" aria-modal="false" tabindex="-1" onpointerdown={inside}>
      <div class="date-picker-nav">
        <button class="btn" type="button" onmousedown={quiet} onpointerdown={inside} onclick={(event) => { inside(event); move(-1); }}>Previous month</button>
        <p class="m-0">{heading}</p>
        <button class="btn" type="button" onmousedown={quiet} onpointerdown={inside} onclick={(event) => { inside(event); move(1); }}>Next month</button>
      </div>
      <div class="date-picker-week" aria-hidden="true">
        {#each weekdays as weekday (weekday)}
          <span>{weekday}</span>
        {/each}
      </div>
      <div class="date-picker-days">
        {#each cells as cell, index (`${viewYear}-${viewMonth}-${index}`)}
          {#if cell}
            <button
              class="date-picker-day"
              class:today={cell === todayIso}
              type="button"
              data-date={cell}
              aria-label={formatDate(cell)}
              aria-pressed={cell === value}
              onmousedown={quiet}
              onpointerdown={inside}
              onclick={(event) => { inside(event); pick(cell); }}
            >{Number(cell.slice(8))}</button>
          {:else}
            <span aria-hidden="true"></span>
          {/if}
        {/each}
      </div>
    </div>
  {/if}
</div>
