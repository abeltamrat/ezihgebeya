import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrfToken } from './client';
import { boostApi } from './boost';

function mockFetch(body: unknown = { ok: true }) {
  return vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe('boost api', () => {
  beforeEach(() => {
    setCsrfToken('csrf');
    vi.restoreAllMocks();
  });

  it('loads TOP Pin and Boost options through the vendor boost endpoint', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await boostApi.get();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/boost');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });

  it('submits TOP Pin and Boost purchases as FormData', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    const topPin = new FormData();
    topPin.set('package', 'top_pin_7');
    const boost = new FormData();
    boost.set('tier', 'boost_pro');

    await boostApi.buyTopPin(topPin);
    await boostApi.subscribe(boost);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/boost/top-pin');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(topPin);
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/boost/subscribe');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(boost);
  });

  it('cancels TOP Pin and Boost requests through one endpoint', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await boostApi.cancel('top_pin', 7);
    await boostApi.cancel('boost', 8);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/boost/cancel');
    expect(fetchMock.mock.calls[0][1].body).toBe(JSON.stringify({ kind: 'top_pin', id: 7 }));
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/boost/cancel');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({ kind: 'boost', id: 8 }));
  });
});
