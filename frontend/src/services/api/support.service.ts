import { api } from '@/lib/api';

export type SupportTicketStatus = 'open' | 'pending' | 'resolved' | 'closed';
export type SupportTicketPriority = 'low' | 'normal' | 'high' | 'urgent';
export type SupportTicketCategory = 'financial' | 'technical' | 'acquirer' | 'kyc' | 'other';
export type SupportTicketAwaiting = 'admin' | 'seller';
export type SupportMessageAuthor = 'seller' | 'admin' | 'system';

export interface SupportTicketMessage {
  id: string;
  ticket_id: string;
  user_id: string;
  author_type: SupportMessageAuthor;
  body: string;
  is_internal_note: boolean;
  created_at: string;
  updated_at: string;
  author?: { id: string; name: string };
}

export interface SupportTicket {
  id: string;
  account_id: string;
  user_id: string;
  subject: string;
  category: SupportTicketCategory;
  priority: SupportTicketPriority;
  status: SupportTicketStatus;
  awaiting: SupportTicketAwaiting;
  assigned_admin_id?: string | null;
  last_reply_at?: string | null;
  resolved_at?: string | null;
  closed_at?: string | null;
  created_at: string;
  updated_at: string;
  messages?: SupportTicketMessage[];
  user?: { id: string; name: string; email: string };
  account?: { id: string; name: string };
  assigned_admin?: { id: string; name: string; email: string } | null;
}

export interface SupportTicketListResponse {
  success: boolean;
  data: SupportTicket[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
}

export const supportService = {
  // ─── Seller ───────────────────────────────────────────────

  async listMine(params?: { status?: SupportTicketStatus; per_page?: number }): Promise<SupportTicketListResponse> {
    const res = await api.get('/support/tickets', { params });
    return res.data;
  },

  async create(payload: {
    subject: string;
    body: string;
    category?: SupportTicketCategory;
    priority?: SupportTicketPriority;
  }): Promise<{ success: boolean; data: SupportTicket }> {
    const res = await api.post('/support/tickets', payload);
    return res.data;
  },

  async get(id: string): Promise<{ success: boolean; data: SupportTicket }> {
    const res = await api.get(`/support/tickets/${id}`);
    return res.data;
  },

  async reply(id: string, body: string): Promise<{ success: boolean; data: SupportTicketMessage }> {
    const res = await api.post(`/support/tickets/${id}/messages`, { body });
    return res.data;
  },

  async close(id: string): Promise<{ success: boolean; data: SupportTicket }> {
    const res = await api.patch(`/support/tickets/${id}/close`);
    return res.data;
  },

  // ─── Admin ────────────────────────────────────────────────

  async adminList(params?: {
    status?: SupportTicketStatus;
    priority?: SupportTicketPriority;
    category?: SupportTicketCategory;
    awaiting?: SupportTicketAwaiting;
    search?: string;
    per_page?: number;
  }): Promise<SupportTicketListResponse> {
    const res = await api.get('/admin/support/tickets', { params });
    return res.data;
  },

  async adminGet(id: string): Promise<{ success: boolean; data: SupportTicket }> {
    const res = await api.get(`/admin/support/tickets/${id}`);
    return res.data;
  },

  async adminReply(
    id: string,
    body: string,
    isInternalNote = false
  ): Promise<{ success: boolean; data: SupportTicketMessage }> {
    const res = await api.post(`/admin/support/tickets/${id}/messages`, {
      body,
      is_internal_note: isInternalNote,
    });
    return res.data;
  },

  async adminUpdate(
    id: string,
    updates: Partial<Pick<SupportTicket, 'status' | 'priority' | 'category' | 'assigned_admin_id'>>
  ): Promise<{ success: boolean; data: SupportTicket }> {
    const res = await api.patch(`/admin/support/tickets/${id}`, updates);
    return res.data;
  },
};

// ─── Labels em pt-BR compartilhados entre seller e admin ────

export const statusLabels: Record<SupportTicketStatus, { label: string; cls: string }> = {
  open: { label: 'Aberto', cls: 'bg-blue-500/10 text-blue-700 dark:text-blue-300' },
  pending: { label: 'Em andamento', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' },
  resolved: { label: 'Resolvido', cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' },
  closed: { label: 'Fechado', cls: 'bg-surface-hover text-tx-secondary' },
};

export const priorityLabels: Record<SupportTicketPriority, { label: string; cls: string }> = {
  low: { label: 'Baixa', cls: 'bg-surface-hover text-tx-secondary' },
  normal: { label: 'Normal', cls: 'bg-blue-500/10 text-blue-700 dark:text-blue-300' },
  high: { label: 'Alta', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' },
  urgent: { label: 'Urgente', cls: 'bg-red-500/10 text-red-700 dark:text-red-300' },
};

export const categoryLabels: Record<SupportTicketCategory, string> = {
  financial: 'Financeiro',
  technical: 'Técnico',
  acquirer: 'Adquirente',
  kyc: 'KYC',
  other: 'Outros',
};
