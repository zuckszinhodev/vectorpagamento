import { api } from '@/lib/api';

export interface RegisterData {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface LoginData {
  email: string;
  password: string;
  trusted_device_token?: string | null;
}

export interface AuthResponse {
  success: boolean;
  message?: string;
  data: {
    requires_mfa?: boolean;
    mfa_token?: string;
    user?: {
      id: string;
      name: string;
      email: string;
      account_id: string;
      role?: string;
      permissions?: string[];
      kyc_status?: 'pending' | 'submitted' | 'under_review' | 'approved' | 'rejected';
      mfa_enabled?: boolean;
    };
    token?: {
      access_token: string;
      token_type: string;
      expires_in: number;
    };
    trusted_device_token?: string | null;
  };
}

export interface User {
  id: string;
  name: string;
  email: string;
  phone?: string | null;
  account_id: string;
  role?: string;
  permissions?: string[];
  kyc_status?: 'pending' | 'submitted' | 'under_review' | 'approved' | 'rejected';
  mfa_enabled?: boolean;
}

export const authService = {
  async register(data: RegisterData): Promise<AuthResponse> {
    const response = await api.post<AuthResponse>('/auth/register', data);
    return response.data;
  },

  async login(data: LoginData): Promise<AuthResponse> {
    const response = await api.post<AuthResponse>('/auth/login', data);
    return response.data;
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout');
  },

  async me(): Promise<{ success: boolean; data: User }> {
    const response = await api.get('/auth/me');
    return response.data;
  },

  async refreshToken(): Promise<{ token: string }> {
    const response = await api.post('/auth/refresh');
    return response.data;
  },

  setToken(token: string): void {
    localStorage.setItem('auth_token', token);
  },

  getToken(): string | null {
    return localStorage.getItem('auth_token');
  },

  clearToken(): void {
    localStorage.removeItem('auth_token');
  },

  async changePassword(data: { current_password: string; password: string; password_confirmation: string }): Promise<{ success: boolean; data: { message: string } }> {
    const response = await api.post('/auth/change-password', data);
    return response.data;
  },

  async enableMfa(): Promise<{ success: boolean; data: { secret: string; qr_code_url: string; issuer?: string; message: string } }> {
    const response = await api.post('/auth/mfa/enable');
    return response.data;
  },

  async confirmMfa(code: string): Promise<{ success: boolean; data: { message: string } }> {
    const response = await api.post('/auth/mfa/confirm', { code });
    return response.data;
  },

  async disableMfa(code: string): Promise<{ success: boolean; data: { message: string } }> {
    const response = await api.post('/auth/mfa/disable', { code });
    return response.data;
  },

  async verifyMfa(mfaToken: string, code: string, rememberDevice = false): Promise<AuthResponse> {
    const response = await api.post<AuthResponse>('/auth/mfa/verify', { mfa_token: mfaToken, code, remember_device: rememberDevice });
    return response.data;
  },

  async checkMfa(code: string): Promise<{ success: boolean; data: { message: string } }> {
    const response = await api.post('/auth/mfa/check', { code });
    return response.data;
  },

  async googleAuth(credential: string): Promise<AuthResponse> {
    const response = await api.post<AuthResponse>('/auth/google', { credential });
    return response.data;
  },
};
