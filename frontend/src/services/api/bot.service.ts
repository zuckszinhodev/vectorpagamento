import { api } from '@/lib/api';

// ---- Types ----

export type BotMode = 'flow' | 'ai';

/** Ações que a IA só executa se o dono habilitar uma a uma. */
export type BotAiAction = 'lookup_order' | 'resend_pix' | 'resend_access' | 'create_payment_link';

export type BotNodeType =
  | 'message'
  | 'question'
  | 'condition'
  | 'delay'
  | 'set'
  | 'http'
  | 'system_data'
  | 'action'
  | 'ai'
  | 'handoff'
  | 'end';

export type BotConditionOperator =
  | 'equals'
  | 'not_equals'
  | 'contains'
  | 'not_contains'
  | 'starts_with'
  | 'is_empty'
  | 'is_not_empty'
  | 'greater_than'
  | 'less_than';

export interface BotNodeOption {
  label: string;
  next: string | null;
}

export interface BotKeyValue {
  key: string;
  value: string;
}

export interface BotNode {
  id: string;
  type: BotNodeType;
  text?: string;
  next?: string | null;
  /** Posição no canvas. Só o editor usa; o motor ignora. */
  position?: { x: number; y: number };
  /** question */
  options?: BotNodeOption[];
  save_as?: string;
  invalid_message?: string;
  /** condition — `subject` aceita nome de variável ou uma verificação do sistema */
  subject?: string;
  operator?: BotConditionOperator;
  value?: string;
  next_true?: string | null;
  next_false?: string | null;
  /** delay */
  duration?: number;
  unit?: 'seconds' | 'minutes' | 'hours' | 'days';
  /** set */
  assignments?: BotKeyValue[];
  /** http */
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  url?: string;
  headers?: BotKeyValue[];
  body?: string;
  save_path?: string;
  reply_with_message?: boolean;
  /** action */
  action?: BotAiAction;
  params?: Record<string, any>;
  next_error?: string | null;
}

export interface BotFlowData {
  nodes: BotNode[];
  start_node_id?: string | null;
}

export interface BotFaqItem {
  question: string;
  answer: string;
}

export interface BotAiConfig {
  agent_name?: string;
  business_name?: string;
  business_description?: string;
  products_description?: string;
  support_hours?: string;
  refund_policy?: string;
  tone?: string;
  topics?: string[];
  restrictions?: string[];
  faq?: BotFaqItem[];
  custom_instructions?: string;
  fallback_message?: string;
  off_hours_message?: string;
  allowed_actions?: BotAiAction[];
  temperature?: number;
}

export interface BotBusinessHoursDay {
  enabled: boolean;
  start: string;
  end: string;
}

export interface Bot {
  id: string;
  name: string;
  mode: BotMode;
  is_active: boolean;
  flow_data: BotFlowData;
  ai_config: BotAiConfig;
  trigger_keywords: string[];
  only_business_hours: boolean;
  business_hours: Record<string, BotBusinessHoursDay> | null;
  conversations_count: number;
  messages_count: number;
  created_at: string;
}

export interface BotListResponse {
  data: Bot[];
  ai_available: boolean;
  inbound_url: string | null;
}

export interface BotSimulateResponse {
  replies: string[];
  handoff: boolean;
  status: string;
  context: Record<string, any> | null;
}

export interface BotConversation {
  id: string;
  contact_phone: string;
  contact_name: string | null;
  status: 'active' | 'handoff' | 'ended';
  message_count: number;
  last_message_at: string | null;
  bot: { id: string; name: string; mode: BotMode } | null;
}

// ---- Service ----

export const botService = {
  async list(): Promise<BotListResponse> {
    const res = await api.get('/bots');
    return {
      data: res.data.data ?? [],
      ai_available: res.data.ai_available ?? false,
      inbound_url: res.data.inbound_url ?? null,
    };
  },

  async create(payload: Partial<Bot>): Promise<Bot> {
    const res = await api.post('/bots', payload);
    return res.data.data;
  },

  async update(id: string, payload: Partial<Bot>): Promise<Bot> {
    const res = await api.put(`/bots/${id}`, payload);
    return res.data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/bots/${id}`);
  },

  /** Roda o bot numa conversa de teste; não envia nada para fora. */
  async simulate(id: string, message: string, reset = false): Promise<BotSimulateResponse> {
    const res = await api.post(`/bots/${id}/simulate`, { message, reset });
    return res.data.data;
  },

  async conversations(): Promise<BotConversation[]> {
    const res = await api.get('/bots/conversations');
    return res.data.data ?? [];
  },

  async regenerateInboundToken(): Promise<string> {
    const res = await api.post('/bots/inbound-token');
    return res.data.data.inbound_url;
  },
};
