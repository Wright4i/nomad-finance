<script lang="ts">
  import { settledAmount } from '../lib/money'

  let {
    value = $bindable(''),
    exponent = 2,
    name,
    placeholder,
    disabled = false,
    'aria-label': ariaLabel,
    onblur,
    oninput,
    onkeydown,
  }: {
    value?: string
    exponent?: number
    name?: string
    placeholder?: string
    disabled?: boolean
    'aria-label'?: string
    onblur?: (event: FocusEvent) => void
    oninput?: (event: Event) => void
    onkeydown?: (event: KeyboardEvent) => void
  } = $props()

  function rewrite(input: HTMLInputElement): void {
    const next = settledAmount(input.value, exponent)
    if (next === null) return
    value = next
    input.value = next
  }

  function blur(event: FocusEvent): void {
    const input = event.currentTarget
    if (input instanceof HTMLInputElement) rewrite(input)
    onblur?.(event)
  }

  function watch(input: HTMLInputElement): { destroy(): void } {
    const form = input.form
    if (!form) return { destroy() {} }
    const submit = (): void => rewrite(input)
    form.addEventListener('submit', submit, true)
    return {
      destroy() {
        form.removeEventListener('submit', submit, true)
      },
    }
  }
</script>

<input
  {name}
  {placeholder}
  {disabled}
  aria-label={ariaLabel}
  inputmode="decimal"
  bind:value
  use:watch
  onblur={blur}
  oninput={oninput}
  onkeydown={onkeydown}
/>
