import { api } from './client';

export interface AdminHealth {
  ok: true;
  commerce: {
    gmv_30d_formatted: string;
    orders_30d: number;
    aov_30d_formatted: string;
    active_orders_30d: number;
    completed_orders_30d: number;
    platform_revenue_mtd_formatted: string;
    promotion_revenue_mtd_formatted: string;
    subscription_revenue_mtd_formatted: string;
    payment_backlog_formatted: string;
    payment_backlog_count: number;
  };
  supply: {
    active_vendors: number;
    active_listings: number;
    avg_listings_per_vendor: number;
    new_vendors_30d: number;
    activated_new_vendors_30d: number;
    activation_rate_30d: number | null;
  };
  liquidity: {
    median_first_inquiry_hours: number | null;
    zero_traction_older_listings: number;
    older_active_listings: number;
    zero_traction_share: number | null;
  };
  demand: {
    top_searches: Array<{ query: string; searches: number; zeroes: number }>;
    zero_searches: Array<{ query: string; zeroes: number; last_seen: string }>;
  };
  trust: {
    reports_30d: number;
    reports_7d: number;
    open_reports: number;
    closed_reports_30d: number;
    suspicious_flags: number;
    suspicious_breakdown: Record<string, number>;
  };
}

export interface AdminMonetization {
  ok: true;
  top_pin_packages: Record<string, { label: string; price: number; duration_days: number }>;
  boost_tiers: Record<string, { label: string; price: number; rank_weight: number; benefits: string[] }>;
  top_pin_stats: {
    total: number;
    pending: number;
    active: number;
    scheduled: number;
    value_formatted: string;
  };
  boost_stats: {
    total: number;
    pending: number;
    active: number;
    expired: number;
  };
  revenue_30d: {
    top_pin_formatted: string;
    boost_formatted: string;
  };
  pending_payments: Array<{
    id: number;
    business_name: string | null;
    payment_type: string;
    promotion_type: string | null;
    subscription_plan: string | null;
    amount: number;
    amount_formatted: string;
    payment_method: string;
    reference_number: string | null;
    created_at: string;
  }>;
  php_admin_url: string;
}

export const adminApi = {
  health: () => api.get<AdminHealth>('/admin/health'),
  monetization: () => api.get<AdminMonetization>('/admin/monetization'),
};
