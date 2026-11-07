<script lang="ts">
  let { label = 'Loading' }: { label?: string } = $props();
</script>

<div class="page-spinner" role="status" aria-live="polite">
  <span class="page-spinner-mark" aria-hidden="true"></span>
  <span class="page-spinner-label">{label}</span>
</div>
