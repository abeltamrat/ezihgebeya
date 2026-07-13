import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrfToken } from './client';
import { adminApi } from './admin';

function mockFetch(body: unknown = { ok: true }) {
  return vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe('admin api', () => {
  beforeEach(() => {
    setCsrfToken('csrf');
    vi.restoreAllMocks();
  });

  it('loads marketplace health through the v1 admin endpoint', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await adminApi.health();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/admin/health');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });

  it('loads monetization package operations through the v1 admin endpoint', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await adminApi.monetization();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/admin/monetization');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });
});
