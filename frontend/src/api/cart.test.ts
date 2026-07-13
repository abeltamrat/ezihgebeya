import { beforeEach, describe, expect, it, vi } from 'vitest';
import { setCsrfToken } from './client';
import { cartApi, checkoutApi, ordersApi } from './cart';

function mockFetch(body: unknown = { ok: true }) {
  return vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => body,
  });
}

describe('cart and checkout api', () => {
  beforeEach(() => {
    setCsrfToken('csrf');
    vi.restoreAllMocks();
  });

  it('loads and mutates the cart through the v1 cart endpoint', async () => {
    const fetchMock = mockFetch({ ok: true, enabled: true, groups: [], grand_total: 0, cart_count: 0 });
    globalThis.fetch = fetchMock;

    await cartApi.get();
    await cartApi.add('product', 12, 2);
    await cartApi.update('supply', 8, 5);
    await cartApi.remove('product', 12);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/cart');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/cart');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({ do: 'add', listing_type: 'product', listing_id: 12, qty: 2 }));
    expect(fetchMock.mock.calls[2][1].body).toBe(JSON.stringify({ do: 'update', listing_type: 'supply', listing_id: 8, qty: 5 }));
    expect(fetchMock.mock.calls[3][1].body).toBe(JSON.stringify({ do: 'remove', listing_type: 'product', listing_id: 12 }));
  });

  it('loads checkout data and submits checkout through the v1 checkout endpoint', async () => {
    const fetchMock = mockFetch({ ok: true, order_numbers: ['EG260712-001'], requires_proof: false });
    globalThis.fetch = fetchMock;

    await checkoutApi.info();
    await checkoutApi.submit({
      delivery_option: 'pickup',
      delivery_address: '',
      city: 'Addis Ababa',
      subcity: 'Bole',
      phone: '0911223344',
      note: 'Call first.',
      payment_method: 'cash_on_delivery',
    });

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/checkout');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/checkout');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    expect(fetchMock.mock.calls[1][1].body).toBe(JSON.stringify({
      delivery_option: 'pickup',
      delivery_address: '',
      city: 'Addis Ababa',
      subcity: 'Bole',
      phone: '0911223344',
      note: 'Call first.',
      payment_method: 'cash_on_delivery',
    }));
  });

  it('loads orders and cancels a customer-owned order through account endpoints', async () => {
    const fetchMock = mockFetch({ ok: true, data: [] });
    globalThis.fetch = fetchMock;

    await ordersApi.list();
    await ordersApi.cancel(44);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/orders');
    expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/account/orders/44/cancel');
    expect(fetchMock.mock.calls[1][1].method).toBe('POST');
  });

  it('submits customer payment proof as FormData', async () => {
    const fetchMock = mockFetch({ ok: true });
    globalThis.fetch = fetchMock;
    const form = new FormData();
    form.set('payment_method', 'telebirr');
    form.set('reference_number', 'FT26TEST');

    await ordersApi.pay(44, form);

    expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/account/orders/44/pay');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');
    expect(fetchMock.mock.calls[0][1].body).toBe(form);
  });
});
