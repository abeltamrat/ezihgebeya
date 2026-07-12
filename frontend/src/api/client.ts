// Typed API client for /api/v1 — the session-cookie API for this SPA (see pages/api_v1.php).
// Session identity is the existing PHP session cookie (credentials: 'same-origin'); there is
// no separate token store. CSRF token is captured once from GET /me and echoed back on every
// mutation as X-CSRF-Token, per api_v1.php's blanket CSRF check.

const API_BASE = '/api/v1';

let csrfToken: string | null = null;

export function setCsrfToken(token: string): void {
  csrfToken = token;
}

export class ApiError extends Error {
  status: number;
  fields?: Record<string, string[] | string>;
  constructor(message: string, status: number, fields?: Record<string, string[] | string>) {
    super(message);
    this.status = status;
    this.fields = fields;
  }
}

interface Envelope {
  ok: boolean;
  error?: string;
  fields?: Record<string, string[] | string>;
  [key: string]: unknown;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase();
  const headers = new Headers(options.headers);
  const isFormData = options.body instanceof FormData;
  if (!isFormData && options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  if (method !== 'GET' && csrfToken) headers.set('X-CSRF-Token', csrfToken);

  const res = await fetch(API_BASE + path, {
    ...options,
    method,
    headers,
    credentials: 'same-origin', // reuse the PHP session cookie — no second auth system
  });

  let data: Envelope | null = null;
  try {
    data = await res.json();
  } catch {
    // non-JSON response (e.g. a PHP fatal error page) — fall through to the generic error below
  }

  if (!res.ok || !data?.ok) {
    throw new ApiError(data?.error ?? `Request failed (${res.status})`, res.status, data?.fields);
  }
  return data as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body instanceof FormData ? body : JSON.stringify(body ?? {}) }),
  put: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'PUT', body: body instanceof FormData ? body : JSON.stringify(body ?? {}) }),
  del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
