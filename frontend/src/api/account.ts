import { api } from './client';

export interface AccountSettings {
  ok: true;
  profile: {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    account_type: string;
    phone_verified: boolean;
    created_at: string;
  };
  capabilities: {
    marketing_preferences: boolean;
    notification_preferences: boolean;
  };
  marketing: {
    sms: boolean;
    email: boolean;
    push: boolean;
    updated_at: string | null;
  };
  categories: Array<{ key: string; label: string; enabled: boolean }>;
  php_settings_url: string;
  verify_phone_url: string;
}

export const ACCOUNT_EXPORT_URL = '/api/v1/account/export';

export interface FavoriteProduct {
  id: number;
  title: string;
  slug: string;
  url: string;
  image_url: string | null;
  price: string;
  old_price: string;
  city: string | null;
  subcity: string | null;
  category_name: string;
  business_name: string;
  business_verification: string;
  saved_at: string;
}

export interface AccountInquiry {
  id: number;
  business_name: string;
  business_slug: string;
  listing_type: string;
  listing_id: number | null;
  listing_title: string | null;
  inquiry_type: string;
  message: string | null;
  phone: string | null;
  preferred_contact_method: string;
  status: string;
  created_at: string;
  message_count: number;
}

export interface AccountInquiryMessage {
  id: number;
  sender_id: number;
  sender_name: string;
  body: string;
  read_at: string | null;
  created_at: string;
  mine: boolean;
}

export interface AccountNotification {
  id: number;
  type: string;
  title: string;
  body: string | null;
  url: string | null;
  read_at: string | null;
  created_at: string;
  unread: boolean;
}

export interface AccountReview {
  id: number;
  business_id: number;
  business_name: string;
  listing_type: string;
  listing_id: number | null;
  rating: number;
  title: string | null;
  comment: string | null;
  is_verified_purchase: boolean;
  status: string;
  vendor_reply: string | null;
  vendor_replied_at: string | null;
  created_at: string;
}

export interface AccountReport {
  id: number;
  reported_type: string;
  reported_id: number;
  reason: string;
  description: string | null;
  status: string;
  admin_note: string | null;
  created_at: string;
}

export const accountApi = {
  settings: () => api.get<AccountSettings>('/account/settings'),
  saveSettings: (body: { marketing: { sms: boolean; email: boolean; push: boolean }; categories: Record<string, boolean> }) =>
    api.post<AccountSettings>('/account/settings', body),
  favorites: () => api.get<{ ok: true; data: FavoriteProduct[] }>('/account/favorites'),
  saveFavorite: (productId: number) => api.post<{ ok: true; saved: boolean }>(`/account/favorites/${productId}`),
  removeFavorite: (productId: number) => api.del<{ ok: true; saved: boolean }>(`/account/favorites/${productId}`),
  inquiries: () => api.get<{ ok: true; data: AccountInquiry[] }>('/account/inquiries'),
  inquiry: (id: number) => api.get<{ ok: true; data: AccountInquiry; messages: AccountInquiryMessage[] }>(`/account/inquiries/${id}`),
  replyToInquiry: (id: number, body: string) => api.post<{ ok: true }>(`/account/inquiries/${id}/messages`, { body }),
  notifications: () => api.get<{ ok: true; unread_count: number; data: AccountNotification[] }>('/account/notifications'),
  markNotificationRead: (id: number) => api.post<{ ok: true; unread_count: number }>(`/account/notifications/${id}/read`),
  markAllNotificationsRead: () => api.post<{ ok: true; unread_count: number }>('/account/notifications/read-all'),
  reviews: () => api.get<{ ok: true; data: AccountReview[] }>('/account/reviews'),
  submitReview: (body: {
    business_id: number;
    listing_type: string;
    listing_id?: number | null;
    rating: number;
    title?: string;
    comment: string;
  }) => api.post<{ ok: true; data: AccountReview }>('/account/reviews', body),
  reports: () => api.get<{ ok: true; data: AccountReport[] }>('/account/reports'),
  submitReport: (body: { reported_type: string; reported_id: number; reason: string; description?: string }) =>
    api.post<{ ok: true; data: AccountReport }>('/account/reports', body),
  deleteAccount: (password: string, confirm: string) =>
    api.post<{ ok: true }>('/account/delete', { password, confirm }),
};
