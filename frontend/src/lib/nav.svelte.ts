export const route = $state({ path: '/', search: '' });

function readLocation(): void {
  route.path = window.location.pathname;
  route.search = window.location.search;
}

export function startRouter(): void {
  readLocation();
  window.addEventListener('popstate', readLocation);
}

export function go(to: string): void {
  const url = new URL(to, window.location.origin);
  const path = url.pathname;
  const search = url.search;
  if (route.path !== path || route.search !== search) {
    history.pushState({}, '', `${path}${search}${url.hash}`);
  }
  route.path = path;
  route.search = search;
}

/** Leave a bill form for the list. Back keeps the filter that was on the list. */
export function returnToBills(): void {
  const before = window.location.pathname + window.location.search;
  const stop = (): void => window.clearTimeout(timer);
  window.addEventListener('popstate', stop, { once: true });
  const timer = window.setTimeout(() => {
    window.removeEventListener('popstate', stop);
    if (window.location.pathname + window.location.search === before) go('/bills');
  }, 300);
  history.back();
}

export function follow(event: MouseEvent, href: string): void {
  if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    return;
  }
  event.preventDefault();
  go(href);
}

export type View =
  | { name: 'dashboard' }
  | { name: 'accounts' }
  | { name: 'account-form'; id: string | null }
  | { name: 'bills' }
  | { name: 'bill-form'; id: string | null }
  | { name: 'category-form'; id: string | null }
  | { name: 'pay-cycles' }
  | { name: 'pay-cycle-form'; id: string | null }
  | { name: 'envelopes' }
  | { name: 'trips' }
  | { name: 'trip-form'; id: string | null }
  | { name: 'log' }
  | { name: 'calendar' }
  | { name: 'transfers' }
  | { name: 'vehicles' }
  | { name: 'vehicle-form'; id: string | null };

export function match(path: string): View {
  if (path === '/credit' || path === '/plans' || path.startsWith('/plans/')) return { name: 'dashboard' };
  if (path === '/' || path === '') return { name: 'dashboard' };
  if (path === '/accounts') return { name: 'accounts' };
  if (path === '/accounts/new') return { name: 'account-form', id: null };
  const account = path.match(/^\/accounts\/(\d+)$/);
  if (account) return { name: 'account-form', id: account[1] };
  if (path === '/bills') return { name: 'bills' };
  if (path === '/bills/new') return { name: 'bill-form', id: null };
  const bill = path.match(/^\/bills\/(\d+)$/);
  if (bill) return { name: 'bill-form', id: bill[1] };
  if (path === '/categories' || path === '/envelopes' || path === '/budget' || path === '/buckets') return { name: 'envelopes' };
  if (path === '/buckets/new' || path === '/categories/new') return { name: 'category-form', id: null };
  const bucket = path.match(/^\/(?:buckets|categories)\/(\d+)$/);
  if (bucket) return { name: 'category-form', id: bucket[1] };
  if (path === '/pay-cycles' || path === '/pay-cycle') return { name: 'pay-cycles' };
  if (path === '/pay-cycles/new') return { name: 'pay-cycle-form', id: null };
  const payCycle = path.match(/^\/pay-cycles\/(\d+)$/);
  if (payCycle) return { name: 'pay-cycle-form', id: payCycle[1] };
  if (path === '/trips') return { name: 'trips' };
  if (path === '/trips/new') return { name: 'trip-form', id: null };
  const trip = path.match(/^\/trips\/(\d+)$/);
  if (trip) return { name: 'trip-form', id: trip[1] };
  if (path === '/log') return { name: 'log' };
  if (path === '/calendar') return { name: 'calendar' };
  if (path === '/transfers') return { name: 'transfers' };
  if (path === '/vehicles' || path === '/settings/vehicles') return { name: 'vehicles' };
  if (path === '/vehicles/new' || path === '/settings/vehicles/new') return { name: 'vehicle-form', id: null };
  const vehicle = path.match(/^\/(?:settings\/)?vehicles\/(\d+)$/);
  if (vehicle) return { name: 'vehicle-form', id: vehicle[1] };
  return { name: 'dashboard' };
}
