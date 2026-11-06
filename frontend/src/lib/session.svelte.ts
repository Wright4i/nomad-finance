import { api, onUnauthorized, setCsrf } from './api';
import type { User } from './types';

export const session = $state({
  ready: false,
  needsSetup: false,
  allowNewUser: false,
  books: 0,
  user: null as User | null,
});

export function applyTheme(theme: string | undefined): void {
  document.documentElement.classList.toggle('light', theme === 'light');
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'light' ? '#f4efe4' : '#101714');
}

export async function loadSession(): Promise<void> {
  const data = await api<{ needsSetup: boolean; allowNewUser: boolean; csrfToken: string; user: User | null }>('/api/session');
  setCsrf(data.csrfToken);
  session.needsSetup = data.needsSetup;
  session.allowNewUser = data.allowNewUser === true;
  session.user = data.user;
  session.ready = true;
  applyTheme(data.user?.theme ?? 'dark');
}

export function adoptUser(user: User): void {
  session.user = user;
  session.needsSetup = false;
  session.ready = true;
  applyTheme(user.theme);
}

onUnauthorized(() => {
  session.user = null;
});
