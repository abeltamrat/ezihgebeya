import { api } from './client';

export interface CartItem {
  type: 'product' | 'supply';
  id: number;
  title: string;
  slug: string;
  price: number;
  qty: number;
  unit: string;
  line: number;
}

export interface CartGroup {
  business_id: number;
  business_name: string;
  items: CartItem[];
  subtotal: number;
}

export interface CartState {
  ok: true;
  enabled: boolean;
  groups: CartGroup[];
  grand_total: number;
  cart_count: number;
}

export const cartApi = {
  get: () => api.get<CartState>('/cart'),
  add: (listingType: 'product' | 'supply', listingId: number, qty = 1) =>
    api.post<CartState>('/cart', { do: 'add', listing_type: listingType, listing_id: listingId, qty }),
  update: (listingType: 'product' | 'supply', listingId: number, qty: number) =>
    api.post<CartState>('/cart', { do: 'update', listing_type: listingType, listing_id: listingId, qty }),
  remove: (listingType: 'product' | 'supply', listingId: number) =>
    api.post<CartState>('/cart', { do: 'remove', listing_type: listingType, listing_id: listingId }),
};

export interface CheckoutInfo {
  ok: true;
  groups: CartGroup[];
  grand_total: number;
  phone: string | null;
  cities: string[];
  subcities: Record<string, string[]>;
  payment_methods: Record<string, string>;
  payment_instructions: string;
}

export interface CheckoutSubmission {
  delivery_option: 'pickup' | 'delivery';
  delivery_address: string;
  city: string;
  subcity: string;
  phone: string;
  note: string;
  payment_method: string;
}

export interface CheckoutResult {
  ok: true;
  order_numbers: string[];
  requires_proof: boolean;
}

export const checkoutApi = {
  info: () => api.get<CheckoutInfo>('/checkout'),
  submit: (body: CheckoutSubmission) => api.post<CheckoutResult>('/checkout', body),
};

export interface OrderPayment {
  id: number;
  amount: number;
  payment_method: string;
  reference_number: string | null;
  status: string;
  created_at: string;
}

export interface OrderItem {
  title: string;
  unit_price: number;
  quantity: number;
  line_total: number;
}

export interface Order {
  id: number;
  order_number: string;
  business_name: string;
  business_phone: string;
  status: string;
  delivery_option: string;
  payment_method: string;
  created_at: string;
  subtotal: number;
  total: number;
  items: OrderItem[];
  payments: OrderPayment[];
  can_submit_payment: boolean;
  can_cancel: boolean;
}

export const ordersApi = {
  list: () => api.get<{ ok: true; data: Order[] }>('/account/orders'),
  cancel: (orderId: number) => api.post<{ ok: true; order: Order }>(`/account/orders/${orderId}/cancel`),
  pay: (orderId: number, form: FormData) => api.post<{ ok: true; order: Order }>(`/account/orders/${orderId}/pay`, form),
};
