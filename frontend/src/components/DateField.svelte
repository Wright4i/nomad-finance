<script lang="ts">
  import { isoToInput, maskDateInput, parseDateInput } from '../lib/dates';

  let {
    name,
    value = $bindable(''),
    oninput,
  }: {
    name: string;
    value?: string;
    oninput?: () => void;
  } = $props();

  let text = $state(isoToInput(value));
  let focused = false;

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

  function handle(event: Event): void {
    const raw = event.target instanceof HTMLInputElement ? event.target.value : '';
    text = maskDateInput(raw);
    value = parseDateInput(text) ?? '';
    oninput?.();
  }
</script>

<input
  {name}
  type="text"
  inputmode="numeric"
  autocomplete="off"
  spellcheck="false"
  placeholder="MM / DD / YYYY"
  value={text}
  onfocus={() => (focused = true)}
  onblur={() => (focused = false)}
  oninput={handle}
/>
