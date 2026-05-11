import { Routes, Route, Navigate, useLocation, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { useEffect, useRef, useState, lazy, Suspense } from 'react';
import { api } from '@/lib/api';
import { isCustomDomain } from '@/lib/domain';
import { PageSpinner } from '@/components/ui/Spinner';

// Layouts (carregados imediatamente)
import DashboardLayout from '@/layouts/DashboardLayout';
import AuthLayout from '@/layouts/AuthLayout';
import AdminLayout from '@/layouts/AdminLayout';

// Loading spinner reutilizavel
const PageLoader = () => <PageSpinner />;

// Auth Pages (lazy)
const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage'));
const ForgotPasswordPage = lazy(() => import('@/pages/auth/ForgotPasswordPage'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'));
const AccountBlockedPage = lazy(() => import('@/pages/auth/AccountBlockedPage'));

// Dashboard Pages (lazy)
const DashboardPage = lazy(() => import('@/pages/DashboardPage'));
const VitrinePage = lazy(() => import('@/pages/VitrinePage'));
const ChargesPage = lazy(() => import('@/pages/ChargesPage'));
const ChargebackPage = lazy(() => import('@/pages/ChargebackPage'));
const AcquirersPage = lazy(() => import('@/pages/AcquirersPage'));
const ProductsPage = lazy(() => import('@/pages/ProductsPage'));
const CheckoutsPage = lazy(() => import('@/pages/CheckoutsPage'));
const CheckoutBuilderPage = lazy(() => import('@/pages/CheckoutBuilderPage'));
const CheckoutPreviewPage = lazy(() => import('@/pages/CheckoutPreviewPage'));
const CheckoutAnalyticsPage = lazy(() => import('@/pages/CheckoutAnalyticsPage'));
const AffiliatesPage = lazy(() => import('@/pages/AffiliatesPage'));
const WithdrawalsPage = lazy(() => import('@/pages/WithdrawalsPage'));
const SettingsPage = lazy(() => import('@/pages/SettingsPage'));
const KYCPage = lazy(() => import('@/pages/KYCPage'));

// Admin Pages (lazy)
const AdminDashboard = lazy(() => import('@/pages/admin/AdminDashboard'));
const AdminControlTower = lazy(() => import('@/pages/admin/AdminControlTower'));
const AdminCustomers = lazy(() => import('@/pages/admin/AdminCustomers'));
const AdminCustomerDetail = lazy(() => import('@/pages/admin/AdminCustomerDetail'));
const AdminKYC = lazy(() => import('@/pages/admin/AdminKYC'));
const AdminFinance = lazy(() => import('@/pages/admin/AdminFinance'));
const AdminAcquirers = lazy(() => import('@/pages/admin/AdminAcquirers'));
const AdminSecurity = lazy(() => import('@/pages/admin/AdminSecurity'));
const AdminSettings = lazy(() => import('@/pages/admin/AdminSettings'));
const AdminBranding = lazy(() => import('@/pages/admin/AdminBranding'));
const AdminAPI = lazy(() => import('@/pages/admin/AdminAPI'));
const AdminMonitoring = lazy(() => import('@/pages/admin/AdminMonitoring'));
const AdminChargebacks = lazy(() => import('@/pages/admin/AdminChargebacks'));
const AdminApprovals = lazy(() => import('@/pages/admin/AdminApprovals'));
const LandingPagesPage = lazy(() => import('@/pages/LandingPagesPage'));
const LandingPageEditorPage = lazy(() => import('@/pages/LandingPageEditorPage'));
const MemberAreaPage = lazy(() => import('@/pages/MemberAreaPage'));
const CourseEditorPage = lazy(() => import('@/pages/CourseEditorPage'));
const DomainsPage = lazy(() => import('@/pages/DomainsPage'));
const IntegrationsPage = lazy(() => import('@/pages/IntegrationsPage'));
const MessagingPage = lazy(() => import('@/pages/MessagingPage'));
const SupportPage = lazy(() => import('@/pages/SupportPage'));
const AnalyticsPage = lazy(() => import('@/pages/AnalyticsPage'));
const CouponsPage = lazy(() => import('@/pages/CouponsPage'));
const ReportsPage = lazy(() => import('@/pages/ReportsPage'));
const SplitManagement = lazy(() => import('@/pages/SplitManagement'));
const WebhookLogsPage = lazy(() => import('@/pages/WebhookLogsPage'));
const VectorIAPage = lazy(() => import('@/pages/WazerIAPage'));

const BRAND_LOADER_MIN_DURATION_MS = 1200;

// Public Pages (lazy)
const PublicCheckoutPage = lazy(() => import('@/pages/public/CheckoutPage'));
const DomainLandingPage = lazy(() => import('@/pages/public/DomainLandingPage'));
const TermsPage = lazy(() => import('@/pages/TermsPage'));
const PrivacyPage = lazy(() => import('@/pages/PrivacyPage'));
const ApiDocsPage = lazy(() => import('@/pages/ApiDocsPage'));

// Member Area Pages (lazy)
const MemberLoginPage = lazy(() => import('@/pages/members/MemberLoginPage'));
const MemberDashboardPage = lazy(() => import('@/pages/members/MemberDashboardPage'));
const MemberCoursePage = lazy(() => import('@/pages/members/MemberCoursePage'));
const MemberProfilePage = lazy(() => import('@/pages/members/MemberProfilePage'));

// Protected Route Component
const ProtectedRoute = ({ children }: { children: React.ReactNode }) => {
  const { isAuthenticated, isLoading } = useAuthStore();

  if (isLoading) {
    return <PageSpinner />;
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
};

const AdminMfaChallenge = () => {
  const [codeDigits, setCodeDigits] = useState(['', '', '', '', '', '']);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [attempts, setAttempts] = useState(0);
  const [lockedUntil, setLockedUntil] = useState<number | null>(null);
  const pinRefs = useRef<Array<HTMLInputElement | null>>([]);
  const lastSubmittedCode = useRef<string>('');
  const { verifyAdminMfa } = useAuthStore();

  const MAX_ATTEMPTS = 5;
  const LOCKOUT_MS = 5 * 60 * 1000; // 5 minutes

  const isLocked = lockedUntil !== null && Date.now() < lockedUntil;
  const code = codeDigits.join('');

  const submitCode = async (currentCode: string) => {
    if (isLocked) return;

    setError(null);
    setLoading(true);

    try {
      await verifyAdminMfa(currentCode);
      setAttempts(0);
      lastSubmittedCode.current = '';
    } catch (err) {
      const newAttempts = attempts + 1;
      setAttempts(newAttempts);
      if (newAttempts >= MAX_ATTEMPTS) {
        setLockedUntil(Date.now() + LOCKOUT_MS);
        setError(`Muitas tentativas. Tente novamente em 5 minutos.`);
      } else {
        setError(`Código MFA inválido. ${MAX_ATTEMPTS - newAttempts} tentativas restantes.`);
      }

      setCodeDigits(['', '', '', '', '', '']);
      pinRefs.current[0]?.focus();
    } finally {
      setLoading(false);
    }
  };

  const handleDigitChange = (index: number, value: string) => {
    const digit = value.replace(/\D/g, '').slice(-1);
    const next = [...codeDigits];
    next[index] = digit;
    setCodeDigits(next);
    setError(null);

    if (digit && index < 5) {
      pinRefs.current[index + 1]?.focus();
    }
  };

  const handleDigitKeyDown = (index: number, event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Backspace' && !codeDigits[index] && index > 0) {
      pinRefs.current[index - 1]?.focus();
    }
  };

  const handlePaste = (event: React.ClipboardEvent<HTMLDivElement>) => {
    event.preventDefault();
    const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (!pasted) return;

    const next = ['','','','','',''];
    pasted.split('').forEach((digit, index) => {
      next[index] = digit;
    });

    setCodeDigits(next);
    setError(null);
    pinRefs.current[Math.min(pasted.length - 1, 5)]?.focus();
  };

  useEffect(() => {
    if (isLocked || loading || code.length !== 6) {
      return;
    }

    if (lastSubmittedCode.current === code) {
      return;
    }

    lastSubmittedCode.current = code;
    void submitCode(code);
  }, [code, isLocked, loading]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-900">
      <div className="w-full max-w-md bg-white dark:bg-slate-800 rounded-lg shadow-lg p-6">
        <h1 className="text-2xl font-bold mb-4">Verificação de 2FA</h1>
        <p className="text-sm mb-4">Informe o código de autenticação de dois fatores para acessar o painel administrativo.</p>
        <div className="mb-3" onPaste={handlePaste}>
          <div className="flex justify-center gap-2">
            {codeDigits.map((digit, index) => (
              <input
                key={index}
                ref={(element) => { pinRefs.current[index] = element; }}
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                autoComplete="one-time-code"
                maxLength={1}
                value={digit}
                onChange={(event) => handleDigitChange(index, event.target.value)}
                onKeyDown={(event) => handleDigitKeyDown(index, event)}
                disabled={loading || isLocked}
                className="h-12 w-12 rounded-xl border border-slate-300 bg-white text-center text-xl font-semibold tracking-wide text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-700 dark:text-white dark:focus:ring-emerald-500/30"
                aria-label={`Dígito ${index + 1} do código 2FA`}
              />
            ))}
          </div>
          <p className="mt-3 text-center text-xs text-slate-500 dark:text-slate-400">
            Digite os 6 dígitos para validar automaticamente.
          </p>
        </div>
        {error && <p className="text-sm text-red-500 mb-3 text-center">{error}</p>}
        {loading && <p className="text-sm text-slate-500 text-center">Validando código...</p>}
        {isLocked && <p className="text-sm text-amber-600 text-center">Bloqueado temporariamente</p>}
      </div>
    </div>
  );
};

// Admin Route Component - requires admin role + MFA enabled
const AdminRoute = ({ children }: { children: React.ReactNode }) => {
  const { isAuthenticated, isLoading, user, adminMfaVerified } = useAuthStore();
  const location = useLocation();
  const [mfaCheckDone, setMfaCheckDone] = useState(false);

  const allowedAdminRoles = ['owner', 'admin', 'support', 'financial', 'antifraud'];

  const hasPermission = (permission?: string): boolean => {
    if (!permission) return true;
    if (user?.role === 'owner') return true;

    const permissions = user?.permissions ?? [];
    if (permissions.includes('*')) return true;
    if (permissions.includes(permission)) return true;

    const dot = permission.replace(/:/g, '.');
    const colon = permission.replace(/\./g, ':');
    if (permissions.includes(dot) || permissions.includes(colon)) return true;

    const category = dot.split('.')[0];
    return permissions.includes(`${category}.*`) || permissions.includes(`${category}:*`);
  };

  const resolveRequiredPermission = (path: string): string | null => {
    const clean = path.replace(/^\/admin\/?/, '');
    if (clean === '') return 'admin.dashboard.read';
    if (clean.startsWith('customers') || clean.startsWith('sellers')) return 'admin.customers.manage';
    if (clean.startsWith('finance')) return 'admin.withdrawals.manage';
    if (clean.startsWith('kyc')) return 'admin.kyc.review';
    if (clean.startsWith('acquirers')) return 'admin.acquirers.manage';
    if (clean.startsWith('chargebacks')) return 'admin.chargebacks.manage';
    if (clean.startsWith('approvals')) return 'admin.approvals.review';
    if (clean.startsWith('security') || clean.startsWith('monitoring')) return 'admin.security.monitor';
    if (clean.startsWith('api')) return 'admin.webhooks.manage';
    if (clean.startsWith('settings') || clean.startsWith('branding')) return 'admin.config.manage';
    return null;
  };

  useEffect(() => {
    if (!isLoading && isAuthenticated && user && !user.mfa_enabled && allowedAdminRoles.includes(user.role ?? '')) {
      setMfaCheckDone(true);
    }
  }, [isLoading, isAuthenticated, user]);

  if (isLoading) {
    return <PageSpinner />;
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (!allowedAdminRoles.includes(user?.role ?? '')) {
    return <Navigate to="/dashboard" replace />;
  }

  if (!user?.mfa_enabled) {
    return <Navigate to="/settings?mfa_required=true&tab=security" replace />;
  }

  if (!adminMfaVerified) {
    return <AdminMfaChallenge />;
  }

  const requiredPermission = resolveRequiredPermission(location.pathname);
  if (!hasPermission(requiredPermission ?? undefined)) {
    return <Navigate to="/admin" replace />;
  }

  return <>{children}</>;
};

// Public Route Component (redirect if authenticated)
const PublicRoute = ({ children }: { children: React.ReactNode }) => {
  const { isAuthenticated, isLoading } = useAuthStore();

  // Wait for auth check to complete before redirecting
  if (isLoading) {
    return <PageSpinner />;
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};

function App() {
  const { theme, themeVariant, setPrimaryColor, setPlatformName, applyPrimaryColor, applyThemeVariant, primaryColor } = useThemeStore();
  const { checkAuth, isAuthenticated, user, resetAdminMfaVerification, startImpersonation } = useAuthStore();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [isBootLoading, setIsBootLoading] = useState(true);

  // Handle impersonation token from URL
  useEffect(() => {
    const impersonateToken = searchParams.get('impersonate_token');
    if (impersonateToken) {
      // Remove token from URL immediately for security
      const nextParams = new URLSearchParams(searchParams);
      nextParams.delete('impersonate_token');
      setSearchParams(nextParams, { replace: true });

      startImpersonation(impersonateToken).catch(() => {
        // On failure, normal checkAuth will run
      });
      return;
    }
  }, []);

  useEffect(() => {
    // Check authentication on mount
    checkAuth();
  }, [checkAuth]);


  // Apply stored primary color and theme variant on mount
  useEffect(() => {
    if (primaryColor) {
      applyPrimaryColor(primaryColor);
    }
    if (themeVariant) {
      applyThemeVariant(themeVariant, theme);
    }
  }, []);

  // Fetch public branding config on mount
  useEffect(() => {
    const fetchBranding = async () => {
      try {
        const response = await api.get('/branding');
        const config = response.data?.data;
        if (config) {
          if (config.primary_color) {
            setPrimaryColor(config.primary_color);
          }
          if (config.platform_name) {
            setPlatformName(config.platform_name);
          }
        }
      } catch {
        // Silently fail - use cached/default values
      }
    };
    fetchBranding();
  }, [setPrimaryColor, setPlatformName]);

  useEffect(() => {
    // Apply theme
    if (theme === 'dark') {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  }, [theme]);

  useEffect(() => {
    const timeout = setTimeout(() => {
      setIsBootLoading(false);
    }, BRAND_LOADER_MIN_DURATION_MS);

    return () => clearTimeout(timeout);
  }, []);

  if (isBootLoading) {
    return <PageSpinner />;
  }

  return (
    <Suspense fallback={<PageLoader />}>
    <Routes>
      {/* Auth Routes - Redirect to members login on custom domains */}
      <Route
        element={
          isCustomDomain() ? <Navigate to="/members/login" replace /> : (
            <PublicRoute>
              <AuthLayout />
            </PublicRoute>
          )
        }
      >
        <Route path="/login" element={isCustomDomain() ? <Navigate to="/members/login" replace /> : <LoginPage />} />
        <Route path="/register" element={isCustomDomain() ? <Navigate to="/members/login" replace /> : <RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      </Route>

      {/* Reset Password - standalone, accessible even if logged in */}
      <Route path="/reset-password" element={<AuthLayout />}>
        <Route index element={<ResetPasswordPage />} />
      </Route>

      {/* Account Blocked Page - standalone, no auth layout */}
      <Route path="/account-blocked" element={<AccountBlockedPage />} />

      {/* Dashboard Routes */}
      <Route
        element={
          <ProtectedRoute>
            <DashboardLayout />
          </ProtectedRoute>
        }
      >
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/vitrine" element={<VitrinePage />} />
        <Route path="/charges" element={<ChargesPage />} />
        <Route path="/chargebacks" element={<ChargebackPage />} />
        <Route path="/acquirers" element={<AcquirersPage />} />
        <Route path="/products" element={<ProductsPage />} />
        <Route path="/checkouts" element={<CheckoutsPage />} />
        <Route path="/affiliates" element={<AffiliatesPage />} />
        <Route path="/withdrawals" element={<WithdrawalsPage />} />
        <Route path="/landing-pages" element={<LandingPagesPage />} />
        <Route path="/landing-pages/generate" element={<LandingPagesPage />} />
        <Route path="/landing-pages/:id/edit" element={<LandingPageEditorPage />} />
        <Route path="/member-area" element={<MemberAreaPage />} />
        <Route path="/member-area/courses/:courseId" element={<CourseEditorPage />} />
        <Route path="/domains" element={<DomainsPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/analytics" element={<AnalyticsPage />} />
        <Route path="/reports" element={<ReportsPage />} />
        <Route path="/coupons" element={<CouponsPage />} />
        <Route path="/integrations" element={<IntegrationsPage />} />
        <Route path="/messaging" element={<MessagingPage />} />
        <Route path="/support" element={<SupportPage />} />
        <Route path="/splits" element={<SplitManagement />} />
        <Route path="/webhook-logs" element={<WebhookLogsPage />} />
        <Route path="/wazer-ia" element={<VectorIAPage />} />
      </Route>

      {/* Admin Routes - Full screen with dedicated layout */}
      <Route
        path="/admin"
        element={
          <AdminRoute>
            <AdminLayout />
          </AdminRoute>
        }
      >
        <Route index element={<AdminDashboard />} />
        <Route path="control-tower" element={<AdminControlTower />} />
        <Route path="customers" element={<AdminCustomers />} />
        <Route path="customers/:customerId" element={<AdminCustomerDetail />} />
        <Route path="sellers" element={<AdminCustomers />} />
        <Route path="sellers/:customerId" element={<AdminCustomerDetail />} />
        <Route path="finance" element={<AdminFinance />} />
        <Route path="kyc" element={<AdminKYC />} />
        <Route path="acquirers" element={<AdminAcquirers />} />
        <Route path="chargebacks" element={<AdminChargebacks />} />
        <Route path="approvals" element={<AdminApprovals />} />
        <Route path="security" element={<AdminSecurity />} />
        <Route path="settings" element={<AdminSettings />} />
        <Route path="api" element={<AdminAPI />} />
        <Route path="branding" element={<AdminBranding />} />
        <Route path="monitoring" element={<AdminMonitoring />} />
      </Route>

      {/* Checkout Builder - Full screen */}
      <Route
        path="/checkout-builder"
        element={
          <ProtectedRoute>
            <CheckoutBuilderPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/checkout-builder/:id"
        element={
          <ProtectedRoute>
            <CheckoutBuilderPage />
          </ProtectedRoute>
        }
      />
      <Route
        path="/checkouts/:id/analytics"
        element={
          <ProtectedRoute>
            <DashboardLayout>
              <CheckoutAnalyticsPage />
            </DashboardLayout>
          </ProtectedRoute>
        }
      />

      {/* Checkout Preview - Full screen */}
      <Route path="/checkout-preview" element={<CheckoutPreviewPage />} />

      {/* KYC Page - Protected but doesn't require KYC approval */}
      <Route
        path="/kyc"
        element={
          <ProtectedRoute>
            <KYCPage />
          </ProtectedRoute>
        }
      />

      {/* Public Checkout Page - Customer facing */}
      <Route path="/pay/:slug" element={<PublicCheckoutPage />} />
      <Route path="/checkout/:slug" element={<PublicCheckoutPage />} />

      {/* Legal Pages */}
      <Route path="/terms" element={<TermsPage />} />
      <Route path="/privacy" element={<PrivacyPage />} />
      <Route path="/docs" element={<ApiDocsPage />} />
      <Route path="/api-docs" element={<ApiDocsPage />} />

      {/* Member Area - Student facing pages */}
      <Route path="/members/login" element={<MemberLoginPage />} />
      <Route path="/members/dashboard" element={<MemberDashboardPage />} />
      <Route path="/members/profile" element={<MemberProfilePage />} />
      <Route path="/members/courses/:courseId" element={<MemberCoursePage />} />
      <Route path="/members/courses/:courseId/:lessonId" element={<MemberCoursePage />} />

      {/* Redirects */}
      <Route path="/" element={isCustomDomain() ? <DomainLandingPage /> : <Navigate to="/login" replace />} />
      <Route path="*" element={isCustomDomain() ? <DomainLandingPage /> : <Navigate to="/login" replace />} />
    </Routes>
    </Suspense>
  );
}

export default App;
