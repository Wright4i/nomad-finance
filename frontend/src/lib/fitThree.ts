export function fitThree(node: HTMLElement, count: number) {
  let items = count
  let frame = 0

  const apply = () => {
    const chips = [...node.querySelectorAll(':scope > .bill-chip')].filter((chip): chip is HTMLElement => chip instanceof HTMLElement)
    if (items <= 3 || chips.length <= 3) {
      if (node.style.maxHeight !== '') node.style.maxHeight = ''
      return
    }
    const first = chips[0]
    const third = chips[2]
    const height = Math.ceil(third.offsetTop + third.offsetHeight - first.offsetTop)
    if (height < 1) return
    const current = node.style.maxHeight === '' ? NaN : parseFloat(node.style.maxHeight)
    if (Number.isNaN(current) || Math.abs(current - height) > 1) node.style.maxHeight = `${height}px`
  }

  const schedule = () => {
    cancelAnimationFrame(frame)
    frame = requestAnimationFrame(apply)
  }

  const resize = new ResizeObserver(schedule)
  const watch = () => {
    resize.disconnect()
    resize.observe(node)
    for (const chip of node.querySelectorAll(':scope > .bill-chip')) resize.observe(chip)
  }
  const mutations = new MutationObserver(() => {
    watch()
    schedule()
  })
  const keepScrollbarClick = (event: MouseEvent) => {
    if (event.offsetX > node.clientWidth || event.offsetY > node.clientHeight) event.stopPropagation()
  }
  mutations.observe(node, { childList: true })
  node.addEventListener('click', keepScrollbarClick)
  watch()
  schedule()

  return {
    update(next: number) {
      items = next
      watch()
      schedule()
    },
    destroy() {
      cancelAnimationFrame(frame)
      resize.disconnect()
      mutations.disconnect()
      node.removeEventListener('click', keepScrollbarClick)
    },
  }
}
