import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrfToken } from './client';
import { accountApi } from './account';

function mockFetch(body: unknown = { ok: true }) {
  return vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe('account api', () => {
  beforeEach(() => {
    setCsrfToken('csrf');
    vi.restoreAllMocks();
  });

  it('loads account settings from the v1 account endpoint', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await accountApi.settings();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/settings');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });

  it('saves notification preferences to the v1 account endpoint', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await accountApi.saveSettings({ marketing: { sms: true, email: false, push: true }, categories: { orders: true } });

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/settings');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(JSON.stringify({ marketing: { sms: true, email: false, push: true }, categories: { orders: true } }));
  });

  it('loads and removes saved products through the account favorites endpoint', async () => {
    const fetchMock = mockFetch({ ok: true, data: [] });
    globalThis.fetch = fetchMock;

    await accountApi.favorites();
    await accountApi.removeFavorite(12);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/favorites');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/favorites/12');
    expect(fetchMock.mock.calls[1][1].method).toBe('DELETE');
  });

  it('loads customer inquiry threads and sends replies through account endpoints', async () => {
    const fetchMock = mockFetch({ ok: true, data: [], messages: [] });
    globalThis.fetch = fetchMock;

    await accountApi.inquiries();
    await accountApi.inquiry(7);
    await accountApi.replyToInquiry(7, 'Thanks for the update.');

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/inquiries');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/inquiries/7');
    expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/account/inquiries/7/messages');
    expect(fetchMock.mock.calls[2][1].method).toBe('POST');
    expect(fetchMock.mock.calls[2][1].body).toBe(JSON.stringify({ body: 'Thanks for the update.' }));
  });

  it('loads and marks notifications read through account endpoints', async () => {
    const fetchMock = mockFetch({ ok: true, unread_count: 0, data: [] });
    globalThis.fetch = fetchMock;

    await accountApi.notifications();
    await accountApi.markNotificationRead(3);
    await accountApi.markAllNotificationsRead();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/notifications');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/notifications/3/read');
    expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/account/notifications/read-all');
  });

  it('loads and submits reviews through account endpoints', async () => {
    const fetchMock = mockFetch({ ok: true, data: [] });
    globalThis.fetch = fetchMock;

    await accountApi.reviews();
    await accountApi.submitReview({
      business_id: 4,
      listing_type: 'product',
      listing_id: 9,
      rating: 5,
      title: 'Great',
      comment: 'Fast and honest seller.',
    });

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/reviews');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/reviews');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({
      business_id: 4,
      listing_type: 'product',
      listing_id: 9,
      rating: 5,
      title: 'Great',
      comment: 'Fast and honest seller.',
    }));
  });

  it('loads and submits reports through account endpoints', async () => {
    const fetchMock = mockFetch({ ok: true, data: [] });
    globalThis.fetch = fetchMock;

    await accountApi.reports();
    await accountApi.submitReport({
      reported_type: 'product',
      reported_id: 15,
      reason: 'scam',
      description: 'Suspicious listing.',
    });

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/reports');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/reports');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({
      reported_type: 'product',
      reported_id: 15,
      reason: 'scam',
      description: 'Suspicious listing.',
    }));
  });
});
