import { api } from '@/lib/api';

export interface Product {
  id: string;
  account_id: string;
  category_id?: string;
  name: string;
  description?: string;
  type: 'physical' | 'digital' | 'service';
  price: number;
  currency: string;
  images?: string[];
  stock_quantity?: number;
  sku?: string;
  is_active: boolean;
  metadata?: Record<string, any>;
  created_at: string;
  updated_at: string;
}

export interface CreateProductData {
  category_id?: string;
  name: string;
  description?: string;
  type: 'physical' | 'digital' | 'service';
  price: number;
  images?: string[];
  stock_quantity?: number;
  sku?: string;
  is_active?: boolean;
  metadata?: Record<string, any>;
}

export interface UpdateProductData extends Partial<CreateProductData> {}

export interface ProductListParams {
  page?: number;
  per_page?: number;
  category_id?: string;
  type?: string;
  is_active?: boolean;
  search?: string;
}

export interface ProductListResponse {
  success: boolean;
  data: Product[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const productsService = {
  async list(params?: ProductListParams): Promise<ProductListResponse> {
    const response = await api.get<ProductListResponse>('/products', { params });
    return response.data;
  },

  async get(id: string): Promise<{ success: boolean; data: Product }> {
    const response = await api.get(`/products/${id}`);
    return response.data;
  },

  async create(data: CreateProductData): Promise<{ success: boolean; data: Product }> {
    const response = await api.post('/products', data);
    return response.data;
  },

  async update(id: string, data: UpdateProductData): Promise<{ success: boolean; data: Product }> {
    const response = await api.put(`/products/${id}`, data);
    return response.data;
  },

  async delete(id: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/products/${id}`);
    return response.data;
  },
};
