import { Routes, Route, Navigate, useLocation, useSearchParams, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { useEffect, useLayoutEffect, useRef, useState, lazy, Suspense } from 'react';
import { api } from '@/lib/api';
import { isCustomDomain } from '@/lib/domain';
import { PageSpinner } from '@/components/ui/Spinner';

// Layout publico carregado imediatamente; paineis carregados sob demanda.
import AuthLayout from '@/layouts/AuthLayout';

const DashboardLayout = lazy(() => import('@/layouts/DashboardLayout'));

// Carregado somente quando a rota administrativa passa pelo AdminRoute.
const AdminLayout = lazy(() => import('@/layouts/AdminLayout'));

const PageLoader = () => <PageSpinner />;

/**
 * Espera do checkout público enquanto o bundle baixa: só o fundo claro, sem
 * marca e sem spinner. Emenda direto no esqueleto que o próprio checkout
 * mostra depois, então o cliente percebe uma transição só.
 */
const CheckoutBoot = () => <div className="min-h-screen" style={{ backgroundColor: '#f8fafc' }} />;

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
const AdminGatewaySettings = lazy(() => import('@/pages/admin/AdminGatewaySettings'));
const AdminKYC = lazy(() => import('@/pages/admin/AdminKYC'));
const AdminFinance = lazy(() => import('@/pages/admin/AdminFinance'));
const AdminAcquirers = lazy(() => import('@/pages/admin/AdminAcquirers'));
const AdminAcquirerRouting = lazy(() => import('@/pages/admin/AdminAcquirerRouting'));
const AdminMonitoring = lazy(() => import('@/pages/admin/AdminMonitoring'));
const AdminChargebacks = lazy(() => import('@/pages/admin/AdminChargebacks'));
const AdminSupport = lazy(() => import('@/pages/admin/AdminSupport'));
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

// Public Pages (lazy)
const PublicCheckoutPage = lazy(() => import('@/pages/public/CheckoutPage'));
const PixPaymentPage = lazy(() => import('@/pages/public/PixPaymentPage'));
const DomainLandingPage = lazy(() => import('@/pages/public/DomainLandingPage'));
const TermsPage = lazy(() => import('@/pages/TermsPage'));
const PrivacyPage = lazy(() => import('@/pages/PrivacyPage'));
const BuyerSupportPage = lazy(() => import('@/pages/public/BuyerSupportPage'));
const KycPrivacyPage = lazy(() => import('@/pages/KycPrivacyPage'));
const ApiDocsPage = lazy(() => import('@/pages/ApiDocsPage'));

// Member Area Pages (lazy)
const MemberLoginPage = lazy(() => import('@/pages/members/MemberLoginPage'));
const MemberDashboardPage = lazy(() => import('@/pages/members/MemberDashboardPage'));
const MemberCoursePage = lazy(() => import('@/pages/members/MemberCoursePage'));
const MemberProfilePage = lazy(() => import('@/pages/members/MemberProfilePage'));

// Protected Route Component
const ProtectedRoute = ({ children }: { children: React.ReactNode }) => {
  const { isAuthenticated, isLoading } = useAuthStore();
  const location = useLocation();
  const params = new URLSearchParams(location.search);
  const isPublicKycMobile =
    location.pathname === '/kyc' &&
    params.get('mobile') === '1' &&
    Boolean(params.get('kyc_code'));

  if (isLoading) {
    return <PageSpinner />;
  }

  if (!isAuthenticated && !isPublicKycMobile) {
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
  const { verifyAdminMfa, logout } = useAuthStore();
  const navigate = useNavigate();
  const platformName = useThemeStore((s) => s.platformName);

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
    <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-950 px-4">
      <div className="w-full max-w-sm">
        <p className="text-center text-sm font-semibold text-gray-900 dark:text-white mb-6">{platformName}</p>
        <h1 className="text-center text-lg font-semibold text-gray-900 dark:text-white mb-1">Verificação de 2FA</h1>
        <p className="text-center text-sm text-gray-500 dark:text-gray-400 mb-8">
          Insira o código do seu autenticador para continuar.
        </p>

        <div onPaste={handlePaste}>
          <div className="flex justify-center gap-2.5">
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
                className="h-14 w-12 rounded-lg border border-gray-300 bg-white text-center text-xl font-semibold text-gray-900 outline-none transition-colors focus:border-gray-900 focus:ring-1 focus:ring-gray-900 disabled:opacity-40 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:focus:border-white dark:focus:ring-white"
                aria-label={`Dígito ${index + 1} do código 2FA`}
              />
            ))}
          </div>
        </div>

        <div className="mt-5 min-h-[20px]">
          {error && <p className="text-center text-sm text-red-600 dark:text-red-400">{error}</p>}
          {loading && !error && <p className="text-center text-sm text-gray-500">Validando...</p>}
          {isLocked && !error && <p className="text-center text-sm text-amber-600">Bloqueado temporariamente.</p>}
          {!error && !loading && !isLocked && (
            <p className="text-center text-xs text-gray-400">O código é validado automaticamente.</p>
          )}
        </div>

        <button
          type="button"
          onClick={() => navigate('/dashboard')}
          className="mt-8 w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors"
        >
          ← Voltar para o dashboard
        </button>
      </div>
    </div>
  );
};

// Admin Route Component - requires admin role + MFA enabled
const AdminRoute = ({ children }: { children: React.ReactNode }) => {
  const { isAuthenticated, isLoading, user, adminMfaVerified } = useAuthStore();
  const location = useLocation();

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
    if (clean.startsWith('transactions')) return 'admin.withdrawals.manage';
    if (clean.startsWith('withdrawals')) return 'admin.withdrawals.manage';
    if (clean.startsWith('finance')) return 'admin.withdrawals.manage';
    if (clean.startsWith('kyc')) return 'admin.kyc.review';
    if (clean.startsWith('acquirers')) return 'admin.acquirers.manage';
    if (clean.startsWith('chargebacks')) return 'admin.chargebacks.manage';
    if (clean.startsWith('approvals')) return 'admin.approvals.review';
    if (clean.startsWith('monitoring')) return 'admin.security.monitor';
    if (clean.startsWith('support')) return 'admin.customers.manage';
    return null;
  };

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
  const { theme, themeVariant, setPrimaryColor, setPlatformName, setPlatformDescription, setLogoUrl, applyPrimaryColor, applyThemeVariant, setThemeVariant, primaryColor } = useThemeStore();
  const { checkAuth, isAuthenticated, user, resetAdminMfaVerification, startImpersonation } = useAuthStore();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();

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
          if (config.platform_description) {
            setPlatformDescription(config.platform_description);
          }
          // Global default theme chosen by the admin — applies to every user.
          if (config.theme_variant === 'modern' || config.theme_variant === 'classic') {
            setThemeVariant(config.theme_variant);
          }
          setLogoUrl(config.logo_url || '');
          // Apply SEO: document title + meta description
          //
          // O checkout público define o próprio título (nome do produto). Como
          // esta busca é assíncrona, ela ganhava a corrida e sobrescrevia — a
          // aba acabava mostrando a marca do gateway em vez do produto.
          const isPublicCheckout = /^\/(checkout|pay)\//.test(window.location.pathname);
          const title = config.seo_title || config.platform_name;
          if (title && !isPublicCheckout) {
            document.title = title;
          }
          const metaDesc = config.seo_description || config.platform_description;
          if (metaDesc) {
            let tag = document.querySelector('meta[name="description"]');
            if (!tag) {
              tag = document.createElement('meta');
              tag.setAttribute('name', 'description');
              document.head.appendChild(tag);
            }
            tag.setAttribute('content', metaDesc);
          }
        }
      } catch {
        // Silently fail - use cached/default values
      }
    };
    fetchBranding();
  }, [setPrimaryColor, setPlatformName, setPlatformDescription, setLogoUrl, setThemeVariant]);

  useLayoutEffect(() => {
    const isAdminRoute = location.pathname.startsWith('/admin');
    const effectiveTheme = isAdminRoute ? 'light' : (themeVariant === 'modern' ? 'dark' : theme);

    document.documentElement.classList.toggle('app-admin-route', isAdminRoute);
    document.documentElement.classList.toggle('app-dark-forced', false);
    document.documentElement.classList.toggle('theme-modern', themeVariant === 'modern');

    if (effectiveTheme === 'dark') {
      document.documentElement.classList.add('dark');
      document.body.style.backgroundColor = themeVariant === 'modern' ? '#050506' : '#0a0a0a';
    } else {
      document.documentElement.classList.remove('dark');
      document.body.style.backgroundColor = '#F9FAFB';
    }
  }, [theme, themeVariant, location.pathname]);

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
        <Route path="/vector-ia" element={<VectorIAPage />} />
        <Route path="/wazer-ia" element={<Navigate to="/vector-ia" replace />} />
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
        <Route path="transactions" element={<AdminFinance mode="transactions" />} />
        <Route path="withdrawals" element={<AdminFinance mode="withdrawals" />} />
        <Route path="finance" element={<Navigate to="/admin/transactions" replace />} />
        <Route path="kyc" element={<AdminKYC />} />
        <Route path="acquirers" element={<AdminAcquirers />} />
        <Route path="acquirers/routing" element={<AdminAcquirerRouting />} />
        <Route path="chargebacks" element={<AdminChargebacks />} />
        <Route path="support" element={<AdminSupport />} />
        <Route path="approvals" element={<AdminFinance mode="transactions" />} />
        <Route path="security" element={<Navigate to="/admin" replace />} />
        <Route path="settings" element={<AdminGatewaySettings />} />
        <Route path="api" element={<Navigate to="/admin" replace />} />
        <Route path="branding" element={<Navigate to="/admin" replace />} />
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

      {/*
        Checkout do cliente final: Suspense próprio para não herdar o splash da
        marca do gateway. Quem está comprando é cliente do vendedor, não nosso —
        e o splash ainda somava uma segunda tela de carregamento antes do
        esqueleto do próprio checkout.
      */}
      <Route path="/pay/:slug" element={<Suspense fallback={<CheckoutBoot />}><PublicCheckoutPage /></Suspense>} />
      <Route path="/checkout/:slug" element={<Suspense fallback={<CheckoutBoot />}><PublicCheckoutPage /></Suspense>} />
      <Route path="/pix/:chargeId" element={<Suspense fallback={<CheckoutBoot />}><PixPaymentPage /></Suspense>} />

      {/* Legal Pages */}
      <Route path="/terms" element={<TermsPage />} />
      <Route path="/privacy" element={<PrivacyPage />} />
      {/* Ajuda para quem comprou — /support é o painel do vendedor, com login. */}
      <Route path="/ajuda" element={<BuyerSupportPage />} />
      <Route path="/kyc/privacy" element={<KycPrivacyPage />} />
      <Route path="/docs" element={<ApiDocsPage />} />
      <Route path="/api-docs" element={<ApiDocsPage />} />

      {/* Member Area - Student facing pages */}
      <Route path="/members/login" element={isCustomDomain() ? <MemberLoginPage /> : <Navigate to="/login" replace />} />
      <Route path="/members/dashboard" element={isCustomDomain() ? <MemberDashboardPage /> : <Navigate to="/login" replace />} />
      <Route path="/members/profile" element={isCustomDomain() ? <MemberProfilePage /> : <Navigate to="/login" replace />} />
      <Route path="/members/courses/:courseId" element={isCustomDomain() ? <MemberCoursePage /> : <Navigate to="/login" replace />} />
      <Route path="/members/courses/:courseId/:lessonId" element={isCustomDomain() ? <MemberCoursePage /> : <Navigate to="/login" replace />} />

      {/* Redirects */}
      <Route path="/" element={isCustomDomain() ? <DomainLandingPage /> : <Navigate to="/login" replace />} />
      <Route path="*" element={isCustomDomain() ? <DomainLandingPage /> : <Navigate to="/login" replace />} />
    </Routes>
    </Suspense>
  );
}

export default App;
