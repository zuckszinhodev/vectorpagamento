import { useEffect, useMemo, useState, Fragment } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Menu, Transition } from '@headlessui/react';
import { useAuthStore } from '@/stores/authStore';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import clsx from 'clsx';
import AdminCommandPalette from '@/components/admin/AdminCommandPalette';
import { hasAdminPermission } from '@/lib/adminPermissions';
import {
  Bars3Icon,
  XMarkIcon,
  ChartBarIcon,
  BuildingStorefrontIcon,
  BanknotesIcon,
  DocumentTextIcon,
  ComputerDesktopIcon,
  ArrowRightOnRectangleIcon,
  UserCircleIcon,
  ChevronDownIcon,
  DocumentCheckIcon,
  ServerIcon,
  Cog6ToothIcon,
  ExclamationTriangleIcon,
  CpuChipIcon,
  LifebuoyIcon,
  ArrowLeftIcon,
  MagnifyingGlassIcon,
  ChevronLeftIcon,
  BellIcon,
  ShieldExclamationIcon,
  ArrowsRightLeftIcon,
  ReceiptPercentIcon,
  CubeIcon,
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
  queues?: {
    pending_withdrawals: number;
    pending_withdrawals_amount: number;
    pending_approvals: number;
    paid_orders_24h: number;
    pending_chargebacks: number;
    pending_kyc: number;
  };
  switches: {
    payments_enabled: boolean;
    withdrawals_enabled: boolean;
    withdrawal_processing_enabled: boolean;
    auto_withdrawal_enabled: boolean;
  };
  system?: {
    memory_usage_percent: number | null;
    memory_used_mb: number | null;
    memory_total_mb: number | null;
  };
  critical_alerts: string[];
  updated_at: string;
}

const navigationGroups: NavGroup[] = [
  {
    title: 'Visão Geral',
    items: [
      { name: 'Dashboard', href: '/admin', icon: ChartBarIcon, permission: 'admin.dashboard.read' },
    ],
  },
  {
    title: 'Pagamentos',
    items: [
      { name: 'Transações', href: '/admin/transactions', icon: DocumentTextIcon, permission: 'admin.withdrawals.manage' },
      { name: 'Saques', href: '/admin/withdrawals', icon: BanknotesIcon, permission: 'admin.withdrawals.manage' },
      { name: 'Disputas', href: '/admin/chargebacks', icon: ExclamationTriangleIcon, permission: 'admin.chargebacks.manage' },
    ],
  },
  {
    title: 'Clientes',
    items: [
      { name: 'Sellers', href: '/admin/sellers', icon: BuildingStorefrontIcon, permission: 'admin.customers.manage' },
      { name: 'Produtos', href: '/admin/products', icon: CubeIcon, permission: 'admin.showcase.manage' },
      { name: 'KYC', href: '/admin/kyc', icon: DocumentCheckIcon, permission: 'admin.kyc.review' },
      { name: 'Suporte', href: '/admin/support', icon: LifebuoyIcon, permission: 'admin.customers.manage' },
    ],
  },
  {
    title: 'Risco',
    items: [
      { name: 'Risco e fraude', href: '/admin/risk', icon: ShieldExclamationIcon, permission: 'admin.fraud.manage' },
    ],
  },
  {
    title: 'Plataforma',
    items: [
      { name: 'Taxas', href: '/admin/fees', icon: ReceiptPercentIcon, permission: 'admin.config.manage' },
      { name: 'Adquirentes', href: '/admin/acquirers', icon: ServerIcon, permission: 'admin.acquirers.manage' },
      { name: 'Roteamento', href: '/admin/acquirers/routing', icon: ArrowsRightLeftIcon, permission: 'admin.acquirers.manage' },
      { name: 'Configurações', href: '/admin/settings', icon: Cog6ToothIcon, permission: 'admin.config.manage' },
    ],
  },
];

const flatNavigation = navigationGroups.flatMap((group) => group.items);

// Abas do Dashboard: não estão no menu, mas a busca leva direto a elas
const dashboardTabs: Array<NavItem & { group: string }> = [
  { name: 'Operação', href: '/admin?tab=operacao', icon: CpuChipIcon, permission: 'admin.dashboard.read', group: 'Dashboard' },
  { name: 'Segurança', href: '/admin?tab=seguranca', icon: ComputerDesktopIcon, permission: 'admin.security.monitor', group: 'Dashboard' },
];

const COLLAPSED_STORAGE_KEY = 'admin_sidebar_collapsed';

function readSidebarCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSED_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

function StatusPill({ label, on }: { label: string; on: boolean }) {
  return (
    <span className="hidden items-center gap-1.5 rounded-full border border-white/[0.06] px-2.5 py-1 text-[11px] font-medium text-tx-secondary xl:inline-flex">
      <span className={clsx('h-1.5 w-1.5 rounded-full', on ? 'bg-emerald-400' : 'bg-red-500')} />
      {label}
    </span>
  );
}

export default function AdminLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(readSidebarCollapsed);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const { user, logout: authLogout } = useAuthStore();

  const paletteItems = useMemo(
    () =>
      [
        ...navigationGroups.flatMap((group) => group.items.map((item) => ({ ...item, group: group.title }))),
        ...dashboardTabs,
      ].filter((item) => hasAdminPermission(user, item.permission)),
    [user]
  );

  useEffect(() => {
    const handleShortcut = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setPaletteOpen(true);
      }
    };
    window.addEventListener('keydown', handleShortcut);
    return () => window.removeEventListener('keydown', handleShortcut);
  }, []);

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSED_STORAGE_KEY, sidebarCollapsed ? '1' : '0');
    } catch {
      // Preferência só desta sessão
    }
  }, [sidebarCollapsed]);

  const { data: pulseData } = useQuery<AdminPulse>({
    queryKey: ['admin-pulse'],
    queryFn: async () => {
      const response = await api.get('/admin/pulse');
      return response.data?.data;
    },
    refetchInterval: 10000,
  });

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Ignore
    }
    authLogout();
    navigate('/login');
  };

  // A rota mais específica vence: em /admin/acquirers/routing só Roteamento fica ativo
  const activeItem = flatNavigation
    .filter((item) => location.pathname === item.href || (item.href !== '/admin' && location.pathname.startsWith(`${item.href}/`)))
    .sort((a, b) => b.href.length - a.href.length)[0];

  const isActiveRoute = (href: string) => activeItem?.href === href;

  const currentPageTitle = activeItem?.name || 'Admin';

  const switches = pulseData?.switches;
  const criticalAlerts = pulseData?.critical_alerts ?? [];
  const queues = pulseData?.queues;
  const pendingWork = [
    { label: 'Saques pendentes', count: queues?.pending_withdrawals ?? 0, href: '/admin/withdrawals' },
    { label: 'KYC para revisar', count: queues?.pending_kyc ?? 0, href: '/admin/kyc' },
    { label: 'Disputas abertas', count: queues?.pending_chargebacks ?? 0, href: '/admin/chargebacks' },
  ].filter((item) => item.count > 0);
  const notificationCount = criticalAlerts.length + pendingWork.length;
  const haltedServices = [
    switches && !switches.payments_enabled ? 'Pagamentos' : null,
    switches && !switches.withdrawals_enabled ? 'Saques' : null,
  ].filter(Boolean);

  const sidebarWidth = sidebarCollapsed ? 'w-[72px]' : 'w-[260px]';
  const sidebarPl = sidebarCollapsed ? 'lg:pl-[72px]' : 'lg:pl-[260px]';

  return (
    <div className="flex h-screen overflow-hidden bg-surface-page">
      {/* Mobile sidebar overlay */}
      <Transition.Root show={sidebarOpen} as={Fragment}>
        <div className="relative z-50 lg:hidden">
          <Transition.Child
            as={Fragment}
            enter="transition-opacity ease-out duration-300"
            enterFrom="opacity-0"
            enterTo="opacity-100"
            leave="transition-opacity ease-in duration-200"
            leaveFrom="opacity-100"
            leaveTo="opacity-0"
          >
            <div className="fixed inset-0 bg-black/40 backdrop-blur-sm" onClick={() => setSidebarOpen(false)} />
          </Transition.Child>

          <div className="fixed inset-0 flex">
            <Transition.Child
              as={Fragment}
              enter="transition ease-out duration-300 transform"
              enterFrom="-translate-x-full"
              enterTo="translate-x-0"
              leave="transition ease-in duration-200 transform"
              leaveFrom="translate-x-0"
              leaveTo="-translate-x-full"
            >
              <div className="relative flex w-full max-w-[260px] flex-1">
                <button
                  type="button"
                  className="absolute right-3 top-3 z-10 rounded-lg p-1.5 text-white/60 hover:text-white hover:bg-white/10 transition-colors"
                  onClick={() => setSidebarOpen(false)}
                >
                  <XMarkIcon className="h-5 w-5" />
                </button>
                <SidebarContent
                  isActiveRoute={isActiveRoute}
                  pulseData={pulseData}
                  collapsed={false}
                  onToggleCollapse={() => {}}
                />
              </div>
            </Transition.Child>
          </div>
        </div>
      </Transition.Root>

      {/* Desktop sidebar */}
      <div className={clsx('hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:flex-col transition-all duration-300 ease-in-out', sidebarWidth)}>
        <SidebarContent
          isActiveRoute={isActiveRoute}
          pulseData={pulseData}
          collapsed={sidebarCollapsed}
          onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
        />
      </div>

      {/* Main content area */}
      <div className={clsx('flex min-w-0 flex-1 flex-col transition-all duration-300 ease-in-out', sidebarPl)}>
        {/* Top bar */}
        <header className="sticky top-0 z-40 flex h-14 shrink-0 items-center gap-x-4 border-b border-border-DEFAULT bg-surface-page/80 backdrop-blur-xl px-4 sm:px-6">
          {/* Mobile menu toggle */}
          <button
            type="button"
            className="rounded-lg p-2 text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-colors lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Bars3Icon className="h-5 w-5" />
          </button>

          <div className="h-5 w-px bg-border-DEFAULT lg:hidden" />

          {/* Breadcrumb */}
          <div className="flex items-center gap-2 min-w-0">
            <Link to="/admin" className="text-xs text-tx-muted hover:text-tx-secondary transition-colors">
              Admin
            </Link>
            {currentPageTitle !== 'Dashboard' && (
              <>
                <span className="text-tx-muted">/</span>
                <span className="text-sm font-semibold text-tx-primary truncate">{currentPageTitle}</span>
              </>
            )}
            {currentPageTitle === 'Dashboard' && (
              <span className="text-sm font-semibold text-tx-primary">Dashboard</span>
            )}
          </div>

          <div className="ml-auto flex items-center gap-x-2">
            {switches && (
              <>
                <StatusPill label="Pagamentos" on={switches.payments_enabled} />
                <StatusPill label="Saques" on={switches.withdrawals_enabled} />
              </>
            )}

            {/* Global search */}
            <button
              onClick={() => setPaletteOpen(true)}
              aria-label="Buscar telas e sellers"
              className="flex items-center gap-2 rounded-lg border border-border-DEFAULT px-2.5 py-1.5 text-xs text-tx-muted transition-colors hover:border-accent-primary hover:text-tx-primary"
            >
              <MagnifyingGlassIcon className="h-4 w-4" />
              <span className="hidden sm:inline">Buscar</span>
              <kbd className="hidden text-[10px] md:inline">Ctrl K</kbd>
            </button>

            {/* Notifications */}
            <Menu as="div" className="relative">
              <Menu.Button aria-label="Alertas" className="relative rounded-lg p-2 text-tx-muted transition-colors hover:bg-surface-hover hover:text-tx-primary">
                <BellIcon className="h-5 w-5" />
                {notificationCount > 0 && <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500" />}
              </Menu.Button>
              <Menu.Items className="absolute right-0 z-10 mt-2 w-80 origin-top-right rounded-xl border border-border-DEFAULT bg-surface-elevated p-2 focus:outline-none">
                <p className="px-2 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Precisa de atenção</p>
                {criticalAlerts.map((alert) => (
                  <p key={alert} className="flex items-start gap-2 px-2 py-1.5 text-sm text-red-400">
                    <ExclamationTriangleIcon className="mt-0.5 h-4 w-4 shrink-0" />
                    {alert}
                  </p>
                ))}
                {pendingWork.map((item) => (
                  <Menu.Item key={item.href}>
                    {({ active }) => (
                      <Link
                        to={item.href}
                        className={clsx('flex items-center justify-between rounded-lg px-2 py-1.5 text-sm text-tx-secondary', active && 'bg-surface-hover')}
                      >
                        {item.label}
                        <span className="font-semibold text-tx-primary">{item.count}</span>
                      </Link>
                    )}
                  </Menu.Item>
                ))}
                {notificationCount === 0 && <p className="px-2 py-3 text-sm text-tx-muted">Nada pendente.</p>}
              </Menu.Items>
            </Menu>

            {/* Profile */}
            <Menu as="div" className="relative">
              <Menu.Button className="flex items-center gap-2 rounded-xl p-1.5 hover:bg-surface-hover transition-colors">
                <div className="flex flex-col items-start">
                  <span className="text-xs font-semibold text-tx-primary">{user?.name || 'Admin'}</span>
                  <span className="text-[10px] text-tx-muted">{user?.role || 'owner'}</span>
                </div>
                <ChevronDownIcon className="hidden lg:block h-3.5 w-3.5 text-tx-muted" />
              </Menu.Button>

              <Transition
                as={Fragment}
                enter="transition ease-out duration-150"
                enterFrom="opacity-0 scale-95 translate-y-1"
                enterTo="opacity-100 scale-100 translate-y-0"
                leave="transition ease-in duration-100"
                leaveFrom="opacity-100 scale-100 translate-y-0"
                leaveTo="opacity-0 scale-95 translate-y-1"
              >
                <Menu.Items className="absolute right-0 z-10 mt-2 w-52 origin-top-right rounded-xl bg-surface-elevated border border-border-DEFAULT py-1 shadow-float focus:outline-none">
                  <div className="px-3 py-2.5 border-b border-border-DEFAULT">
                    <p className="text-sm font-semibold text-tx-primary truncate">{user?.name}</p>
                    <p className="text-xs text-tx-muted truncate">{user?.email}</p>
                  </div>
                  <div className="p-1">
                    <Menu.Item>
                      {({ active }) => (
                        <Link
                          to="/dashboard"
                          className={clsx(
                            'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                            active ? 'bg-surface-hover text-tx-primary' : 'text-tx-secondary'
                          )}
                        >
                          <UserCircleIcon className="h-4 w-4" />
                          Meu Dashboard
                        </Link>
                      )}
                    </Menu.Item>
                    <Menu.Item>
                      {({ active }) => (
                        <Link
                          to="/settings"
                          className={clsx(
                            'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                            active ? 'bg-surface-hover text-tx-primary' : 'text-tx-secondary'
                          )}
                        >
                          <Cog6ToothIcon className="h-4 w-4" />
                          Configurações
                        </Link>
                      )}
                    </Menu.Item>
                  </div>
                  <div className="border-t border-border-DEFAULT p-1">
                    <Menu.Item>
                      {({ active }) => (
                        <button
                          onClick={handleLogout}
                          className={clsx(
                            'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                            active ? 'bg-status-error-subtle text-status-error' : 'text-red-500'
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
          </div>
        </header>

        {haltedServices.length > 0 && (
          <div role="alert" className="flex items-center gap-2 border-b border-red-500/30 bg-red-500/10 px-4 py-2 text-sm font-medium text-red-300 sm:px-6">
            <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
            {haltedServices.join(' e ')} desligado{haltedServices.length > 1 ? 's' : ''} na plataforma.
          </div>
        )}

        {/* Page content */}
        <main className="min-w-0 flex-1 overflow-y-auto overflow-x-hidden">
          <div className="mx-auto w-full max-w-[1440px] min-w-0 p-4 sm:p-6 lg:p-8">
            <Outlet />
          </div>
        </main>
      </div>

      <AdminCommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        items={paletteItems}
        canSearchSellers={hasAdminPermission(user, 'admin.customers.manage')}
      />
    </div>
  );
}

// ── Sidebar ────────────────────────────────────────────

function SidebarContent({
  isActiveRoute,
  pulseData,
  collapsed,
  onToggleCollapse,
}: {
  isActiveRoute: (href: string) => boolean;
  pulseData?: AdminPulse;
  collapsed: boolean;
  onToggleCollapse: () => void;
}) {
  const { user } = useAuthStore();

  const visibleNavigationGroups = navigationGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => hasAdminPermission(user, item.permission)),
    }))
    .filter((group) => group.items.length > 0);

  return (
    <div className="flex h-full grow flex-col overflow-hidden border-r border-white/[0.06] bg-[#0b0b0d] text-white">
      {/* Logo area */}
      <div className="flex h-14 shrink-0 items-center justify-between px-4">
        {!collapsed && (
          <Link to="/admin" className="flex items-center gap-2.5 min-w-0">
            <img src="/img/gorillapay-logo.webp" alt="GorillaPay" width={1024} height={650} className="h-8 w-auto object-contain" />
            <p className="text-[10px] font-semibold text-red-400 uppercase tracking-wider">Admin</p>
          </Link>
        )}
        <button
          onClick={onToggleCollapse}
          className="hidden lg:flex h-6 w-6 items-center justify-center rounded-md text-white/30 hover:text-white/70 hover:bg-white/5 transition-colors"
        >
          <ChevronLeftIcon className={clsx('h-3.5 w-3.5 transition-transform duration-300', collapsed && 'rotate-180')} />
        </button>
      </div>

      {/* Navigation */}
      <nav className="flex flex-1 flex-col overflow-y-auto px-2 pb-4 scrollbar-thin scrollbar-thumb-white/10">
        <ul role="list" className="flex flex-1 flex-col gap-y-5">
          {visibleNavigationGroups.map((group) => (
            <li key={group.title}>
              {!collapsed && (
                <p className="mb-1.5 px-2.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-white/25">
                  {group.title}
                </p>
              )}
              {collapsed && <div className="mx-auto mb-1.5 h-px w-6 bg-white/10" />}
              <ul className="space-y-0.5">
                {group.items.map((item) => {
                  const active = isActiveRoute(item.href);
                  const dynamicBadge =
                    item.href === '/admin/withdrawals' ? pulseData?.queues?.pending_withdrawals :
                    item.href === '/admin/kyc' ? pulseData?.queues?.pending_kyc :
                    item.href === '/admin/chargebacks' ? pulseData?.queues?.pending_chargebacks :
                    item.href === '/admin' ? pulseData?.critical_alerts?.length :
                    item.badge;
                  const badgeCount = Number(dynamicBadge ?? 0);

                  return (
                    <li key={item.name}>
                      <Link
                        to={item.href}
                        title={collapsed ? item.name : undefined}
                        className={clsx(
                          'group relative flex items-center gap-x-2.5 rounded-lg px-2.5 py-2 text-[13px] font-medium transition-all duration-200',
                          collapsed && 'justify-center px-0',
                          active
                            ? 'bg-white/[0.1] text-white shadow-sm'
                            : 'text-white/50 hover:bg-white/[0.05] hover:text-white/80'
                        )}
                      >
                        {active && (
                          <div className="absolute left-0 top-1/2 -translate-y-1/2 h-5 w-[3px] rounded-r-full bg-accent-primary" />
                        )}
                        <item.icon className={clsx('h-[18px] w-[18px] shrink-0 transition-colors', active ? 'text-accent-primary' : 'text-white/40 group-hover:text-white/60')} />
                        {!collapsed && <span className="min-w-0 flex-1 truncate">{item.name}</span>}
                        {!collapsed && badgeCount > 0 && (
                          <span className={clsx(
                            'ml-auto flex h-5 min-w-[20px] items-center justify-center rounded-full px-1.5 text-[10px] font-bold',
                            active ? 'bg-accent-primary text-white' : 'bg-white/10 text-white/70'
                          )}>
                            {badgeCount > 99 ? '99+' : badgeCount}
                          </span>
                        )}
                        {collapsed && badgeCount > 0 && (
                          <span className="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full bg-accent-primary ring-2 ring-[#0b0b0d]" />
                        )}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      </nav>

      {/* Bottom section */}
      {!collapsed && (
        <div className="shrink-0 border-t border-white/[0.06] p-3">
          <Link
            to="/dashboard"
            className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] font-medium text-white/40 hover:bg-white/[0.05] hover:text-white/70 transition-colors"
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar ao Dashboard
          </Link>
        </div>
      )}
    </div>
  );
}
