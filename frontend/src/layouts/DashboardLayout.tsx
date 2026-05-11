import { Outlet, NavLink, Link, useNavigate, useLocation } from 'react-router-dom';
import { Fragment, useState, useEffect, ReactNode, lazy, Suspense } from 'react';
import { Menu, Transition } from '@headlessui/react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

const CosmicBackground = lazy(() => import('@/components/ui/CosmicBackground'));

import {
  Bars3Icon,
  HomeIcon,
  CreditCardIcon,
  ShoppingBagIcon,
  LinkIcon,
  Cog6ToothIcon,
  ArrowRightOnRectangleIcon,
  MoonIcon,
  SunIcon,
  BellIcon,
  XMarkIcon,
  BuildingStorefrontIcon,
  BanknotesIcon,
  UserCircleIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  CurrencyDollarIcon,
  InformationCircleIcon,
  GlobeAltIcon,
  AcademicCapIcon,
  PuzzlePieceIcon,
  QuestionMarkCircleIcon,
  ArrowUturnLeftIcon,
  ChevronDownIcon,
  ShieldCheckIcon,
  ChatBubbleLeftRightIcon,
  ArrowsRightLeftIcon,
  SparklesIcon,
  BoltIcon,
  SignalIcon,
} from '@heroicons/react/24/outline';
import { notificationsService, Notification } from '@/services/api/notifications.service';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import clsx from 'clsx';

interface DashboardLayoutProps {
  children?: ReactNode;
}

interface NavChild {
  name: string;
  href: string;
}

interface NavItem {
  name: string;
  href: string;
  icon: React.ForwardRefExoticComponent<any>;
  comingSoon?: boolean;
  children?: NavChild[];
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/dashboard', icon: HomeIcon },
  { name: 'Vitrine', href: '/vitrine', icon: BuildingStorefrontIcon },
  { name: 'Cobranças', href: '/charges', icon: CreditCardIcon, children: [
    { name: 'Todas Cobranças', href: '/charges' },
    { name: 'Chargebacks', href: '/chargebacks' },
  ]},
  { name: 'Produtos', href: '/products', icon: ShoppingBagIcon },
  { name: 'Checkouts', href: '/checkouts', icon: LinkIcon },
  { name: 'Adquirentes', href: '/acquirers', icon: ArrowsRightLeftIcon },
  { name: 'Membros', href: '/member-area', icon: AcademicCapIcon },
  { name: 'Saques', href: '/withdrawals', icon: BanknotesIcon },
  { name: 'VectorIA', href: '/wazer-ia', icon: ChatBubbleLeftRightIcon },
];

export default function DashboardLayout({ children }: DashboardLayoutProps) {
  const { user, logout, isImpersonating, stopImpersonation } = useAuthStore();
  const { theme, toggleTheme } = useThemeStore();
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [expandedMobileMenu, setExpandedMobileMenu] = useState<string | null>(null);
  const hasOriginalAdminToken = typeof window !== 'undefined' && Boolean(sessionStorage.getItem('admin_original_token'));
  const isImpersonationSession = isImpersonating || hasOriginalAdminToken;

  // Update page title based on current route
  useEffect(() => {
    const pageTitles: Record<string, string> = {
      '/dashboard': 'Dashboard',
      '/vitrine': 'Vitrine',
      '/charges': 'Cobranças',
      '/chargebacks': 'Chargebacks',
      '/acquirers': 'Adquirentes',
      '/products': 'Produtos',
      '/checkouts': 'Checkouts',
      '/member-area': 'Área de Membros',
      '/domains': 'Domínios',
      '/withdrawals': 'Saques',
      '/settings': 'Configurações',
      '/affiliates': 'Afiliados',
      '/landing-pages': 'Landing Pages',
      '/analytics': 'Análises',
      '/reports': 'Relatórios',
      '/coupons': 'Cupons',
      '/integrations': 'Integrações',
      '/messaging': 'Mensagens',
      '/support': 'Suporte',
      '/wazer-ia': 'VectorIA',
      '/admin': 'Administração',
    };
    const path = location.pathname;
    const title = pageTitles[path] || Object.entries(pageTitles).find(([key]) => path.startsWith(key))?.[1] || 'Dashboard';
    document.title = `${title} | Vector Pagamentos`;
  }, [location.pathname]);

  // Verifica se o usuário tem permissão de admin
  const isAdmin = ['owner', 'admin', 'support', 'financial', 'antifraud'].includes(user?.role || '');

  // Fetch notifications from API
  const { data: notificationsData } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => notificationsService.getNotifications({ per_page: 20 }),
    refetchInterval: 30000, // Refetch every 30 seconds
  });

  const notifications = notificationsData?.data || [];
  const unreadCount = notificationsData?.meta?.unread_count || 0;

  // Mark single notification as read
  const markAsReadMutation = useMutation({
    mutationFn: (id: string) => notificationsService.markAsRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  // Mark all notifications as read
  const markAllAsReadMutation = useMutation({
    mutationFn: () => notificationsService.markAllAsRead(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const markAsRead = (id: string) => {
    markAsReadMutation.mutate(id);
  };

  const markAllAsRead = () => {
    markAllAsReadMutation.mutate();
  };

  const handleReturnToAdmin = () => {
    stopImpersonation();
    navigate('/admin', { replace: true });
  };

  const getNotificationIcon = (type: Notification['type']) => {
    switch (type) {
      case 'sale':
        return <CurrencyDollarIcon className="h-5 w-5 text-emerald-500" />;
      case 'system':
        return <CheckCircleIcon className="h-5 w-5 text-blue-500" />;
      case 'warning':
        return <ExclamationTriangleIcon className="h-5 w-5 text-amber-500" />;
      case 'info':
      default:
        return <InformationCircleIcon className="h-5 w-5 text-accent-primary" />;
    }
  };

  const handleLogout = () => {
    if (isImpersonationSession) {
      handleReturnToAdmin();
      return;
    }

    logout();
    navigate('/login');
  };

  return (
    <div className="min-h-screen bg-surface-page relative overflow-x-hidden">
      {/* Impersonation top bar */}
      {isImpersonationSession && (
        <div className="sticky top-0 z-[60] flex items-center justify-center gap-3 bg-emerald-600 px-4 py-1.5 text-white text-xs font-medium">
          <span>Modo impersonação: logado como <strong>{user?.name}</strong></span>
          <button
            onClick={handleReturnToAdmin}
            className="rounded bg-white/20 px-2 py-0.5 text-xs font-semibold hover:bg-white/30 transition-colors"
          >
            Sair
          </button>
        </div>
      )}
      {/* Cosmic Background - theme-aware */}
      <Suspense fallback={null}>
        <CosmicBackground isDark={theme === 'dark'} />
      </Suspense>

      {/* Top Navigation */}
      <header className="sticky top-0 z-50 glass-nav border-b border-border-DEFAULT/60 bg-surface-page/95 backdrop-blur-xl">
        <div className="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex h-14 items-center justify-between">
            {/* Logo */}
            <div className="flex items-center gap-6">
              <NavLink to="/dashboard" className="flex items-center group">
                <span className="text-base font-bold text-tx-primary tracking-tight">
                  Wazer<span className="text-emerald-500">Pay</span>
                </span>
              </NavLink>

              {/* Desktop Navigation */}
              <nav className="hidden lg:flex items-center gap-0.5">
                {navigation.map((item) => (
                  item.comingSoon ? (
                    <div
                      key={item.name}
                      className="px-2.5 py-2 rounded-element text-xs font-medium cursor-not-allowed opacity-50 text-tx-muted"
                    >
                      <span className="flex items-center gap-1.5">
                        <item.icon className="h-3.5 w-3.5" />
                        {item.name}
                      </span>
                    </div>
                  ) : item.children ? (
                    <Menu as="div" className="relative" key={item.name}>
                      {({ open }) => (
                        <>
                          <Menu.Button
                            className={clsx(
                              'px-2.5 py-2 rounded-element text-xs font-medium transition-all duration-fast',
                              open || location.pathname === '/charges' || location.pathname === '/chargebacks'
                                ? 'bg-accent-subtle text-accent-primary'
                                : 'text-tx-secondary hover:text-tx-primary hover:bg-surface-hover'
                            )}
                          >
                            <span className="flex items-center gap-1.5">
                              <item.icon className="h-3.5 w-3.5" />
                              {item.name}
                              <ChevronDownIcon className={clsx('h-3 w-3 transition-transform', open && 'rotate-180')} />
                            </span>
                          </Menu.Button>
                          <Transition
                            as={Fragment}
                            enter="transition ease-out duration-150"
                            enterFrom="transform opacity-0 scale-95 translate-y-1"
                            enterTo="transform opacity-100 scale-100 translate-y-0"
                            leave="transition ease-in duration-100"
                            leaveFrom="transform opacity-100 scale-100 translate-y-0"
                            leaveTo="transform opacity-0 scale-95 translate-y-1"
                          >
                            <Menu.Items className="absolute left-0 mt-2 w-48 origin-top-left rounded-card bg-surface-elevated border border-border-DEFAULT shadow-float focus:outline-none overflow-hidden z-50">
                              <div className="p-1">
                                {item.children?.map((child) => (
                                  <Menu.Item key={child.name}>
                                    {({ active }) => (
                                      <NavLink
                                        to={child.href}
                                        end
                                        className={({ isActive }) =>
                                          clsx(
                                            'flex items-center gap-2 rounded-element px-3 py-2 text-sm transition-colors duration-fast',
                                            isActive
                                              ? 'bg-accent-subtle text-accent-primary'
                                              : active
                                              ? 'bg-surface-hover text-tx-primary'
                                              : 'text-tx-secondary'
                                          )
                                        }
                                      >
                                        {child.name}
                                      </NavLink>
                                    )}
                                  </Menu.Item>
                                ))}
                              </div>
                            </Menu.Items>
                          </Transition>
                        </>
                      )}
                    </Menu>
                  ) : (
                    <NavLink
                      key={item.name}
                      to={item.href}
                      className={({ isActive }) =>
                        clsx(
                          'px-2.5 py-2 rounded-element text-xs font-medium transition-all duration-fast',
                          isActive
                            ? 'bg-accent-subtle text-accent-primary'
                            : 'text-tx-secondary hover:text-tx-primary hover:bg-surface-hover'
                        )
                      }
                    >
                      <span className="flex items-center gap-1.5">
                        <item.icon className="h-3.5 w-3.5" />
                        {item.name}
                      </span>
                    </NavLink>
                  )
                ))}
              </nav>
            </div>

            {/* Right Section */}
            <div className="flex items-center gap-1">
              {/* Theme Toggle */}
              <button
                onClick={toggleTheme}
                className="p-2 rounded-lg text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-all duration-fast"
              >
                {theme === 'dark' ? (
                  <SunIcon className="h-[18px] w-[18px]" />
                ) : (
                  <MoonIcon className="h-[18px] w-[18px]" />
                )}
              </button>

              {/* Notifications */}
              <Menu as="div" className="relative">
                <Menu.Button className="relative p-2 rounded-lg text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-all duration-fast">
                  <BellIcon className="h-[18px] w-[18px]" />
                  {unreadCount > 0 && (
                    <span className="absolute top-1 right-1 h-4 w-4 flex items-center justify-center text-[10px] font-bold text-white bg-accent-primary rounded-full ring-2 ring-surface-nav">
                      {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                  )}
                </Menu.Button>

                <Transition
                  as={Fragment}
                  enter="transition ease-out duration-200"
                  enterFrom="transform opacity-0 scale-95 translate-y-1"
                  enterTo="transform opacity-100 scale-100 translate-y-0"
                  leave="transition ease-in duration-150"
                  leaveFrom="transform opacity-100 scale-100 translate-y-0"
                  leaveTo="transform opacity-0 scale-95 translate-y-1"
                >
                  <Menu.Items className="fixed sm:absolute right-2 sm:right-0 left-2 sm:left-auto mt-3 sm:w-80 origin-top-right rounded-card bg-surface-elevated border border-border-DEFAULT shadow-float focus:outline-none overflow-hidden z-50">
                    <div className="p-2">
                      {/* Header */}
                      <div className="flex items-center justify-between px-3 py-3 border-b border-border-DEFAULT">
                        <p className="text-sm font-semibold text-tx-primary">
                          Notificações
                        </p>
                        {unreadCount > 0 && (
                          <button
                            onClick={markAllAsRead}
                            className="text-xs text-accent-primary hover:underline"
                          >
                            Marcar todas como lidas
                          </button>
                        )}
                      </div>

                      {/* Notifications List */}
                      <div className="max-h-80 overflow-y-auto">
                        {notifications.length === 0 ? (
                          <div className="px-3 py-8 text-center">
                            <BellIcon className="h-8 w-8 mx-auto text-tx-muted mb-2" />
                            <p className="text-sm text-tx-muted">
                              Nenhuma notificação
                            </p>
                          </div>
                        ) : (
                          notifications.map((notification) => (
                            <Menu.Item key={notification.id}>
                              {({ active }) => (
                                <button
                                  onClick={() => markAsRead(notification.id)}
                                  className={clsx(
                                    'flex w-full items-start gap-3 px-3 py-3 text-left transition-colors duration-fast rounded-element my-1',
                                    active
                                      ? 'bg-surface-hover'
                                      : notification.read
                                      ? 'bg-transparent'
                                      : 'bg-accent-subtle'
                                  )}
                                >
                                  <div className="flex-shrink-0 mt-0.5">
                                    {getNotificationIcon(notification.type)}
                                  </div>
                                  <div className="flex-1 min-w-0">
                                    <p
                                      className={clsx(
                                        'text-sm font-medium',
                                        notification.read
                                          ? 'text-tx-secondary'
                                          : 'text-tx-primary'
                                      )}
                                    >
                                      {notification.title}
                                    </p>
                                    <p className="text-xs text-tx-secondary">
                                      {notification.message}
                                    </p>
                                    <p className="text-xs text-tx-muted mt-1">
                                      {notification.time}
                                    </p>
                                  </div>
                                  {!notification.read && (
                                    <div className="flex-shrink-0">
                                      <span className="h-2 w-2 bg-accent-primary rounded-full block" />
                                    </div>
                                  )}
                                </button>
                              )}
                            </Menu.Item>
                          ))
                        )}
                      </div>
                    </div>
                  </Menu.Items>
                </Transition>
              </Menu>

              {/* Admin Button (only for admins) */}
              {isAdmin && (
                <button
                  onClick={() => navigate('/admin')}
                  className="flex items-center gap-1.5 px-3 py-2 rounded-element text-accent-primary hover:bg-accent-subtle transition-all duration-fast"
                  title="Painel de Administração"
                >
                  <span className="text-sm font-semibold">ADMIN</span>
                </button>
              )}

              {/* Profile Dropdown */}
              <Menu as="div" className="relative ml-1">
                <Menu.Button className="flex items-center gap-2 py-1.5 pl-1.5 pr-3 rounded-full hover:bg-surface-hover border border-transparent hover:border-border-DEFAULT transition-all duration-fast">
                  <div className="h-7 w-7 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center">
                    <span className="text-white text-xs font-bold">{(user?.name || 'U')[0].toUpperCase()}</span>
                  </div>
                  <span className="text-xs font-medium text-tx-primary hidden sm:block">{user?.name?.split(' ')[0] || 'ADMIN'}</span>
                </Menu.Button>

                <Transition
                  as={Fragment}
                  enter="transition ease-out duration-200"
                  enterFrom="transform opacity-0 scale-95 translate-y-1"
                  enterTo="transform opacity-100 scale-100 translate-y-0"
                  leave="transition ease-in duration-150"
                  leaveFrom="transform opacity-100 scale-100 translate-y-0"
                  leaveTo="transform opacity-0 scale-95 translate-y-1"
                >
                  <Menu.Items className="absolute right-0 mt-3 w-56 origin-top-right rounded-card bg-surface-elevated border border-border-DEFAULT shadow-float focus:outline-none overflow-hidden">
                    <div className="p-2">
                      {/* User Info */}
                      <div className="px-3 py-3 mb-2 border-b border-border-DEFAULT">
                        <p className="text-sm font-medium text-tx-primary truncate">
                          {user?.name || 'Usuário'}
                        </p>
                        <p className="text-xs text-tx-muted truncate">
                          {user?.email}
                        </p>
                      </div>
                      <div className="px-3 py-2 mb-1">
                        <p className="text-xs font-medium text-tx-muted uppercase tracking-wider">
                          Conta
                        </p>
                      </div>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/settings')}
                            className={clsx(
                              'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                              active
                                ? 'bg-surface-hover text-tx-primary'
                                : 'text-tx-secondary'
                            )}
                          >
                            <Cog6ToothIcon className="h-4 w-4" />
                            Configurações
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/integrations')}
                            className={clsx(
                              'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                              active
                                ? 'bg-surface-hover text-tx-primary'
                                : 'text-tx-secondary'
                            )}
                          >
                            <PuzzlePieceIcon className="h-4 w-4" />
                            Integrações
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/domains')}
                            className={clsx(
                              'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                              active
                                ? 'bg-surface-hover text-tx-primary'
                                : 'text-tx-secondary'
                            )}
                          >
                            <GlobeAltIcon className="h-4 w-4" />
                            Domínios
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/support')}
                            className={clsx(
                              'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                              active
                                ? 'bg-surface-hover text-tx-primary'
                                : 'text-tx-secondary'
                            )}
                          >
                            <QuestionMarkCircleIcon className="h-4 w-4" />
                            Suporte
                          </button>
                        )}
                      </Menu.Item>
                      {isImpersonationSession && (
                        <>
                          <div className="my-2 border-t border-border-DEFAULT" />
                          <div className="px-3 py-2 mb-1">
                            <p className="text-xs font-medium text-tx-muted uppercase tracking-wider">
                              Admin
                            </p>
                          </div>
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={handleReturnToAdmin}
                                className={clsx(
                                  'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                                  active
                                    ? 'bg-accent-subtle text-accent-primary'
                                    : 'text-tx-secondary'
                                )}
                              >
                                <ArrowUturnLeftIcon className="h-4 w-4" />
                                Voltar para Admin
                              </button>
                            )}
                          </Menu.Item>
                        </>
                      )}
                      <div className="my-2 border-t border-border-DEFAULT" />
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={handleLogout}
                            className={clsx(
                              'flex w-full items-center gap-3 rounded-element px-3 py-2.5 text-sm transition-colors duration-fast',
                              active
                                ? 'bg-status-error-subtle text-status-error'
                                : 'text-tx-secondary'
                            )}
                          >
                            <ArrowRightOnRectangleIcon className="h-4 w-4" />
                            Sair
                          </button>
                        )}
                      </Menu.Item>
                    </div>
                  </Menu.Items>
                </Transition>
              </Menu>

              {/* Mobile Menu Button */}
              <button
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                className="lg:hidden p-2.5 rounded-element text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-all duration-fast"
              >
                {mobileMenuOpen ? (
                  <XMarkIcon className="h-5 w-5" />
                ) : (
                  <Bars3Icon className="h-5 w-5" />
                )}
              </button>
            </div>
          </div>
        </div>

        {/* Mobile Menu */}
        <Transition
          show={mobileMenuOpen}
          as={Fragment}
          enter="transition ease-out duration-200"
          enterFrom="opacity-0 -translate-y-1"
          enterTo="opacity-100 translate-y-0"
          leave="transition ease-in duration-150"
          leaveFrom="opacity-100 translate-y-0"
          leaveTo="opacity-0 -translate-y-1"
        >
          <div className="lg:hidden border-t border-border-subtle bg-surface-nav backdrop-blur-xl">
            <nav className="max-w-7xl mx-auto px-4 py-3 space-y-1">
              {navigation.map((item) => (
                item.comingSoon ? (
                  <div
                    key={item.name}
                    className="flex items-center gap-3 px-4 py-3 rounded-element text-sm font-medium cursor-not-allowed opacity-50 text-tx-muted"
                  >
                    <item.icon className="h-5 w-5" />
                    {item.name}
                  </div>
                ) : item.children ? (
                  <div key={item.name}>
                    <button
                      onClick={() => setExpandedMobileMenu(expandedMobileMenu === item.name ? null : item.name)}
                      className={clsx(
                        'w-full flex items-center justify-between gap-3 px-4 py-3 rounded-element text-sm font-medium transition-all duration-fast',
                        location.pathname === item.href || item.children.some(c => location.pathname === c.href)
                          ? 'bg-accent-subtle text-accent-primary'
                          : 'text-tx-secondary hover:bg-surface-hover'
                      )}
                    >
                      <span className="flex items-center gap-3">
                        <item.icon className="h-5 w-5" />
                        {item.name}
                      </span>
                      <ChevronDownIcon className={clsx('h-4 w-4 transition-transform', expandedMobileMenu === item.name && 'rotate-180')} />
                    </button>
                    {expandedMobileMenu === item.name && (
                      <div className="ml-9 mt-1 space-y-1">
                        {item.children.map((child) => (
                          <NavLink
                            key={child.name}
                            to={child.href}
                            end
                            onClick={() => setMobileMenuOpen(false)}
                            className={({ isActive }) =>
                              clsx(
                                'flex items-center gap-2 px-3 py-2 rounded-element text-xs font-medium transition-all duration-fast',
                                isActive
                                  ? 'bg-accent-subtle text-accent-primary'
                                  : 'text-tx-muted hover:bg-surface-hover'
                              )
                            }
                          >
                            {child.name}
                          </NavLink>
                        ))}
                      </div>
                    )}
                  </div>
                ) : (
                  <NavLink
                    key={item.name}
                    to={item.href}
                    onClick={() => setMobileMenuOpen(false)}
                    className={({ isActive }) =>
                      clsx(
                        'flex items-center gap-3 px-4 py-3 rounded-element text-sm font-medium transition-all duration-fast',
                        isActive
                          ? 'bg-accent-subtle text-accent-primary'
                          : 'text-tx-secondary hover:bg-surface-hover'
                      )
                    }
                  >
                    <item.icon className="h-5 w-5" />
                    {item.name}
                  </NavLink>
                )
              ))}
            </nav>
          </div>
        </Transition>
      </header>

      {/* Main Content */}
      <main className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* KYC Banner — informativo, não bloqueia uso */}
        {user?.kyc_status && user.kyc_status !== 'approved' && (
          <div className="mb-6 flex items-center gap-3 rounded-card border border-status-warning/30 bg-status-warning-subtle px-4 py-3">
            <ExclamationTriangleIcon className="h-5 w-5 flex-shrink-0 text-status-warning" />
            <p className="text-sm font-medium text-tx-primary flex-1">
              Complete sua verificação KYC para poder realizar saques. Você pode continuar usando a plataforma normalmente.
            </p>
            <Link
              to="/kyc"
              className="flex-shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold bg-accent hover:bg-accent-hover text-white px-3 py-1.5 rounded-md transition-colors"
            >
              <ShieldCheckIcon className="h-4 w-4" />
              Verificar KYC
            </Link>
          </div>
        )}
        {children || <Outlet />}
      </main>
    </div>
  );
}
