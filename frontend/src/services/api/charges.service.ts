import { api } from '@/lib/api';

export interface Charge {
  id: string;
  account_id: string;
  amount: number;
  currency: string;
  status: 'pending' | 'processing' | 'paid' | 'approved' | 'failed' | 'cancelled' | 'refunded' | 'expired' | 'chargeback';
  payment_method: 'credit_card' | 'debit_card' | 'pix' | 'boleto';
  customer: {
    name: string;
    email: string;
    document: string;
    phone?: string;
  };
  description?: string;
  metadata?: Record<string, any>;
  payment_details?: Record<string, any>;
  pix_qr_code?: string;
  pix_qr_code_url?: string;
  pix_copy_paste?: string;
  boleto_url?: string;
  boleto_barcode?: string;
  acquirer_name?: string;
  has_split?: boolean;
  split_amount?: number;
  split_recipients?: number;
  has_chargeback?: boolean;
  expires_at?: string;
  paid_at?: string;
  cancelled_at?: string;
  created_at: string;
  updated_at: string;
}

export interface CreateChargeData {
  amount: number;
  payment_method: 'credit_card' | 'pix' | 'boleto';
  customer: {
    name: string;
    email: string;
    document: string;
    phone?: string;
  };
  description?: string;
  metadata?: Record<string, any>;
  card_token?: string;
  installments?: number;
}

export interface ChargeListParams {
  page?: number;
  per_page?: number;
  status?: string;
  payment_method?: string;
  date_from?: string;
  date_to?: string;
  search?: string;
}

export interface ChargeListResponse {
  success: boolean;
  data: Charge[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const chargesService = {
  async list(params?: ChargeListParams): Promise<ChargeListResponse> {
    const response = await api.get<ChargeListResponse>('/charges', { params });
    return response.data;
  },

  async get(id: string): Promise<{ success: boolean; data: Charge }> {
    const response = await api.get(`/charges/${id}`);
    return response.data;
  },

  async create(data: CreateChargeData): Promise<{ success: boolean; data: Charge }> {
    const response = await api.post('/charges', data);
    return response.data;
  },

  async cancel(id: string): Promise<{ success: boolean; data: Charge }> {
    const response = await api.post(`/charges/${id}/cancel`);
    return response.data;
  },

  async refund(id: string, amount?: number): Promise<{ success: boolean; data: Charge }> {
    const response = await api.post(`/charges/${id}/refund`, { amount });
    return response.data;
  },

  async syncStatus(id: string): Promise<{ success: boolean; data: Charge; synced: boolean; message: string }> {
    const response = await api.post(`/charges/${id}/sync`);
    return response.data;
  },

  async syncAllPending(): Promise<{ success: boolean; message: string; data: { total_pending: number; synced: number; updated: number; errors: number } }> {
    const response = await api.post('/charges/sync-all');
    return response.data;
  },
};
