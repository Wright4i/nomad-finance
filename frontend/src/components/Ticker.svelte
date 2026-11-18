<script lang="ts">
  import Dollars from './Dollars.svelte'

  let { minor, currency, exponent = 2 }: { minor: number, currency: string, exponent?: number } = $props()
</script>

<p class="figure"><Dollars {minor} {currency} {exponent} /></p>
