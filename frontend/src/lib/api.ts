import { DATE_INPUT_ERROR } from './dates';

export class ApiError extends Error {
  status: number;
  fields: Record<string, string>;

  constructor(message: string, status: number, fields: Record<string, string> = {}) {
    super(message);
    this.status = status;
    this.fields = fields;
  }
}

let csrf = '';
let on401 = () => {};

export function setCsrf(token: string): void {
  csrf = token;
}

export function onUnauthorized(handler: () => void): void {
  on401 = handler;
}

function forScreen(message: string): string {
  return message.includes('YYYY-MM-DD') ? DATE_INPUT_ERROR : message;
}

export function explain(err: unknown): { message: string; fields: Record<string, string> } {
  if (err instanceof ApiError) {
    const fields: Record<string, string> = {};
    for (const [key, value] of Object.entries(err.fields)) {
      fields[key] = forScreen(value);
    }
    return { message: forScreen(err.message), fields };
  }
  return { message: 'Something went wrong.', fields: {} };
}

export async function api<T>(path: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
  const method = opts.method ?? 'GET';
  const headers = new Headers({ Accept: 'application/json' });
  if (opts.body !== undefined) {
    headers.set('Content-Type', 'application/json');
  }
  if (method !== 'GET' && method !== 'HEAD') {
    headers.set('X-CSRF-Token', csrf);
  }

  const response = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  });

  let data: { error?: string; fields?: Record<string, string> } = {};
  try {
    data = await response.json();
  } catch {
    data = {};
  }

  if (response.status === 401) {
    on401();
  }
  if (!response.ok) {
    throw new ApiError(data.error ?? 'Request failed.', response.status, data.fields ?? {});
  }

  return data as T;
}
