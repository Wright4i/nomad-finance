<script lang="ts">
  import { CURRENCIES } from '../lib/money';

  let {
    name,
    value = $bindable(''),
    allowEmpty = false,
    exclude = '',
  }: {
    name: string;
    value?: string;
    allowEmpty?: boolean;
    exclude?: string;
  } = $props();

  let choices = $derived(CURRENCIES.filter((item) => item.code !== exclude.trim().toUpperCase()));
</script>

<select {name} bind:value>
  {#if allowEmpty}
    <option value="">None</option>
  {/if}
  {#each choices as item (item.code)}
    <option value={item.code}>{item.code} ({item.name})</option>
  {/each}
</select>
