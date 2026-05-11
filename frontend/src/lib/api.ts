import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';
import toast from 'react-hot-toast';

// Configure API base URL to point to Laravel backend
const API_BASE_URL = import.meta.env.VITE_API_URL || 'https://api.vectorpagamentos.com/api/v1';

export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
});

// Token refresh state management
let isRefreshing = false;
let failedQueue: Array<{
  resolve: (token: string) => void;
  reject: (error: unknown) => void;
}> = [];

const processQueue = (error: unknown, token: string | null = null) => {
  failedQueue.forEach((prom) => {
    if (token) {
      prom.resolve(token);
    } else {
      prom.reject(error);
    }
  });
  failedQueue = [];
};

// Request interceptor
api.interceptors.request.use(
  (config) => {
    // Add auth token if available
    const token = localStorage.getItem('auth_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    // Add idempotency key for POST requests
    if (config.method === 'post' && !config.headers['X-Idempotency-Key']) {
      config.headers['X-Idempotency-Key'] = crypto.randomUUID();
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor with automatic token refresh
api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config as InternalAxiosRequestConfig & { _retry?: boolean };
    const code = (error.response?.data as any)?.error?.code;
    const requestUrl = originalRequest?.url || '';

    // Handle account blocked/suspended â€” immediate redirect
    if (error.response?.status === 403 && code === 'account_blocked') {
      const blockReason = (error.response?.data as any)?.error?.block_reason;
      localStorage.removeItem('auth_token');
      window.location.href = '/account-blocked';
      // Store block reason for the blocked page to read
      try { sessionStorage.setItem('block_reason', blockReason || ''); } catch (_) {}
      return Promise.reject(error);
    }

    // Handle 401 - try to refresh token before giving up
    if (error.response?.status === 401 && originalRequest && !originalRequest._retry) {
      // Don't try to refresh for login/register/refresh endpoints
      const isLoginEndpoint =
        requestUrl.includes('/auth/login') ||
        requestUrl.includes('/auth/register') ||
        requestUrl.includes('/auth/refresh');

      const isMfaChallengeEndpoint =
        requestUrl.includes('/auth/mfa/check') ||
        requestUrl.includes('/auth/mfa/verify');

      const isExpectedMfaAuthError =
        code === 'invalid_mfa_code' ||
        code === 'invalid_mfa_token' ||
        code === 'mfa_ip_mismatch' ||
        code === 'mfa_not_enabled';

      if (isLoginEndpoint || isMfaChallengeEndpoint || code === 'invalid_credentials' || isExpectedMfaAuthError) {
        return Promise.reject(error);
      }

      // If already refreshing, queue this request
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({
            resolve: (token: string) => {
              originalRequest.headers.Authorization = `Bearer ${token}`;
              resolve(api(originalRequest));
            },
            reject: (err: unknown) => {
              reject(err);
            },
          });
        });
      }

      originalRequest._retry = true;
      isRefreshing = true;

      try {
        const currentToken = localStorage.getItem('auth_token');
        if (!currentToken) {
          throw new Error('No token to refresh');
        }

        const response = await axios.post(
          `${API_BASE_URL}/auth/refresh`,
          {},
          {
            headers: {
              Authorization: `Bearer ${currentToken}`,
              Accept: 'application/json',
            },
          }
        );

        const newToken = response.data?.data?.token?.access_token;
        if (!newToken || typeof newToken !== 'string') {
          throw new Error('No token in refresh response');
        }

        localStorage.setItem('auth_token', newToken);
        isRefreshing = false;
        processQueue(null, newToken);

        // Retry original request with new token
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        return api(originalRequest);
      } catch (refreshError) {
        isRefreshing = false;
        processQueue(refreshError, null);

        // Refresh failed â€” clear auth and redirect
        localStorage.removeItem('auth_token');
        window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }

    if (error.response?.status === 429) {
      toast.error('Muitas requisiÃ§Ãµes. Aguarde um momento.');
    } else if (error.response?.status && error.response.status >= 500) {
      toast.error('Erro no servidor. Tente novamente mais tarde.');
    }

    return Promise.reject(error);
  }
);

// Helper functions
export const formatCurrency = (amount: number, currency = 'BRL'): string => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency,
  }).format(amount / 100);
};

export const formatDate = (date: string | Date): string => {
  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(date));
};

export const formatDateShort = (date: string | Date): string => {
  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
  }).format(new Date(date));
};

