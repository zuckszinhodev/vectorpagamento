import { api } from '@/lib/api';

// ── Types ──────────────────────────────────────────────

export interface SplitRecipient {
  recipient_id: string;
  recipient_type: 'account' | 'affiliate' | 'external';
  type: 'percentage' | 'fixed';
  value: number;
  description?: string;
}

export interface SplitRule {
  id: string;
  account_id: string;
  name: string;
  product_id?: string | null;
  checkout_id?: string | null;
  is_default: boolean;
  is_active: boolean;
  priority: number;
  recipients: SplitRecipient[];
  charge_fee_to_producer: boolean;
  minimum_amount: number;
  auto_process: boolean;
  transfer_delay_hours: number;
  created_at: string;
  updated_at: string;
}

export interface CreateSplitRuleData {
  name: string;
  product_id?: string | null;
  checkout_id?: string | null;
  is_default?: boolean;
  is_active?: boolean;
  priority?: number;
  recipients: SplitRecipient[];
  charge_fee_to_producer?: boolean;
  minimum_amount?: number;
  auto_process?: boolean;
  transfer_delay_hours?: number;
}

export interface UpdateSplitRuleData extends Partial<CreateSplitRuleData> {}

export interface SplitConfig {
  enabled: boolean;
  default_split_type: 'percentage' | 'fixed';
  default_affiliate_commission: number;
  auto_process: boolean;
  minimum_amount: number;
}

export interface Split {
  id: string;
  transaction_id: string;
  recipient_id: string;
  recipient_type: 'account' | 'affiliate' | 'external';
  type: 'percentage' | 'fixed';
  amount: number;
  percentage?: number;
  fee_amount: number;
  net_amount: number;
  status: 'pending' | 'processing' | 'transferred' | 'failed';
  transferred_at?: string;
  created_at: string;
}

export interface SimulationRecipient {
  recipient_id: string;
  recipient_type: string;
  description: string;
  type: string;
  value: number;
  gross_amount: number;
  fee_amount: number;
  net_amount: number;
}

export interface SimulationResult {
  rule_id: string;
  rule_name: string;
  input_amount: number;
  fee_amount: number;
  distributable_amount: number;
  distributed_amount: number;
  remainder: number;
  recipients: SimulationRecipient[];
}

export interface ProcessPendingResult {
  processed: number;
  failed: number;
  errors: string[];
}

// ── Service ────────────────────────────────────────────

export const splitsService = {
  // Config (legacy)
  async getConfig(): Promise<{ success: boolean; data: SplitConfig }> {
    const response = await api.get('/splits/config');
    return response.data;
  },

  async updateConfig(data: SplitConfig): Promise<{ success: boolean; data: SplitConfig }> {
    const response = await api.put('/splits/config', data);
    return response.data;
  },

  // Rules CRUD
  async listRules(): Promise<{ success: boolean; data: SplitRule[] }> {
    const response = await api.get('/splits/rules');
    return response.data;
  },

  async getRule(id: string): Promise<{ success: boolean; data: SplitRule }> {
    const response = await api.get(`/splits/rules/${id}`);
    return response.data;
  },

  async createRule(data: CreateSplitRuleData): Promise<{ success: boolean; data: SplitRule; message: string }> {
    const response = await api.post('/splits/rules', data);
    return response.data;
  },

  async updateRule(id: string, data: UpdateSplitRuleData): Promise<{ success: boolean; data: SplitRule; message: string }> {
    const response = await api.put(`/splits/rules/${id}`, data);
    return response.data;
  },

  async deleteRule(id: string): Promise<{ success: boolean; message: string }> {
    const response = await api.delete(`/splits/rules/${id}`);
    return response.data;
  },

  // Simulation
  async simulate(ruleId: string, amount: number, feeAmount?: number): Promise<{ success: boolean; data: SimulationResult }> {
    const response = await api.post(`/splits/rules/${ruleId}/simulate`, {
      amount,
      fee_amount: feeAmount ?? 0,
    });
    return response.data;
  },

  // Charge splits
  async getChargeSplits(chargeId: string): Promise<{ success: boolean; data: Split[] }> {
    const response = await api.get(`/splits/charges/${chargeId}`);
    return response.data;
  },

  // Process pending
  async processPending(): Promise<{ success: boolean; data: ProcessPendingResult }> {
    const response = await api.post('/splits/process-pending');
    return response.data;
  },
};

