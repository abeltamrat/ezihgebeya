import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrfToken } from './client';
import { vendorApi } from './vendor';

function mockFetch(body: unknown = { ok: true }) {
  return vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe('vendor api', () => {
  beforeEach(() => {
    setCsrfToken('csrf');
    vi.restoreAllMocks();
  });

  it('sets a product image as primary through the v1 media endpoint', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.setPrimaryImage(12, 34);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/listings/product/12/images/34/primary');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
  });

  it('loads and saves the vendor business profile through the v1 business endpoint', async () => {
    const fetchMock = mockFetch({ ok: true, business: null, cities: [], subcities: {}, default_phone: null });
    globalThis.fetch = fetchMock;
    const form = new FormData();
    form.set('business_name', 'Ezih Test Shop');
    form.set('phone', '0911223344');
    form.set('city', 'Addis Ababa');

    await vendorApi.business();
    await vendorApi.saveBusiness(form);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/business');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/business');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(form);
  });

  it('deletes a product image through the v1 media endpoint', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.deleteImage('product', 12, 34);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/listings/product/12/images/34');
    expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
  });

  it('deletes a service or supply single image without a media row id', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.deleteImage('service', 12);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/listings/service/12/images');
    expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
  });

  it('lists vendor inquiries with an optional status filter', async () => {
    const fetchMock = mockFetch({ ok: true, data: [], statuses: ['new'] });
    globalThis.fetch = fetchMock;

    await vendorApi.inquiries('new');

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/inquiries?status=new');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });

  it('updates inquiry status and sends thread replies through v1 endpoints', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.updateInquiryStatus(7, 'responded');
    await vendorApi.replyToInquiry(7, 'Thanks, we will call you.');

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/inquiries/7/status');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(JSON.stringify({ status: 'responded' }));
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/inquiries/7/messages');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({ body: 'Thanks, we will call you.' }));
  });

  it('updates order status and confirms manual payment proofs through v1 endpoints', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.updateOrderStatus(9, 'processing');
    await vendorApi.confirmOrderPayment(9, 22);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/orders/9/status');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(JSON.stringify({ status: 'processing' }));
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/orders/9/payments/22/confirm');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
  });

  it('creates and deletes vendor videos through v1 endpoints', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.createVideo({ platform: 'youtube', original_url: 'https://youtu.be/abc', linked_type: 'business' });
    await vendorApi.deleteVideo(5);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/videos');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/vendor/videos/5');
    expect(fetchMock.mock.calls[1][1].method).toBe('DELETE');
  });

  it('submits verification documents as FormData', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;
    const form = new FormData();
    form.append('requested_level', 'document_verified');

    await vendorApi.submitVerification(form);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/verification');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(form);
  });

  it('replies to a review through the one-time vendor reply endpoint', async () => {
    const fetchMock = mockFetch();
    globalThis.fetch = fetchMock;

    await vendorApi.replyToReview(44, 'Thank you for the review.');

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/reviews/44/reply');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(JSON.stringify({ reply: 'Thank you for the review.' }));
  });

  it('loads vendor analytics through the v1 analytics endpoint', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;

    await vendorApi.analytics();

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/vendor/analytics');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
  });
});
