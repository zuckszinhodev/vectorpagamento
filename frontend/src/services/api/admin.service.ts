import { api } from '@/lib/api';

export interface SystemStats {
  active_users: number;
  transactions_today: number;
  volume_today: number;
  success_rate: number;
}

export interface AdminUser {
  id: string;
  account_id: string;
  name: string;
  email: string;
  status: string;
  kyc_status: string;
  available_balance: number;
  created_at: string;
  role?: string;
}

export interface RecentActivity {
  id: string;
  type: string;
  action: string;
  user: string;
  email?: string;
  amount?: number;
  time: string;
  timestamp: string;
  status: 'success' | 'error' | 'info';
}

export interface AdminUsersResponse {
  success: boolean;
  data: AdminUser[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export const adminService = {
  async getSystemStats(): Promise<{ success: boolean; data: SystemStats }> {
    const response = await api.get('/admin/stats');
    return response.data;
  },

  async getUsers(params?: { page?: number; per_page?: number; search?: string; status?: string }): Promise<AdminUsersResponse> {
    const response = await api.get('/admin/customers', { params });
    return response.data;
  },

  async updateUserStatus(userId: string, status: 'active' | 'suspended' | 'blocked'): Promise<{ success: boolean; message: string }> {
    const response = await api.put(`/admin/customers/${userId}/status`, { status });
    return response.data;
  },

  async deleteUser(userId: string): Promise<{ success: boolean; message: string }> {
    const response = await api.delete(`/admin/customers/${userId}`);
    return response.data;
  },

  async getRecentActivities(limit?: number): Promise<{ success: boolean; data: RecentActivity[] }> {
    const response = await api.get('/admin/activities', { params: { limit } });
    return response.data;
  },
};
