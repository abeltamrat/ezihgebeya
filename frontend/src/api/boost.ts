import { api } from './client';

export interface TopPinPackage {
  label: string;
  price: number;
  duration_days: number;
}

export interface BoostTier {
  label: string;
  price: number;
  rank_weight: number;
  benefits: string[];
}

export interface BoostListing {
  type: 'product' | 'service' | 'supply';
  id: number;
  title: string;
  is_featured: boolean;
}

export interface TopPin {
  id: number;
  listing_type: string;
  listing_id: number;
  budget: number;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
}

export interface BoostSubscription {
  id: number;
  plan: string;
  months: number;
  status: string;
  starts_at: string | null;
  ends_at: string | null;
}

export interface BoostState {
  ok: true;
  verified: boolean;
  top_pin_packages: Record<string, TopPinPackage>;
  boost_tiers: Record<string, BoostTier>;
  current_boost: string | null;
  payment_methods: Record<string, string>;
  payment_instructions: string;
  listings: BoostListing[];
  top_pins: TopPin[];
  boost_subscriptions: BoostSubscription[];
}

export const boostApi = {
  get: () => api.get<BoostState>('/vendor/boost'),
  buyTopPin: (form: FormData) => api.post<{ ok: true; promotion_id: number; amount: number }>('/vendor/boost/top-pin', form),
  subscribe: (form: FormData) => api.post<{ ok: true; subscription_id: number; amount: number }>('/vendor/boost/subscribe', form),
  cancel: (kind: 'top_pin' | 'boost', id: number) => api.post<{ ok: true }>('/vendor/boost/cancel', { kind, id }),
};
