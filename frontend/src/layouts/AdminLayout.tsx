import { useState, Fragment } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Menu, Transition } from '@headlessui/react';
import { useAuthStore } from '@/stores/authStore';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  Bars3Icon,
  XMarkIcon,
  ChartBarIcon,
  BuildingStorefrontIcon,
  BanknotesIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  ComputerDesktopIcon,
  BellIcon,
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
    title: 'Plataforma',
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

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Ignore
    }
    authLogout();
    navigate('/login');
  };

  const isActiveRoute = (href: string) => {
    if (href === '/admin') return location.pathname === '/admin';
    return location.pathname.startsWith(href);
  };

  const currentPageTitle =
    flatNavigation.find((item) => location.pathname === item.href)?.name ||
    flatNavigation.find((item) => item.href !== '/admin' && location.pathname.startsWith(item.href))?.name ||
    'Admin';

  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      {/* Mobile sidebar */}
      <Transition.Root show={sidebarOpen} as={Fragment}>
        <div className="relative z-50 lg:hidden">
          <Transition.Child
            as={Fragment}
            enter="transition-opacity ease-linear duration-200"
            enterFrom="opacity-0"
            enterTo="opacity-100"
            leave="transition-opacity ease-linear duration-200"
            leaveFrom="opacity-100"
            leaveTo="opacity-0"
          >
            <div className="fixed inset-0 bg-black/30" onClick={() => setSidebarOpen(false)} />
          </Transition.Child>

          <div className="fixed inset-0 flex">
            <Transition.Child
              as={Fragment}
              enter="transition ease-in-out duration-200 transform"
              enterFrom="-translate-x-full"
              enterTo="translate-x-0"
              leave="transition ease-in-out duration-200 transform"
              leaveFrom="translate-x-0"
              leaveTo="-translate-x-full"
            >
              <div className="relative mr-16 flex w-full max-w-[272px] flex-1">
                <div className="absolute left-full top-0 flex w-16 justify-center pt-5">
                  <button type="button" className="-m-2.5 p-2.5" onClick={() => setSidebarOpen(false)}>
                    <XMarkIcon className="h-6 w-6 text-gray-600" />
                  </button>
                </div>
                <SidebarContent isActiveRoute={isActiveRoute} pulseData={pulseData} />
              </div>
            </Transition.Child>
          </div>
        </div>
      </Transition.Root>

      {/* Desktop sidebar */}
      <div className="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-[272px] lg:flex-col">
        <SidebarContent isActiveRoute={isActiveRoute} pulseData={pulseData} />
      </div>

      {/* Main content */}
      <div className="flex flex-1 flex-col lg:pl-[272px]">
        {/* Top bar */}
        <header className="sticky top-0 z-40 flex h-14 items-center gap-x-4 border-b border-gray-200 bg-white px-4 sm:px-6 lg:px-8">
          <button
            type="button"
            className="-m-2.5 p-2.5 text-gray-500 lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Bars3Icon className="h-5 w-5" />
          </button>

          <div className="h-5 w-px bg-gray-200 lg:hidden" />

          <h1 className="text-sm font-semibold text-gray-900">{currentPageTitle}</h1>

          <div className="ml-auto flex items-center gap-x-3">
            {/* Notifications */}
            <button
              type="button"
              className="relative rounded-md p-1.5 text-gray-400 hover:text-gray-600"
            >
              <BellIcon className="h-5 w-5" />
              {unreadCount > 0 && (
                <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-medium text-white">
                  {unreadCount > 99 ? '99+' : unreadCount}
                </span>
              )}
            </button>

            {/* Profile */}
            <Menu as="div" className="relative">
              <Menu.Button className="flex items-center gap-2 rounded-md p-1.5 hover:bg-gray-50">
                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-xs font-medium text-white">
                  {user?.name?.charAt(0)?.toUpperCase() || 'A'}
                </div>
                <span className="hidden text-sm font-medium text-gray-700 lg:block">{user?.name || 'Admin'}</span>
                <ChevronDownIcon className="hidden h-4 w-4 text-gray-400 lg:block" />
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
                <Menu.Items className="absolute right-0 z-10 mt-2 w-44 origin-top-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-200 focus:outline-none">
                  <Menu.Item>
                    {({ active }) => (
                      <Link
                        to="/dashboard"
                        className={`${active ? 'bg-gray-50' : ''} flex items-center gap-2 px-3 py-2 text-sm text-gray-700`}
                      >
                        <UserCircleIcon className="h-4 w-4 text-gray-400" />
                        Meu Dashboard
                      </Link>
                    )}
                  </Menu.Item>
                  <Menu.Item>
                    {({ active }) => (
                      <button
                        onClick={handleLogout}
                        className={`${active ? 'bg-gray-50' : ''} flex w-full items-center gap-2 px-3 py-2 text-sm text-red-600`}
                      >
                        <ArrowRightOnRectangleIcon className="h-4 w-4" />
                        Sair
                      </button>
                    )}
                  </Menu.Item>
                </Menu.Items>
              </Transition>
            </Menu>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-[1400px] p-4 sm:p-6 lg:p-8">
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

  const pulseColor =
    pulseData?.state === 'critical' ? 'bg-red-500' :
    pulseData?.state === 'attention' ? 'bg-amber-500' : 'bg-emerald-500';

  return (
    <div className="flex grow flex-col overflow-y-auto border-r border-gray-200 bg-white px-4 pb-4">
      {/* Logo */}
      <div className="flex h-14 shrink-0 items-center px-2">
        <Link to="/admin" className="flex items-center gap-2">
          <span className="text-base font-semibold text-gray-900">Vector</span>
          <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-500">
            Admin
          </span>
        </Link>
      </div>

      {/* Pulse indicator */}
      {pulseData && (
        <div className="mb-4 flex items-center gap-2 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
          <span className={`h-2 w-2 rounded-full ${pulseColor}`} />
          <span className="text-xs font-medium text-gray-600">
            {pulseData.state === 'healthy' ? 'Operação estável' : pulseData.state === 'attention' ? 'Atenção' : 'Crítico'}
          </span>
          <span className="ml-auto text-xs text-gray-400">{pulseData.health_score}%</span>
        </div>
      )}

      {/* Navigation */}
      <nav className="flex flex-1 flex-col">
        <ul role="list" className="flex flex-1 flex-col gap-y-5">
          {visibleNavigationGroups.map((group) => (
            <li key={group.title}>
              <p className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                {group.title}
              </p>
              <ul className="space-y-0.5">
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
                        className={`group flex items-center gap-x-2.5 rounded-md px-2.5 py-2 text-[13px] font-medium transition-colors ${
                          active
                            ? 'bg-gray-100 text-gray-900'
                            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                        }`}
                      >
                        <item.icon
                          className={`h-[18px] w-[18px] shrink-0 ${
                            active ? 'text-gray-900' : 'text-gray-400 group-hover:text-gray-600'
                          }`}
                        />
                        {item.name}
                        {dynamicBadge && dynamicBadge > 0 && (
                          <span className="ml-auto flex h-5 min-w-[20px] items-center justify-center rounded-full bg-gray-900 px-1.5 text-[10px] font-medium text-white">
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
      </nav>
    </div>
  );
}
