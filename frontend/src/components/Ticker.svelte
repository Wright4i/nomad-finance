<script lang="ts">
  import { formatMinor } from '../lib/money';

  let { minor, currency, exponent = 2 }: { minor: number; currency: string; exponent?: number } = $props();
</script>

<p class="figure">{formatMinor(minor, currency, exponent)}</p>
