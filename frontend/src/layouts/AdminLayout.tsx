import { useState, useEffect, useRef, Fragment } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Menu, Transition } from '@headlessui/react';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  Bars3Icon,
  XMarkIcon,
  ChartBarIcon,
  BuildingStorefrontIcon,
  UsersIcon,
  BanknotesIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  ComputerDesktopIcon,
  BellIcon,
  MoonIcon,
  SunIcon,
  ArrowRightOnRectangleIcon,
  UserCircleIcon,
  ChevronDownIcon,
  ShieldCheckIcon,
  DocumentCheckIcon,
  ServerIcon,
  KeyIcon,
  SwatchIcon,
  ExclamationTriangleIcon,
  CpuChipIcon,
} from '@heroicons/react/24/outline';

interface NavItem {
  name: string;
  href: string;
  icon: typeof ChartBarIcon;
  permission?: string;
  badge?: number;
}

interface NavGroup {
  title: string;
  items: NavItem[];
}

interface AdminPulse {
  health_score: number;
  state: 'healthy' | 'attention' | 'critical';
  queues: {
    pending_withdrawals: number;
    pending_withdrawals_amount: number;
    pending_approvals: number;
    pending_kyc: number;
  };
  switches: {
    payments_enabled: boolean;
    withdrawals_enabled: boolean;
    withdrawal_processing_enabled: boolean;
    auto_withdrawal_enabled: boolean;
  };
  critical_alerts: string[];
  updated_at: string;
}

const navigationGroups: NavGroup[] = [
  {
    title: 'Visao Geral',
    items: [
      { name: 'Dashboard', href: '/admin', icon: ChartBarIcon, permission: 'admin.dashboard.read' },
      { name: 'Control Tower', href: '/admin/control-tower', icon: CpuChipIcon, permission: 'admin.dashboard.read' },
      { name: 'Monitoramento', href: '/admin/monitoring', icon: ComputerDesktopIcon, permission: 'admin.security.monitor' },
    ],
  },
  {
    title: 'Operacao',
    items: [
      { name: 'Sellers', href: '/admin/sellers', icon: BuildingStorefrontIcon, permission: 'admin.customers.manage' },
      { name: 'Financeiro', href: '/admin/finance', icon: BanknotesIcon, permission: 'admin.withdrawals.manage' },
      { name: 'KYC', href: '/admin/kyc', icon: DocumentCheckIcon, permission: 'admin.kyc.review' },
      { name: 'Disputas', href: '/admin/chargebacks', icon: ExclamationTriangleIcon, permission: 'admin.chargebacks.manage' },
      { name: 'Aprovacoes', href: '/admin/approvals', icon: DocumentTextIcon, permission: 'admin.approvals.review' },
    ],
  },
  {
    title: 'Governanca e Plataforma',
    items: [
      { name: 'Adquirentes', href: '/admin/acquirers', icon: ServerIcon, permission: 'admin.acquirers.manage' },
      { name: 'Segurança', href: '/admin/security', icon: ShieldCheckIcon, permission: 'admin.security.monitor' },
      { name: 'API & Webhooks', href: '/admin/api', icon: KeyIcon, permission: 'admin.webhooks.manage' },
      { name: 'Configurações', href: '/admin/settings', icon: Cog6ToothIcon, permission: 'admin.config.manage' },
      { name: 'Branding', href: '/admin/branding', icon: SwatchIcon, permission: 'admin.config.manage' },
    ],
  },
];

const flatNavigation = navigationGroups.flatMap((group) => group.items);

export default function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const { user, logout: authLogout } = useAuthStore();
  const { theme, toggleTheme } = useThemeStore();

  const isDark = theme === 'dark';

  // Notifications query
  const { data: notificationsData } = useQuery({
    queryKey: ['notifications'],
    queryFn: async () => {
      const response = await api.get('/notifications?unread_only=true&per_page=5');
      return response.data;
    },
    refetchInterval: 30000,
  });

  const unreadCount = notificationsData?.meta?.total || 0;

  const { data: pulseData } = useQuery<AdminPulse>({
    queryKey: ['admin-pulse'],
    queryFn: async () => {
      const response = await api.get('/admin/pulse');
      return response.data?.data;
    },
    refetchInterval: 20000,
  });

  const pulseStateClass =
    pulseData?.state === 'critical'
      ? 'bg-red-500/10 text-red-600 border-red-500/30'
      : pulseData?.state === 'attention'
        ? 'bg-amber-500/10 text-amber-600 border-amber-500/30'
        : 'bg-emerald-500/10 text-emerald-600 border-emerald-500/30';

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Ignore errors
    }
    authLogout();
    navigate('/login');
  };

  const isActiveRoute = (href: string) => {
    if (href === '/admin') {
      return location.pathname === '/admin';
    }
    return location.pathname.startsWith(href);
  };

  const currentPageTitle =
    flatNavigation.find((item) => location.pathname === item.href)?.name ||
    flatNavigation.find((item) => item.href !== '/admin' && location.pathname.startsWith(item.href))?.name ||
    'Painel Administrativo';

  return (
    <div className="flex h-screen overflow-hidden bg-surface-page admin-layout-root">
      {/* Mobile sidebar backdrop */}
      <Transition.Root show={sidebarOpen} as={Fragment}>
        <div className="relative z-50 lg:hidden">
          <Transition.Child
            as={Fragment}
            enter="transition-opacity ease-linear duration-300"
            enterFrom="opacity-0"
            enterTo="opacity-100"
            leave="transition-opacity ease-linear duration-300"
            leaveFrom="opacity-100"
            leaveTo="opacity-0"
          >
            <div className="fixed inset-0 bg-gray-900/80" onClick={() => setSidebarOpen(false)} />
          </Transition.Child>

          <div className="fixed inset-0 flex">
            <Transition.Child
              as={Fragment}
              enter="transition ease-in-out duration-300 transform"
              enterFrom="-translate-x-full"
              enterTo="translate-x-0"
              leave="transition ease-in-out duration-300 transform"
              leaveFrom="translate-x-0"
              leaveTo="-translate-x-full"
            >
              <div className="relative mr-16 flex w-full max-w-xs flex-1">
                <div className="absolute left-full top-0 flex w-16 justify-center pt-5">
                  <button type="button" className="-m-2.5 p-2.5" onClick={() => setSidebarOpen(false)}>
                    <XMarkIcon className="h-6 w-6 text-white" />
                  </button>
                </div>
                <SidebarContent isActiveRoute={isActiveRoute} pulseData={pulseData} />
              </div>
            </Transition.Child>
          </div>
        </div>
      </Transition.Root>

      {/* Desktop sidebar */}
      <div className="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col">
        <SidebarContent isActiveRoute={isActiveRoute} pulseData={pulseData} />
      </div>

      {/* Main content */}
      <div className="flex flex-1 flex-col lg:pl-72">
        {/* Top bar */}
        <div className="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 border-b border-border-DEFAULT bg-surface-page/85 px-4 backdrop-blur sm:gap-x-6 sm:px-6 lg:px-8">
          <button
            type="button"
            className="-m-2.5 p-2.5 text-tx-muted lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Bars3Icon className="h-6 w-6" />
          </button>

          {/* Separator */}
          <div className="h-6 w-px bg-border-DEFAULT lg:hidden" />

          <div className="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
            {/* Page title */}
            <div className="flex items-center">
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.15em] text-tx-muted">Painel Administrativo</p>
                <h1 className="text-base font-semibold text-tx-primary sm:text-lg">{currentPageTitle}</h1>
              </div>
            </div>

            <div className="flex flex-1 items-center justify-end gap-x-4 lg:gap-x-6">
              <button
                type="button"
                onClick={toggleTheme}
                className="rounded-lg border border-border-DEFAULT bg-surface-card p-2 text-tx-secondary hover:bg-surface-hover"
                title={isDark ? 'Ativar tema claro' : 'Ativar tema escuro'}
              >
                {isDark ? <SunIcon className="h-5 w-5" /> : <MoonIcon className="h-5 w-5" />}
              </button>

              <button
                type="button"
                className="relative rounded-lg border border-border-DEFAULT bg-surface-card p-2 text-tx-secondary hover:bg-surface-hover"
                title="Notificacoes"
              >
                <BellIcon className="h-5 w-5" />
                {unreadCount > 0 ? (
                  <span className="absolute -right-1.5 -top-1.5 inline-flex min-h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                    {unreadCount > 99 ? '99+' : unreadCount}
                  </span>
                ) : null}
              </button>

              {/* Separator */}
              <div className="hidden lg:block lg:h-6 lg:w-px lg:bg-border-DEFAULT" />

              {/* Profile dropdown */}
              <Menu as="div" className="relative">
                <Menu.Button className="-m-1.5 flex items-center p-1.5 gap-3 rounded-lg hover:bg-surface-hover transition-colors">
                  <div className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-green-500 to-green-600 text-sm font-semibold text-white">
                    {user?.name?.charAt(0)?.toUpperCase() || 'A'}
                  </div>
                  <div className="hidden lg:flex lg:items-center">
                    <span className="text-sm font-medium text-tx-primary">{user?.name || 'Admin'}</span>
                    <ChevronDownIcon className="ml-2 h-4 w-4 text-tx-muted" />
                  </div>
                </Menu.Button>

                <Transition
                  as={Fragment}
                  enter="transition ease-out duration-100"
                  enterFrom="transform opacity-0 scale-95"
                  enterTo="transform opacity-100 scale-100"
                  leave="transition ease-in duration-75"
                  leaveFrom="transform opacity-100 scale-100"
                  leaveTo="transform opacity-0 scale-95"
                >
                  <Menu.Items className="absolute right-0 z-10 mt-2.5 w-48 origin-top-right rounded-xl bg-surface-card py-2 shadow-lg ring-1 ring-border-DEFAULT focus:outline-none">
                    <Menu.Item>
                      {({ active }) => (
                        <Link
                          to="/dashboard"
                          className={`${
                            active ? 'bg-surface-hover' : ''
                          } flex items-center gap-3 px-4 py-2 text-sm text-tx-primary`}
                        >
                          <UserCircleIcon className="h-5 w-5 text-tx-muted" />
                          Meu Dashboard
                        </Link>
                      )}
                    </Menu.Item>
                    <Menu.Item>
                      {({ active }) => (
                        <button
                          onClick={handleLogout}
                          className={`${
                            active ? 'bg-surface-hover' : ''
                          } flex w-full items-center gap-3 px-4 py-2 text-sm text-red-500`}
                        >
                          <ArrowRightOnRectangleIcon className="h-5 w-5" />
                          Sair
                        </button>
                      )}
                    </Menu.Item>
                  </Menu.Items>
                </Transition>
              </Menu>
            </div>
          </div>
        </div>

        {pulseData ? (
          <div className="border-b border-border-DEFAULT bg-surface-card/70 px-4 py-2 sm:px-6 lg:px-8">
            <div className="flex flex-wrap items-center gap-2 text-xs">
              <span className={`rounded-full border px-2.5 py-1 font-semibold ${pulseStateClass}`}>
                Operacao {pulseData.state === 'healthy' ? 'estavel' : pulseData.state === 'attention' ? 'em atencao' : 'critica'} ({pulseData.health_score}%)
              </span>

              <Link to="/admin/finance" className="rounded-full bg-blue-500/10 px-2.5 py-1 font-medium text-blue-600 hover:bg-blue-500/20">
                Saques pendentes: {pulseData.queues.pending_withdrawals}
              </Link>

              <Link to="/admin/control-tower" className="rounded-full bg-green-500/10 px-2.5 py-1 font-medium text-green-600 hover:bg-green-500/20">
                Abrir control tower
              </Link>

              <Link to="/admin/approvals" className="rounded-full bg-green-500/10 px-2.5 py-1 font-medium text-green-600 hover:bg-green-500/20">
                Aprovacoes: {pulseData.queues.pending_approvals}
              </Link>

              <Link to="/admin/kyc" className="rounded-full bg-cyan-500/10 px-2.5 py-1 font-medium text-cyan-600 hover:bg-cyan-500/20">
                KYC pendente: {pulseData.queues.pending_kyc}
              </Link>

              {!pulseData.switches.withdrawal_processing_enabled ? (
                <span className="rounded-full bg-red-500/10 px-2.5 py-1 font-medium text-red-600">
                  Processamento de saque pausado
                </span>
              ) : null}

              {!pulseData.switches.payments_enabled ? (
                <span className="rounded-full bg-red-500/10 px-2.5 py-1 font-medium text-red-600">
                  Pagamentos pausados
                </span>
              ) : null}
            </div>
          </div>
        ) : null}

        {/* Page content */}
        <main className="flex-1 overflow-y-auto">
          <div className="p-4 sm:p-6 lg:p-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}

function SidebarContent({
  isActiveRoute,
  pulseData,
}: {
  isActiveRoute: (href: string) => boolean;
  pulseData?: AdminPulse;
}) {
  const { user } = useAuthStore();

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

  const visibleNavigationGroups = navigationGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => hasPermission(item.permission)),
    }))
    .filter((group) => group.items.length > 0);

  return (
    <div className="flex grow flex-col gap-y-5 overflow-y-auto border-r border-white/10 bg-slate-950 px-6 pb-4">
      {/* Logo */}
      <div className="flex h-16 shrink-0 items-center">
        <Link to="/admin" className="flex items-center gap-3">
          <img
            src="/img/logo.png"
            alt="Vector Pagamentos"
            className="h-9 w-auto"
            loading="eager"
          />
          <span className="text-lg font-bold leading-tight text-white">Vector Pagamentos</span>
          <span className="ml-2 rounded bg-blue-500/20 px-2 py-0.5 text-xs font-semibold text-blue-300">
            Admin
          </span>
        </Link>
      </div>

      {/* Navigation */}
      <nav className="flex flex-1 flex-col">
        <ul role="list" className="flex flex-1 flex-col gap-y-4">
          {visibleNavigationGroups.map((group) => (
            <li key={group.title}>
              <p className="mb-2 px-2 text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">
                {group.title}
              </p>
              <ul className="space-y-1">
                {group.items.map((item) => {
                  const active = isActiveRoute(item.href);
                  const dynamicBadge =
                    item.href === '/admin/finance'
                      ? pulseData?.queues.pending_withdrawals
                      : item.href === '/admin/approvals'
                        ? pulseData?.queues.pending_approvals
                        : item.href === '/admin/kyc'
                          ? pulseData?.queues.pending_kyc
                          : item.badge;

                  return (
                    <li key={item.name}>
                      <Link
                        to={item.href}
                        className={`group flex items-center gap-x-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all ${
                          active
                            ? 'bg-gradient-to-r from-green-600 to-green-700 text-white shadow-lg shadow-green-900/30'
                            : 'text-slate-300 hover:bg-white/10 hover:text-white'
                        }`}
                      >
                        <item.icon
                          className={`h-5 w-5 shrink-0 ${
                            active ? 'text-white' : 'text-slate-400 group-hover:text-white'
                          }`}
                        />
                        {item.name}
                        {dynamicBadge && dynamicBadge > 0 && (
                          <span className="ml-auto flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1.5 text-xs font-bold text-white">
                            {dynamicBadge > 99 ? '99+' : dynamicBadge}
                          </span>
                        )}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>

        {/* Footer */}
        <div className="mt-auto border-t border-white/10 pt-4">
          <div className="flex items-center gap-3 rounded-lg bg-white/5 px-3 py-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-green-600 to-green-700 text-xs font-bold text-white">
              W
            </div>
            <div className="flex-1 min-w-0">
              <p className="truncate text-sm font-medium text-white">Vector Pagamentos Gateway</p>
              <p className="truncate text-xs text-slate-400">Sistema Administrativo</p>
            </div>
          </div>
        </div>
      </nav>
    </div>
  );
}


