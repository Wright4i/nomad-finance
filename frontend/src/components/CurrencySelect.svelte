<script lang="ts">
  import { CURRENCIES } from '../lib/money';

  let {
    name,
    value = $bindable(''),
    allowEmpty = false,
    exclude = '',
    onchange,
  }: {
    name: string;
    value?: string;
    allowEmpty?: boolean;
    exclude?: string;
    onchange?: () => void;
  } = $props();

  let choices = $derived(CURRENCIES.filter((item) => item.code !== exclude.trim().toUpperCase()));
</script>

<select {name} bind:value onchange={() => onchange?.()}>
  {#if allowEmpty}
    <option value="">None</option>
  {/if}
  {#each choices as item (item.code)}
    <option value={item.code}>{item.code} ({item.name})</option>
  {/each}
</select>
