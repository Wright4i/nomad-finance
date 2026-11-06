<script lang="ts">
  import { fade } from 'svelte/transition';
  import { prefersReducedMotion } from '../lib/motion';

  let { message = $bindable('') }: { message?: string } = $props();

  const success = $derived(message === 'Saved.');
  const reduced = $derived(prefersReducedMotion());
  const duration = $derived(reduced ? 120 : 180);

  $effect(() => {
    if (!success) return;
    const wait = reduced ? 2200 : 2600;
    const timer = setTimeout(() => {
      message = '';
    }, wait);
    return () => clearTimeout(timer);
  });
</script>

<div aria-live="polite">
  {#if message}
    <p class="note m-0" class:teal={success} class:banner={!success} transition:fade={{ duration }}>{message}</p>
  {/if}
</div>
