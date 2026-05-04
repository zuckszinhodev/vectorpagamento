import { api } from '@/lib/api';

export interface Notification {
  id: string;
  type: 'sale' | 'system' | 'warning' | 'info';
  title: string;
  message: string;
  data?: Record<string, any>;
  read: boolean;
  time: string;
  created_at: string;
}

export interface NotificationListResponse {
  success: boolean;
  data: Notification[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    unread_count: number;
  };
}

export interface UnreadCountResponse {
  success: boolean;
  data: {
    unread_count: number;
  };
}

export const notificationsService = {
  /**
   * Get all notifications for the authenticated user
   */
  async getNotifications(params?: { read?: boolean; per_page?: number }): Promise<NotificationListResponse> {
    const response = await api.get('/notifications', { params });
    return response.data;
  },

  /**
   * Get unread count
   */
  async getUnreadCount(): Promise<UnreadCountResponse> {
    const response = await api.get('/notifications/unread-count');
    return response.data;
  },

  /**
   * Mark a notification as read
   */
  async markAsRead(notificationId: string): Promise<{ success: boolean; message: string }> {
    const response = await api.post(`/notifications/${notificationId}/read`);
    return response.data;
  },

  /**
   * Mark all notifications as read
   */
  async markAllAsRead(): Promise<{ success: boolean; message: string }> {
    const response = await api.post('/notifications/read-all');
    return response.data;
  },

  /**
   * Delete a notification
   */
  async delete(notificationId: string): Promise<{ success: boolean; message: string }> {
    const response = await api.delete(`/notifications/${notificationId}`);
    return response.data;
  },

  /**
   * Clear all read notifications
   */
  async clearRead(): Promise<{ success: boolean; message: string }> {
    const response = await api.delete('/notifications/clear-read');
    return response.data;
  },
};
