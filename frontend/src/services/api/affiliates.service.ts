import { api } from '@/lib/api';

export interface Affiliate {
  id: string;
  account_id: string;
  name: string;
  email: string;
  document: string;
  phone?: string;
  code: string;
  commission_type: 'percentage' | 'fixed';
  commission_value: number;
  is_active: boolean;
  metadata?: Record<string, any>;
  created_at: string;
  updated_at: string;
}

export interface AffiliateStatistics {
  total_sales: number;
  total_commission: number;
  pending_commission: number;
  paid_commission: number;
  total_conversions: number;
  conversion_rate: number;
}

export interface CreateAffiliateData {
  name: string;
  email: string;
  document: string;
  phone?: string;
  code?: string;
  commission_type: 'percentage' | 'fixed';
  commission_value: number;
  is_active?: boolean;
  metadata?: Record<string, any>;
}

export interface UpdateAffiliateData extends Partial<CreateAffiliateData> {}

export interface AffiliateListParams {
  page?: number;
  per_page?: number;
  is_active?: boolean;
  search?: string;
}

export interface AffiliateListResponse {
  success: boolean;
  data: Affiliate[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const affiliatesService = {
  async list(params?: AffiliateListParams): Promise<AffiliateListResponse> {
    const response = await api.get<AffiliateListResponse>('/affiliates', { params });
    return response.data;
  },

  async get(id: string): Promise<{ success: boolean; data: Affiliate }> {
    const response = await api.get(`/affiliates/${id}`);
    return response.data;
  },

  async getStatistics(id: string): Promise<{ success: boolean; data: AffiliateStatistics }> {
    const response = await api.get(`/affiliates/${id}/stats`);
    return response.data;
  },

  async create(data: CreateAffiliateData): Promise<{ success: boolean; data: Affiliate }> {
    const response = await api.post('/affiliates', data);
    return response.data;
  },

  async update(id: string, data: UpdateAffiliateData): Promise<{ success: boolean; data: Affiliate }> {
    const response = await api.put(`/affiliates/${id}`, data);
    return response.data;
  },

  async delete(id: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/affiliates/${id}`);
    return response.data;
  },
};
