<script lang="ts">
  let { name, mini = false }: { name: string; mini?: boolean } = $props();

  let mark = $derived(name);
</script>

<span class="glyph" class:mini aria-hidden="true">
  <svg viewBox="0 0 24 24" fill="none">
    {#if mark === 'checking'}
      <path d="M5 12.5 10 17.5 19 6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'savings'}
      <ellipse cx="12" cy="7.2" rx="6.4" ry="2.15" stroke="currentColor" stroke-width="1.8" />
      <path d="M5.6 7.2v9c0 1.2 2.9 2.2 6.4 2.2s6.4-1 6.4-2.2v-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <path d="M5.6 11.7c0 1.2 2.9 2.2 6.4 2.2s6.4-1 6.4-2.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'cash'}
      <rect x="3.5" y="6.5" width="17" height="11" rx="2" stroke="currentColor" stroke-width="1.8" />
      <circle cx="12" cy="12" r="2.1" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'credit'}
      <rect x="3.5" y="6" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.8" />
      <path d="M3.5 10h17" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'loan'}
      <path d="M7.5 16.5 16.5 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      <circle cx="9" cy="9" r="1.2" fill="currentColor" />
      <circle cx="15" cy="15" r="1.2" fill="currentColor" />
    {:else if mark === 'other'}
      <path d="M12 4.5 19 12l-7 7.5L5 12l7-7.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'lodging'}
      <path d="M4 15.5h16M6 15.5V10h5v5.5M14 15.5V8.5h4V15.5M6 10V7.5M4 7.5h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'tree'}
      <path d="M12 3 16.6 12.2H7.4L12 3z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
      <path d="M10.2 12.2h3.6V20.4h-3.6z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'needs'}
      <path d="m12 4.5 1.7 4.2 4.6.4-3.5 2.9 1.1 4.5L12 14.2 8.1 16.5l1.1-4.5-3.5-2.9 4.6-.4L12 4.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'rent'}
      <path d="M4.5 11.5 12 5l7.5 6.5M7 10.8V19h10v-8.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'wants'}
      <path d="M12 19C7.8 16.2 4.8 13.4 4.8 10.2 4.8 7.5 6.8 5.5 9.2 5.5 10.5 5.5 11.4 6.4 12 7.6 12.6 6.4 13.5 5.5 14.8 5.5 17.2 5.5 19.2 7.5 19.2 10.2 19.2 13.4 16.2 16.2 12 19z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'subscriptions'}
      <path d="m12 4 1.8 4.4 4.7.4-3.6 3 1.1 4.6L12 14.2 8 16.4l1.1-4.6-3.6-3 4.7-.4L12 4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'electricity'}
      <path d="M13 3.5 7 13h5l-1.2 7.5L18 10h-5.2L13 3.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'water'}
      <path d="M12 4s4.5 5 4.5 8.2a4.5 4.5 0 0 1-9 0C7.5 9 12 4 12 4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'internet'}
      <path d="M5 10.5a7 7 0 0 1 14 0M7.5 13a4 4 0 0 1 9 0M12 16.5h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'cellular' || mark === 'phone'}
      <rect x="8" y="3.5" width="8" height="17" rx="2" stroke="currentColor" stroke-width="1.8" />
      <path d="M11 17.5h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'gas' || mark === 'fuel'}
      <path d="M12 19.5c2.6 0 4.2-1.8 4.2-4.2 0-2.6-4.2-7.3-4.2-7.3S7.8 12.7 7.8 15.3c0 2.4 1.6 4.2 4.2 4.2z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'balance'}
      <path d="M12 5v13.5M8 18.5h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      <path d="M6.5 10.4 12 7.6l5.5 2.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <path d="M4.2 10.4c.45 1.55 1.5 2.5 2.3 2.5s1.85-.95 2.3-2.5M15.2 10.4c.45 1.55 1.5 2.5 2.3 2.5s1.85-.95 2.3-2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'groceries'}
      <path d="M6 8h12l-1 10H7L6 8zM9 8V6.5a3 3 0 0 1 6 0V8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'restaurants'}
      <path d="M8 4.5v6M8 7.5h2.2V4.5M16 4.5v15M16 10.5h-2.4V4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'healthcare' || mark === 'medical'}
      <circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.8" />
      <path d="M12 8.5v7M8.5 12h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'vehicle' || mark === 'car' || mark === 'transport'}
      <path d="M6 16.5V9.5h12v7M6 12.5h12M8.5 9.5 10 6.5h4l1.5 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="9" cy="16.5" r="1.3" fill="currentColor" />
      <circle cx="15" cy="16.5" r="1.3" fill="currentColor" />
    {:else if mark === 'truck'}
      <path d="M3.5 15.5V8.5h9v7M12.5 11h4.2L19 14v1.5M3.5 15.5h15.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="7" cy="16.5" r="1.3" fill="currentColor" />
      <circle cx="16" cy="16.5" r="1.3" fill="currentColor" />
    {:else if mark === 'motorcycle'}
      <circle cx="7" cy="16" r="2.2" stroke="currentColor" stroke-width="1.8" />
      <circle cx="17" cy="16" r="2.2" stroke="currentColor" stroke-width="1.8" />
      <path d="M9 16h4l2-4h2M9 12h3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'rv'}
      <path d="M4 15.5V8.5h11l4 4v3M4 15.5h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="8" cy="16.5" r="1.3" fill="currentColor" />
      <circle cx="16" cy="16.5" r="1.3" fill="currentColor" />
    {:else if mark === 'semi'}
      <path d="M3 15.5V9h7v6.5M10 12h4.2L17 15.5M3 15.5h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="6.5" cy="16.5" r="1.3" fill="currentColor" />
      <circle cx="14" cy="16.5" r="1.3" fill="currentColor" />
    {:else if mark === 'utv'}
      <path d="M3.5 15.5V9.5h17v6M3.5 15.5h17M6.5 9.5V7h4.2M13.3 9.5V7h4.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="7.5" cy="16.5" r="1.3" fill="currentColor" />
      <circle cx="16.5" cy="16.5" r="1.3" fill="currentColor" />
    {:else if mark === 'atv'}
      <circle cx="6.5" cy="16.5" r="2" stroke="currentColor" stroke-width="1.8" />
      <circle cx="17.5" cy="16.5" r="2" stroke="currentColor" stroke-width="1.8" />
      <path d="M8.2 16.2 11 11.5h3.2l2.2 4.7M10.2 11.5 11.4 8h2.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'golf cart'}
      <path d="M4.5 16V11h8.5v5M13 13.2h5M4.5 16h15.5M6.5 11V7.5h6.5V11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="8" cy="17" r="1.3" fill="currentColor" />
      <circle cx="16" cy="17" r="1.3" fill="currentColor" />
    {:else if mark === 'dirt bike'}
      <circle cx="6.5" cy="16.8" r="2.3" stroke="currentColor" stroke-width="1.8" />
      <circle cx="17.5" cy="16.8" r="2.3" stroke="currentColor" stroke-width="1.8" />
      <path d="M8.5 16.5 12 11.2l2.6 1.6 2.4-4.4M12 11.2 10.2 13.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'debt' || mark === 'tax'}
      <path d="M7 4h7l4 4v12H7V4zM14 4v4h4M9 13h6M9 16.5h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'entertainment' || mark === 'education'}
      <path d="M8 16.5V7.5l8 4.5-8 4.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'shopping'}
      <path d="M7 9h10l-1 10H8L7 9zM9.5 9c0-2.2 1.1-3.5 2.5-3.5S14.5 6.8 14.5 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'childcare'}
      <circle cx="8.2" cy="8" r="1.7" stroke="currentColor" stroke-width="1.8" />
      <circle cx="15.4" cy="9" r="1.4" stroke="currentColor" stroke-width="1.8" />
      <path d="M4.6 17.2c.5-2.5 2-3.7 3.6-3.7s3.1 1.2 3.6 3.7M12.2 17.2c.4-2.1 1.6-3.1 2.9-3.1s2.4 1 2.8 3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'travel'}
      <path d="M4 14.5 20 9.5l-7 1.2L10 16.5l1.4-4.2L4 14.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'insurance'}
      <path d="M12 3.5 19 6.5v5.2c0 4.2-2.8 7-7 8.8-4.2-1.8-7-4.6-7-8.8V6.5L12 3.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'propane'}
      <path d="M9 8h6v9H9zM10.5 8V6h3v2M12 17v2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'maintenance'}
      <path d="M14.5 5.5a3 3 0 0 0-4.2 4.2L5 15.1 8.9 19l5.4-5.3a3 3 0 0 0 4.2-4.2L16 12l-2-2 2.5-2.5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'roadside'}
      <path d="M12 4.5 20 18.5H4L12 4.5zM12 10v4M12 16.2h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'mailbox'}
      <path d="M5 8.5h10a3 3 0 0 1 0 6H5zM5 8.5v8M15 14.5v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'laundry'}
      <path d="M6 6.5h12v12H6zM6 10h12M8.5 14.5a3.5 2.2 0 1 0 7 0 3.5 2.2 0 1 0-7 0" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'mortgage'}
      <path d="M4 11.5 12 5l8 6.5M7 10.8V19h4v-4h2v4h4v-8.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'storage'}
      <path d="M5 8h14v4H5zM6 12h12v4H6zM7 16h10v3H7z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'memberships'}
      <rect x="4" y="7" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.8" />
      <path d="M4 11h16" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'software'}
      <rect x="4" y="5" width="16" height="12" rx="1.5" stroke="currentColor" stroke-width="1.8" />
      <path d="M9 19.5h6M12 17v2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'pets'}
      <circle cx="8" cy="8" r="1.4" fill="currentColor" />
      <circle cx="12" cy="6.5" r="1.4" fill="currentColor" />
      <circle cx="16" cy="8" r="1.4" fill="currentColor" />
      <path d="M9 13.5c0-2 1.4-3 3-3s3 1 3 3c0 2.4-1.3 4.5-3 4.5s-3-2.1-3-4.5z" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'misc'}
      <path d="M12 4.5v3M12 16.5v3M4.5 12h3M16.5 12h3M7 7l2 2M15 15l2 2M17 7l-2 2M9 15l-2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'emergency'}
      <circle cx="12" cy="12" r="7" stroke="currentColor" stroke-width="1.8" />
      <path d="M12 8v5M12 15.5h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'repairs'}
      <path d="M6 16.5 16.5 6M8 18.5l-2-2M15 7.5l2.2-2.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'investments'}
      <path d="M5 16.5 9.5 12l3 2.5L19 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'liquidities'}
      <path d="M5 14c1.5-2 2.5-2 4 0s2.5 2 4 0 2.5-2 4 0 2.5 2 2 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      <path d="M5 10c1.5-2 2.5-2 4 0s2.5 2 4 0 2.5-2 4 0 2.5 2 2 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'active'}
      <circle cx="12" cy="12" r="6" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'paused'}
      <path d="M9 6.5v11M15 6.5v11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'paid'}
      <path d="M6 12.5 10 16.5 18 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'weekly'}
      <path d="M16.2 8.2A5.2 5.2 0 1 0 17 12M17 7.2V11h-3.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'biweekly'}
      <path d="M7 8h4M13 8h4M7 12h10M7 16h4M13 16h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'semimonthly'}
      <path d="M12 5a7 7 0 1 0 0 14V5z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'monthly'}
      <path d="M6 6.5h12V19H6V6.5zM6 10.5h12M9 4.5v3M15 4.5v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'irregular'}
      <path d="M5 15.5 9 9l4 6 3-3 3 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'lock'}
      <rect x="6" y="10.5" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.8" />
      <path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'dollar'}
      <path d="M12 4v16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      <path d="M16.2 8.1c0-1.9-1.8-3-4.2-3s-4.2 1.2-4.2 3.1c0 1.6 1.2 2.2 4.2 2.7s4.2 1.1 4.2 2.9-1.7 3.1-4.2 3.1-4.2-1.3-4.2-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'envelope'}
      <path d="M4.5 7.5h15v9.5h-15zM4.5 8l7.5 5.2L19.5 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'sun'}
      <circle cx="12" cy="12" r="3.1" stroke="currentColor" stroke-width="1.8" />
      <path d="M12 3.4v2.1M12 18.5v2.1M3.4 12h2.1M18.5 12h2.1M5.8 5.8l1.5 1.5M16.7 16.7l1.5 1.5M18.2 5.8l-1.5 1.5M7.3 16.7l-1.5 1.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'moon'}
      <path d="M15.8 16.2A6.4 6.4 0 0 1 8 6.4 6.6 6.6 0 1 0 15.8 16.2z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
    {:else if mark === 'settings'}
      <path d="M4 8h3.2M10.8 8H20M4 16h9.2M16.8 16H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      <circle cx="9" cy="8" r="1.7" stroke="currentColor" stroke-width="1.8" />
      <circle cx="15" cy="16" r="1.7" stroke="currentColor" stroke-width="1.8" />
    {:else if mark === 'vault'}
      <rect x="4.5" y="4" width="15" height="16" rx="2" stroke="currentColor" stroke-width="1.8" />
      <circle cx="12.5" cy="12" r="3.1" stroke="currentColor" stroke-width="1.8" />
      <path d="M12.5 12h2.4M4.5 8.2h1.6M4.5 15.8h1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'entry'}
      <path d="M8 4.5h6.2L18 8.3V19.5H8z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
      <path d="M14 4.8V8.5h3.7M10.5 12h5M10.5 15h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'wrench'}
      <path d="M14.7 5.1a2.9 2.9 0 0 0-3.2 4L6.3 14.3a1.7 1.7 0 0 0 2.4 2.4l5.2-5.2a2.9 2.9 0 0 0 3.9-3.6L15.4 10.3 12.6 7.5l2.1-2.4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'pencil'}
      <path d="M13.2 5.4 18.6 10.8 8.2 21.2H4.8v-3.4L13.2 5.4z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
      <path d="m11.4 7.2 5.4 5.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'crosshair'}
      <circle cx="12" cy="12" r="3.1" stroke="currentColor" stroke-width="1.8" />
      <path d="M12 3.2v3.1M12 17.7v3.1M3.2 12h3.1M17.7 12h3.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    {:else if mark === 'up'}
      <path d="M6 14.5 12 8.5l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'down'}
      <path d="M6 9.5 12 15.5l6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'collapse'}
      <path d="M14.5 6.5 9 12l5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {:else if mark === 'expand'}
      <path d="M9.5 6.5 15 12l-5.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
    {/if}
  </svg>
</span>
