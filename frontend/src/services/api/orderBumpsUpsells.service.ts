import { api } from '@/lib/api';

// ─── Order Bump Types ────────────────────────────────────────────────
export interface OrderBump {
  id: string;
  checkout_id: string;
  product_id: string;
  title: string;
  description?: string;
  call_to_action: string;
  image_url?: string;
  price: number;
  original_price?: number;
  discount_percent?: number;
  display_position: 'before_payment' | 'after_product' | 'sidebar';
  sort_order: number;
  is_active: boolean;
  is_pre_selected: boolean;
  settings: Record<string, unknown>;
  views_count: number;
  conversions_count: number;
  total_revenue: number;
  conversion_rate: number;
  product?: {
    id: string;
    name: string;
    price: number;
    image_url?: string;
  };
}

export interface CreateOrderBumpData {
  product_id: string;
  title: string;
  description?: string;
  call_to_action?: string;
  image_url?: string;
  price: number;
  original_price?: number;
  discount_percent?: number;
  display_position?: 'before_payment' | 'after_product' | 'sidebar';
  sort_order?: number;
  is_active?: boolean;
  is_pre_selected?: boolean;
  settings?: Record<string, unknown>;
}

export interface UpdateOrderBumpData extends Partial<CreateOrderBumpData> {}

// ─── Upsell Types ────────────────────────────────────────────────────
export interface UpsellOffer {
  id: string;
  checkout_id: string;
  product_id: string;
  title: string;
  description?: string;
  headline?: string;
  call_to_action: string;
  decline_text: string;
  image_url?: string;
  video_url?: string;
  price: number;
  original_price?: number;
  discount_percent?: number;
  type: 'upsell' | 'downsell';
  next_upsell_id?: string;
  downsell_id?: string;
  sort_order: number;
  is_active: boolean;
  one_click_purchase: boolean;
  timer_seconds?: number;
  settings: Record<string, unknown>;
  benefits: string[];
  views_count: number;
  conversions_count: number;
  total_revenue: number;
  conversion_rate: number;
  product?: {
    id: string;
    name: string;
    price: number;
    image_url?: string;
  };
}

export interface CreateUpsellData {
  product_id: string;
  title: string;
  description?: string;
  headline?: string;
  call_to_action?: string;
  decline_text?: string;
  image_url?: string;
  video_url?: string;
  price: number;
  original_price?: number;
  discount_percent?: number;
  type?: 'upsell' | 'downsell';
  next_upsell_id?: string;
  downsell_id?: string;
  sort_order?: number;
  is_active?: boolean;
  one_click_purchase?: boolean;
  timer_seconds?: number;
  settings?: Record<string, unknown>;
  benefits?: string[];
}

export interface UpdateUpsellData extends Partial<CreateUpsellData> {}

// ─── Services ────────────────────────────────────────────────────────
export const orderBumpsService = {
  async list(checkoutId: string): Promise<{ success: boolean; data: OrderBump[] }> {
    const response = await api.get(`/checkouts/${checkoutId}/order-bumps`);
    return response.data;
  },

  async get(checkoutId: string, bumpId: string): Promise<{ success: boolean; data: OrderBump }> {
    const response = await api.get(`/checkouts/${checkoutId}/order-bumps/${bumpId}`);
    return response.data;
  },

  async create(checkoutId: string, data: CreateOrderBumpData): Promise<{ success: boolean; data: OrderBump }> {
    const response = await api.post(`/checkouts/${checkoutId}/order-bumps`, data);
    return response.data;
  },

  async update(checkoutId: string, bumpId: string, data: UpdateOrderBumpData): Promise<{ success: boolean; data: OrderBump }> {
    const response = await api.put(`/checkouts/${checkoutId}/order-bumps/${bumpId}`, data);
    return response.data;
  },

  async delete(checkoutId: string, bumpId: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/checkouts/${checkoutId}/order-bumps/${bumpId}`);
    return response.data;
  },

  async reorder(checkoutId: string, order: string[]): Promise<{ success: boolean }> {
    const response = await api.post(`/checkouts/${checkoutId}/order-bumps/reorder`, { order });
    return response.data;
  },
};

export const upsellsService = {
  async list(checkoutId: string): Promise<{ success: boolean; data: UpsellOffer[] }> {
    const response = await api.get(`/checkouts/${checkoutId}/upsells`);
    return response.data;
  },

  async get(checkoutId: string, upsellId: string): Promise<{ success: boolean; data: UpsellOffer }> {
    const response = await api.get(`/checkouts/${checkoutId}/upsells/${upsellId}`);
    return response.data;
  },

  async create(checkoutId: string, data: CreateUpsellData): Promise<{ success: boolean; data: UpsellOffer }> {
    const response = await api.post(`/checkouts/${checkoutId}/upsells`, data);
    return response.data;
  },

  async update(checkoutId: string, upsellId: string, data: UpdateUpsellData): Promise<{ success: boolean; data: UpsellOffer }> {
    const response = await api.put(`/checkouts/${checkoutId}/upsells/${upsellId}`, data);
    return response.data;
  },

  async delete(checkoutId: string, upsellId: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/checkouts/${checkoutId}/upsells/${upsellId}`);
    return response.data;
  },

  async reorder(checkoutId: string, order: string[]): Promise<{ success: boolean }> {
    const response = await api.post(`/checkouts/${checkoutId}/upsells/reorder`, { order });
    return response.data;
  },
};

// ─── Public Checkout Upsell API (no auth) ────────────────────────────
export const publicUpsellService = {
  async getNextUpsell(chargeId: string): Promise<{ success: boolean; data: any }> {
    const response = await api.get(`/checkout/charge/${chargeId}/upsell`);
    return response.data;
  },

  async acceptUpsell(chargeId: string, upsellId: string): Promise<{ success: boolean; data: any }> {
    const response = await api.post(`/checkout/charge/${chargeId}/upsell/${upsellId}/accept`);
    return response.data;
  },

  async declineUpsell(chargeId: string, upsellId: string): Promise<{ success: boolean; data: any }> {
    const response = await api.post(`/checkout/charge/${chargeId}/upsell/${upsellId}/decline`);
    return response.data;
  },
};

