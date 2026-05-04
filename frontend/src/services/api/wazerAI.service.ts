import { api } from '@/lib/api';

export interface WazerAIChatMessage {
  role: 'user' | 'assistant';
  content: string;
}

export interface WazerAISuggestion {
  text: string;
  category: string;
  icon?: string;
}

export interface WazerAIActionResult {
  success: boolean;
  message?: string;
  error?: string;
  data?: Record<string, unknown>;
}

export const wazerAIService = {
  async chat(
    message: string,
    history: WazerAIChatMessage[] = []
  ): Promise<{ success: boolean; data: { message: string; model?: string; action_result?: WazerAIActionResult | null } }> {
    const response = await api.post('/wazer-ai/chat', { message, history });
    return response.data;
  },

  async getSuggestions(): Promise<{ success: boolean; data: WazerAISuggestion[] }> {
    const response = await api.get('/wazer-ai/suggestions');
    return response.data;
  },

  async executeAction(
    action: string,
    params: Record<string, unknown>
  ): Promise<{ success: boolean; data: WazerAIActionResult }> {
    const response = await api.post('/wazer-ai/action', { action, params });
    return response.data;
  },
};
