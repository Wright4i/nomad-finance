/** Less motion from the system shortens an effect. Effects are not behind a switch. */

export function prefersReducedMotion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
