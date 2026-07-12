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
}

export const vendorApi = {
  dashboard: () => api.get<VendorDashboard>('/vendor/dashboard'),
  meta: (type: ListingType) => api.get<ListingMeta>(`/vendor/listings/${type}/meta`),
  list: (type: ListingType) => api.get<{ ok: true; data: Listing[] }>(`/vendor/listings/${type}`),
  get: (type: ListingType, id: number) => api.get<{ ok: true; data: Listing }>(`/vendor/listings/${type}/${id}`),
  create: (type: ListingType, body: Record<string, unknown>) =>
    api.post<{ ok: true; data: Listing }>(`/vendor/listings/${type}`, body),
  update: (type: ListingType, id: number, body: Record<string, unknown>) =>
    api.put<{ ok: true; data: Listing }>(`/vendor/listings/${type}/${id}`, body),
  remove: (type: ListingType, id: number) => api.del<{ ok: true }>(`/vendor/listings/${type}/${id}`),
  uploadImages: (type: ListingType, id: number, files: FileList) => {
    const form = new FormData();
    Array.from(files).forEach((f) => form.append('images[]', f));
    return api.post<{ ok: true; uploaded: number }>(`/vendor/listings/${type}/${id}/images`, form);
  },
};
