import { api } from '@/lib/api';

export interface Checkout {
  id: string;
  account_id: string;
  name: string;
  slug: string;
  description?: string;
  type: 'single_product' | 'multiple_products' | 'donation' | 'subscription';
  products?: Array<{
    product_id: string;
    quantity: number;
    price_override?: number;
  }>;
  settings: {
    theme?: 'light' | 'dark';
    primary_color?: string;
    logo_url?: string;
    success_url?: string;
    cancel_url?: string;
    collect_phone?: boolean;
    collect_address?: boolean;
    payment_methods?: string[];
    max_installments?: number;
  };
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateCheckoutData {
  name: string;
  slug?: string;
  description?: string;
  type: 'single_product' | 'multiple_products' | 'donation' | 'subscription';
  products?: Array<{
    product_id: string;
    quantity: number;
    price_override?: number;
  }>;
  settings?: Checkout['settings'];
  is_active?: boolean;
}

export interface UpdateCheckoutData extends Partial<CreateCheckoutData> {}

export interface CheckoutListParams {
  page?: number;
  per_page?: number;
  type?: string;
  is_active?: boolean;
  search?: string;
}

export interface CheckoutListResponse {
  success: boolean;
  data: Checkout[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const checkoutsService = {
  async list(params?: CheckoutListParams): Promise<CheckoutListResponse> {
    const response = await api.get<CheckoutListResponse>('/checkouts', { params });
    return response.data;
  },

  async get(id: string): Promise<{ success: boolean; data: Checkout }> {
    const response = await api.get(`/checkouts/${id}`);
    return response.data;
  },

  async getBySlug(slug: string): Promise<{ success: boolean; data: Checkout }> {
    const response = await api.get(`/checkout/${slug}`);
    return response.data;
  },

  async create(data: CreateCheckoutData): Promise<{ success: boolean; data: Checkout }> {
    const response = await api.post('/checkouts', data);
    return response.data;
  },

  async update(id: string, data: UpdateCheckoutData): Promise<{ success: boolean; data: Checkout }> {
    const response = await api.put(`/checkouts/${id}`, data);
    return response.data;
  },

  async delete(id: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/checkouts/${id}`);
    return response.data;
  },
};
