import { api } from '@/lib/api';

// ---- Types ----

export interface MessagingProvider {
  id: string;
  channel: 'whatsapp' | 'sms';
  provider_name: string;
  api_url: string | null;
  masked_api_key: string;
  instance_name: string | null;
  masked_token: string;
  sender_number: string | null;
  is_active: boolean;
  settings: Record<string, any> | null;
  messages_sent: number;
  messages_failed: number;
  last_sent_at: string | null;
  created_at: string;
}

export interface MessageTemplate {
  id: string;
  name: string;
  channel: 'whatsapp' | 'sms';
  trigger_event: string;
  message_body: string;
  is_active: boolean;
  times_sent: number;
  provider: { id: string; name: string; channel: string } | null;
  created_at: string;
}

export interface MessageLog {
  id: string;
  channel: 'whatsapp' | 'sms';
  recipient_phone: string;
  recipient_name: string | null;
  message_body: string;
  trigger_event: string | null;
  status: 'pending' | 'sent' | 'delivered' | 'failed' | 'read';
  created_at: string;
  provider: { id: string; provider_name: string; channel: string } | null;
}

export interface MessagingStats {
  total_sent: number;
  total_failed: number;
  whatsapp: { providers: number; active: number; sent: number; failed: number };
  sms: { providers: number; active: number; sent: number; failed: number };
  templates: { total: number; active: number };
  recent_logs: Record<string, number>;
}

export interface TriggerOption {
  value: string;
  label: string;
}

export interface WhatsAppQrCodeResponse {
  success: boolean;
  connected?: boolean;
  state?: string;
  base64?: string;
  code?: string;
  pairingCode?: string;
  message?: string;
}

export interface WhatsAppStatusResponse {
  configured: boolean;
  state: string;
  connected: boolean;
  message: string;
  provider_id?: string;
  instance_name?: string;
  phone?: string;
  owner?: string;
  profile_name?: string;
  profile_picture?: string;
}

export interface Contact {
  id: string;
  name: string;
  phone: string;
  email?: string;
  tags?: string[];
  list_id?: string;
  created_at: string;
}

export interface ContactList {
  id: string;
  name: string;
  description?: string;
  contacts_count: number;
  created_at: string;
}

export interface Campaign {
  id: string;
  name: string;
  status: 'draft' | 'scheduled' | 'sending' | 'completed' | 'paused' | 'failed';
  template_id?: string;
  template_name?: string;
  list_id?: string;
  list_name?: string;
  message_body: string;
  media_url?: string;
  media_type?: 'image' | 'video' | 'document' | 'audio';
  total_recipients: number;
  sent_count: number;
  delivered_count: number;
  read_count: number;
  failed_count: number;
  scheduled_at?: string;
  started_at?: string;
  completed_at?: string;
  created_at: string;
}

// ---- Service ----

export const messagingService = {
  // Stats
  async getStats(): Promise<MessagingStats> {
    const res = await api.get('/messaging/stats');
    return res.data.data;
  },

  // Providers
  async getProviders(): Promise<MessagingProvider[]> {
    const res = await api.get('/messaging/providers');
    return res.data.data;
  },

  async createProvider(data: Record<string, any>): Promise<MessagingProvider> {
    const res = await api.post('/messaging/providers', data);
    return res.data.data;
  },

  async updateProvider(id: string, data: Record<string, any>): Promise<MessagingProvider> {
    const res = await api.put(`/messaging/providers/${id}`, data);
    return res.data.data;
  },

  async deleteProvider(id: string): Promise<void> {
    await api.delete(`/messaging/providers/${id}`);
  },

  async autoSetupProvider(params: { channel: 'whatsapp' | 'sms' | 'both'; instance_name?: string }): Promise<{ data: MessagingProvider[]; qrcode?: string }> {
    const res = await api.post('/messaging/providers/auto-setup', params);
    return { data: res.data.data, qrcode: res.data.qrcode };
  },

  // WhatsApp Connection
  async getWhatsAppQrCode(): Promise<WhatsAppQrCodeResponse> {
    const res = await api.get('/messaging/whatsapp/qrcode');
    return res.data.data;
  },

  async getWhatsAppStatus(): Promise<WhatsAppStatusResponse> {
    const res = await api.get('/messaging/whatsapp/status');
    return res.data.data;
  },

  async disconnectWhatsApp(): Promise<void> {
    await api.post('/messaging/whatsapp/disconnect');
  },

  async restartWhatsApp(): Promise<void> {
    await api.post('/messaging/whatsapp/restart');
  },

  async testProvider(id: string): Promise<{ valid: boolean; message: string }> {
    const res = await api.post(`/messaging/providers/${id}/test`);
    return res.data.data;
  },

  async sendTestMessage(id: string, phone: string, message: string): Promise<{ status: string; message_id: string }> {
    const res = await api.post(`/messaging/providers/${id}/send-test`, { phone, message });
    return res.data.data;
  },

  // Single message dispatch
  async sendMessage(data: { phone: string; message: string; media_url?: string; media_type?: string }): Promise<{ status: string; message_id: string }> {
    const res = await api.post('/messaging/send', data);
    return res.data.data;
  },

  // Bulk dispatch
  async sendBulk(data: { phones: string[]; message: string; media_url?: string; media_type?: string; delay_ms?: number }): Promise<{ campaign_id: string; total: number }> {
    const res = await api.post('/messaging/send-bulk', data);
    return res.data.data;
  },

  // Campaigns
  async getCampaigns(params?: { status?: string; page?: number }): Promise<{ data: Campaign[]; meta: any }> {
    const res = await api.get('/messaging/campaigns', { params });
    return res.data;
  },

  async createCampaign(data: Record<string, any>): Promise<Campaign> {
    const res = await api.post('/messaging/campaigns', data);
    return res.data.data;
  },

  async pauseCampaign(id: string): Promise<void> {
    await api.post(`/messaging/campaigns/${id}/pause`);
  },

  async resumeCampaign(id: string): Promise<void> {
    await api.post(`/messaging/campaigns/${id}/resume`);
  },

  async deleteCampaign(id: string): Promise<void> {
    await api.delete(`/messaging/campaigns/${id}`);
  },

  // Contact Lists
  async getContactLists(): Promise<ContactList[]> {
    const res = await api.get('/messaging/contacts/lists');
    return res.data.data;
  },

  async createContactList(data: { name: string; description?: string }): Promise<ContactList> {
    const res = await api.post('/messaging/contacts/lists', data);
    return res.data.data;
  },

  async deleteContactList(id: string): Promise<void> {
    await api.delete(`/messaging/contacts/lists/${id}`);
  },

  async getContacts(params?: { list_id?: string; search?: string; page?: number; per_page?: number }): Promise<{ data: Contact[]; meta: any }> {
    const res = await api.get('/messaging/contacts', { params });
    return res.data;
  },

  async createContact(data: { name: string; phone: string; email?: string; tags?: string[]; list_id?: string }): Promise<Contact> {
    const res = await api.post('/messaging/contacts', data);
    return res.data.data;
  },

  async importContacts(data: { contacts: { name: string; phone: string; email?: string }[]; list_id?: string }): Promise<{ imported: number; duplicates: number }> {
    const res = await api.post('/messaging/contacts/import', data);
    return res.data.data;
  },

  async deleteContact(id: string): Promise<void> {
    await api.delete(`/messaging/contacts/${id}`);
  },

  // Templates
  async getTemplates(channel?: string): Promise<MessageTemplate[]> {
    const params = channel ? { channel } : {};
    const res = await api.get('/messaging/templates', { params });
    return res.data.data;
  },

  async createTemplate(data: Record<string, any>): Promise<MessageTemplate> {
    const res = await api.post('/messaging/templates', data);
    return res.data.data;
  },

  async updateTemplate(id: string, data: Record<string, any>): Promise<MessageTemplate> {
    const res = await api.put(`/messaging/templates/${id}`, data);
    return res.data.data;
  },

  async deleteTemplate(id: string): Promise<void> {
    await api.delete(`/messaging/templates/${id}`);
  },

  async getTriggers(): Promise<TriggerOption[]> {
    const res = await api.get('/messaging/templates/triggers');
    return res.data.data;
  },

  async getVariables(): Promise<Record<string, string>> {
    const res = await api.get('/messaging/templates/variables');
    return res.data.data;
  },

  // Logs
  async getLogs(params?: { channel?: string; status?: string; per_page?: number; page?: number }): Promise<{
    data: MessageLog[];
    meta: { current_page: number; per_page: number; total: number; last_page: number };
  }> {
    const res = await api.get('/messaging/logs', { params });
    return res.data;
  },
};
