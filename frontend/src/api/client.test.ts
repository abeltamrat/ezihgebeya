import { describe, it, expect, vi, beforeEach } from 'vitest';
import { api, ApiError, setCsrfToken } from './client';

function mockFetch(body: unknown, init: { ok?: boolean; status?: number; json?: boolean } = {}) {
  const { ok = true, status = 200, json = true } = init;
  return vi.fn().mockResolvedValue({
    ok,
    status,
    json: async () => {
      if (!json) throw new SyntaxError('Unexpected token < in JSON');
      return body;
    },
  });
}

describe('api client', () => {
  beforeEach(() => {
    setCsrfToken('');
    vi.restoreAllMocks();
  });

  it('resolves with the envelope on a successful response', async () => {
    globalThis.fetch = mockFetch({ ok: true, data: { id: 1 } });
    const result = await api.get<{ ok: boolean; data: { id: number } }>('/vendor/dashboard');
    expect(result.data.id).toBe(1);
  });

  it('throws ApiError with the server message and field errors on a failed response', async () => {
    globalThis.fetch = mockFetch(
      { ok: false, error: 'Validation failed', fields: { title: ['Title is required'] } },
      { ok: false, status: 422 },
    );
    await expect(api.post('/vendor/listings/product', { title: '' })).rejects.toMatchObject({
      message: 'Validation failed',
      status: 422,
      fields: { title: ['Title is required'] },
    });
  });

  it('throws a generic ApiError when the server returns a non-JSON error page', async () => {
    // Regression case: a PHP fatal error page (or any non-JSON response) must not crash the
    // client with an unhandled parse error — it should surface as a normal ApiError instead.
    globalThis.fetch = mockFetch(null, { ok: false, status: 500, json: false });
    await expect(api.get('/vendor/dashboard')).rejects.toBeInstanceOf(ApiError);
    await expect(api.get('/vendor/dashboard')).rejects.toMatchObject({ status: 500 });
  });

  it('sends X-CSRF-Token on mutating requests once a token is set, but not on GET', async () => {
    setCsrfToken('test-csrf-token');
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await api.get('/vendor/dashboard');
    let headers = fetchMock.mock.calls[0][1].headers as Headers;
    expect(headers.has('X-CSRF-Token')).toBe(false);

    await api.post('/vendor/listings/product', { title: 'x' });
    headers = fetchMock.mock.calls[1][1].headers as Headers;
    expect(headers.get('X-CSRF-Token')).toBe('test-csrf-token');
  });

  it('always sends credentials: same-origin so the PHP session cookie is reused', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    await api.get('/me');
    expect(fetchMock.mock.calls[0][1].credentials).toBe('same-origin');
  });

  it('does not force a JSON Content-Type when sending FormData (image uploads)', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    const fd = new FormData();
    fd.append('images[]', new Blob(['x']), 'test.png');
    await api.post('/vendor/listings/product/1/images', fd);
    const headers = fetchMock.mock.calls[0][1].headers as Headers;
    expect(headers.has('Content-Type')).toBe(false);
    expect(fetchMock.mock.calls[0][1].body).toBe(fd);
  });

  it('JSON-encodes a plain object body and sets Content-Type: application/json', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    await api.put('/vendor/listings/product/1', { title: 'Updated' });
    const call = fetchMock.mock.calls[0][1];
    const headers = call.headers as Headers;
    expect(headers.get('Content-Type')).toBe('application/json');
    expect(call.body).toBe(JSON.stringify({ title: 'Updated' }));
  });

  it('routes api.del to a DELETE request', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    await api.del('/vendor/listings/product/1');
    expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
  });
});
