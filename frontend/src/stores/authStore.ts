import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { authService, type User } from '@/services/api';

interface MfaPending {
  mfaToken: string;
  rememberDevice: boolean;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  mfaPending: MfaPending | null;
  adminMfaVerified: boolean;
  isImpersonating: boolean;
  login: (email: string, password: string, trustedDeviceToken?: string | null) => Promise<void>;
  verifyAdminMfa: (code: string) => Promise<void>;
  resetAdminMfaVerification: () => void;
  googleLogin: (credential: string) => Promise<void>;
  verifyMfa: (code: string, rememberDevice?: boolean) => Promise<void>;
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

const ADMIN_MFA_STORAGE_KEY = 'admin_mfa_verified';
const TRUSTED_DEVICE_STORAGE_KEY = 'trusted_device_tokens';

const persistAdminMfaVerification = (userId: string): void => {
  localStorage.setItem(ADMIN_MFA_STORAGE_KEY, userId);
};

const clearAdminMfaVerification = (): void => {
  localStorage.removeItem(ADMIN_MFA_STORAGE_KEY);
};

const hasPersistedAdminMfaVerification = (userId?: string | null): boolean => {
  if (!userId) {
    return false;
  }

  return localStorage.getItem(ADMIN_MFA_STORAGE_KEY) === userId;
};

const getTrustedDeviceTokenMap = (): Record<string, string> => {
  try {
    const raw = localStorage.getItem(TRUSTED_DEVICE_STORAGE_KEY);
    if (!raw) {
      return {};
    }

    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
};

const getTrustedDeviceToken = (email: string): string | null => {
  return getTrustedDeviceTokenMap()[email.toLowerCase()] ?? null;
};

const setTrustedDeviceToken = (email: string, token: string): void => {
  const normalizedEmail = email.toLowerCase();
  const tokens = getTrustedDeviceTokenMap();
  tokens[normalizedEmail] = token;
  localStorage.setItem(TRUSTED_DEVICE_STORAGE_KEY, JSON.stringify(tokens));
};

const clearTrustedDeviceToken = (email?: string | null): void => {
  if (!email) {
    return;
  }

  const normalizedEmail = email.toLowerCase();
  const tokens = getTrustedDeviceTokenMap();
  if (!(normalizedEmail in tokens)) {
    return;
  }

  delete tokens[normalizedEmail];
  localStorage.setItem(TRUSTED_DEVICE_STORAGE_KEY, JSON.stringify(tokens));
};

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => {
      const hardLogout = () => {
        authService.clearToken();
        clearAdminMfaVerification();
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

      login: async (email: string, password: string, trustedDeviceToken?: string | null) => {
        try {
          const response = await authService.login({ email, password, trusted_device_token: trustedDeviceToken ?? getTrustedDeviceToken(email) });

          if (response.data.requires_mfa && response.data.mfa_token) {
            set({
              mfaPending: { mfaToken: response.data.mfa_token, rememberDevice: true },
              isLoading: false,
            });
            return;
          }

          const { user, token: tokenData } = response.data;
          const accessToken = tokenData!.access_token;
          const returnedTrustedDeviceToken = response.data.trusted_device_token;

          authService.setToken(accessToken);
          if (returnedTrustedDeviceToken) {
            setTrustedDeviceToken(email, returnedTrustedDeviceToken);
          }

          set({
            user: user!,
            token: accessToken,
            isAuthenticated: true,
            isLoading: false,
            mfaPending: null,
            adminMfaVerified: hasPersistedAdminMfaVerification(user!.id),
          });
        } catch (error) {
          set({ isLoading: false });
          throw error;
        }
      },

      googleLogin: async (credential: string) => {
        try {
          const response = await authService.googleAuth(credential);

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
            adminMfaVerified: hasPersistedAdminMfaVerification(user!.id),
          });
        } catch (error) {
          set({ isLoading: false });
          throw error;
        }
      },

      verifyMfa: async (code: string, rememberDevice = true) => {
        const { mfaPending } = get();
        if (!mfaPending) throw new Error('No MFA session pending');
        try {
          const response = await authService.verifyMfa(mfaPending.mfaToken, code, rememberDevice);
          const { user, token: tokenData } = response.data;
          const accessToken = tokenData!.access_token;
          const returnedTrustedDeviceToken = response.data.trusted_device_token;

          authService.setToken(accessToken);
          if (rememberDevice && returnedTrustedDeviceToken) {
            setTrustedDeviceToken(user!.email, returnedTrustedDeviceToken);
          }

          set({
            user: user!,
            token: accessToken,
            isAuthenticated: true,
            isLoading: false,
            mfaPending: null,
            adminMfaVerified: hasPersistedAdminMfaVerification(user!.id),
          });
        } catch (error) {
          throw error;
        }
      },

      verifyAdminMfa: async (code: string) => {
        const { user } = get();
        await authService.checkMfa(code);
        if (user?.id) {
          persistAdminMfaVerification(user.id);
        }
        set({ adminMfaVerified: true, mfaPending: null });
      },

      resetAdminMfaVerification: () => {
        clearAdminMfaVerification();
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
            adminMfaVerified: hasPersistedAdminMfaVerification(user.id),
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
          set({
            user,
            token: latestToken,
            isAuthenticated: true,
            isLoading: false,
            isImpersonating: hasOriginalAdminToken,
            adminMfaVerified: !hasOriginalAdminToken && hasPersistedAdminMfaVerification(user.id),
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
      name: 'vectorpagamentos-auth',
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
