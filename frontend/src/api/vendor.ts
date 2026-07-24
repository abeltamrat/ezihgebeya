import { api } from './client';

export interface VendorDashboard {
  ok: true;
  business: {
    id: number;
    name: string;
    status: string;
    city: string;
    plan: string;
  } | null;
  stats?: Record<string, number>;
  recent_inquiries?: Array<{
    id: number;
    name: string | null;
    phone: string;
    status: string;
    listing_title: string | null;
    listing_type: string;
    message: string;
    created_at: string;
  }>;
}

export interface VendorBusiness {
  id: number;
  business_name: string;
  slug: string;
  business_type: string;
  description: string | null;
  phone: string;
  city: string;
  subcity: string | null;
  area_name: string | null;
  address: string | null;
  tin_number: string | null;
  license_number: string | null;
  logo_url: string | null;
  cover_image_url: string | null;
  verification_status: string;
  status: string;
  public_url: string | null;
}

export interface VendorBusinessState {
  ok: true;
  business: VendorBusiness | null;
  cities: string[];
  subcities: Record<string, string[]>;
  default_phone: string | null;
}

export type ListingType = 'product' | 'service' | 'supply';

export interface AttributeDef {
  id: number;
  category_id: number;
  key_name: string;
  label: string;
  input_type: 'text' | 'number' | 'select' | 'boolean';
  options: string | null; // JSON-encoded string[] for select
  unit: string | null;
  is_required: boolean | number;
  is_filterable: boolean | number;
  sort_order: number;
}

export interface ListingMeta {
  ok: true;
  categories: Array<{ id: number; name: string }>;
  attributes_by_category: Record<string, AttributeDef[]>;
  cities: string[];
  subcities: Record<string, string[]>;
  can_add_listing: boolean;
  plan: string;
  max_images_per_listing: number;
  max_image_upload_mb: number;
  ar_enabled: boolean;
  ar_allowed: boolean;
  ar_model_max_mb: number;
  model_conversion_enabled: boolean;
  model_conversion_formats: string[];
  model_conversion_max_source_mb: number;
  profile_location: {
    city: string;
    subcity: string;
  };
}

export interface Listing {
  id: number;
  type: ListingType;
  title: string;
  slug: string;
  public_url: string;
  category_id: number;
  category_name: string | null;
  description: string;
  city: string;
  subcity: string;
  status: string;
  is_featured: boolean;
  views: number;
  inquiries: number;
  attributes: Record<string, string | number | boolean>;
  created_at: string;
  updated_at: string;
  price: string | number | null;
  discount_price: string | number | null;
  product_type: string | null;
  condition_type: string | null;
  is_negotiable: boolean | null;
  stock_quantity: string | number | null;
  material: string | null;
  brand: string | null;
  color: string | null;
  dimensions: string | null;
  warranty: string | null;
  delivery_available: boolean | null;
  installation_available: boolean | null;
  customization_available: boolean | null;
  experience_years: string | number | null;
  price_type: string | null;
  grade: string | null;
  size: string | null;
  thickness: string | null;
  unit_of_measurement: string | null;
  bulk_price: string | number | null;
  minimum_order_quantity: string | number | null;
  images: Array<{ id: number; url: string; is_primary: boolean }>;
  ar_models: Array<{ type: 'glb' | 'usdz'; url: string }>;
  model_conversion: {
    id: number;
    source_name: string;
    source_format: string;
    source_size: number;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'cancelled';
    attempts: number;
    error: string | null;
    created_at: string;
    updated_at: string;
    completed_at: string | null;
  } | null;
}

export interface VendorInquiry {
  id: number;
  customer_id: number | null;
  listing_type: 'product' | 'service' | 'supply' | 'business' | 'video';
  listing_id: number | null;
  listing_title: string | null;
  inquiry_type: string;
  name: string | null;
  message: string | null;
  phone: string | null;
  preferred_contact_method: string;
  source: string;
  status: string;
  created_at: string;
  updated_at: string;
  message_count: number;
}

export interface VendorInquiryMessage {
  id: number;
  sender_id: number;
  sender_name: string;
  body: string;
  read_at: string | null;
  created_at: string;
  mine: boolean;
}

export interface VendorOrder {
  id: number;
  order_number: string;
  customer: string;
  customer_id: number;
  status: string;
  allowed_next_statuses: string[];
  delivery_option: string;
  delivery_address: string | null;
  city: string | null;
  subcity: string | null;
  phone: string | null;
  note: string | null;
  subtotal: string | number;
  delivery_fee: string | number;
  total: string | number;
  total_formatted: string;
  payment_method: string;
  created_at: string;
  items: Array<{
    id: number;
    listing_type: string;
    listing_id: number;
    title: string;
    unit_price: string | number;
    quantity: string | number;
    line_total: string | number;
    line_total_formatted: string;
  }>;
  payments: Array<{
    id: number;
    payer_id: number;
    amount: string | number;
    amount_formatted: string;
    currency: string;
    payment_method: string;
    reference_number: string | null;
    proof_url: string | null;
    status: string;
    created_at: string;
  }>;
}

export interface VendorVideo {
  id: number;
  platform: 'tiktok' | 'youtube';
  original_url: string;
  video_id: string | null;
  embed_url: string | null;
  title: string | null;
  linked_type: 'product' | 'service' | 'supply' | 'business';
  linked_id: number | null;
  cta_label: string;
  status: string;
  views_count: number;
  cta_clicks_count: number;
  created_at: string;
}

export interface VendorVideoMeta {
  ok: true;
  cta_labels: string[];
  can_add_video: boolean;
  plan: string;
  owned_listings: Record<'product' | 'service' | 'supply', Array<{ id: number; title: string }>>;
}

export interface VerificationRequest {
  id: number;
  requested_level: string;
  status: string;
  message: string | null;
  admin_note: string | null;
  created_at: string;
  updated_at: string;
  documents: Array<{ id: number; doc_type: string; label: string; url: string | null; created_at: string }>;
}

export interface VerificationState {
  ok: true;
  current_level: string;
  doc_types: Array<{ key: string; label: string }>;
  levels: Array<{ key: string; label: string }>;
  open: VerificationRequest | null;
  history: VerificationRequest[];
}

export interface VendorReview {
  id: number;
  reviewer_id: number;
  reviewer_name: string;
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

export interface VendorAnalytics {
  ok: true;
  // Tier-gated (PLAN.md "Gate the full dashboard behind Boost tiers"): 'basic' (free — totals/funnel
  // only), 'standard' (Boost Basic — adds money + top lists), 'full' (Boost Pro/Max — everything).
  analytics_level: 'basic' | 'standard' | 'full';
  upgrade_url: string;
  totals: Array<{ label: string; value: number | null; formatted?: string; suffix?: string }>;
  funnel: Array<{ label: string; count: number; dropoff_percent: number | null }>;
  money: {
    order_revenue_30d_formatted: string;
    average_order_value_30d_formatted: string;
    promotion_spend_30d_formatted: string;
    promoted_inquiries_30d: number;
    promoted_orders_30d: number;
  } | null;
  listings: Array<{
    listing_type: string;
    id: number;
    title: string;
    status: string;
    views30: number;
    views7: number;
    favorites30: number;
    inquiries30: number;
    inquiries7: number;
    orders30: number;
    revenue30_formatted: string;
  }>;
  top_products: Array<{ title: string; views: number; inquiries: number; favorites: number }>;
  lead_sources: Array<{ source: string; count: number }>;
  lead_statuses: Array<{ status: string; count: number }>;
  revenue_by_listing: Array<{ listing_type: string; listing_id: number; title: string; orders_count: number; revenue_formatted: string }>;
  reviews: {
    average_rating: number | null;
    average_rating_30d: number | null;
    reviews_30d: number;
    median_response_minutes: number | null;
    median_response_label: string | null;
  } | null;
  top_videos: Array<{ id: number; title: string; views: number; cta_clicks: number; ctr_percent: number | null }>;
}

export interface VendorSoftwareItem {
  id: number;
  title: string;
  slug: string;
  item_type: 'software' | 'plugin';
  short_description: string;
  description: string;
  version: string | null;
  developer: string | null;
  category: string | null;
  platforms: string[];
  license_type: string | null;
  delivery_type: 'file' | 'external';
  file_name: string | null;
  file_size: number | null;
  download_url: string;
  youtube_embed_url: string | null;
  is_featured: boolean;
  download_count: number;
  published_at: string | null;
  screenshots: Array<{ id: number; url: string; caption: string | null }>;
}

export interface VendorSoftwareLibrary {
  ok: true;
  data: VendorSoftwareItem[];
  categories: string[];
  platforms: string[];
}

export const vendorApi = {
  dashboard: () => api.get<VendorDashboard>('/vendor/dashboard'),
  business: () => api.get<VendorBusinessState>('/vendor/business'),
  saveBusiness: (form: FormData) => api.post<{ ok: true; business: VendorBusiness }>('/vendor/business', form),
  meta: (type: ListingType) => api.get<ListingMeta>(`/vendor/listings/${type}/meta`),
  list: (type: ListingType) => api.get<{ ok: true; data: Listing[] }>(`/vendor/listings/${type}`),
  get: (type: ListingType, id: number) => api.get<{ ok: true; data: Listing }>(`/vendor/listings/${type}/${id}`),
  create: (type: ListingType, body: Record<string, unknown>) =>
    api.post<{ ok: true; data: Listing }>(`/vendor/listings/${type}`, body),
  update: (type: ListingType, id: number, body: Record<string, unknown>) =>
    api.put<{ ok: true; data: Listing }>(`/vendor/listings/${type}/${id}`, body),
  remove: (type: ListingType, id: number) => api.del<{ ok: true }>(`/vendor/listings/${type}/${id}`),
  uploadImages: (type: ListingType, id: number, files: FileList | readonly File[]) => {
    const form = new FormData();
    Array.from(files).forEach((f) => form.append('images[]', f));
    return api.post<{ ok: true; uploaded: number }>(`/vendor/listings/${type}/${id}/images`, form);
  },
  uploadModels: (id: number, glb: File | null, usdz: File | null, source: File | null = null) => {
    const form = new FormData();
    if (glb) form.set('model_glb', glb);
    if (usdz) form.set('model_usdz', usdz);
    if (source) form.set('model_source', source);
    return api.post<{
      ok: true;
      uploaded: Array<'glb' | 'usdz'>;
      conversion: Listing['model_conversion'];
    }>(`/vendor/listings/product/${id}/models`, form);
  },
  setPrimaryImage: (id: number, imageId: number) =>
    api.post<{ ok: true }>(`/vendor/listings/product/${id}/images/${imageId}/primary`),
  deleteImage: (type: ListingType, id: number, imageId?: number) =>
    api.del<{ ok: true }>(`/vendor/listings/${type}/${id}/images${imageId ? `/${imageId}` : ''}`),
  inquiries: (status = '') =>
    api.get<{ ok: true; data: VendorInquiry[]; statuses: string[] }>(
      `/vendor/inquiries${status ? `?status=${encodeURIComponent(status)}` : ''}`,
    ),
  inquiry: (id: number) =>
    api.get<{ ok: true; data: VendorInquiry; messages: VendorInquiryMessage[] }>(`/vendor/inquiries/${id}`),
  updateInquiryStatus: (id: number, status: string) =>
    api.post<{ ok: true }>(`/vendor/inquiries/${id}/status`, { status }),
  replyToInquiry: (id: number, body: string) =>
    api.post<{ ok: true }>(`/vendor/inquiries/${id}/messages`, { body }),
  orders: () => api.get<{ ok: true; data: VendorOrder[]; statuses: string[] }>('/vendor/orders'),
  updateOrderStatus: (id: number, status: string) =>
    api.post<{ ok: true }>(`/vendor/orders/${id}/status`, { status }),
  confirmOrderPayment: (orderId: number, paymentId: number) =>
    api.post<{ ok: true }>(`/vendor/orders/${orderId}/payments/${paymentId}/confirm`),
  videoMeta: () => api.get<VendorVideoMeta>('/vendor/videos/meta'),
  videos: () => api.get<{ ok: true; data: VendorVideo[] }>('/vendor/videos'),
  createVideo: (body: Record<string, unknown>) =>
    api.post<{ ok: true; data: VendorVideo }>('/vendor/videos', body),
  deleteVideo: (id: number) => api.del<{ ok: true }>(`/vendor/videos/${id}`),
  verification: () => api.get<VerificationState>('/vendor/verification'),
  submitVerification: (form: FormData) =>
    api.post<{ ok: true; data: VerificationRequest }>('/vendor/verification', form),
  reviews: () =>
    api.get<{ ok: true; rating_average: number; rating_count: number; data: VendorReview[] }>('/vendor/reviews'),
  replyToReview: (id: number, reply: string) =>
    api.post<{ ok: true }>(`/vendor/reviews/${id}/reply`, { reply }),
  analytics: () => api.get<VendorAnalytics>('/vendor/analytics'),
  softwareLibrary: () => api.get<VendorSoftwareLibrary>('/vendor/software'),
};
