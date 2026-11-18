export type TipBox = { left: number, top: number, right: number, bottom: number, width: number, height: number }

export function tipOrigin(
  anchor: TipBox,
  tip: { width: number, height: number },
  viewportWidth: number,
  inNav: boolean,
): { left: number, top: number } {
  const edge = 8
  if (inNav) {
    let left = anchor.right + edge
    if (left + tip.width > viewportWidth - edge) {
      left = Math.max(edge, anchor.left - tip.width - edge)
    }
    return { left, top: anchor.top + (anchor.height - tip.height) / 2 }
  }
  let left = anchor.left
  let top = anchor.top - tip.height - 6
  if (top < edge) top = anchor.bottom + 6
  if (left + tip.width > viewportWidth - edge) {
    left = Math.max(edge, viewportWidth - edge - tip.width)
  }
  return { left, top }
}

export function tip(node: HTMLElement, label: string): { update: (next: string) => void, destroy: () => void } {
  let current = label
  let timer = 0
  let bubble: HTMLDivElement | null = null
  const mark = (): void => {
    node.classList.toggle('has-tip', current !== '')
  }
  const hide = (): void => {
    window.clearTimeout(timer)
    timer = 0
    if (bubble?.matches(':popover-open')) bubble.hidePopover()
    bubble?.remove()
    bubble = null
  }
  const show = (): void => {
    hide()
    if (!current) return
    timer = window.setTimeout(() => {
      if (!current) return
      const el = document.createElement('div')
      el.className = 'nav-tip'
      el.setAttribute('role', 'tooltip')
      el.textContent = current
      el.popover = 'manual'
      const box = node.getBoundingClientRect()
      el.style.visibility = 'hidden'
      el.style.left = `${box.left}px`
      el.style.top = `${box.top}px`
      document.body.appendChild(el)
      el.showPopover()
      const placed = tipOrigin(
        box,
        el.getBoundingClientRect(),
        window.innerWidth,
        node.closest('nav') !== null,
      )
      el.style.left = `${placed.left}px`
      el.style.top = `${placed.top}px`
      el.style.visibility = 'visible'
      bubble = el
    }, 100)
  }
  mark()
  node.addEventListener('mouseenter', show)
  node.addEventListener('mouseleave', hide)
  node.addEventListener('focus', show)
  node.addEventListener('blur', hide)
  return {
    update(next: string) {
      current = next
      mark()
      if (!current) hide()
    },
    destroy() {
      hide()
      node.classList.remove('has-tip')
      node.removeEventListener('mouseenter', show)
      node.removeEventListener('mouseleave', hide)
      node.removeEventListener('focus', show)
      node.removeEventListener('blur', hide)
    },
  }
}
