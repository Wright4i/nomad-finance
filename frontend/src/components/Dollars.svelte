<script lang="ts">
  import { dollarClass, formatMinor } from '../lib/money'

  let {
    minor,
    currency = '',
    exponent = 2,
    owe = false,
    plain = false,
    suffix = '',
    class: className = '',
  }: {
    minor: number
    currency?: string
    exponent?: number
    owe?: boolean
    plain?: boolean
    suffix?: string
    class?: string
  } = $props()

  let shown = $derived(owe ? -minor : minor)
</script>

<span class="money {plain ? '' : dollarClass(shown)} {className}">{formatMinor(shown, currency, exponent)}{suffix}</span>
