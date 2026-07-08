import { Outlet, NavLink, Link, useNavigate, useLocation } from 'react-router-dom';
import { Fragment, useState, useEffect, ReactNode, lazy, Suspense } from 'react';
import { Menu, Transition } from '@headlessui/react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

const CosmicBackground = lazy(() => import('@/components/ui/CosmicBackground'));
const LiveGlobeMap = lazy(() => import('@/components/LiveGlobeMap'));

import {
  Bars3Icon,
  HomeIcon,
  CreditCardIcon,
  ShoppingBagIcon,
  Cog6ToothIcon,
  ArrowRightOnRectangleIcon,
  MoonIcon,
  SunIcon,
  BellIcon,
  XMarkIcon,
  BuildingStorefrontIcon,
  BanknotesIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  CurrencyDollarIcon,
  InformationCircleIcon,
  GlobeAltIcon,
  PuzzlePieceIcon,
  QuestionMarkCircleIcon,
  ArrowUturnLeftIcon,
  ChevronDownIcon,
  ArrowsRightLeftIcon,
  SparklesIcon,
  MagnifyingGlassIcon,
  PaperAirplaneIcon,
  ArrowPathIcon,
} from '@heroicons/react/24/outline';
import { notificationsService, Notification } from '@/services/api/notifications.service';
import { productsService } from '@/services/api/products.service';
import { wazerAIService, type WazerAIChatMessage } from '@/services/api/wazerAI.service';
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
  { name: 'Nominais', href: '/acquirers', icon: ArrowsRightLeftIcon },
  { name: 'Saques', href: '/withdrawals', icon: BanknotesIcon },
];

const searchPrompts = [
  'Pesquise como precificar seu produto',
  'Pergunte como melhorar o marketing do seu produto',
  'Busque produtos, cobrancas ou clientes',
  'Pergunte para a IA como vender mais hoje',
  'Pesquise oportunidades dentro do sistema',
];

interface AiSearchMessage extends WazerAIChatMessage {
  id: string;
  isLoading?: boolean;
}

export default function DashboardLayout({ children }: DashboardLayoutProps) {
  const { user, logout, isImpersonating, stopImpersonation } = useAuthStore();
  const { theme, toggleTheme } = useThemeStore();
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [expandedMobileMenu, setExpandedMobileMenu] = useState<string | null>(null);
  const [globalSearch, setGlobalSearch] = useState('');
  const [placeholderIndex, setPlaceholderIndex] = useState(0);
  const [typedSearchPlaceholder, setTypedSearchPlaceholder] = useState('');
  const [isDeletingSearchPlaceholder, setIsDeletingSearchPlaceholder] = useState(false);
  const [isAiPanelOpen, setIsAiPanelOpen] = useState(false);
  const [aiMessages, setAiMessages] = useState<AiSearchMessage[]>([]);
  const [isAiLoading, setIsAiLoading] = useState(false);
  const hasOriginalAdminToken = typeof window !== 'undefined' && Boolean(sessionStorage.getItem('admin_original_token'));
  const isImpersonationSession = isImpersonating || hasOriginalAdminToken;

  useEffect(() => {
    const currentText = searchPrompts[placeholderIndex];
    const isComplete = typedSearchPlaceholder === currentText;
    const isEmpty = typedSearchPlaceholder.length === 0;
    const delay = isComplete && !isDeletingSearchPlaceholder
      ? 1700
      : isDeletingSearchPlaceholder
      ? 48
      : 82;

    const timeout = window.setTimeout(() => {
      if (!isDeletingSearchPlaceholder) {
        if (isComplete) {
          setIsDeletingSearchPlaceholder(true);
          return;
        }

        setTypedSearchPlaceholder(currentText.slice(0, typedSearchPlaceholder.length + 1));
        return;
      }

      if (isEmpty) {
        setIsDeletingSearchPlaceholder(false);
        setPlaceholderIndex((current) => (current + 1) % searchPrompts.length);
        return;
      }

      setTypedSearchPlaceholder(currentText.slice(0, typedSearchPlaceholder.length - 1));
    }, delay);

    return () => window.clearTimeout(timeout);
  }, [placeholderIndex, typedSearchPlaceholder, isDeletingSearchPlaceholder]);

  // Update page title based on current route
  useEffect(() => {
    const pageTitles: Record<string, string> = {
      '/dashboard': 'Dashboard',
      '/vitrine': 'Vitrine',
      '/charges': 'Cobranças',
      '/chargebacks': 'Chargebacks',
      '/acquirers': 'Nominais',
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
      '/vector-ia': 'VectorIA',
      '/wazer-ia': 'VectorIA',
      '/admin': 'Administração',
    };
    const path = location.pathname;
    const title = pageTitles[path] || Object.entries(pageTitles).find(([key]) => path.startsWith(key))?.[1] || 'Dashboard';
    document.title = `${title} | VectorPagamentos`;
  }, [location.pathname]);

  // Verifica se o usuário tem permissão de admin
  const isAdmin = ['owner', 'admin', 'support', 'financial', 'antifraud'].includes(user?.role || '');
  const shouldShowKycBanner = location.pathname !== '/dashboard';
  const shouldPromptKycCompletion = user?.kyc_status === 'pending' || user?.kyc_status === 'rejected';

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

  const createMessageId = () => {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
      return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  };

  const getAiHistory = (): WazerAIChatMessage[] => {
    return aiMessages
      .filter((message) => !message.isLoading)
      .slice(-12)
      .map((message) => ({ role: message.role, content: message.content }));
  };

  const parseMoneyToCents = (value: string) => {
    const normalized = value
      .replace(/\s/g, '')
      .replace(/r\$/i, '')
      .replace(/\./g, '')
      .replace(',', '.');

    const amount = Number.parseFloat(normalized);
    if (!Number.isFinite(amount) || amount <= 0) return null;

    return Math.round(amount * 100);
  };

  const parseCreateProductCommand = (text: string) => {
    const normalized = text
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase();

    const isCreateProduct =
      /\b(cria|crie|criar|cadastre|cadastra|adicione|adicionar)\b/.test(normalized) &&
      /\bproduto\b/.test(normalized);

    if (!isCreateProduct) return null;

    const priceMatch = text.match(/(?:por|preco|preço|valor)\s*(?:de\s*)?(r\$\s*)?(\d{1,9}(?:[.,]\d{1,2})?)/i)
      || text.match(/r\$\s*(\d{1,9}(?:[.,]\d{1,2})?)/i);
    const rawPrice = priceMatch?.[2] || priceMatch?.[1] || '';
    const price = rawPrice ? parseMoneyToCents(rawPrice) : null;

    let name = text
      .replace(/(?:por|preco|preço|valor)\s*(?:de\s*)?(?:r\$\s*)?\d{1,9}(?:[.,]\d{1,2})?/i, '')
      .replace(/r\$\s*\d{1,9}(?:[.,]\d{1,2})?/i, '')
      .replace(/\b(cria|crie|criar|cadastre|cadastra|adicione|adicionar)\b/gi, '')
      .replace(/\b(um|uma|o|a|novo|nova)\b/gi, '')
      .replace(/\bproduto\b/gi, '')
      .replace(/\s+/g, ' ')
      .trim();

    name = name.replace(/^(chamado|chamada|nomeado|nomeada|de|para)\s+/i, '').trim();

    const type = /\b(fisico|físico)\b/i.test(text)
      ? 'physical'
      : /\b(servico|serviço)\b/i.test(text)
      ? 'service'
      : 'digital';

    return { name, price, type: type as 'physical' | 'digital' | 'service' };
  };

  const tryRunSystemCommand = async (question: string) => {
    const productCommand = parseCreateProductCommand(question);
    if (!productCommand) return null;

    if (!productCommand.name) {
      return 'Consigo criar o produto para voce. Me diga o nome dele, por exemplo: "crie um produto Curso de Instagram por R$ 97".';
    }

    if (!productCommand.price) {
      return `Qual sera o preco do produto "${productCommand.name}"? Envie, por exemplo: "crie produto ${productCommand.name} por R$ 97".`;
    }

    const result = await productsService.create({
      name: productCommand.name,
      description: '',
      type: productCommand.type,
      price: productCommand.price,
      is_active: true,
      metadata: {
        created_by: 'vector_ia_search',
        payment_methods: {
          credit_card: false,
          pix: true,
          boleto: false,
        },
      },
    });

    queryClient.invalidateQueries({ queryKey: ['products'] });
    queryClient.invalidateQueries({ queryKey: ['products-list'] });
    queryClient.invalidateQueries({ queryKey: ['checkouts'] });

    const product = result.data as any;
    const checkoutUrl = product.checkout_url ? `\n\nCheckout: ${product.checkout_url}` : '';

    return `Produto criado com sucesso: ${product.name || productCommand.name} por ${new Intl.NumberFormat('pt-BR', {
      style: 'currency',
      currency: 'BRL',
    }).format(productCommand.price / 100)}.${checkoutUrl}\n\nEle ja aparece na pagina de Produtos.`;
  };

  const sendAiSearch = async (text?: string) => {
    const question = (text || globalSearch).trim();
    if (!question || isAiLoading) return;

    const userMessage: AiSearchMessage = {
      id: createMessageId(),
      role: 'user',
      content: question,
    };

    const loadingMessage: AiSearchMessage = {
      id: createMessageId(),
      role: 'assistant',
      content: 'Analisando sua pergunta e buscando contexto no sistema...',
      isLoading: true,
    };

    setAiMessages((current) => [...current, userMessage, loadingMessage]);
    setGlobalSearch('');
    setIsAiPanelOpen(true);
    setIsAiLoading(true);

    try {
      const commandResponse = await tryRunSystemCommand(question);

      if (commandResponse) {
        setAiMessages((current) =>
          current.map((message) =>
            message.id === loadingMessage.id
              ? {
                  ...message,
                  content: commandResponse,
                  isLoading: false,
                }
              : message
          )
        );
        navigate('/products');
        return;
      }

      const response = await wazerAIService.chat(
        `Pesquise no sistema Vector Pagamentos e responda de forma pratica para o usuario: ${question}`,
        getAiHistory()
      );

      setAiMessages((current) =>
        current.map((message) =>
          message.id === loadingMessage.id
            ? {
                ...message,
                content: response.success
                  ? response.data.message
                  : 'Nao consegui responder agora. Tente novamente em alguns instantes.',
                isLoading: false,
              }
            : message
        )
      );
    } catch (error: any) {
      const fallbackMessage =
        error?.response?.data?.message ||
        error?.response?.data?.error ||
        'Nao consegui conectar com a IA agora. Verifique se o backend esta rodando e tente novamente.';

      setAiMessages((current) =>
        current.map((message) =>
          message.id === loadingMessage.id
            ? { ...message, content: fallbackMessage, isLoading: false }
            : message
        )
      );
    } finally {
      setIsAiLoading(false);
    }
  };

  const handleGlobalSearchSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    sendAiSearch();
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
      <Suspense fallback={null}>
        <LiveGlobeMap accountId={user?.account_id} />
      </Suspense>

      {/* Top Navigation */}
      <header className="sticky top-0 z-50 glass-nav border-b border-border-DEFAULT/60 bg-surface-page/95 backdrop-blur-xl">
        <div className="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex h-14 items-center justify-between gap-3">
            {/* Logo */}
            <div className="flex items-center gap-6">
              <NavLink to="/dashboard" className="flex items-center group">
                <img
                  src="/img/logo.png"
                  alt="VectorPagamentos"
                  className="h-8 w-auto object-contain"
                />
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

            {/* Global AI Search */}
            <div className="hidden xl:flex flex-1 justify-center px-2">
              <form onSubmit={handleGlobalSearchSubmit} className="relative w-full max-w-xl">
                <MagnifyingGlassIcon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                <input
                  value={globalSearch}
                  onChange={(event) => setGlobalSearch(event.target.value)}
                  className={clsx(
                    'h-10 w-full rounded-full pl-10 pr-12 text-sm shadow-sm outline-none transition-all focus:border-accent-primary focus:ring-4 focus:ring-accent-primary/10',
                    theme === 'dark'
                      ? 'border-white/10 bg-[#0b100d] text-white placeholder:text-white/70'
                      : 'border-slate-200 bg-white text-slate-900 placeholder:text-slate-600'
                  )}
                  placeholder={typedSearchPlaceholder}
                  aria-label="Pesquise ou pergunte para a IA"
                />
                <button
                  type="submit"
                  disabled={isAiLoading || !globalSearch.trim()}
                  className="absolute right-1.5 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full bg-accent-primary text-white transition-all hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                  title="Pesquisar com IA"
                >
                  {isAiLoading ? (
                    <ArrowPathIcon className="h-4 w-4 animate-spin" />
                  ) : (
                    <PaperAirplaneIcon className="h-4 w-4" />
                  )}
                </button>
              </form>
            </div>

            {/* Right Section */}
            <div className="flex items-center gap-1">
              <button
                onClick={() => setIsAiPanelOpen(true)}
                className="xl:hidden p-2 rounded-lg text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-all duration-fast"
                title="Busca inteligente"
              >
                <MagnifyingGlassIcon className="h-[18px] w-[18px]" />
              </button>

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

      <Transition
        show={isAiPanelOpen}
        as={Fragment}
        enter="transition ease-out duration-200"
        enterFrom="opacity-0"
        enterTo="opacity-100"
        leave="transition ease-in duration-150"
        leaveFrom="opacity-100"
        leaveTo="opacity-0"
      >
        <div className="fixed inset-0 z-[70]">
          <button
            type="button"
            aria-label="Fechar busca inteligente"
            onClick={() => setIsAiPanelOpen(false)}
            className="absolute inset-0 h-full w-full bg-black/35 backdrop-blur-[2px]"
          />

          <Transition.Child
            as={Fragment}
            enter="transition ease-out duration-300"
            enterFrom="translate-x-full"
            enterTo="translate-x-0"
            leave="transition ease-in duration-200"
            leaveFrom="translate-x-0"
            leaveTo="translate-x-full"
          >
            <aside className="absolute right-0 top-0 flex h-full w-full max-w-md flex-col border-l border-border-DEFAULT bg-surface-page shadow-2xl">
              <div className="flex items-center justify-between border-b border-border-DEFAULT px-5 py-4">
                <div>
                  <p className="text-sm font-semibold text-tx-primary">Busca inteligente</p>
                </div>
                <button
                  onClick={() => setIsAiPanelOpen(false)}
                  className="rounded-lg p-2 text-tx-muted transition-colors hover:bg-surface-hover hover:text-tx-primary"
                  title="Fechar"
                >
                  <XMarkIcon className="h-5 w-5" />
                </button>
              </div>

              <div className="flex-1 space-y-4 overflow-y-auto px-5 py-5">
                {aiMessages.length === 0 ? (
                  <div className="space-y-4">
                    <div className="rounded-card border border-border-DEFAULT bg-surface-elevated p-4">
                      <p className="text-sm font-medium text-tx-primary">Como posso ajudar?</p>
                      <p className="mt-1 text-sm text-tx-secondary">
                        Use a busca para encontrar informacoes do sistema ou pedir uma analise rapida para a IA.
                      </p>
                    </div>

                    <div className="grid gap-2">
                      {searchPrompts.slice(0, 4).map((prompt) => (
                        <button
                          key={prompt}
                          onClick={() => sendAiSearch(prompt)}
                          className="flex items-center gap-3 rounded-card border border-border-DEFAULT bg-surface-elevated px-4 py-3 text-left text-sm text-tx-secondary transition-all hover:border-accent-primary/50 hover:bg-accent-subtle hover:text-accent-primary"
                        >
                          <MagnifyingGlassIcon className="h-4 w-4 flex-shrink-0" />
                          <span>{prompt}</span>
                        </button>
                      ))}
                    </div>
                  </div>
                ) : (
                  aiMessages.map((message) => (
                    <div
                      key={message.id}
                      className={clsx(
                        'flex',
                        message.role === 'user' ? 'justify-end' : 'justify-start'
                      )}
                    >
                      <div
                        className={clsx(
                          'max-w-[88%] rounded-2xl px-4 py-3 text-sm leading-relaxed shadow-sm',
                          message.role === 'user'
                            ? 'bg-accent-primary text-white'
                            : 'border border-border-DEFAULT bg-surface-elevated text-tx-primary'
                        )}
                      >
                        {message.isLoading ? (
                          <div className="flex items-center gap-2">
                            <ArrowPathIcon className="h-4 w-4 animate-spin text-accent-primary" />
                            <span>{message.content}</span>
                          </div>
                        ) : (
                          <p className="whitespace-pre-wrap">{message.content}</p>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </div>

              <form onSubmit={handleGlobalSearchSubmit} className="border-t border-border-DEFAULT p-4">
                <div className="flex items-center gap-2 rounded-full border border-border-DEFAULT bg-surface-elevated px-3 py-2 focus-within:border-accent-primary focus-within:ring-4 focus-within:ring-accent-primary/10">
                  <input
                    value={globalSearch}
                    onChange={(event) => setGlobalSearch(event.target.value)}
                    className="min-w-0 flex-1 bg-transparent px-1 text-sm text-tx-primary outline-none placeholder:text-tx-muted"
                    placeholder=""
                    aria-label="Pergunte para a IA"
                  />
                  <button
                    type="submit"
                    disabled={isAiLoading || !globalSearch.trim()}
                    className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-accent-primary text-white transition-all hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                    title="Enviar pergunta"
                  >
                    {isAiLoading ? (
                      <ArrowPathIcon className="h-4 w-4 animate-spin" />
                    ) : (
                      <PaperAirplaneIcon className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </form>
            </aside>
          </Transition.Child>
        </div>
      </Transition>

      {/* Main Content */}
      <main className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* KYC Banner — informativo, não bloqueia uso */}
        {shouldShowKycBanner && shouldPromptKycCompletion && (
          <div className="mb-6 flex items-center gap-3 rounded-card border border-status-warning/30 bg-status-warning-subtle px-4 py-3">
            <ExclamationTriangleIcon className="h-5 w-5 flex-shrink-0 text-status-warning" />
            <p className="text-sm font-medium text-tx-primary flex-1">
              Complete sua verificação KYC para poder realizar saques. Você pode continuar usando a plataforma normalmente.
            </p>
            <Link
              to="/kyc"
              className="flex-shrink-0 inline-flex items-center gap-1.5 text-xs font-semibold bg-accent hover:bg-accent-hover text-white px-3 py-1.5 rounded-md transition-colors"
            >
              Verificar KYC
            </Link>
          </div>
        )}
        {children || <Outlet />}
      </main>
    </div>
  );
}
