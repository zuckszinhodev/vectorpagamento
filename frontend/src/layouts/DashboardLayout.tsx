import { Fragment, ReactNode, useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Menu, Transition } from '@headlessui/react';
import {
  ArrowRightOnRectangleIcon,
  ArrowUturnLeftIcon,
  BanknotesIcon,
  Bars3Icon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  CubeIcon,
  CurrencyDollarIcon,
  HomeIcon,
  MagnifyingGlassIcon,
  MegaphoneIcon,
  QuestionMarkCircleIcon,
  RectangleGroupIcon,
  ShoppingBagIcon,
  SparklesIcon,
  UserGroupIcon,
  UserIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { useAuthStore } from '@/stores/authStore';

interface DashboardLayoutProps {
  children?: ReactNode;
}

interface NavItem {
  name: string;
  href: string;
  icon: React.ForwardRefExoticComponent<any>;
  match: string[];
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/dashboard', icon: HomeIcon, match: ['/dashboard'] },
  { name: 'Transações', href: '/charges', icon: CurrencyDollarIcon, match: ['/charges', '/chargebacks'] },
  { name: 'Financeiro', href: '/withdrawals', icon: BanknotesIcon, match: ['/withdrawals', '/reports'] },
  { name: 'Produtos', href: '/products', icon: CubeIcon, match: ['/products'] },
  { name: 'Vitrine', href: '/vitrine', icon: ShoppingBagIcon, match: ['/vitrine'] },
  { name: 'Afiliados', href: '/splits', icon: UserGroupIcon, match: ['/splits'] },
  { name: 'Indique e Ganhe', href: '/affiliates', icon: MegaphoneIcon, match: ['/affiliates'] },
  { name: 'Adquirentes', href: '/acquirers', icon: RectangleGroupIcon, match: ['/acquirers'] },
  { name: 'Backlog', href: '/support', icon: ClipboardDocumentListIcon, match: ['/support', '/webhook-logs'] },
];

const pageTitles: Record<string, string> = {
  '/dashboard': 'Dashboard',
  '/vitrine': 'Vitrine',
  '/charges': 'Transações',
  '/chargebacks': 'Transações',
  '/acquirers': 'Adquirentes',
  '/products': 'Produtos',
  '/checkouts': 'Assinaturas',
  '/member-area': 'Área de Membros',
  '/domains': 'Domínios',
  '/withdrawals': 'Financeiro',
  '/settings': 'Configurações',
  '/affiliates': 'Indique e Ganhe',
  '/landing-pages': 'Landing Pages',
  '/analytics': 'Análises',
  '/reports': 'Financeiro',
  '/coupons': 'Assinaturas',
  '/integrations': 'Integrações',
  '/messaging': 'Mensagens',
  '/support': 'Backlog',
  '/splits': 'Afiliados',
  '/wazer-ia': 'Vector Pagamentos IA',
  '/admin': 'Administração',
};

function BrandMark() {
  return (
    <div className="flex items-center">
      <img src="/img/logo.png" alt="Vector Pagamentos" className="h-10 w-auto" />
    </div>
  );
}

export default function DashboardLayout({ children }: DashboardLayoutProps) {
  const { user, logout, isImpersonating, stopImpersonation } = useAuthStore();
  const navigate = useNavigate();
  const location = useLocation();

  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [mobileShowAllNav, setMobileShowAllNav] = useState(false);
  const hasOriginalAdminToken = typeof window !== 'undefined' && Boolean(sessionStorage.getItem('admin_original_token'));
  const isImpersonationSession = isImpersonating || hasOriginalAdminToken;

  const isAdmin = ['owner', 'admin', 'support', 'financial', 'antifraud'].includes(user?.role || '');

  useEffect(() => {
    const path = location.pathname;
    const title = pageTitles[path] || Object.entries(pageTitles).find(([key]) => path.startsWith(key))?.[1] || 'Dashboard';
    document.title = `${title} | Vector Pagamentos`;
  }, [location.pathname]);

  useEffect(() => {
    if (!mobileMenuOpen) {
      setMobileShowAllNav(false);
    }
  }, [mobileMenuOpen]);

  const handleReturnToAdmin = () => {
    stopImpersonation();
    navigate('/admin', { replace: true });
  };

  const handleLogout = () => {
    if (isImpersonationSession) {
      handleReturnToAdmin();
      return;
    }

    logout();
    navigate('/login');
  };

  const isItemActive = (item: NavItem) => {
    return item.match.some((prefix) => location.pathname === prefix || location.pathname.startsWith(prefix + '/'));
  };

  const mobilePrimaryHrefs = ['/dashboard', '/charges', '/withdrawals', '/products', '/checkouts', '/vitrine'];
  const mobilePrimaryNavigation = navigation.filter((item) => mobilePrimaryHrefs.includes(item.href));
  const mobileSecondaryNavigation = navigation.filter((item) => !mobilePrimaryHrefs.includes(item.href));

  const SidebarContent = ({ isMobile = false }: { isMobile?: boolean }) => (
    <div className="flex h-full flex-col bg-[#080a10]">
      <div className={clsx('flex items-center border-b border-white/10 px-5', isMobile ? 'h-[72px]' : 'h-[84px]')}>
        <BrandMark />
      </div>

      <div className="flex-1 overflow-y-auto px-3 py-4">
        <p className="px-3 pb-3 text-sm font-semibold uppercase tracking-[0.08em] text-zinc-400">
          {isMobile ? 'Acesso Rápido' : 'Geral'}
        </p>
        <nav className="space-y-1.5">
          {(isMobile ? mobilePrimaryNavigation : navigation).map((item) => {
            const active = isItemActive(item);
            return (
              <NavLink
                key={item.name}
                to={item.href}
                onClick={() => {
                  setMobileMenuOpen(false);
                  setMobileShowAllNav(false);
                }}
                className={clsx(
                  'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-base font-semibold transition-all duration-200',
                  active
                    ? 'bg-white/12 text-white'
                    : 'text-zinc-400 hover:bg-white/8 hover:text-zinc-100'
                )}
              >
                <item.icon className={clsx('h-5 w-5', active ? 'text-white' : 'text-zinc-500 group-hover:text-zinc-300')} />
                <span className="whitespace-nowrap">{item.name}</span>
              </NavLink>
            );
          })}
        </nav>

        {isMobile && mobileSecondaryNavigation.length > 0 && (
          <div className="mt-4">
            <button
              type="button"
              onClick={() => setMobileShowAllNav((prev) => !prev)}
              className="w-full rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2.5 text-left text-sm font-semibold text-zinc-300 transition-colors hover:bg-white/[0.08]"
            >
              {mobileShowAllNav ? 'Ocultar opções extras' : 'Ver mais opções'}
            </button>

            {mobileShowAllNav && (
              <div className="mt-2 rounded-xl border border-white/10 bg-white/[0.03] p-2">
                <p className="px-2 pb-2 text-xs font-semibold uppercase tracking-[0.08em] text-zinc-500">Mais opções</p>
                <nav className="space-y-1">
                  {mobileSecondaryNavigation.map((item) => {
                    const active = isItemActive(item);
                    return (
                      <NavLink
                        key={item.name}
                        to={item.href}
                        onClick={() => {
                          setMobileMenuOpen(false);
                          setMobileShowAllNav(false);
                        }}
                        className={clsx(
                          'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
                          active
                            ? 'bg-white/12 text-white'
                            : 'text-zinc-400 hover:bg-white/8 hover:text-zinc-100'
                        )}
                      >
                        <item.icon className={clsx('h-5 w-5', active ? 'text-white' : 'text-zinc-500 group-hover:text-zinc-300')} />
                        <span className="whitespace-nowrap">{item.name}</span>
                      </NavLink>
                    );
                  })}
                </nav>
              </div>
            )}
          </div>
        )}
      </div>

      <div className="border-t border-white/10 p-4">
        {!isMobile && (
          <div className="rounded-xl border border-white/10 bg-white/5 px-3 py-3">
            <div className="mb-2 flex items-center justify-between text-sm text-zinc-300">
              <span>Faturamento</span>
              <span className="font-semibold">R$ 0 / R$ 10.000</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-white/10">
              <div className="h-full w-[2%] rounded-full bg-green-500" />
            </div>
          </div>
        )}

        <button
          onClick={handleLogout}
          className={clsx(
            'inline-flex w-full items-center justify-start gap-2 rounded-xl px-3 py-2.5 text-base font-medium text-zinc-300 transition-colors hover:bg-white/8 hover:text-white',
            isMobile ? 'mt-0' : 'mt-3'
          )}
        >
          <ArrowRightOnRectangleIcon className="h-5 w-5" />
          Sair
        </button>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen bg-[#05070c] text-zinc-100">
      <Transition.Root show={mobileMenuOpen} as={Fragment}>
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
            <div className="fixed inset-0 bg-black/70 backdrop-blur-sm" onClick={() => setMobileMenuOpen(false)} />
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
              <div className="relative mr-16 flex w-full max-w-[320px] flex-1 border-r border-white/10">
                <div className="absolute left-full top-0 flex w-16 justify-center pt-5">
                  <button type="button" className="-m-2.5 p-2.5 text-white" onClick={() => setMobileMenuOpen(false)}>
                    <XMarkIcon className="h-7 w-7" />
                  </button>
                </div>
                <SidebarContent isMobile />
              </div>
            </Transition.Child>
          </div>
        </div>
      </Transition.Root>

      <div className="flex min-h-screen">
        <aside className="hidden w-[310px] shrink-0 border-r border-white/10 lg:block">
          <SidebarContent />
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          {isImpersonationSession && (
            <div className="sticky top-0 z-[70] flex items-center justify-center gap-3 bg-green-600 px-4 py-2 text-xs font-semibold text-white">
              <span>Modo impersonação: logado como {user?.name}</span>
              <button
                onClick={handleReturnToAdmin}
                className="rounded-md bg-white/20 px-2 py-0.5 text-xs hover:bg-white/30"
              >
                Voltar
              </button>
            </div>
          )}

          <header className="sticky top-0 z-40 border-b border-white/10 bg-[#07090f]/95 backdrop-blur">
            <div className="flex h-[84px] items-center gap-3 px-4 sm:px-6 lg:px-8">
              <button
                type="button"
                className="rounded-xl border border-white/10 bg-white/5 p-2 text-zinc-300 lg:hidden"
                onClick={() => setMobileMenuOpen(true)}
              >
                <Bars3Icon className="h-6 w-6" />
              </button>

              <div className="relative w-full max-w-xl">
                <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-500" />
                <input
                  type="text"
                  placeholder="Pesquisar páginas (Ctrl+K)"
                  className="h-12 w-full rounded-xl border border-white/10 bg-white/[0.04] pl-10 pr-4 text-base text-zinc-200 placeholder-zinc-500 outline-none transition-colors focus:border-green-400/80"
                />
              </div>

              <div className="ml-auto flex items-center gap-2 sm:gap-3">
                <Menu as="div" className="relative">
                  <Menu.Button className="group flex items-center gap-3 rounded-2xl border border-white/15 bg-gradient-to-br from-white/[0.09] to-white/[0.02] px-2.5 py-2 text-zinc-200 shadow-[inset_0_1px_0_rgba(255,255,255,0.06)] transition-all hover:border-green-400/40 hover:from-green-500/15 hover:to-white/[0.04]">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-green-500 to-green-700 font-bold text-white shadow-[0_8px_24px_rgba(22,163,74,0.3)]">
                      {(user?.name || 'G')[0].toUpperCase()}
                    </div>
                    <div className="hidden min-w-0 text-left sm:block">
                      <p className="truncate text-sm font-semibold leading-4 text-zinc-100">{user?.name || 'Seller'}</p>
                      <p className="mt-0.5 truncate text-xs text-zinc-400">{user?.email || 'seller@vectorpagamentos.com'}</p>
                    </div>
                  </Menu.Button>

                  <Transition
                    as={Fragment}
                    enter="transition ease-out duration-150"
                    enterFrom="transform opacity-0 scale-95"
                    enterTo="transform opacity-100 scale-100"
                    leave="transition ease-in duration-100"
                    leaveFrom="transform opacity-100 scale-100"
                    leaveTo="transform opacity-0 scale-95"
                  >
                    <Menu.Items className="absolute right-0 z-50 mt-2 w-56 origin-top-right overflow-hidden rounded-2xl border border-white/10 bg-[#11131b] p-2 shadow-2xl focus:outline-none">
                      <div className="border-b border-white/10 px-3 py-2">
                        <p className="truncate text-sm font-semibold text-zinc-100">{user?.name || 'Usuário'}</p>
                        <p className="truncate text-xs text-zinc-400">{user?.email}</p>
                      </div>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/settings')}
                            className={clsx(
                              'mt-2 flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm',
                              active ? 'bg-white/10 text-zinc-100' : 'text-zinc-300'
                            )}
                          >
                            <Cog6ToothIcon className="h-5 w-5" />
                            Configurações
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => navigate('/support')}
                            className={clsx(
                              'flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm',
                              active ? 'bg-white/10 text-zinc-100' : 'text-zinc-300'
                            )}
                          >
                            <QuestionMarkCircleIcon className="h-5 w-5" />
                            Suporte
                          </button>
                        )}
                      </Menu.Item>
                      {isAdmin && (
                        <Menu.Item>
                          {({ active }) => (
                            <button
                              onClick={() => navigate('/admin')}
                              className={clsx(
                                'flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm',
                                active ? 'bg-white/10 text-zinc-100' : 'text-zinc-300'
                              )}
                            >
                              <UserIcon className="h-5 w-5" />
                              Administração
                            </button>
                          )}
                        </Menu.Item>
                      )}
                      {isImpersonationSession && (
                        <Menu.Item>
                          {({ active }) => (
                            <button
                              onClick={handleReturnToAdmin}
                              className={clsx(
                                'flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm',
                                active ? 'bg-green-500/15 text-green-200' : 'text-green-300'
                              )}
                            >
                              <ArrowUturnLeftIcon className="h-5 w-5" />
                              Voltar para Admin
                            </button>
                          )}
                        </Menu.Item>
                      )}
                      <div className="my-2 border-t border-white/10" />
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={handleLogout}
                            className={clsx(
                              'flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm',
                              active ? 'bg-red-500/15 text-red-300' : 'text-zinc-300'
                            )}
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
          </header>

          <main className="flex-1 overflow-y-auto px-4 pb-6 pt-5 sm:px-6 lg:px-8">
            {user?.kyc_status && user.kyc_status !== 'approved' && (
              <div className="mb-5 flex items-center gap-3 rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-3">
                <QuestionMarkCircleIcon className="h-5 w-5 shrink-0 text-amber-400" />
                <p className="flex-1 text-sm font-medium text-amber-100">
                  Complete seu cadastro para continuar usando a plataforma.
                </p>
                <Link
                  to="/kyc"
                  className="rounded-lg border border-amber-300/40 bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-500/25"
                >
                  Complete seu cadastro aqui
                </Link>
              </div>
            )}

            {children || <Outlet />}
          </main>
        </div>
      </div>
    </div>
  );
}

