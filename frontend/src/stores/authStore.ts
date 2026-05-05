import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { authService, type User } from '@/services/api';

interface MfaPending {
  mfaToken: string;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  mfaPending: MfaPending | null;
  adminMfaVerified: boolean;
  isImpersonating: boolean;
  login: (email: string, password: string) => Promise<void>;
  verifyAdminMfa: (code: string) => Promise<void>;
  resetAdminMfaVerification: () => void;
  verifyMfa: (code: string) => Promise<void>;
  cancelMfa: () => void;
  register: (data: RegisterData) => Promise<void>;
  logout: () => void;
  checkAuth: () => Promise<void>;
  updateUser: (user: Partial<User>) => void;
  startImpersonation: (token: string) => Promise<void>;
  stopImpersonation: () => void;
}

interface RegisterData {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => {
      const hardLogout = () => {
        authService.clearToken();
        sessionStorage.removeItem('admin_mfa_verified');
        sessionStorage.removeItem('admin_original_token');

        set({
          user: null,
          token: null,
          isAuthenticated: false,
          isLoading: false,
          isImpersonating: false,
          adminMfaVerified: false,
          mfaPending: null,
        });
      };

      return {
      user: null,
      token: null,
      isAuthenticated: false,
      isLoading: true,
      mfaPending: null,
      adminMfaVerified: false,
      isImpersonating: false,

      login: async (email: string, password: string) => {
        try {
          const response = await authService.login({ email, password });

          if (response.data.requires_mfa && response.data.mfa_token) {
            set({
              mfaPending: { mfaToken: response.data.mfa_token },
              isLoading: false,
            });
            return;
          }

          const { user, token: tokenData } = response.data;
          const accessToken = tokenData!.access_token;

          authService.setToken(accessToken);

          set({
            user: user!,
            token: accessToken,
            isAuthenticated: true,
            isLoading: false,
            mfaPending: null,
            adminMfaVerified: false,
          });
        } catch (error) {
          set({ isLoading: false });
          throw error;
        }
      },

      verifyMfa: async (code: string) => {
        const { mfaPending } = get();
        if (!mfaPending) throw new Error('No MFA session pending');
        try {
          const response = await authService.verifyMfa(mfaPending.mfaToken, code);
          const { user, token: tokenData } = response.data;
          const accessToken = tokenData!.access_token;

          authService.setToken(accessToken);

          set({
            user: user!,
            token: accessToken,
            isAuthenticated: true,
            isLoading: false,
            mfaPending: null,
            adminMfaVerified: false,
          });
        } catch (error) {
          throw error;
        }
      },

      verifyAdminMfa: async (code: string) => {
        await authService.checkMfa(code);
        // Use sessionStorage with TTL (15 min) — cleared on tab close
        const expiresAt = Date.now() + 15 * 60 * 1000;
        sessionStorage.setItem('admin_mfa_verified', String(expiresAt));
        set({ adminMfaVerified: true, mfaPending: null });
      },

      resetAdminMfaVerification: () => {
        sessionStorage.removeItem('admin_mfa_verified');
        set({ adminMfaVerified: false });
      },

      cancelMfa: () => {
        set({ mfaPending: null });
      },

      register: async (data: RegisterData) => {
        try {
          const response = await authService.register(data);
          const { user, token: tokenData } = response.data;
          const accessToken = tokenData.access_token;

          // Store token in localStorage for API interceptor
          authService.setToken(accessToken);

          set({
            user,
            token: accessToken,
            isAuthenticated: true,
            isLoading: false,
            adminMfaVerified: false,
          });
        } catch (error) {
          set({ isLoading: false });
          throw error;
        }
      },

      logout: () => {
        const hasOriginalAdminToken = Boolean(sessionStorage.getItem('admin_original_token'));

        if (get().isImpersonating || hasOriginalAdminToken) {
          get().stopImpersonation();
          return;
        }

        authService.logout().catch(() => {});
        hardLogout();
      },

      checkAuth: async () => {
        // First check localStorage for token
        const storedToken = authService.getToken();
        const stateToken = get().token;
        const token = storedToken || stateToken;

        if (!token) {
          set({ isLoading: false, isAuthenticated: false });
          return;
        }

        // Ensure token is in localStorage for API interceptor
        if (!storedToken && stateToken) {
          authService.setToken(stateToken);
        }

        try {
          const response = await authService.me();
          const user = response.data;
          const hasOriginalAdminToken = Boolean(sessionStorage.getItem('admin_original_token'));

          // After a successful /me call, the token may have been refreshed
          // by the interceptor. Sync latest token from localStorage.
          const latestToken = authService.getToken() || token;
          // Check sessionStorage MFA with TTL validation
          const mfaExpiry = sessionStorage.getItem('admin_mfa_verified');
          const persistedAdminMfa = mfaExpiry ? Date.now() < Number(mfaExpiry) : false;
          if (!persistedAdminMfa) {
            sessionStorage.removeItem('admin_mfa_verified');
          }

          // Keep existing admin 2FA verification state on refresh
          const existingAdminMfaVerified = get().adminMfaVerified;

          set({
            user,
            token: latestToken,
            isAuthenticated: true,
            isLoading: false,
            isImpersonating: hasOriginalAdminToken,
            adminMfaVerified: persistedAdminMfa,
          });
        } catch {
          // Clear everything on auth failure
          hardLogout();
        }
      },

      updateUser: (userData: Partial<User>) => {
        const { user } = get();
        if (user) {
          set({ user: { ...user, ...userData } });
        }
      },

      startImpersonation: async (impersonateToken: string) => {
        // Save admin token so we can return later
        const adminToken = authService.getToken();
        if (adminToken && !sessionStorage.getItem('admin_original_token')) {
          sessionStorage.setItem('admin_original_token', adminToken);
        }

        // Set the impersonation token
        authService.setToken(impersonateToken);

        try {
          const response = await authService.me();
          const user = response.data;

          set({
            user,
            token: impersonateToken,
            isAuthenticated: true,
            isLoading: false,
            isImpersonating: true,
            adminMfaVerified: false,
            mfaPending: null,
          });
        } catch {
          // Restore admin token on failure
          if (adminToken) {
            authService.setToken(adminToken);
          }
          throw new Error('Falha ao impersonar usuário');
        }
      },

      stopImpersonation: () => {
        authService.logout().catch(() => {});

        const adminToken = sessionStorage.getItem('admin_original_token');
        sessionStorage.removeItem('admin_original_token');

        if (adminToken) {
          authService.setToken(adminToken);
          set({
            isImpersonating: false,
            isLoading: true,
          });
          // Re-check auth with admin token
          void get().checkAuth();
        } else {
          hardLogout();
        }
      },
    };
    },
    {
      name: 'wazerpay-auth',
      partialize: (state) => ({ token: state.token }),
      onRehydrateStorage: () => (state) => {
        // After hydration, sync token to localStorage and validate auth
        if (state?.token) {
          const storedToken = localStorage.getItem('auth_token');
          if (!storedToken) {
            localStorage.setItem('auth_token', state.token);
          }
        }
      },
    }
  )
);

