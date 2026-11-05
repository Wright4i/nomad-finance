export const route = $state({ path: '/' });

export function startRouter(): void {
  route.path = window.location.pathname;
  window.addEventListener('popstate', () => {
    route.path = window.location.pathname;
  });
}

export function go(to: string): void {
  if (route.path !== to) {
    history.pushState({}, '', to);
  }
  route.path = to;
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
  | { name: 'categories' }
  | { name: 'category-form'; id: string | null }
  | { name: 'pay-cycles' }
  | { name: 'pay-cycle-form'; id: string | null }
  | { name: 'envelopes' }
  | { name: 'trips' }
  | { name: 'trip-form'; id: string | null }
  | { name: 'log' }
  | { name: 'calendar' }
  | { name: 'vehicles' }
  | { name: 'vehicle-form'; id: string | null }
  | { name: 'settings' };

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
  if (path === '/categories') return { name: 'categories' };
  if (path === '/categories/new') return { name: 'category-form', id: null };
  const category = path.match(/^\/categories\/(\d+)$/);
  if (category) return { name: 'category-form', id: category[1] };
  if (path === '/pay-cycles' || path === '/pay-cycle') return { name: 'pay-cycles' };
  if (path === '/pay-cycles/new') return { name: 'pay-cycle-form', id: null };
  const payCycle = path.match(/^\/pay-cycles\/(\d+)$/);
  if (payCycle) return { name: 'pay-cycle-form', id: payCycle[1] };
  if (path === '/envelopes') return { name: 'envelopes' };
  if (path === '/trips') return { name: 'trips' };
  if (path === '/trips/new') return { name: 'trip-form', id: null };
  const trip = path.match(/^\/trips\/(\d+)$/);
  if (trip) return { name: 'trip-form', id: trip[1] };
  if (path === '/log') return { name: 'log' };
  if (path === '/calendar') return { name: 'calendar' };
  if (path === '/settings/vehicles') return { name: 'vehicles' };
  if (path === '/settings/vehicles/new') return { name: 'vehicle-form', id: null };
  const vehicle = path.match(/^\/settings\/vehicles\/(\d+)$/);
  if (vehicle) return { name: 'vehicle-form', id: vehicle[1] };
  if (path === '/settings') return { name: 'settings' };
  return { name: 'dashboard' };
}
