<script lang="ts">
  import { prefersReducedMotion } from '../lib/motion';

  let { message = $bindable(''), ondone }: { message?: string; ondone?: () => void } = $props();

  let canvas = $state<HTMLCanvasElement | undefined>();
  const celebrating = $derived(message === 'Paid.' || message === 'Transferred.');

  $effect(() => {
    if (!celebrating) return;
    const reduced = prefersReducedMotion();
    const wait = reduced ? 1600 : 2600;
    let frame = 0;
    let stop = false;
    if (!reduced && canvas) {
      const node = canvas;
      const ctx = node.getContext('2d');
      if (ctx) {
        const dpr = window.devicePixelRatio || 1;
        const width = window.innerWidth;
        const height = window.innerHeight;
        node.width = Math.floor(width * dpr);
        node.height = Math.floor(height * dpr);
        ctx.scale(dpr, dpr);
        const colors = ['#3cbf6e', '#e23b4a', '#f2c14e', '#7aa2ff', '#ff8bd2', '#f6f1e7'];
        const pieces = Array.from({ length: 90 }, () => ({
          x: width / 2,
          y: height * 0.38,
          vx: (Math.random() - 0.5) * 16,
          vy: Math.random() * -14 - 3,
          w: 6 + Math.random() * 5,
          h: 8 + Math.random() * 6,
          color: colors[Math.floor(Math.random() * colors.length)] ?? '#3cbf6e',
          rot: Math.random() * 6,
          vr: (Math.random() - 0.5) * 0.28,
        }));
        const tick = () => {
          if (stop) return;
          ctx.clearRect(0, 0, width, height);
          for (const piece of pieces) {
            piece.vy += 0.28;
            piece.x += piece.vx;
            piece.y += piece.vy;
            piece.rot += piece.vr;
            ctx.save();
            ctx.translate(piece.x, piece.y);
            ctx.rotate(piece.rot);
            ctx.fillStyle = piece.color;
            ctx.fillRect(-piece.w / 2, -piece.h / 2, piece.w, piece.h);
            ctx.restore();
          }
          frame += 1;
          if (frame < 110) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      }
    }
    const timer = setTimeout(() => {
      message = '';
      ondone?.();
    }, wait);
    return () => {
      stop = true;
      clearTimeout(timer);
    };
  });
</script>

{#if celebrating}
  <div class="celebrate-overlay" role="status">
    <canvas bind:this={canvas} aria-hidden="true"></canvas>
    <p class="celebrate-toast m-0">{message}</p>
  </div>
{/if}

<style>
  .celebrate-overlay {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: grid;
    place-items: center;
    pointer-events: none;
  }

  canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
  }

  .celebrate-toast {
    position: relative;
    padding: 0.9rem 1.4rem;
    border-radius: 999px;
    background: color-mix(in oklab, var(--bg-raise) 88%, white);
    color: var(--text);
    font-family: var(--font-display);
    font-size: 1.8rem;
    font-weight: 650;
    box-shadow: 0 18px 50px color-mix(in oklab, black 28%, transparent);
    animation: celebrate-toast 220ms var(--ease-out) both;
  }

  @keyframes celebrate-toast {
    from {
      opacity: 0;
      transform: translateY(8px) scale(0.96);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .celebrate-toast {
      animation: none;
    }
  }
</style>
