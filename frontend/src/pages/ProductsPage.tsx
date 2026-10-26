import { useState, useRef, useEffect, useMemo } from 'react';
import clsx from 'clsx';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency } from '@/lib/api';
import { domainsService, CustomDomain } from '@/services/api/domains.service';
import { SectionSpinner } from '@/components/ui/Spinner';
import { Menu, Transition } from '@headlessui/react';
import { Fragment } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { Link, useNavigate } from 'react-router-dom';
import RichTextEditor from '@/components/RichTextEditor';
import PixIcon from '@/components/icons/PixIcon';
import { prepareProductDiscount } from '@/lib/productDiscount';
import {
  PlusIcon,
  MagnifyingGlassIcon,
  EllipsisVerticalIcon,
  ArrowPathIcon,
  PencilIcon,
  TrashIcon,
  PhotoIcon,
  CloudArrowUpIcon,
  ArrowLeftIcon,
  EyeIcon,
  ClipboardDocumentIcon,
  LinkIcon,
  GlobeAltIcon,
  FunnelIcon,
  Squares2X2Icon,
  ListBulletIcon,
  ChevronDownIcon,
  XMarkIcon,
  CubeIcon,
  TagIcon,
  UsersIcon,
  CheckCircleIcon,
  XCircleIcon,
  SwatchIcon,
  ChartBarIcon,
  DocumentTextIcon,
  CreditCardIcon,
  TruckIcon,
  ArchiveBoxIcon,
  ReceiptPercentIcon,
  CheckBadgeIcon,
  InformationCircleIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Product {
  id: string;
  name: string;
  description: string;
  price: number;
  original_price?: number | null;
  currency: string;
  type: 'physical' | 'digital' | 'service';
  status: 'active' | 'inactive';
  category?: string;
  image_url?: string;
  checkout_url?: string;
  checkout_slug?: string;
  metadata?: Record<string, any>;
  affiliate_enabled: boolean;
  affiliate_commission_percent?: number;
  visible_on_marketplace: boolean;
  shipping_required: boolean;
  shipping_info?: {
    weight?: number;
    width?: number;
    height?: number;
    length?: number;
    free_shipping: boolean;
    shipping_price?: number;
  };
  created_at: string;
}

interface ProductForm {
  coupon_code?: string;
  name: string;
  description: string;
  price: number;
  type: 'physical' | 'digital' | 'service';
  category?: string;
  discount_enabled: boolean;
  discount_type: 'percentage' | 'fixed';
  discount_value?: number;
  affiliate_enabled: boolean;
  affiliate_commission_percent?: number;
  visible_on_marketplace: boolean;
  image_url?: string;
  shipping_required: boolean;
  shipping_weight?: number;
  shipping_width?: number;
  shipping_height?: number;
  shipping_length?: number;
  free_shipping: boolean;
  shipping_price?: number;
  // Métodos de pagamento
  payment_methods: {
    credit_card: boolean;
    pix: boolean;
    boleto: boolean;
  };
  // Estoque (produtos físicos)
  stock_enabled: boolean;
  stock_quantity?: number;
  stock_alert_threshold?: number;
  // Página de obrigado
  thank_you_page_url?: string;
  thank_you_page_type: 'default' | 'custom' | 'redirect';
  // Order Bump
  order_bump_enabled: boolean;
  order_bump_product_id?: string;
  order_bump_title?: string;
  order_bump_description?: string;
  order_bump_discount_percent?: number;
  // Upsell
  upsell_enabled: boolean;
  upsell_product_id?: string;
  upsell_title?: string;
  upsell_description?: string;
  upsell_discount_percent?: number;
  // Meta Pixel
  facebook_pixel_enabled: boolean;
  facebook_pixel_id?: string;
  facebook_pixel_track_page_view: boolean;
  facebook_pixel_track_initiate_checkout: boolean;
  facebook_pixel_track_purchase: boolean;
}

const PRODUCT_IMAGE_MAX_SIZE_BYTES = 5 * 1024 * 1024;
const PRODUCT_IMAGE_ALLOWED_TYPES = new Set([
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
]);
const PRODUCT_IMAGE_ALLOWED_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);

function SalesIcon({
  type,
  className = '',
}: {
  type: 'payment' | 'card' | 'boleto' | 'bump' | 'upsell' | 'pixel';
  className?: string;
}) {
  const common = 'fill-none stroke-current';

  if (type === 'payment') {
    return (
      <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
        <rect x="7" y="11" width="34" height="26" rx="8" className={common} strokeWidth="3" />
        <path d="M8 20h32M15 29h7M27 29h6" className={common} strokeWidth="3" strokeLinecap="round" />
        <circle cx="36" cy="15" r="4" className="fill-current opacity-20" />
      </svg>
    );
  }

  if (type === 'card') {
    return (
      <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
        <rect x="8" y="13" width="32" height="23" rx="7" className={common} strokeWidth="3" />
        <path d="M8 21h32M16 29h8" className={common} strokeWidth="3" strokeLinecap="round" />
        <path d="M31 28h5v4h-5z" className="fill-current opacity-25" />
      </svg>
    );
  }

  if (type === 'boleto') {
    return (
      <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
        <path d="M13 7h22a3 3 0 0 1 3 3v31l-5-3-4 3-5-3-5 3-4-3-5 3V10a3 3 0 0 1 3-3Z" className={common} strokeWidth="3" strokeLinejoin="round" />
        <path d="M17 16h14M17 23h18M17 30h10" className={common} strokeWidth="3" strokeLinecap="round" />
      </svg>
    );
  }

  if (type === 'bump') {
    return (
      <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
        <path d="M14 12h20a5 5 0 0 1 5 5v14a5 5 0 0 1-5 5H14a5 5 0 0 1-5-5V17a5 5 0 0 1 5-5Z" className={common} strokeWidth="3" />
        <path d="M24 17v14M17 24h14" className={common} strokeWidth="3" strokeLinecap="round" />
        <path d="M34 10l4-4M39 16h5M9 37l-4 4" className={common} strokeWidth="3" strokeLinecap="round" />
      </svg>
    );
  }

  if (type === 'upsell') {
    return (
      <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
        <path d="M8 34 19 23l7 7 14-16" className={common} strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M29 14h11v11" className={common} strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" />
        <rect x="8" y="36" width="8" height="4" rx="2" className="fill-current opacity-25" />
        <rect x="21" y="32" width="8" height="8" rx="2" className="fill-current opacity-25" />
        <rect x="34" y="27" width="8" height="13" rx="2" className="fill-current opacity-25" />
      </svg>
    );
  }

  return (
    <svg className={className} viewBox="0 0 48 48" aria-hidden="true">
      <rect x="8" y="8" width="32" height="32" rx="10" className={common} strokeWidth="3" />
      <path d="M16 30V18h8a5 5 0 0 1 0 10h-8M29 18h5M29 24h5M29 30h5" className={common} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="37" cy="11" r="4" className="fill-current opacity-25" />
    </svg>
  );
}

/** Toggle switch padrão do formulário — usado com `peer` no input irmão. */
const TOGGLE_TRACK_CLASS =
  "h-6 w-11 rounded-full bg-neutral-200 dark:bg-neutral-700 peer transition-colors peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent-primary/20 peer-checked:bg-accent-primary after:content-[''] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition-all peer-checked:after:translate-x-full peer-checked:after:border-white";

/** Card de seção do formulário: cabeçalho com ícone, título, descrição e ação opcional. */
function FormSection({
  icon: Icon,
  iconTone = 'text-accent-primary',
  iconBg = 'bg-accent-primary/10',
  title,
  description,
  action,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  iconTone?: string;
  iconBg?: string;
  title: string;
  description?: string;
  action?: React.ReactNode;
  children?: React.ReactNode;
}) {
  return (
    <section className="overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card shadow-sm">
      <header className="flex items-center gap-3 border-b border-border-DEFAULT bg-surface-hover/30 px-5 py-3.5">
        <div className={`flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl ${iconBg} ${iconTone}`}>
          <Icon className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold text-tx-primary">{title}</h2>
          {description && <p className="mt-0.5 text-xs leading-relaxed text-tx-muted">{description}</p>}
        </div>
        {action && <div className="flex-shrink-0">{action}</div>}
      </header>
      {children && <div className="p-5">{children}</div>}
    </section>
  );
}

/** Preferência de domínio para os links de checkout, lembrada entre sessões. */
const CHECKOUT_DOMAIN_KEY = 'products:checkout-domain';
const SYSTEM_DOMAIN_OPTION = 'system';

export default function ProductsPage() {
  const [search, setSearch] = useState('');
  const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
  const [isFormVisible, setIsFormVisible] = useState(false);
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('list');
  const [filterStatus, setFilterStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [filterType, setFilterType] = useState<'all' | 'digital' | 'physical' | 'service'>('all');
  const [filterAffiliate, setFilterAffiliate] = useState<'all' | 'enabled' | 'disabled'>('all');
  const [sortBy, setSortBy] = useState<'newest' | 'oldest' | 'price_asc' | 'price_desc' | 'name'>('newest');
  const [showFilters, setShowFilters] = useState(false);
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  // Fetch verified domains to build checkout URLs
  const { data: domainsData } = useQuery({
    queryKey: ['domains'],
    queryFn: () => domainsService.list(),
  });

  const [checkoutDomainId, setCheckoutDomainId] = useState<string>(
    () => localStorage.getItem(CHECKOUT_DOMAIN_KEY) || '',
  );

  // Só domínio verificado E liberado para checkout: um domínio marcado apenas
  // para a área de membros não serve para link de venda.
  const checkoutDomains = useMemo(
    () =>
      (domainsData?.data || []).filter(
        (d: CustomDomain) => d.status === 'verified' && (d.use_for ?? ['checkout', 'members']).includes('checkout'),
      ),
    [domainsData],
  );

  // Escolha explícita vence; senão cai no principal, depois no primeiro, depois no sistema.
  const activeCheckoutDomain = useMemo(() => {
    if (checkoutDomainId === SYSTEM_DOMAIN_OPTION) return null;
    if (checkoutDomainId) {
      const chosen = checkoutDomains.find((d: CustomDomain) => d.id === checkoutDomainId);
      if (chosen) return chosen;
    }
    return checkoutDomains.find((d: CustomDomain) => d.is_primary) ?? checkoutDomains[0] ?? null;
  }, [checkoutDomainId, checkoutDomains]);

  const checkoutBaseUrl = activeCheckoutDomain
    ? `https://${activeCheckoutDomain.domain}`
    : window.location.origin;

  const chooseCheckoutDomain = (value: string) => {
    setCheckoutDomainId(value);
    localStorage.setItem(CHECKOUT_DOMAIN_KEY, value);
  };

  const { data, refetch, isLoading } = useQuery({
    queryKey: ['products', search],
    queryFn: async () => {
      const response = await api.get('/products', {
        params: { search },
      });
      
      return response.data;
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/products/${id}`);
    },
    onSuccess: () => {
      toast.success('Produto removido!');
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['products-list'] });
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao remover produto');
    },
  });

  // Usar dados da API
  const products: Product[] = data?.data || [];

  // Filtragem e ordenação
  const filteredProducts = useMemo(() => {
    let result = [...products];

    // Filtrar por status
    if (filterStatus !== 'all') {
      result = result.filter(p => p.status === filterStatus);
    }

    // Filtrar por tipo
    if (filterType !== 'all') {
      result = result.filter(p => p.type === filterType);
    }

    // Filtrar por programa de afiliados
    if (filterAffiliate !== 'all') {
      result = result.filter(p => 
        filterAffiliate === 'enabled' ? p.affiliate_enabled : !p.affiliate_enabled
      );
    }

    // Ordenação
    result.sort((a, b) => {
      switch (sortBy) {
        case 'newest':
          return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        case 'oldest':
          return new Date(a.created_at).getTime() - new Date(b.created_at).getTime();
        case 'price_asc':
          return a.price - b.price;
        case 'price_desc':
          return b.price - a.price;
        case 'name':
          return a.name.localeCompare(b.name);
        default:
          return 0;
      }
    });

    return result;
  }, [products, filterStatus, filterType, filterAffiliate, sortBy]);

  // Estatísticas
  const stats = useMemo(() => ({
    total: products.length,
    active: products.filter(p => p.status === 'active').length,
    inactive: products.filter(p => p.status === 'inactive').length,
    withAffiliates: products.filter(p => p.affiliate_enabled).length,
    digital: products.filter(p => p.type === 'digital').length,
    physical: products.filter(p => p.type === 'physical').length,
    service: products.filter(p => p.type === 'service').length,
  }), [products]);

  const activeFiltersCount = [
    filterStatus !== 'all',
    filterType !== 'all',
    filterAffiliate !== 'all',
  ].filter(Boolean).length;

  const clearFilters = () => {
    setFilterStatus('all');
    setFilterType('all');
    setFilterAffiliate('all');
  };

  const handleEdit = (product: Product) => {
    setSelectedProduct(product);
    setIsFormVisible(true);
  };

  const handleCreate = () => {
    setSelectedProduct(null);
    setIsFormVisible(true);
  };

  const handleCloseForm = () => {
    setIsFormVisible(false);
    setSelectedProduct(null);
  };

  const handleDelete = (product: Product) => {
    if (confirm('Tem certeza que deseja remover este produto?')) {
      deleteMutation.mutate(product.id);
    }
  };

  // Copiar link do checkout (usando domínio verificado)
  const copyCheckoutLink = (product: Product) => {
    if (product.checkout_slug) {
      const url = `${checkoutBaseUrl}/checkout/${product.checkout_slug}`;
      navigator.clipboard.writeText(url);
      toast.success('Link do checkout copiado!');
    } else {
      toast.error('Checkout não encontrado. Edite o produto para gerar o link.');
    }
  };

  // Abrir Checkout Builder
  const openCheckoutBuilder = async (product: Product) => {
    try {
      const response = await api.get('/checkouts', { params: { product_id: product.id } });
      const checkouts = response.data?.data || response.data;
      if (checkouts && checkouts.length > 0) {
        navigate(`/checkout-builder/${checkouts[0].id}`);
      } else {
        toast.error('Checkout não encontrado. Crie um checkout para este produto primeiro.');
      }
    } catch {
      toast.error('Erro ao buscar checkout do produto.');
    }
  };

  // Abrir Analytics do produto (via checkout vinculado)
  const openProductAnalytics = async (product: Product) => {
    try {
      const response = await api.get('/checkouts', { params: { product_id: product.id } });
      const checkouts = response.data?.data || response.data;
      if (checkouts && checkouts.length > 0) {
        navigate(`/checkouts/${checkouts[0].id}/analytics`);
      } else {
        toast.error('Checkout não encontrado. Crie um checkout para este produto primeiro.');
      }
    } catch {
      toast.error('Erro ao buscar checkout do produto.');
    }
  };

  // Visualizar checkout
  const viewCheckout = (product: Product) => {
    if (product.checkout_slug) {
      window.open(`${checkoutBaseUrl}/checkout/${product.checkout_slug}`, '_blank');
    } else {
      toast.error('Checkout não encontrado.');
    }
  };

  // Se o formulário estiver visível, mostrar a tela de criação/edição
  if (isFormVisible) {
    return (
      <ProductFormPage
        key={selectedProduct?.id || 'new'}
        product={selectedProduct}
        onClose={handleCloseForm}
      />
    );
  }

  return (
    <div className="space-y-6">
      {/* Cabeçalho compacto — o espaço vazio virou informação útil */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-tx-primary">Produtos</h1>
          <p className="mt-1 text-sm text-tx-muted">
            {stats.total > 0
              ? 'Gerencie seus produtos, checkouts e programa de afiliados.'
              : 'Cadastre seu primeiro produto para começar a vender.'}
          </p>
        </div>
        <button
          data-tour="create-product"
          onClick={handleCreate}
          className="hidden lg:inline-flex items-center gap-2 rounded-xl bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-primary/90"
        >
          <PlusIcon className="h-4 w-4" />
          Criar produto
        </button>
      </div>

      {stats.total > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Total', value: stats.total, icon: CubeIcon, tone: 'text-tx-primary', bg: 'bg-surface-hover' },
            { label: 'Publicados', value: stats.active, icon: CheckCircleIcon, tone: 'text-emerald-600', bg: 'bg-emerald-500/10' },
            { label: 'Rascunhos', value: stats.inactive, icon: PencilIcon, tone: 'text-amber-600', bg: 'bg-amber-500/10' },
            { label: 'Com afiliação', value: stats.withAffiliates, icon: UsersIcon, tone: 'text-violet-600', bg: 'bg-violet-500/10' },
          ].map((stat) => (
            <div
              key={stat.label}
              className="flex items-center gap-3 rounded-xl border border-border-DEFAULT bg-surface-card p-3.5"
            >
              <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${stat.bg}`}>
                <stat.icon className={`h-4 w-4 ${stat.tone}`} />
              </span>
              <div className="min-w-0">
                <p className={`text-xl font-bold leading-none ${stat.tone}`}>{stat.value}</p>
                <p className="mt-1 truncate text-[11px] font-medium text-tx-muted">{stat.label}</p>
              </div>
            </div>
          ))}
        </div>
      )}


      {/* Domínio dos links — só faz sentido se a conta já tiver algum verificado */}
      {checkoutDomains.length > 0 && (
        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-3 sm:p-4 shadow-sm">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3 min-w-0">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent-primary/10 text-accent-primary">
                <GlobeAltIcon className="h-5 w-5" />
              </span>
              <div className="min-w-0">
                <p className="text-sm font-medium text-tx-primary">Domínio dos links de checkout</p>
                <p className="mt-0.5 text-xs text-tx-muted truncate font-mono">
                  {checkoutBaseUrl}/checkout/<span className="text-tx-secondary">seu-produto</span>
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2 shrink-0">
              {!activeCheckoutDomain && (
                <span className="hidden sm:inline text-[11px] text-tx-muted">domínio do sistema</span>
              )}
              <select
                value={checkoutDomainId === SYSTEM_DOMAIN_OPTION ? SYSTEM_DOMAIN_OPTION : activeCheckoutDomain?.id ?? SYSTEM_DOMAIN_OPTION}
                onChange={(e) => chooseCheckoutDomain(e.target.value)}
                className="rounded-xl border border-border-DEFAULT bg-surface-input px-3 py-2 text-sm text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
              >
                <option value={SYSTEM_DOMAIN_OPTION}>Usar domínio do sistema</option>
                {checkoutDomains.map((domain: CustomDomain) => (
                  <option key={domain.id} value={domain.id}>
                    {domain.domain}
                    {domain.is_primary ? ' — principal' : ''}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>
      )}

      {/* Search & Filters Bar */}
      <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-3 sm:p-4 shadow-sm">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          {/* Search */}
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar por nome, categoria ou slug..."
              className="w-full rounded-xl border border-border-DEFAULT bg-surface-input pl-11 pr-10 py-3 text-sm text-tx-primary placeholder-tx-muted transition-all focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            {search && (
              <button
                onClick={() => setSearch('')}
                className="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1 text-tx-muted hover:bg-surface-hover hover:text-tx-primary"
                aria-label="Limpar busca"
              >
                <XMarkIcon className="h-4 w-4" />
              </button>
            )}
          </div>

          {/* Controls */}
          <div className="flex flex-wrap items-center gap-2">
            <button
              onClick={() => setShowFilters(!showFilters)}
              className={`inline-flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all ${
                showFilters
                  ? 'bg-accent-primary text-white shadow-sm'
                  : 'border border-border-DEFAULT bg-surface-input text-tx-secondary hover:bg-surface-hover'
              }`}
            >
              <FunnelIcon className="h-4 w-4" />
              Filtros
              {activeFiltersCount > 0 && (
                <span className={`ml-0.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1 text-[11px] font-bold ${
                  showFilters ? 'bg-white/25' : 'bg-accent-primary text-white'
                }`}>
                  {activeFiltersCount}
                </span>
              )}
            </button>

            <div className="relative">
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as any)}
                className="appearance-none cursor-pointer rounded-xl border border-border-DEFAULT bg-surface-input pl-3.5 pr-9 py-2.5 text-sm font-medium text-tx-primary transition-all hover:bg-surface-hover focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
              >
                <option value="newest">Mais recentes</option>
                <option value="oldest">Mais antigos</option>
                <option value="price_asc">Menor preço</option>
                <option value="price_desc">Maior preço</option>
                <option value="name">Nome A-Z</option>
              </select>
              <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
            </div>

            <div className="hidden sm:flex items-center rounded-xl border border-border-DEFAULT bg-surface-input p-0.5">
              <button
                onClick={() => setViewMode('grid')}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium transition-all ${
                  viewMode === 'grid'
                    ? 'bg-accent-primary text-white shadow-sm'
                    : 'text-tx-muted hover:text-tx-primary'
                }`}
                title="Grade"
              >
                <Squares2X2Icon className="h-4 w-4" />
                Grade
              </button>
              <button
                onClick={() => setViewMode('list')}
                className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium transition-all ${
                  viewMode === 'list'
                    ? 'bg-accent-primary text-white shadow-sm'
                    : 'text-tx-muted hover:text-tx-primary'
                }`}
                title="Lista"
              >
                <ListBulletIcon className="h-4 w-4" />
                Lista
              </button>
            </div>

            <button
              onClick={() => refetch()}
              className="rounded-xl border border-border-DEFAULT bg-surface-input p-2.5 text-tx-muted transition-all hover:bg-surface-hover hover:text-tx-primary"
              title="Atualizar"
            >
              <ArrowPathIcon className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* Expanded Filters */}
        {showFilters && (
          <div className="mt-4 border-t border-border-DEFAULT pt-4">
            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <label className="text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Status</label>
                <div className="relative mt-1.5">
                  <select
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value as any)}
                    className="w-full cursor-pointer appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-9 py-2 text-sm text-tx-primary transition-all focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
                  >
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>

              <div>
                <label className="text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Tipo</label>
                <div className="relative mt-1.5">
                  <select
                    value={filterType}
                    onChange={(e) => setFilterType(e.target.value as any)}
                    className="w-full cursor-pointer appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-9 py-2 text-sm text-tx-primary transition-all focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
                  >
                    <option value="all">Todos</option>
                    <option value="digital">Digital</option>
                    <option value="physical">Físico</option>
                    <option value="service">Serviço</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>

              <div>
                <label className="text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Afiliados</label>
                <div className="relative mt-1.5">
                  <select
                    value={filterAffiliate}
                    onChange={(e) => setFilterAffiliate(e.target.value as any)}
                    className="w-full cursor-pointer appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-9 py-2 text-sm text-tx-primary transition-all focus:border-accent-primary focus:outline-none focus:ring-4 focus:ring-accent-primary/10"
                  >
                    <option value="all">Todos</option>
                    <option value="enabled">Ativado</option>
                    <option value="disabled">Desativado</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>
            </div>

            {activeFiltersCount > 0 && (
              <div className="mt-3 flex justify-end">
                <button
                  onClick={clearFilters}
                  className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-accent-primary transition-all hover:bg-accent-subtle"
                >
                  <XMarkIcon className="h-4 w-4" />
                  Limpar filtros
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Products */}
      {isLoading ? (
        <SectionSpinner />
      ) : filteredProducts.length === 0 ? (
        <div className="relative overflow-hidden rounded-2xl border border-dashed border-border-DEFAULT bg-surface-card p-10 sm:p-14 text-center">
          <div
            aria-hidden
            className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,rgba(59,130,246,0.08),transparent_60%)]"
          />
          <div className="relative">
            <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-blue-500/10 text-blue-500 ring-1 ring-blue-500/20">
              <CubeIcon className="h-10 w-10" />
            </div>
            <h3 className="mt-6 text-xl font-semibold text-tx-primary">
              {products.length === 0 ? 'Nenhum produto ainda' : 'Nada por aqui'}
            </h3>
            <p className="mx-auto mt-2 max-w-md text-sm text-tx-muted">
              {products.length === 0
                ? 'Crie seu primeiro produto para gerar um link de checkout e começar a receber pagamentos.'
                : 'Nenhum produto corresponde aos filtros atuais. Ajuste ou limpe os filtros para ver mais resultados.'}
            </p>
            {products.length === 0 ? (
              <button
                onClick={handleCreate}
                className="mt-6 inline-flex items-center gap-2 rounded-xl bg-[rgb(var(--primary))] px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-[rgba(var(--primary),0.25)] transition-all hover:-translate-y-0.5 hover:bg-[rgba(var(--primary),0.9)]"
              >
                <PlusIcon className="h-5 w-5" />
                Criar meu primeiro produto
              </button>
            ) : (
              activeFiltersCount > 0 && (
                <button
                  onClick={clearFilters}
                  className="mt-6 inline-flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-input px-5 py-3 text-sm font-semibold text-tx-primary transition-all hover:bg-surface-hover"
                >
                  <XMarkIcon className="h-4 w-4" />
                  Limpar filtros
                </button>
              )
            )}
          </div>
        </div>
      ) : viewMode === 'grid' ? (
        /* Grid View — polished cards */
        <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {filteredProducts.map((product) => (
            <div
              key={product.id}
              className="group relative flex flex-col overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card transition-all hover:-translate-y-1 hover:border-accent-primary/40 hover:shadow-xl hover:shadow-black/5"
            >
              <div className="relative aspect-[4/3] overflow-hidden bg-gradient-to-br from-surface-hover to-surface-page">
                {product.image_url ? (
                  <img
                    src={product.image_url}
                    alt={product.name}
                    className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                  />
                ) : (
                  <div className="flex h-full items-center justify-center">
                    <PhotoIcon className="h-12 w-12 text-tx-muted/60" />
                  </div>
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/0 to-transparent opacity-0 transition-opacity group-hover:opacity-100" />
                <div className="absolute inset-x-0 bottom-0 flex items-center justify-center gap-1.5 p-3 opacity-0 transition-all translate-y-2 group-hover:translate-y-0 group-hover:opacity-100">
                  <button
                    onClick={() => viewCheckout(product)}
                    className="rounded-lg bg-white/95 p-2 text-gray-700 shadow-sm backdrop-blur transition-transform hover:scale-110"
                    title="Visualizar"
                  >
                    <EyeIcon className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => copyCheckoutLink(product)}
                    className="rounded-lg bg-white/95 p-2 text-gray-700 shadow-sm backdrop-blur transition-transform hover:scale-110"
                    title="Copiar link"
                  >
                    <LinkIcon className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => openCheckoutBuilder(product)}
                    className="rounded-lg bg-white/95 p-2 text-gray-700 shadow-sm backdrop-blur transition-transform hover:scale-110"
                    title="Checkout Builder"
                  >
                    <SwatchIcon className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => openProductAnalytics(product)}
                    className="rounded-lg bg-white/95 p-2 text-gray-700 shadow-sm backdrop-blur transition-transform hover:scale-110"
                    title="Analytics"
                  >
                    <ChartBarIcon className="h-4 w-4" />
                  </button>
                </div>

                <div className="absolute left-3 top-3 flex flex-wrap gap-1.5">
                  <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold backdrop-blur ${
                      product.status === 'active'
                        ? 'bg-emerald-500/90 text-white'
                        : 'bg-neutral-500/90 text-white'
                    }`}
                  >
                    <span className={`h-1.5 w-1.5 rounded-full ${product.status === 'active' ? 'bg-white animate-pulse' : 'bg-white/70'}`} />
                    {product.status === 'active' ? 'Ativo' : 'Inativo'}
                  </span>
                </div>
              </div>

              <div className="flex flex-1 flex-col p-4">
                <div className="flex items-start justify-between gap-2">
                  <h3 className="line-clamp-2 flex-1 text-sm font-semibold text-tx-primary" title={product.name}>
                    {product.name}
                  </h3>
                  <Menu as="div" className="relative flex-shrink-0">
                    <Menu.Button className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-hover hover:text-tx-primary">
                      <EllipsisVerticalIcon className="h-4 w-4" />
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
                      <Menu.Items className="absolute right-0 z-10 mt-1 w-44 origin-top-right rounded-xl border border-border-DEFAULT bg-surface-card shadow-lg ring-1 ring-black/5 focus:outline-none">
                        <div className="py-1.5">
                          {[
                            { onClick: () => viewCheckout(product), Icon: EyeIcon, label: 'Visualizar' },
                            { onClick: () => copyCheckoutLink(product), Icon: LinkIcon, label: 'Copiar link' },
                            { onClick: () => openProductAnalytics(product), Icon: ChartBarIcon, label: 'Analytics' },
                            { onClick: () => openCheckoutBuilder(product), Icon: SwatchIcon, label: 'Checkout Builder' },
                            { onClick: () => handleEdit(product), Icon: PencilIcon, label: 'Editar' },
                          ].map(({ onClick, Icon, label }) => (
                            <Menu.Item key={label}>
                              {({ active }) => (
                                <button
                                  onClick={onClick}
                                  className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center gap-2 px-3 py-2 text-xs text-tx-secondary`}
                                >
                                  <Icon className="h-4 w-4" />
                                  {label}
                                </button>
                              )}
                            </Menu.Item>
                          ))}
                          <div className="my-1 h-px bg-border-DEFAULT" />
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => handleDelete(product)}
                                className={`${active ? 'bg-status-error/10' : ''} flex w-full items-center gap-2 px-3 py-2 text-xs text-status-error`}
                              >
                                <TrashIcon className="h-4 w-4" />
                                Remover
                              </button>
                            )}
                          </Menu.Item>
                        </div>
                      </Menu.Items>
                    </Transition>
                  </Menu>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-1.5">
                  <span
                    className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ${
                      product.type === 'digital'
                        ? 'bg-sky-500/10 text-sky-700 ring-1 ring-sky-500/20'
                        : product.type === 'physical'
                        ? 'bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/20'
                        : 'bg-neutral-500/10 text-neutral-700 ring-1 ring-neutral-500/20'
                    }`}
                  >
                    {product.type === 'digital' ? 'Digital' : product.type === 'physical' ? 'Físico' : 'Serviço'}
                  </span>
                  {product.affiliate_enabled && (
                    <>
                      <span className="inline-flex items-center gap-1 rounded-md bg-violet-500/10 px-2 py-0.5 text-[11px] font-medium text-violet-700 ring-1 ring-violet-500/20">
                        <UsersIcon className="h-3 w-3" />
                        {product.affiliate_commission_percent}%
                      </span>
                      {/* Categoria é obrigatória com afiliação ligada e é por ela
                          que o produto é achado na vitrine — vale mostrar aqui. */}
                      {product.category && (
                        <span className="inline-flex items-center gap-1 rounded-md bg-violet-500/10 px-2 py-0.5 text-[11px] font-medium text-violet-700 ring-1 ring-violet-500/20">
                          <TagIcon className="h-3 w-3" />
                          {product.category}
                        </span>
                      )}
                    </>
                  )}
                </div>

                <div className="mt-auto flex items-end justify-between pt-4">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wider text-tx-muted">Preço</p>
                    <p className="text-lg font-bold text-accent-primary">{formatCurrency(product.price)}</p>
                  </div>
                  <button
                    onClick={() => handleEdit(product)}
                    className="rounded-lg border border-border-DEFAULT bg-surface-input px-3 py-1.5 text-xs font-semibold text-tx-primary transition-all hover:bg-accent-primary hover:text-white hover:border-accent-primary"
                  >
                    Editar
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        /* List View — polished table */
        <div className="overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-surface-hover/60">
                <tr>
                  <th className="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Produto</th>
                  <th className="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted hidden sm:table-cell">Tipo</th>
                  <th className="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Preço</th>
                  <th className="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-tx-muted hidden md:table-cell">Status</th>
                  <th className="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-tx-muted hidden lg:table-cell">Afiliados</th>
                  <th className="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-tx-muted">Ações</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-DEFAULT">
                {filteredProducts.map((product) => (
                  <tr key={product.id} className="group transition-colors hover:bg-surface-hover/50">
                    <td className="px-5 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="relative h-12 w-12 flex-shrink-0 overflow-hidden rounded-xl bg-surface-hover ring-1 ring-border-DEFAULT">
                          {product.image_url ? (
                            <img src={product.image_url} alt="" className="h-full w-full object-cover" />
                          ) : (
                            <div className="flex h-full w-full items-center justify-center">
                              <PhotoIcon className="h-5 w-5 text-tx-muted" />
                            </div>
                          )}
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-tx-primary">{product.name}</p>
                          {product.category && (
                            <p className="mt-0.5 text-xs text-tx-muted">{product.category}</p>
                          )}
                        </div>
                      </div>
                    </td>
                    <td className="px-5 py-3.5 hidden sm:table-cell">
                      <span
                        className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${
                          product.type === 'digital'
                            ? 'bg-sky-500/10 text-sky-700 ring-1 ring-sky-500/20'
                            : product.type === 'physical'
                            ? 'bg-amber-500/10 text-amber-700 ring-1 ring-amber-500/20'
                            : 'bg-neutral-500/10 text-neutral-700 ring-1 ring-neutral-500/20'
                        }`}
                      >
                        {product.type === 'digital' ? 'Digital' : product.type === 'physical' ? 'Físico' : 'Serviço'}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <span className="text-sm font-bold text-accent-primary">{formatCurrency(product.price)}</span>
                    </td>
                    <td className="px-5 py-3.5 text-center hidden md:table-cell">
                      <span
                        className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${
                          product.status === 'active'
                            ? 'bg-emerald-500/10 text-emerald-700 ring-1 ring-emerald-500/20'
                            : 'bg-neutral-500/10 text-neutral-600 ring-1 ring-neutral-500/20'
                        }`}
                      >
                        <span className={`h-1.5 w-1.5 rounded-full ${product.status === 'active' ? 'bg-emerald-500' : 'bg-neutral-400'}`} />
                        {product.status === 'active' ? 'Ativo' : 'Inativo'}
                      </span>
                    </td>
                    <td className="px-5 py-3.5 text-center hidden lg:table-cell">
                      {product.affiliate_enabled ? (
                        <div className="flex flex-col items-center gap-1">
                          <span className="inline-flex items-center gap-1 rounded-md bg-violet-500/10 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-500/20">
                            <UsersIcon className="h-3 w-3" />
                            {product.affiliate_commission_percent}%
                          </span>
                          {product.category && (
                            <span className="text-[11px] text-tx-muted">{product.category}</span>
                          )}
                        </div>
                      ) : (
                        <span className="text-xs text-tx-muted">—</span>
                      )}
                    </td>
                    <td className="px-5 py-3.5 text-right">
                      <div className="flex items-center justify-end gap-0.5 opacity-70 transition-opacity group-hover:opacity-100">
                        <button
                          onClick={() => viewCheckout(product)}
                          className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-page hover:text-tx-primary"
                          title="Visualizar"
                        >
                          <EyeIcon className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => copyCheckoutLink(product)}
                          className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-page hover:text-tx-primary"
                          title="Copiar link"
                        >
                          <LinkIcon className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openProductAnalytics(product)}
                          className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-page hover:text-tx-primary"
                          title="Analytics"
                        >
                          <ChartBarIcon className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => openCheckoutBuilder(product)}
                          className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-page hover:text-accent-primary"
                          title="Checkout Builder"
                        >
                          <SwatchIcon className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => handleEdit(product)}
                          className="rounded-lg p-1.5 text-tx-muted transition-colors hover:bg-surface-page hover:text-tx-primary"
                          title="Editar"
                        >
                          <PencilIcon className="h-4 w-4" />
                        </button>
                        <button
                          onClick={() => handleDelete(product)}
                          className="rounded-lg p-1.5 text-status-error transition-colors hover:bg-status-error/10"
                          title="Remover"
                        >
                          <TrashIcon className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Results count */}
      {filteredProducts.length > 0 && (
        <p className="text-center text-xs text-tx-muted">
          Exibindo <span className="font-semibold text-tx-secondary">{filteredProducts.length}</span> de <span className="font-semibold text-tx-secondary">{products.length}</span> produtos
        </p>
      )}

      {/* Floating Action Button (mobile) */}
      <button
        onClick={handleCreate}
        className="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-accent-primary text-white shadow-xl shadow-accent-primary/30 transition-all hover:scale-110 hover:bg-accent-primary/90 active:scale-95 lg:hidden"
        title="Criar Produto"
      >
        <PlusIcon className="h-6 w-6" />
      </button>
    </div>
  );
}

// Componente de formulário em página completa
function ProductFormPage({
  product,
  onClose,
}: {
  product: Product | null;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const isEditing = !!product;
  const [discountMode, setDiscountMode] = useState<'direct' | 'coupon'>('direct');
  const savedProductId = useRef(product?.id);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(product?.image_url || null);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isDragging, setIsDragging] = useState(false);
  const productMetadata = product?.metadata && typeof product.metadata === 'object' ? product.metadata : {};
  const productPixel = productMetadata.facebook_pixel && typeof productMetadata.facebook_pixel === 'object'
    ? productMetadata.facebook_pixel
    : {};

  const getInvalidProductImageReason = (file: File): string | null => {
    const mimeType = file.type.toLowerCase();
    const extension = file.name.split('.').pop()?.toLowerCase() || '';
    const isAllowedType = PRODUCT_IMAGE_ALLOWED_TYPES.has(mimeType)
      || (mimeType === '' && PRODUCT_IMAGE_ALLOWED_EXTENSIONS.has(extension));

    if (!isAllowedType) {
      return 'Formato inválido. Use JPG, PNG, GIF ou WEBP.';
    }

    if (file.size > PRODUCT_IMAGE_MAX_SIZE_BYTES) {
      return 'A imagem deve ter no máximo 5MB.';
    }

    return null;
  };

  const setSelectedImageFile = (file: File) => {
    setImageFile(file);

    const reader = new FileReader();
    reader.onload = (e) => {
      setImagePreview(e.target?.result as string);
    };
    reader.readAsDataURL(file);
  };

  const {
    register,
    handleSubmit,
    watch,
    reset,
    setValue,
    control,
    formState: { errors },
  } = useForm<ProductForm>({
    defaultValues: product
      ? {
          name: product.name,
          description: product.description,
          price: (productMetadata.direct_discount?.enabled ? (product.original_price ?? product.price) : product.price) / 100,
          type: product.type,
          category: product.category || '',
          discount_enabled: productMetadata.direct_discount?.enabled ?? (product as any).discount_enabled ?? false,
          discount_type: productMetadata.direct_discount?.type ?? (product as any).discount_type ?? 'percentage',
          discount_value: productMetadata.direct_discount?.value ?? (product as any).discount_value,
          affiliate_enabled: product.affiliate_enabled,
          affiliate_commission_percent: product.affiliate_commission_percent,
          visible_on_marketplace: product.visible_on_marketplace || false,
          // Lê de metadata.shipping (onde de fato persiste), com o formato
          // antigo como reserva para produtos salvos antes desta correção.
          shipping_required:
            productMetadata.shipping?.required ?? product.shipping_required ?? false,
          shipping_weight: productMetadata.shipping?.weight ?? product.shipping_info?.weight ?? 0,
          shipping_width: productMetadata.shipping?.width ?? product.shipping_info?.width ?? 0,
          shipping_height: productMetadata.shipping?.height ?? product.shipping_info?.height ?? 0,
          shipping_length: productMetadata.shipping?.length ?? product.shipping_info?.length ?? 0,
          free_shipping:
            productMetadata.shipping?.free_shipping ?? product.shipping_info?.free_shipping ?? false,
          shipping_price:
            (productMetadata.shipping?.shipping_price ?? product.shipping_info?.shipping_price ?? 0) / 100,
          payment_methods: productMetadata.payment_methods || {
            credit_card: false,
            pix: true,
            boleto: false,
          },
          thank_you_page_type: (product as any).thank_you_page_type || 'default',
          thank_you_page_url: (product as any).thank_you_page_url || '',
          order_bump_enabled: productMetadata.order_bump_enabled || false,
          order_bump_product_id: productMetadata.order_bump_product_id || '',
          order_bump_title: productMetadata.order_bump_title || '',
          order_bump_description: productMetadata.order_bump_description || '',
          order_bump_discount_percent: productMetadata.order_bump_discount_percent || 10,
          upsell_enabled: productMetadata.upsell_enabled || false,
          upsell_product_id: productMetadata.upsell_product_id || '',
          upsell_title: productMetadata.upsell_title || '',
          upsell_description: productMetadata.upsell_description || '',
          upsell_discount_percent: productMetadata.upsell_discount_percent || 15,
          facebook_pixel_enabled: Boolean(productPixel.enabled),
          facebook_pixel_id: productPixel.pixel_id || '',
          facebook_pixel_track_page_view: productPixel.track_page_view ?? true,
          facebook_pixel_track_initiate_checkout: productPixel.track_initiate_checkout ?? true,
          facebook_pixel_track_purchase: productPixel.track_purchase ?? true,
        }
      : {
          type: 'digital',
          category: '',
          discount_enabled: false,
          discount_type: 'percentage',
          affiliate_enabled: false,
          affiliate_commission_percent: 10,
          visible_on_marketplace: false,
          shipping_required: false,
          free_shipping: false,
          shipping_price: 0,
          stock_enabled: false,
          stock_quantity: 0,
          stock_alert_threshold: 10,
          payment_methods: {
            credit_card: false,
            pix: true,
            boleto: false,
          },
          thank_you_page_type: 'default',
          thank_you_page_url: '',
          order_bump_enabled: false,
          order_bump_title: '',
          order_bump_description: '',
          order_bump_discount_percent: 10,
          upsell_enabled: false,
          upsell_title: '',
          upsell_description: '',
          upsell_discount_percent: 15,
          facebook_pixel_enabled: false,
          facebook_pixel_id: '',
          facebook_pixel_track_page_view: true,
          facebook_pixel_track_initiate_checkout: true,
          facebook_pixel_track_purchase: true,
        },
  });

  // Estado para exibição formatada do preço (máscara de moeda)
  const formatCurrencyInput = (cents: number): string => {
    if (cents === 0) return '';
    return (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const [priceDisplay, setPriceDisplay] = useState(() => {
    if (product) {
      return formatCurrencyInput(product.price);
    }
    return '';
  });

  const handlePriceChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Extrair apenas dígitos
    const digits = e.target.value.replace(/\D/g, '');
    const cents = parseInt(digits, 10) || 0;
    setPriceDisplay(formatCurrencyInput(cents));
    setValue('price', cents / 100, { shouldValidate: true });
  };

  // Reset form when product changes
  useEffect(() => {
    if (product) {
      reset({
        name: product.name,
        description: product.description,
        price: (productMetadata.direct_discount?.enabled ? (product.original_price ?? product.price) : product.price) / 100,
        type: product.type,
        category: product.category || '',
        discount_enabled: productMetadata.direct_discount?.enabled ?? (product as any).discount_enabled ?? false,
        discount_type: productMetadata.direct_discount?.type ?? (product as any).discount_type ?? 'percentage',
        discount_value: productMetadata.direct_discount?.value ?? (product as any).discount_value,
        affiliate_enabled: product.affiliate_enabled,
        affiliate_commission_percent: product.affiliate_commission_percent,
        visible_on_marketplace: product.visible_on_marketplace || false,
        shipping_required: product.shipping_required || false,
        shipping_weight: product.shipping_info?.weight || 0,
        shipping_width: product.shipping_info?.width || 0,
        shipping_height: product.shipping_info?.height || 0,
        shipping_length: product.shipping_info?.length || 0,
        free_shipping: product.shipping_info?.free_shipping || false,
        shipping_price: product.shipping_info?.shipping_price ? product.shipping_info.shipping_price / 100 : 0,
        payment_methods: productMetadata.payment_methods || {
          credit_card: false,
          pix: true,
          boleto: false,
        },
        thank_you_page_type: (product as any).thank_you_page_type || 'default',
        thank_you_page_url: (product as any).thank_you_page_url || '',
        order_bump_enabled: productMetadata.order_bump_enabled || false,
        order_bump_product_id: productMetadata.order_bump_product_id || '',
        order_bump_title: productMetadata.order_bump_title || '',
        order_bump_description: productMetadata.order_bump_description || '',
        order_bump_discount_percent: productMetadata.order_bump_discount_percent || 10,
        upsell_enabled: productMetadata.upsell_enabled || false,
        upsell_product_id: productMetadata.upsell_product_id || '',
        upsell_title: productMetadata.upsell_title || '',
        upsell_description: productMetadata.upsell_description || '',
        upsell_discount_percent: productMetadata.upsell_discount_percent || 15,
        facebook_pixel_enabled: Boolean(productPixel.enabled),
        facebook_pixel_id: productPixel.pixel_id || '',
        facebook_pixel_track_page_view: productPixel.track_page_view ?? true,
        facebook_pixel_track_initiate_checkout: productPixel.track_initiate_checkout ?? true,
        facebook_pixel_track_purchase: productPixel.track_purchase ?? true,
      });
      setPriceDisplay(formatCurrencyInput(product.price));
      setImagePreview(product.image_url || null);
      setImageFile(null);
    } else {
      reset({
        type: 'digital',
        category: '',
        discount_enabled: false,
        discount_type: 'percentage',
        affiliate_enabled: false,
        affiliate_commission_percent: 10,
        visible_on_marketplace: false,
        shipping_required: false,
        free_shipping: false,
        shipping_price: 0,
        thank_you_page_type: 'default',
        thank_you_page_url: '',
        order_bump_enabled: false,
        order_bump_title: '',
        order_bump_description: '',
        order_bump_discount_percent: 10,
        upsell_enabled: false,
        upsell_title: '',
        upsell_description: '',
        upsell_discount_percent: 15,
        facebook_pixel_enabled: false,
        facebook_pixel_id: '',
        facebook_pixel_track_page_view: true,
        facebook_pixel_track_initiate_checkout: true,
        facebook_pixel_track_purchase: true,
      });
      setImagePreview(null);
      setImageFile(null);
    }
  }, [product, reset]);

  // Image upload removed - using base64/URL directly in payload

  const affiliateEnabled = watch('affiliate_enabled');
  const productType = watch('type');
  const productName = watch('name');
  const isPhysicalProduct = productType === 'physical';
  const freeShipping = watch('free_shipping');
  const thankYouPageType = watch('thank_you_page_type');

  const mutation = useMutation({
    mutationFn: async ({ data, publish }: { data: ProductForm; publish: boolean }) => {
      const discount = prepareProductDiscount(data.price, data.discount_enabled, discountMode, data.discount_type, data.discount_value);
      setIsUploading(true);
      
      // Upload image if a new file was selected
      let finalImageUrl = imagePreview;
      
      if (imageFile) {
        const invalidImageReason = getInvalidProductImageReason(imageFile);
        if (invalidImageReason) {
          setIsUploading(false);
          throw new Error(invalidImageReason);
        }

        // Upload the new image file
        const formData = new FormData();
        formData.append('image', imageFile);
        
        try {
          const uploadResponse = await api.post('/upload/product-image', formData, {
            headers: {
              // Let browser set multipart boundary automatically
              'Content-Type': undefined,
            },
          });
          finalImageUrl = uploadResponse.data?.data?.url;
          if (!finalImageUrl) {
            throw new Error('Upload concluído sem URL de imagem.');
          }
        } catch (uploadError: any) {
          setIsUploading(false);
          const uploadErrorMessage = uploadError?.response?.data?.errors?.image?.[0]
            || uploadError?.response?.data?.error?.message
            || uploadError?.response?.data?.message
            || 'Erro ao fazer upload da imagem';
          throw new Error(uploadErrorMessage);
        }
      }
      
      const payload: Record<string, any> = {
        name: data.name,
        description: data.description,
        ...discount,
        type: data.type,
        image_url: finalImageUrl,
        visible_on_marketplace: data.visible_on_marketplace,
        affiliate_enabled: data.affiliate_enabled,
        // Métodos de pagamento
        payment_methods: data.payment_methods,
        // Desconto
        discount_enabled: data.discount_enabled,
        discount_type: data.discount_type,
        discount_value: data.discount_value,
        // Estoque
        stock_enabled: data.stock_enabled,
        stock_quantity: data.stock_quantity,
        stock_alert_threshold: data.stock_alert_threshold,
        // Envio (produtos físicos)
        shipping_required: data.shipping_required,
        shipping_weight: data.shipping_weight,
        shipping_width: data.shipping_width,
        shipping_height: data.shipping_height,
        shipping_length: data.shipping_length,
        free_shipping: data.free_shipping,
        shipping_price: data.shipping_price ? Math.round(data.shipping_price * 100) : undefined,
        // Página de obrigado
        thank_you_page_type: data.thank_you_page_type,
        thank_you_page_url: data.thank_you_page_url,
        // Order Bump
        order_bump_enabled: data.order_bump_enabled,
        order_bump_product_id: data.order_bump_product_id,
        order_bump_title: data.order_bump_title,
        order_bump_description: data.order_bump_description,
        order_bump_discount_percent: data.order_bump_discount_percent,
        // Upsell
        upsell_enabled: data.upsell_enabled,
        upsell_product_id: data.upsell_product_id,
        upsell_title: data.upsell_title,
        upsell_description: data.upsell_description,
        upsell_discount_percent: data.upsell_discount_percent,
        // Meta Pixel
        metadata: {
          ...productMetadata,
          direct_discount: {
            enabled: data.discount_enabled && discountMode === 'direct',
            type: data.discount_type,
            value: data.discount_value,
          },
          payment_methods: data.payment_methods,
          // Envio: sem coluna no banco, persiste aqui — igual ao order bump.
          // Como chave de topo era descartado pelo controller.
          shipping: {
            required: data.shipping_required,
            weight: data.shipping_weight,
            width: data.shipping_width,
            height: data.shipping_height,
            length: data.shipping_length,
            free_shipping: data.free_shipping,
            shipping_price: data.shipping_price ? Math.round(data.shipping_price * 100) : 0,
          },
          order_bump_enabled: data.order_bump_enabled,
          order_bump_product_id: data.order_bump_product_id,
          order_bump_title: data.order_bump_title,
          order_bump_description: data.order_bump_description,
          order_bump_discount_percent: data.order_bump_discount_percent,
          upsell_enabled: data.upsell_enabled,
          upsell_product_id: data.upsell_product_id,
          upsell_title: data.upsell_title,
          upsell_description: data.upsell_description,
          upsell_discount_percent: data.upsell_discount_percent,
          facebook_pixel: {
            enabled: data.facebook_pixel_enabled,
            pixel_id: data.facebook_pixel_id?.trim() || '',
            track_page_view: data.facebook_pixel_track_page_view,
            track_initiate_checkout: data.facebook_pixel_track_initiate_checkout,
            track_add_payment_info: true,
            track_purchase: data.facebook_pixel_track_purchase,
          },
        },
      };

      if (data.category) {
        payload.category = data.category;
      }

      if (data.affiliate_enabled && data.affiliate_commission_percent != null) {
        payload.affiliate_commission_percent = data.affiliate_commission_percent;
      }

      // Rascunho = produto salvo porém fora do ar (is_active=false).
      payload.is_active = publish;

      // Salvar na API
      const response = savedProductId.current
        ? await api.put(`/products/${savedProductId.current}`, payload)
        : await api.post('/products', payload);
      savedProductId.current = response.data.data.id;
      if (data.discount_enabled && discountMode === 'coupon') {
        try {
          await api.post('/coupons', {
            code: data.coupon_code?.trim().toUpperCase(),
            type: data.discount_type,
            value: data.discount_type === 'fixed' ? Math.round(Number(data.discount_value) * 100) : Number(data.discount_value),
            product_ids: [savedProductId.current],
            is_active: true,
          });
          queryClient.invalidateQueries({ queryKey: ['coupons'] });
          toast.success('Cupom criado para este produto! Gerencie em Cupons.');
        } catch (error: any) {
          queryClient.invalidateQueries({ queryKey: ['products'] });
          const message = error.response?.data?.errors?.code?.[0] || error.response?.data?.message || 'Tente novamente.';
          throw new Error(`Produto salvo, mas o cupom não foi criado. ${message}`);
        }
      }
      return response;
    },
    onSuccess: (_result, variables) => {
      setIsUploading(false);
      toast.success(
        variables.publish
          ? isEditing
            ? 'Produto salvo e publicado!'
            : 'Produto publicado com link de checkout!'
          : 'Rascunho salvo. O produto não aparece no checkout até ser publicado.',
      );
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['products-list'] });
      queryClient.invalidateQueries({ queryKey: ['checkouts'] });
      reset();
      onClose();
    },
    onError: (error: any) => {
      setIsUploading(false);
      toast.error(error.response?.data?.error?.message || error.message || 'Erro ao salvar produto');
    },
  });

  // Produto novo nasce como rascunho até ser publicado; ao editar, mantém o
  // estado atual como referência para rotular os botões.
  const isPublished = isEditing && product?.status === 'active';
  const busy = mutation.isPending || isUploading;

  const save = (publish: boolean) =>
    handleSubmit((data: ProductForm) => {
      // Despublicar tira o produto do ar: merece confirmação explícita.
      if (!publish && isPublished && !confirm('Salvar como rascunho vai tirar este produto do ar. Continuar?')) {
        return;
      }
      mutation.mutate({ data, publish });
    })();

  const publishLabel = isUploading
    ? 'Enviando imagem...'
    : mutation.isPending
    ? 'Salvando...'
    : isPublished
    ? 'Salvar alterações'
    : isEditing
    ? 'Publicar produto'
    : 'Criar e publicar';

  return (
    <form onSubmit={handleSubmit((data: ProductForm) => mutation.mutate({ data, publish: true }))} className="space-y-5">
      {/* Header sticky com ações sempre visíveis */}
      <div className="sticky top-0 z-30 -mx-4 border-b border-border-DEFAULT bg-surface-page/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={onClose}
            className="flex-shrink-0 rounded-xl border border-border-DEFAULT bg-surface-card p-2.5 text-tx-secondary transition-colors hover:bg-surface-hover hover:text-tx-primary"
            title="Voltar para produtos"
          >
            <ArrowLeftIcon className="h-5 w-5" />
          </button>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5 text-xs font-medium text-tx-muted">
              <button type="button" onClick={onClose} className="transition-colors hover:text-accent-primary">
                Produtos
              </button>
              <span>/</span>
              <span className="text-tx-secondary">{isEditing ? 'Editar' : 'Novo'}</span>
            </div>
            <div className="flex items-center gap-2">
              <h1 className="truncate text-lg font-bold tracking-tight text-tx-primary sm:text-xl">
                {productName?.trim() || (isEditing ? 'Editar produto' : 'Novo produto')}
              </h1>
              {isEditing && (
                <span
                  className={clsx(
                    'flex-shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                    isPublished ? 'bg-emerald-500/10 text-emerald-600' : 'bg-amber-500/10 text-amber-600',
                  )}
                >
                  {isPublished ? 'PUBLICADO' : 'RASCUNHO'}
                </span>
              )}
            </div>
          </div>

          <div className="flex flex-shrink-0 items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              className="hidden rounded-xl border border-border-DEFAULT bg-surface-card px-4 py-2.5 text-sm font-semibold text-tx-secondary transition-colors hover:bg-surface-hover sm:inline-flex"
            >
              Cancelar
            </button>
            <button
              type="button"
              onClick={() => save(false)}
              disabled={busy}
              title="Salva sem colocar no ar"
              className="inline-flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-card px-4 py-2.5 text-sm font-semibold text-tx-secondary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
            >
              Salvar rascunho
            </button>
            <button
              type="button"
              onClick={() => save(true)}
              disabled={busy}
              className="inline-flex items-center gap-2 rounded-xl bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white transition-all hover:bg-accent-primary/90 disabled:cursor-not-allowed disabled:opacity-60 sm:px-5"
            >
              {publishLabel}
            </button>
          </div>
        </div>
      </div>

      {/* Form */}
      <div className="grid gap-5 lg:grid-cols-3">
          {/* Coluna Principal - Informações básicas */}
          <div className="lg:col-span-2 space-y-5">
            <FormSection
              icon={DocumentTextIcon}
              title="Informações básicas"
              description="Nome, descrição e precificação que o cliente vê no checkout."
            >
              <div className="space-y-5">
                <div>
                  <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                    Nome do produto <span className="text-status-error">*</span>
                  </label>
                  <input
                    {...register('name', { required: 'Nome é obrigatório' })}
                    type="text"
                    className="input"
                    placeholder="Ex: Curso de Marketing Digital"
                  />
                  {errors.name && (
                    <p className="mt-1.5 text-sm text-status-error">{errors.name.message}</p>
                  )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                      Preço (R$) <span className="text-status-error">*</span>
                    </label>
                    <input
                      type="text"
                      inputMode="decimal"
                      value={priceDisplay}
                      onChange={handlePriceChange}
                      className="input"
                      placeholder="99,90"
                    />
                    <input type="hidden" {...register('price', {
                      required: 'Preço é obrigatório',
                      min: { value: 0.01, message: 'Preço mínimo é R$ 0,01' },
                    })} />
                    {errors.price && (
                      <p className="mt-1.5 text-sm text-status-error">{errors.price.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                      Tipo de produto
                    </label>
                    <select {...register('type')} className="input">
                      <option value="digital">Digital</option>
                      <option value="physical">Físico</option>
                      <option value="service">Serviço</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                    Descrição
                  </label>
                  <Controller
                    name="description"
                    control={control}
                    render={({ field }) => (
                      <RichTextEditor
                        value={field.value || ''}
                        onChange={field.onChange}
                        placeholder="Descreva seu produto detalhadamente. Você pode adicionar formatação, imagens e vídeos..."
                      />
                    )}
                  />
                </div>
              </div>
            </FormSection>

            <FormSection
              icon={CreditCardIcon}
              iconTone="text-emerald-600"
              iconBg="bg-emerald-500/10"
              title="Métodos de pagamento"
              description="Formas de pagamento aceitas no checkout deste produto."
            >
              {/*
                Sem caixas de seleção: PIX é o único método ativo no sistema e não
                pode ser desmarcado. Os inputs continuam registrados, porém ocultos,
                para o payload enviado seguir idêntico ao de antes.
              */}
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="flex items-center gap-3 rounded-xl border border-border-DEFAULT bg-surface-hover/40 p-3 opacity-60">
                  <input {...register('payment_methods.credit_card')} type="checkbox" defaultChecked={false} disabled className="sr-only" />
                  <SalesIcon type="card" className="h-6 w-6 flex-shrink-0 text-tx-muted" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-tx-primary">Cartão</p>
                    <p className="text-[11px] text-tx-muted">Em breve</p>
                  </div>
                </div>

                <div className="flex items-center gap-3 rounded-xl border border-emerald-500/40 bg-emerald-500/5 p-3">
                  <input {...register('payment_methods.pix')} type="checkbox" defaultChecked className="sr-only" />
                  <PixIcon className="h-6 w-6 flex-shrink-0 text-emerald-600" />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-tx-primary">PIX</p>
                    <p className="text-[11px] text-emerald-600">Sempre ativo</p>
                  </div>
                  <CheckCircleIcon className="h-5 w-5 flex-shrink-0 text-emerald-600" />
                </div>

                <div className="flex items-center gap-3 rounded-xl border border-border-DEFAULT bg-surface-hover/40 p-3 opacity-60">
                  <input {...register('payment_methods.boleto')} type="checkbox" defaultChecked={false} disabled className="sr-only" />
                  <SalesIcon type="boleto" className="h-6 w-6 flex-shrink-0 text-tx-muted" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-tx-primary">Boleto</p>
                    <p className="text-[11px] text-tx-muted">Em breve</p>
                  </div>
                </div>
              </div>
            </FormSection>

            {/* Envio e Estoque - Produtos Físicos */}
            {isPhysicalProduct && (
              <>
              <FormSection
                icon={TruckIcon}
                iconTone="text-blue-600"
                iconBg="bg-blue-500/10"
                title="Envio"
                description="Dimensões e frete usados no cálculo do checkout."
              >
                <div className="space-y-5">
                  <div>
                    <label className="mb-2 block text-sm font-medium text-tx-secondary">
                      Dimensões do pacote
                    </label>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                      {[
                        { field: 'shipping_length' as const, label: 'Comprimento', unit: 'cm', step: '0.1' },
                        { field: 'shipping_width' as const, label: 'Largura', unit: 'cm', step: '0.1' },
                        { field: 'shipping_height' as const, label: 'Altura', unit: 'cm', step: '0.1' },
                        { field: 'shipping_weight' as const, label: 'Peso', unit: 'kg', step: '0.001' },
                      ].map(({ field, label, unit, step }) => (
                        <div key={field}>
                          <label className="mb-1 block text-[11px] font-medium text-tx-muted">
                            {label} ({unit})
                          </label>
                          <input
                            {...register(field)}
                            type="number"
                            step={step}
                            className="input text-sm"
                            placeholder="0"
                          />
                        </div>
                      ))}
                    </div>
                  </div>

                  <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 transition-colors hover:bg-emerald-500/10">
                    <input
                      {...register('free_shipping')}
                      type="checkbox"
                      className="h-4 w-4 flex-shrink-0 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
                    />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-medium text-tx-primary">Frete grátis</p>
                      <p className="text-xs text-tx-muted">O cliente não paga frete neste produto</p>
                    </div>
                  </label>

                  {!freeShipping && (
                    <div>
                      <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                        Valor do frete (R$)
                      </label>
                      <input
                        {...register('shipping_price')}
                        type="number"
                        step="0.01"
                        className="input"
                        placeholder="15.00"
                      />
                      <p className="mt-1.5 text-xs text-tx-muted">
                        Valor fixo. Deixe em branco para calcular automaticamente.
                      </p>
                    </div>
                  )}

                  <div className="flex items-start gap-2 rounded-xl bg-sky-500/5 p-3 ring-1 ring-sky-500/20">
                    <InformationCircleIcon className="mt-0.5 h-4 w-4 flex-shrink-0 text-sky-600" />
                    <p className="text-xs leading-relaxed text-sky-700 dark:text-sky-400">
                      O endereço de entrega é solicitado automaticamente no checkout.
                    </p>
                  </div>
                </div>
              </FormSection>

              <FormSection
                icon={ArchiveBoxIcon}
                iconTone="text-amber-600"
                iconBg="bg-amber-500/10"
                title="Controle de estoque"
                description="Limite a quantidade disponível para venda."
                action={
                  <label className="relative inline-flex cursor-pointer items-center">
                    <input {...register('stock_enabled')} type="checkbox" className="peer sr-only" />
                    <div className={TOGGLE_TRACK_CLASS} />
                  </label>
                }
              >
                {watch('stock_enabled') ? (
                  <div className="space-y-5">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      <div>
                        <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                          Quantidade em estoque
                        </label>
                        <input
                          {...register('stock_quantity', {
                            min: { value: 0, message: 'Quantidade não pode ser negativa' }
                          })}
                          type="number"
                          min="0"
                          className="input"
                          placeholder="100"
                        />
                      </div>

                      <div>
                        <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                          Alerta de estoque baixo
                        </label>
                        <input
                          {...register('stock_alert_threshold', {
                            min: { value: 0, message: 'Valor não pode ser negativo' }
                          })}
                          type="number"
                          min="0"
                          className="input"
                          placeholder="10"
                        />
                      </div>
                    </div>

                    {watch('stock_quantity') !== undefined && (
                      (watch('stock_quantity') || 0) <= (watch('stock_alert_threshold') || 0) ? (
                        <div className="flex items-center gap-2 rounded-xl bg-amber-500/5 p-3 ring-1 ring-amber-500/25">
                          <ExclamationTriangleIcon className="h-5 w-5 flex-shrink-0 text-amber-600" />
                          <p className="text-sm font-medium text-amber-700 dark:text-amber-400">
                            Estoque baixo: {watch('stock_quantity')} unidades
                          </p>
                        </div>
                      ) : (
                        <div className="flex items-center gap-2 rounded-xl bg-emerald-500/5 p-3 ring-1 ring-emerald-500/25">
                          <CheckCircleIcon className="h-5 w-5 flex-shrink-0 text-emerald-600" />
                          <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                            Estoque disponível: {watch('stock_quantity')} unidades
                          </p>
                        </div>
                      )
                    )}
                  </div>
                ) : (
                  <p className="text-sm text-tx-muted">
                    Estoque ilimitado. Ative o controle para definir uma quantidade.
                  </p>
                )}
              </FormSection>
              </>
            )}
          </div>

          {/* Coluna Lateral - Configurações */}
          <div className="space-y-5 lg:sticky lg:top-24 lg:self-start">
            {/* Imagem do produto */}
            <FormSection
              icon={PhotoIcon}
              iconTone="text-sky-600"
              iconBg="bg-sky-500/10"
              title="Imagem do produto"
              description="Aparece no checkout e na vitrine."
            >
              <div
                onClick={() => fileInputRef.current?.click()}
                onDragOver={(e) => {
                  e.preventDefault();
                  setIsDragging(true);
                }}
                onDragLeave={() => setIsDragging(false)}
                onDrop={(e) => {
                  e.preventDefault();
                  setIsDragging(false);
                  const file = e.dataTransfer.files[0];
                  if (file) {
                    const invalidImageReason = getInvalidProductImageReason(file);
                    if (invalidImageReason) {
                      toast.error(invalidImageReason);
                      return;
                    }

                    setSelectedImageFile(file);
                  }
                }}
                className={`relative cursor-pointer rounded-xl border-2 border-dashed transition-all ${
                  isDragging
                    ? 'border-accent-primary bg-accent-subtle'
                    : 'border-border-DEFAULT hover:border-accent-primary/70 hover:bg-surface-hover/40'
                } ${imagePreview ? 'p-2' : 'p-6'}`}
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/*"
                  className="hidden"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                      const invalidImageReason = getInvalidProductImageReason(file);
                      if (invalidImageReason) {
                        toast.error(invalidImageReason);
                        return;
                      }

                      setSelectedImageFile(file);
                    }
                  }}
                />

                {imagePreview ? (
                  <div className="group relative">
                    <img
                      src={imagePreview}
                      alt="Preview"
                      className="mx-auto aspect-[4/3] w-full rounded-lg object-cover"
                    />
                    <div className="absolute inset-0 flex items-center justify-center gap-2 rounded-lg bg-black/60 opacity-0 transition-opacity group-hover:opacity-100">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          fileInputRef.current?.click();
                        }}
                        className="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-neutral-900 transition-transform hover:scale-105"
                      >
                        Trocar
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          setImagePreview(null);
                          setImageFile(null);
                        }}
                        className="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-semibold text-white transition-transform hover:scale-105"
                      >
                        Remover
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="text-center">
                    <CloudArrowUpIcon className="mx-auto h-9 w-9 text-tx-muted" />
                    <p className="mt-2 text-sm font-medium text-tx-secondary">
                      Clique ou arraste
                    </p>
                    <p className="mt-0.5 text-xs text-tx-muted">
                      PNG, JPG ou WEBP · até 5MB
                    </p>
                  </div>
                )}
              </div>
            </FormSection>

            {/* Desconto */}
            <FormSection
              icon={ReceiptPercentIcon}
              iconTone="text-rose-600"
              iconBg="bg-rose-500/10"
              title="Desconto"
              description="Aplique no preço ou crie um cupom de desconto."
              action={
                <label className="relative inline-flex cursor-pointer items-center">
                  <input {...register('discount_enabled')} type="checkbox" className="peer sr-only" />
                  <div className={TOGGLE_TRACK_CLASS} />
                </label>
              }
            >
              {watch('discount_enabled') ? (
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-2" role="group" aria-label="Forma de desconto">
                    {([{ value: 'direct', label: 'Desconto direto' }, { value: 'coupon', label: 'Criar cupom' }] as const).map((option) => (
                      <button key={option.value} type="button" aria-pressed={discountMode === option.value}
                        onClick={() => setDiscountMode(option.value)}
                        className={clsx('rounded-element border-2 p-3 text-sm font-semibold transition-colors', discountMode === option.value ? 'border-accent-primary bg-accent-subtle text-accent-primary' : 'border-border-DEFAULT text-tx-secondary')}>
                        {option.label}
                      </button>
                    ))}
                  </div>
                  <p className="text-xs text-tx-muted">
                    {discountMode === 'direct' ? 'O desconto será aplicado automaticamente no checkout.' : 'O cupom será criado ao salvar o produto e será válido somente para ele. O cliente deverá informar o código no checkout.'}
                  </p>
                  {discountMode === 'coupon' && (
                    <div>
                      <label htmlFor="product-coupon-code" className="block text-sm font-medium text-tx-secondary">Código do cupom</label>
                      <input id="product-coupon-code" className="input mt-1 uppercase" placeholder="Ex: PROMO10" maxLength={50}
                        {...register('coupon_code', {
                          validate: (value) => !watch('discount_enabled') || discountMode !== 'coupon' || /^[A-Z0-9]{1,50}$/.test(value?.trim().toUpperCase() || '') || 'Informe um código com letras e números (até 50 caracteres).',
                        })} />
                      {errors.coupon_code && <p className="mt-1 text-xs text-status-error">{errors.coupon_code.message}</p>}
                    </div>
                  )}
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary mb-2">
                      Tipo de Desconto
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                      <label className={`flex items-center justify-center gap-2 p-3 rounded-element border-2 cursor-pointer transition-all ${
                        watch('discount_type') === 'percentage'
                          ? 'border-accent-primary bg-accent-subtle text-accent-primary'
                          : 'border-border-DEFAULT hover:border-border-DEFAULT'
                      }`}>
                        <input
                          {...register('discount_type')}
                          type="radio"
                          value="percentage"
                          className="sr-only"
                        />
                        <span className="font-bold">%</span>
                      </label>
                      <label className={`flex items-center justify-center gap-2 p-3 rounded-element border-2 cursor-pointer transition-all ${
                        watch('discount_type') === 'fixed' 
                          ? 'border-accent-primary bg-accent-subtle text-accent-primary' 
                          : 'border-border-DEFAULT hover:border-border-DEFAULT'
                      }`}>
                        <input
                          {...register('discount_type')}
                          type="radio"
                          value="fixed"
                          className="sr-only"
                        />
                        <span className="font-bold">R$</span>
                      </label>
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary">
                      {watch('discount_type') === 'percentage' ? 'Desconto (%)' : 'Desconto (R$)'}
                    </label>
                    <input
                      {...register('discount_value', {
                        required: watch('discount_enabled') ? 'Informe o valor do desconto' : false,
                        min: { value: 0.01, message: 'Valor mínimo é 0,01' },
                        max: watch('discount_type') === 'percentage' 
                          ? { value: 100, message: 'Máximo 100%' }
                          : undefined,
                      })}
                      type="number"
                      step="0.01"
                      className="input mt-1"
                      placeholder={watch('discount_type') === 'percentage' ? '10' : '50.00'}
                    />
                    {errors.discount_value && <p className="mt-1 text-xs text-status-error">{errors.discount_value.message}</p>}
                    {watch('discount_value') && watch('price') && (
                      <div className="mt-2 rounded-lg bg-emerald-500/5 p-2.5 ring-1 ring-emerald-500/20">
                        <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                          {discountMode === 'coupon' ? 'Preço com cupom: ' : 'Preço final: '}{formatCurrency(
                            watch('discount_type') === 'percentage'
                              ? (watch('price') * 100) - ((watch('price') * 100) * (watch('discount_value') || 0) / 100)
                              : (watch('price') * 100) - ((watch('discount_value') || 0) * 100)
                          )}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              ) : (
                <p className="text-sm text-tx-muted">
                  Sem desconto. Ative para definir um preço promocional.
                </p>
              )}
            </FormSection>

            {/* Afiliados e Vitrine */}
            <FormSection
              icon={UsersIcon}
              iconTone="text-violet-600"
              iconBg="bg-violet-500/10"
              title="Programa de afiliados"
              description="Permitir que afiliados promovam este produto."
              action={
                <label className="relative inline-flex cursor-pointer items-center">
                  <input {...register('affiliate_enabled')} type="checkbox" className="peer sr-only" />
                  <div className={TOGGLE_TRACK_CLASS} />
                </label>
              }
            >
              {affiliateEnabled ? (
                <div className="space-y-4">
                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                      Comissão para afiliados (%)
                    </label>
                    <input
                      {...register('affiliate_commission_percent', {
                        min: { value: 1, message: 'Mínimo 1%' },
                        max: { value: 50, message: 'Máximo 50%' },
                      })}
                      type="number"
                      step="0.1"
                      className="input"
                      placeholder="10"
                    />
                    {watch('affiliate_commission_percent') && watch('price') && (
                      <p className="mt-1.5 text-sm font-medium text-accent-primary">
                        Comissão por venda: {formatCurrency(Math.round((watch('price') * 100) * (watch('affiliate_commission_percent') || 0) / 100))}
                      </p>
                    )}
                    {errors.affiliate_commission_percent && (
                      <p className="mt-1.5 text-sm text-status-error">
                        {errors.affiliate_commission_percent.message}
                      </p>
                    )}
                  </div>

                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-tx-secondary">
                      Categoria <span className="text-status-error">*</span>
                    </label>
                    <select
                      {...register('category', {
                        validate: (value) => {
                          if (affiliateEnabled && !value) {
                            return 'Categoria é obrigatória para produtos com afiliados';
                          }
                          return true;
                        }
                      })}
                      className={`input ${errors.category ? 'border-status-error' : ''}`}
                    >
                      <option value="">Selecione uma categoria...</option>
                      <option value="Tecnologia">Tecnologia</option>
                      <option value="Marketing">Marketing</option>
                      <option value="Finanças">Finanças</option>
                      <option value="Design">Design</option>
                      <option value="Negócios">Negócios</option>
                      <option value="Saúde">Saúde</option>
                      <option value="Desenvolvimento Pessoal">Desenvolvimento Pessoal</option>
                      <option value="Educação">Educação</option>
                    </select>
                    {errors.category && (
                      <p className="mt-1.5 text-sm text-status-error">
                        {errors.category.message}
                      </p>
                    )}
                  </div>

                  <div className="flex items-start gap-2 rounded-xl bg-emerald-500/5 p-3 ring-1 ring-emerald-500/20">
                    <CheckCircleIcon className="mt-0.5 h-4 w-4 flex-shrink-0 text-emerald-600" />
                    <p className="text-xs leading-relaxed text-emerald-700 dark:text-emerald-400">
                      Este produto aparecerá na vitrine pública <span className="font-medium">/vitrine</span> para afiliados promoverem.
                    </p>
                  </div>
                </div>
              ) : (
                <p className="text-sm text-tx-muted">
                  Desativado. Ative para definir comissão e listar na vitrine de afiliados.
                </p>
              )}
            </FormSection>

            {/* Página de Obrigado */}
            <FormSection
              icon={CheckBadgeIcon}
              iconTone="text-emerald-600"
              iconBg="bg-emerald-500/10"
              title="Página de obrigado"
              description="O que acontece após a compra ser aprovada."
            >
              <div className="space-y-4">
                <div className="grid grid-cols-3 gap-1 sm:gap-2">
                  <label className={`flex flex-col items-center justify-center p-3 rounded-element border-2 cursor-pointer transition-all ${
                    thankYouPageType === 'default' 
                      ? 'border-accent-primary bg-accent-subtle text-accent-primary' 
                      : 'border-border-DEFAULT hover:border-border-DEFAULT'
                  }`}>
                    <input
                      {...register('thank_you_page_type')}
                      type="radio"
                      value="default"
                      className="sr-only"
                    />
                    <svg className="h-5 w-5 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span className="text-xs font-medium">Padrão</span>
                  </label>
                  <label className={`flex flex-col items-center justify-center p-3 rounded-element border-2 cursor-pointer transition-all ${
                    thankYouPageType === 'custom' 
                      ? 'border-accent-primary bg-accent-subtle text-accent-primary' 
                      : 'border-border-DEFAULT hover:border-border-DEFAULT'
                  }`}>
                    <input
                      {...register('thank_you_page_type')}
                      type="radio"
                      value="custom"
                      className="sr-only"
                    />
                    <svg className="h-5 w-5 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    <span className="text-xs font-medium">Personalizada</span>
                  </label>
                  <label className={`flex flex-col items-center justify-center p-3 rounded-element border-2 cursor-pointer transition-all ${
                    thankYouPageType === 'redirect' 
                      ? 'border-accent-primary bg-accent-subtle text-accent-primary' 
                      : 'border-border-DEFAULT hover:border-border-DEFAULT'
                  }`}>
                    <input
                      {...register('thank_you_page_type')}
                      type="radio"
                      value="redirect"
                      className="sr-only"
                    />
                    <svg className="h-5 w-5 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                    <span className="text-xs font-medium">Redirecionar</span>
                  </label>
                </div>

                {(thankYouPageType === 'custom' || thankYouPageType === 'redirect') && (
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary mb-1">
                      {thankYouPageType === 'redirect' ? 'URL de Redirecionamento' : 'URL da Página'}
                    </label>
                    <input
                      {...register('thank_you_page_url')}
                      type="url"
                      className="input"
                      placeholder="https://seusite.com/obrigado"
                    />
                    <p className="mt-1.5 text-xs text-tx-muted">
                      {thankYouPageType === 'redirect'
                        ? 'O cliente será redirecionado para esta URL após a compra'
                        : 'URL da sua página de obrigado personalizada'
                      }
                    </p>
                  </div>
                )}
              </div>
            </FormSection>

            {/* Ações (mobile — no desktop ficam no header sticky) */}
            <div className="flex flex-col gap-2 lg:hidden">
              <button
                type="button"
                onClick={() => save(true)}
                disabled={busy}
                className="flex h-12 w-full items-center justify-center rounded-xl bg-accent-primary text-sm font-semibold text-white transition-all hover:bg-accent-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {publishLabel}
              </button>
              <button
                type="button"
                onClick={() => save(false)}
                disabled={busy}
                className="flex h-12 w-full items-center justify-center rounded-xl border border-border-DEFAULT bg-surface-card text-sm font-semibold text-tx-secondary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
              >
                Salvar rascunho
              </button>
              <button
                type="button"
                onClick={onClose}
                className="flex h-12 w-full items-center justify-center rounded-xl border border-border-DEFAULT bg-surface-card text-sm font-semibold text-tx-secondary transition-colors hover:bg-surface-hover"
              >
                Cancelar
              </button>
            </div>
          </div>
      </div>
    </form>
  );
}
