import { useState, useRef, useEffect, useMemo } from 'react';
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
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Product {
  id: string;
  name: string;
  description: string;
  price: number;
  currency: string;
  type: 'physical' | 'digital' | 'service';
  status: 'active' | 'inactive';
  category?: string;
  image_url?: string;
  checkout_url?: string;
  checkout_slug?: string;
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
  // Área de Membros
  member_area_enabled: boolean;
  course_id?: string;
}

const PRODUCT_IMAGE_MAX_SIZE_BYTES = 5 * 1024 * 1024;
const PRODUCT_IMAGE_ALLOWED_TYPES = new Set([
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
]);
const PRODUCT_IMAGE_ALLOWED_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);

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
  const checkoutBaseUrl = (() => {
    const verified = (domainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');
    const primary = verified.find((d: CustomDomain) => d.is_primary) || verified[0];
    return primary ? `https://${primary.domain}` : window.location.origin;
  })();

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
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Produtos</h1>
          <p className="text-sm text-tx-muted">
            {stats.total} {stats.total === 1 ? 'produto' : 'produtos'} • {stats.active} {stats.active === 1 ? 'ativo' : 'ativos'}
          </p>
        </div>
        <button
          onClick={handleCreate}
          className="hidden lg:flex items-center gap-2 px-4 py-2.5 rounded-lg bg-accent-primary text-white font-medium hover:bg-accent-primary/90 transition-all hover:scale-105 active:scale-95 shadow-lg"
        >
          <PlusIcon className="h-5 w-5" />
          Criar Produto
        </button>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10">
              <CubeIcon className="h-5 w-5 text-blue-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Total</p>
              <p className="text-xl font-bold text-tx-primary">{stats.total}</p>
            </div>
          </div>
        </div>
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-500/10">
              <CheckCircleIcon className="h-5 w-5 text-green-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Ativos</p>
              <p className="text-xl font-bold text-green-600">{stats.active}</p>
            </div>
          </div>
        </div>
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/10">
              <UsersIcon className="h-5 w-5 text-purple-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Afiliados</p>
              <p className="text-xl font-bold text-purple-600">{stats.withAffiliates}</p>
            </div>
          </div>
        </div>
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10">
              <TagIcon className="h-5 w-5 text-amber-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Digital</p>
              <p className="text-xl font-bold text-amber-600">{stats.digital}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Search & Filters Bar */}
      <div className="rounded-2xl bg-surface-card p-3 sm:p-4 shadow-sm border border-border-DEFAULT">
        <div className="flex flex-col gap-2 sm:gap-3">
          {/* Search */}
          <div className="relative">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar produtos..."
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-input pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder-tx-muted focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20 transition-all"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>

          {/* Controls Row */}
          <div className="grid grid-cols-2 sm:flex sm:flex-row gap-2">
            {/* Filter Toggle */}
            <button
              onClick={() => setShowFilters(!showFilters)}
              className={`inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium transition-all ${
                showFilters 
                  ? 'bg-accent-primary text-white shadow-sm' 
                  : 'bg-surface-hover text-tx-secondary hover:bg-surface-page border border-border-DEFAULT'
              }`}
            >
              <FunnelIcon className="h-4 w-4" />
              <span className="hidden sm:inline">Filtros</span>
              {activeFiltersCount > 0 && (
                <span className={`flex h-5 w-5 items-center justify-center rounded-full text-xs font-bold ${
                  showFilters ? 'bg-white/20' : 'bg-accent-primary text-white'
                }`}>
                  {activeFiltersCount}
                </span>
              )}
            </button>

            {/* Sort */}
            <div className="relative sm:flex-1 sm:max-w-[200px]">
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as any)}
                className="w-full appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-8 py-2.5 text-sm font-medium text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20 transition-all cursor-pointer hover:bg-surface-hover"
              >
                <option value="newest">Mais recentes</option>
                <option value="oldest">Mais antigos</option>
                <option value="price_asc">Menor preço</option>
                <option value="price_desc">Maior preço</option>
                <option value="name">Nome A-Z</option>
              </select>
              <ChevronDownIcon className="pointer-events-none absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
            </div>

            {/* View Toggle - Hidden on mobile */}
            <div className="hidden sm:flex items-center rounded-lg border border-border-DEFAULT bg-surface-input overflow-hidden">
              <button
                onClick={() => setViewMode('grid')}
                className={`p-2.5 transition-all ${
                  viewMode === 'grid' 
                    ? 'bg-accent-primary text-white' 
                    : 'text-tx-muted hover:bg-surface-hover hover:text-tx-primary'
                }`}
                title="Visualização em grade"
              >
                <Squares2X2Icon className="h-4 w-4" />
              </button>
              <div className="w-px h-6 bg-border-DEFAULT" />
              <button
                onClick={() => setViewMode('list')}
                className={`p-2.5 transition-all ${
                  viewMode === 'list' 
                    ? 'bg-accent-primary text-white' 
                    : 'text-tx-muted hover:bg-surface-hover hover:text-tx-primary'
                }`}
                title="Visualização em lista"
              >
                <ListBulletIcon className="h-4 w-4" />
              </button>
            </div>

            {/* Refresh Button */}
            <button
              onClick={() => refetch()}
              className="col-span-2 sm:col-span-1 sm:w-auto p-2.5 rounded-lg border border-border-DEFAULT bg-surface-input text-tx-muted hover:bg-surface-hover hover:text-tx-primary transition-all flex items-center justify-center gap-2"
              title="Atualizar"
            >
              <ArrowPathIcon className="h-4 w-4" />
              <span className="sm:hidden text-sm font-medium">Atualizar</span>
            </button>
          </div>
        </div>

        {/* Expanded Filters */}
        {showFilters && (
          <div className="mt-3 pt-3 border-t border-border-DEFAULT">
            <div className="grid grid-cols-1 sm:flex sm:flex-wrap gap-3 sm:items-center">
              {/* Status Filter */}
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-tx-secondary min-w-[60px]">Status:</span>
                <div className="relative flex-1 sm:flex-initial">
                  <select
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value as any)}
                    className="w-full sm:w-auto appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-8 py-2 text-sm text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20 transition-all cursor-pointer"
                  >
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>

              {/* Type Filter */}
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-tx-secondary min-w-[60px]">Tipo:</span>
                <div className="relative flex-1 sm:flex-initial">
                  <select
                    value={filterType}
                    onChange={(e) => setFilterType(e.target.value as any)}
                    className="w-full sm:w-auto appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-8 py-2 text-sm text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20 transition-all cursor-pointer"
                  >
                    <option value="all">Todos</option>
                    <option value="digital">Digital</option>
                    <option value="physical">Físico</option>
                    <option value="service">Serviço</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>

              {/* Affiliate Filter */}
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium text-tx-secondary min-w-[60px]">Afiliados:</span>
                <div className="relative flex-1 sm:flex-initial">
                  <select
                    value={filterAffiliate}
                    onChange={(e) => setFilterAffiliate(e.target.value as any)}
                    className="w-full sm:w-auto appearance-none rounded-lg border border-border-DEFAULT bg-surface-input pl-3 pr-8 py-2 text-sm text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20 transition-all cursor-pointer"
                  >
                    <option value="all">Todos</option>
                    <option value="enabled">Ativado</option>
                    <option value="disabled">Desativado</option>
                  </select>
                  <ChevronDownIcon className="pointer-events-none absolute right-2 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
                </div>
              </div>

              {activeFiltersCount > 0 && (
                <button
                  onClick={clearFilters}
                  className="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-accent-primary hover:bg-accent-subtle transition-all w-full sm:w-auto"
                >
                  <XMarkIcon className="h-4 w-4" />
                  Limpar filtros
                </button>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Products */}
      {isLoading ? (
        <SectionSpinner />
      ) : filteredProducts.length === 0 ? (
        <div className="card p-12 text-center">
          <CubeIcon className="mx-auto h-16 w-16 text-tx-muted" />
          <h3 className="mt-4 text-lg font-semibold text-tx-primary">
            {products.length === 0 ? 'Nenhum produto cadastrado' : 'Nenhum produto encontrado'}
          </h3>
          <p className="mt-2 text-base text-tx-muted">
            {products.length === 0 
              ? 'Comece criando seu primeiro produto'
              : 'Tente ajustar os filtros'}
          </p>
          {products.length === 0 && (
            <button onClick={handleCreate} className="btn-primary mt-4 text-base px-6 py-3">
              Criar Produto
            </button>
          )}
        </div>
      ) : viewMode === 'grid' ? (
        /* Grid View - Compact Cards */
        <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {filteredProducts.map((product) => (
            <div key={product.id} className="card overflow-hidden hover:shadow-md transition-shadow group">
              {/* Product Image - Smaller */}
              <div className="aspect-square bg-surface-hover relative">
                {product.image_url ? (
                  <img
                    src={product.image_url}
                    alt={product.name}
                    className="h-full w-full object-cover"
                  />
                ) : (
                  <div className="flex h-full items-center justify-center">
                    <PhotoIcon className="h-8 w-8 text-tx-muted" />
                  </div>
                )}
                {/* Quick Actions Overlay */}
                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                  <button
                    onClick={() => viewCheckout(product)}
                    className="p-2 bg-white rounded-full hover:bg-gray-100"
                    title="Visualizar"
                  >
                    <EyeIcon className="h-4 w-4 text-gray-700" />
                  </button>
                  <button
                    onClick={() => copyCheckoutLink(product)}
                    className="p-2 bg-white rounded-full hover:bg-gray-100"
                    title="Copiar link"
                  >
                    <LinkIcon className="h-4 w-4 text-gray-700" />
                  </button>
                  <button
                    onClick={() => openCheckoutBuilder(product)}
                    className="p-2 bg-white rounded-full hover:bg-gray-100"
                    title="Personalizar Checkout"
                  >
                    <SwatchIcon className="h-4 w-4 text-gray-700" />
                  </button>
                  <button
                    onClick={() => handleEdit(product)}
                    className="p-2 bg-white rounded-full hover:bg-gray-100"
                    title="Editar"
                  >
                    <PencilIcon className="h-4 w-4 text-gray-700" />
                  </button>
                </div>
                {/* Status Badge */}
                <span
                  className={`absolute top-2 right-2 px-1.5 py-0.5 text-[10px] font-medium rounded ${
                    product.status === 'active' 
                      ? 'bg-green-500 text-white' 
                      : 'bg-gray-500 text-white'
                  }`}
                >
                  {product.status === 'active' ? 'Ativo' : 'Inativo'}
                </span>
              </div>

              {/* Product Info - Compact */}
              <div className="p-2.5">
                <h3 className="font-medium text-sm text-tx-primary line-clamp-1" title={product.name}>
                  {product.name}
                </h3>

                <div className="mt-2 flex items-center justify-between">
                  <span className="text-sm font-bold text-accent-primary">
                    {formatCurrency(product.price)}
                  </span>
                  <Menu as="div" className="relative">
                    <Menu.Button className="rounded p-1 hover:bg-surface-hover">
                      <EllipsisVerticalIcon className="h-4 w-4 text-tx-muted" />
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
                      <Menu.Items className="absolute right-0 z-10 mt-1 w-36 origin-top-right rounded-lg bg-surface-card shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                        <div className="py-1">
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => viewCheckout(product)}
                                className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-3 py-1.5 text-xs text-tx-secondary`}
                              >
                                <EyeIcon className="mr-2 h-4 w-4" />
                                Visualizar
                              </button>
                            )}
                          </Menu.Item>
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => copyCheckoutLink(product)}
                                className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-3 py-1.5 text-xs text-tx-secondary`}
                              >
                                <LinkIcon className="mr-2 h-4 w-4" />
                                Copiar link
                              </button>
                            )}
                          </Menu.Item>
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => openCheckoutBuilder(product)}
                                className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-3 py-1.5 text-xs text-tx-secondary`}
                              >
                                <SwatchIcon className="mr-2 h-4 w-4" />
                                Checkout Builder
                              </button>
                            )}
                          </Menu.Item>
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => handleEdit(product)}
                                className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-3 py-1.5 text-xs text-tx-secondary`}
                              >
                                <PencilIcon className="mr-2 h-4 w-4" />
                                Editar
                              </button>
                            )}
                          </Menu.Item>
                          <hr className="my-1" />
                          <Menu.Item>
                            {({ active }) => (
                              <button
                                onClick={() => handleDelete(product)}
                                className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-3 py-1.5 text-xs text-status-error`}
                              >
                                <TrashIcon className="mr-2 h-4 w-4" />
                                Remover
                              </button>
                            )}
                          </Menu.Item>
                        </div>
                      </Menu.Items>
                    </Transition>
                  </Menu>
                </div>

                <div className="mt-2 flex items-center gap-1 flex-wrap">
                  <span
                    className={`px-1.5 py-0.5 text-[10px] rounded ${
                      product.type === 'digital'
                        ? 'bg-blue-100 text-blue-700'
                        : product.type === 'physical'
                        ? 'bg-amber-100 text-amber-700'
                        : 'bg-gray-100 text-gray-700'
                    }`}
                  >
                    {product.type === 'digital' ? 'Digital' : product.type === 'physical' ? 'Físico' : 'Serviço'}
                  </span>
                  {product.affiliate_enabled && (
                    <span className="px-1.5 py-0.5 text-[10px] rounded bg-purple-100 text-purple-700">
                      {product.affiliate_commission_percent}%
                    </span>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        /* List View */
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-surface-hover border-b border-border-DEFAULT">
              <tr>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-tx-muted uppercase">Produto</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-tx-muted uppercase hidden sm:table-cell">Tipo</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-tx-muted uppercase">Preço</th>
                <th className="px-4 py-2.5 text-center text-xs font-medium text-tx-muted uppercase hidden md:table-cell">Status</th>
                <th className="px-4 py-2.5 text-center text-xs font-medium text-tx-muted uppercase hidden lg:table-cell">Afiliados</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-tx-muted uppercase">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT">
              {filteredProducts.map((product) => (
                <tr key={product.id} className="hover:bg-surface-hover">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 rounded-lg bg-surface-hover overflow-hidden flex-shrink-0">
                        {product.image_url ? (
                          <img src={product.image_url} alt="" className="h-full w-full object-cover" />
                        ) : (
                          <div className="h-full w-full flex items-center justify-center">
                            <PhotoIcon className="h-5 w-5 text-tx-muted" />
                          </div>
                        )}
                      </div>
                      <div className="min-w-0">
                        <p className="text-sm font-medium text-tx-primary truncate">{product.name}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 hidden sm:table-cell">
                    <span className={`px-2 py-0.5 text-xs rounded ${
                      product.type === 'digital' ? 'bg-blue-100 text-blue-700' :
                      product.type === 'physical' ? 'bg-amber-100 text-amber-700' :
                      'bg-gray-100 text-gray-700'
                    }`}>
                      {product.type === 'digital' ? 'Digital' : product.type === 'physical' ? 'Físico' : 'Serviço'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <span className="text-sm font-semibold text-accent-primary">{formatCurrency(product.price)}</span>
                  </td>
                  <td className="px-4 py-3 text-center hidden md:table-cell">
                    <span className={`px-2 py-0.5 text-xs rounded ${
                      product.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                    }`}>
                      {product.status === 'active' ? 'Ativo' : 'Inativo'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center hidden lg:table-cell">
                    {product.affiliate_enabled ? (
                      <span className="px-2 py-0.5 text-xs rounded bg-purple-100 text-purple-700">
                        {product.affiliate_commission_percent}%
                      </span>
                    ) : (
                      <span className="text-xs text-tx-muted">-</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => viewCheckout(product)}
                        className="p-1.5 rounded hover:bg-surface-page text-tx-muted hover:text-tx-primary"
                        title="Visualizar"
                      >
                        <EyeIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => copyCheckoutLink(product)}
                        className="p-1.5 rounded hover:bg-surface-page text-tx-muted hover:text-tx-primary"
                        title="Copiar link"
                      >
                        <LinkIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => openCheckoutBuilder(product)}
                        className="p-1.5 rounded hover:bg-surface-page text-tx-muted hover:text-accent-primary"
                        title="Personalizar Checkout"
                      >
                        <SwatchIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => handleEdit(product)}
                        className="p-1.5 rounded hover:bg-surface-page text-tx-muted hover:text-tx-primary"
                        title="Editar"
                      >
                        <PencilIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => handleDelete(product)}
                        className="p-1.5 rounded hover:bg-surface-page text-status-error"
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
        <p className="text-xs text-tx-muted text-center">
          Exibindo {filteredProducts.length} de {products.length} produtos
        </p>
      )}

      {/* Floating Action Button */}
      <button
        onClick={handleCreate}
        className="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-accent-primary text-white shadow-lg hover:bg-accent-primary/90 transition-all hover:scale-110 active:scale-95 lg:hidden"
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
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(product?.image_url || null);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [isDragging, setIsDragging] = useState(false);

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
          price: product.price / 100,
          type: product.type,
          category: product.category || '',
          discount_enabled: (product as any).discount_enabled || false,
          discount_type: (product as any).discount_type || 'percentage',
          discount_value: (product as any).discount_value,
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
          thank_you_page_type: (product as any).thank_you_page_type || 'default',
          thank_you_page_url: (product as any).thank_you_page_url || '',
          order_bump_enabled: (product as any).order_bump_enabled || false,
          order_bump_product_id: (product as any).order_bump_product_id || '',
          order_bump_title: (product as any).order_bump_title || '',
          order_bump_description: (product as any).order_bump_description || '',
          order_bump_discount_percent: (product as any).order_bump_discount_percent || 10,
          upsell_enabled: (product as any).upsell_enabled || false,
          upsell_product_id: (product as any).upsell_product_id || '',
          upsell_title: (product as any).upsell_title || '',
          upsell_description: (product as any).upsell_description || '',
          upsell_discount_percent: (product as any).upsell_discount_percent || 15,
          member_area_enabled: (product as any).member_area_enabled || false,
          course_id: (product as any).course_id || '',
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
          member_area_enabled: false,
          course_id: '',
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
        price: product.price / 100,
        type: product.type,
        category: product.category || '',
        discount_enabled: (product as any).discount_enabled || false,
        discount_type: (product as any).discount_type || 'percentage',
        discount_value: (product as any).discount_value,
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
        thank_you_page_type: (product as any).thank_you_page_type || 'default',
        thank_you_page_url: (product as any).thank_you_page_url || '',
        order_bump_enabled: (product as any).order_bump_enabled || false,
        order_bump_product_id: (product as any).order_bump_product_id || '',
        order_bump_title: (product as any).order_bump_title || '',
        order_bump_description: (product as any).order_bump_description || '',
        order_bump_discount_percent: (product as any).order_bump_discount_percent || 10,
        upsell_enabled: (product as any).upsell_enabled || false,
        upsell_product_id: (product as any).upsell_product_id || '',
        upsell_title: (product as any).upsell_title || '',
        upsell_description: (product as any).upsell_description || '',
        upsell_discount_percent: (product as any).upsell_discount_percent || 15,
        member_area_enabled: (product as any).member_area_enabled || false,
        course_id: (product as any).course_id || '',
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
        member_area_enabled: false,
        course_id: '',
      });
      setImagePreview(null);
      setImageFile(null);
    }
  }, [product, reset]);

  // Query para buscar produtos disponíveis para Order Bump e Upsell
  const { data: availableProducts = [] } = useQuery<Product[]>({
    queryKey: ['products-for-offers'],
    queryFn: async () => {
      const response = await api.get('/products');
      // Filtrar para não mostrar o produto atual
      const products = response.data?.data || response.data || [];
      return products.filter((p: Product) => p.id !== product?.id);
    },
  });

  // Query para buscar cursos da área de membros
  const { data: availableCourses = [] } = useQuery<{ id: string; name: string; description?: string }[]>({
    queryKey: ['courses-for-products'],
    queryFn: async () => {
      const response = await api.get('/member-area/courses');
      return response.data?.data || response.data || [];
    },
  });

  // Image upload removed - using base64/URL directly in payload

  const affiliateEnabled = watch('affiliate_enabled');
  const productType = watch('type');
  const isPhysicalProduct = productType === 'physical';
  const freeShipping = watch('free_shipping');
  const orderBumpEnabled = watch('order_bump_enabled');
  const upsellEnabled = watch('upsell_enabled');
  const thankYouPageType = watch('thank_you_page_type');
  const memberAreaEnabled = watch('member_area_enabled');

  const mutation = useMutation({
    mutationFn: async (data: ProductForm) => {
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
        price: Math.round(data.price * 100),
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
        // Área de Membros
        member_area_enabled: data.member_area_enabled,
        course_id: data.course_id,
      };

      if (data.category) {
        payload.category = data.category;
      }

      if (data.affiliate_enabled && data.affiliate_commission_percent != null) {
        payload.affiliate_commission_percent = data.affiliate_commission_percent;
      }

      // Salvar na API
      if (isEditing) {
        const result = await api.put(`/products/${product!.id}`, payload);
        return result;
      }
      const response = await api.post('/products', payload);
      return response;
    },
    onSuccess: () => {
      setIsUploading(false);
      toast.success(isEditing ? 'Produto atualizado!' : 'Produto criado com link de checkout!');
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

  const onSubmit = (data: ProductForm) => {
    mutation.mutate(data);
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Header */}
      <div className="flex items-center gap-3 sm:gap-4">
        <button
          onClick={onClose}
          className="rounded-lg p-2 hover:bg-surface-hover transition-colors"
        >
          <ArrowLeftIcon className="h-5 w-5 text-tx-secondary" />
        </button>
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">
            {isEditing ? 'Editar Produto' : 'Novo Produto'}
          </h1>
          <p className="text-sm text-tx-muted mt-1">
            {isEditing ? 'Atualize as informações do produto' : 'Preencha as informações do seu produto'}
          </p>
        </div>
      </div>

      {/* Form */}
      <form onSubmit={handleSubmit(onSubmit)}>
        <div className="grid gap-4 sm:gap-6 lg:grid-cols-3">
          {/* Coluna Principal - Informações básicas */}
          <div className="lg:col-span-2 space-y-4 sm:space-y-6">
            {/* Card de Imagem */}
            <div className="card p-4 sm:p-6">
              <h2 className="text-lg font-semibold text-tx-primary mb-4">
                Imagem do Produto
              </h2>
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
                className={`relative cursor-pointer rounded-card border-2 border-dashed transition-all ${
                  isDragging
                    ? 'border-accent-primary bg-accent-subtle'
                    : 'border-border-DEFAULT hover:border-accent-primary/70'
                } ${imagePreview ? 'p-2' : 'p-8'}`}
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
                  <div className="relative group">
                    <img
                      src={imagePreview}
                      alt="Preview"
                      className="w-full h-auto max-h-96 object-contain rounded-lg mx-auto"
                    />
                    <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-3">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          fileInputRef.current?.click();
                        }}
                        className="px-4 py-2 bg-surface-card text-tx-primary text-sm font-medium rounded-element hover:bg-surface-hover transition-colors"
                      >
                        Trocar imagem
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          setImagePreview(null);
                          setImageFile(null);
                        }}
                        className="px-4 py-2 bg-red-500 text-white text-sm font-medium rounded-element hover:bg-red-600 transition-colors"
                      >
                        Remover
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="text-center">
                    <CloudArrowUpIcon className="mx-auto h-12 w-12 text-tx-muted" />
                    <p className="mt-3 text-sm font-medium text-tx-secondary">
                      Clique ou arraste uma imagem
                    </p>
                    <p className="mt-1 text-xs text-tx-muted">
                      PNG, JPG ou WEBP até 5MB
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Card de Informações */}
            <div className="card p-4 sm:p-6">
              <h2 className="text-lg font-semibold text-tx-primary mb-4">
                Informações do Produto
              </h2>
              <div className="space-y-4">
                <div>
                  <label className="block text-sm font-medium text-tx-secondary">
                    Nome do Produto
                  </label>
                  <input
                    {...register('name', { required: 'Nome é obrigatório' })}
                    type="text"
                    className="input mt-1"
                    placeholder="Ex: Curso de Marketing Digital"
                  />
                  {errors.name && (
                    <p className="mt-1 text-sm text-status-error">{errors.name.message}</p>
                  )}
                </div>

                <div>
                  <label className="block text-sm font-medium text-tx-secondary mb-1">
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

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary">
                      Preço (R$)
                    </label>
                    <input
                      type="text"
                      inputMode="decimal"
                      value={priceDisplay}
                      onChange={handlePriceChange}
                      className="input mt-1"
                      placeholder="99,90"
                    />
                    <input type="hidden" {...register('price', {
                      required: 'Preço é obrigatório',
                      min: { value: 0.01, message: 'Preço mínimo é R$ 0,01' },
                    })} />
                    {errors.price && (
                      <p className="mt-1 text-sm text-status-error">{errors.price.message}</p>
                    )}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-tx-secondary">
                      Tipo
                    </label>
                    <select {...register('type')} className="input mt-1">
                      <option value="digital">Digital</option>
                      <option value="physical">Físico</option>
                      <option value="service">Serviço</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            {/* Order Bump, Upsell e Área de Membros */}
            <div className="card p-4 sm:p-6">
              <h2 className="text-lg font-semibold text-tx-primary mb-4">Configurações de Vendas</h2>
              
              {/* Métodos de Pagamento */}
              <div className="mb-6 p-4 rounded-xl border-2 border-border-DEFAULT bg-gradient-to-br from-surface-card to-surface-hover/30">
                <div className="flex items-center gap-3 mb-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/10">
                    <svg className="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-tx-primary">Métodos de Pagamento</h3>
                    <p className="text-xs text-tx-muted">Selecione os métodos aceitos</p>
                  </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <label className="flex items-center gap-3 p-3 rounded-lg bg-surface-card cursor-not-allowed border-2 border-transparent transition-all opacity-50">
                    <input
                      {...register('payment_methods.credit_card')}
                      type="checkbox"
                      defaultChecked={false}
                      disabled
                      className="h-5 w-5 rounded border-border-DEFAULT text-purple-500 focus:ring-purple-500"
                    />
                    <div className="flex items-center gap-2">
                      <svg className="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                      </svg>
                      <span className="text-sm font-medium text-tx-primary">Cartão</span>
                      <span className="text-xs text-tx-muted">(em breve)</span>
                    </div>
                  </label>
                  <label className="flex items-center gap-3 p-3 rounded-lg bg-surface-card hover:bg-surface-hover cursor-pointer border-2 border-transparent hover:border-purple-500/30 transition-all">
                    <input
                      {...register('payment_methods.pix')}
                      type="checkbox"
                      defaultChecked
                      className="h-5 w-5 rounded border-border-DEFAULT text-purple-500 focus:ring-purple-500"
                    />
                    <div className="flex items-center gap-2">
                      <PixIcon className="h-5 w-5 text-purple-500" />
                      <span className="text-sm font-medium text-tx-primary">PIX</span>
                    </div>
                  </label>
                  <label className="flex items-center gap-3 p-3 rounded-lg bg-surface-card hover:bg-surface-hover cursor-pointer border-2 border-transparent hover:border-purple-500/30 transition-all opacity-50">
                    <input
                      {...register('payment_methods.boleto')}
                      type="checkbox"
                      defaultChecked={false}
                      disabled
                      className="h-5 w-5 rounded border-border-DEFAULT text-purple-500 focus:ring-purple-500"
                    />
                    <div className="flex items-center gap-2">
                      <svg className="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                      </svg>
                      <span className="text-sm font-medium text-tx-primary">Boleto</span>
                    </div>
                  </label>
                </div>
              </div>

              <div className="space-y-4">
                {/* Order Bump */}
                <div className={`p-4 rounded-lg border-2 transition-all ${orderBumpEnabled ? 'border-accent-primary bg-accent-subtle' : 'border-border-DEFAULT'}`}>
                  <div className="flex items-center gap-4">
                    <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                      <svg className="h-5 w-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                      </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className="text-sm font-semibold text-tx-primary">Order Bump</h3>
                      <p className="text-xs text-tx-muted">Oferta adicional no checkout</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input {...register('order_bump_enabled')} type="checkbox" className="sr-only peer" />
                      <div className="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent-primary rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-accent-primary"></div>
                    </label>
                  </div>
                  {orderBumpEnabled && (
                    <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-tx-secondary mb-1">Produto</label>
                        <select {...register('order_bump_product_id')} className="input text-sm">
                          <option value="">Selecione...</option>
                          {availableProducts.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                        </select>
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-tx-secondary mb-1">Desconto (%)</label>
                        <input {...register('order_bump_discount_percent')} type="number" min="0" max="90" className="input text-sm" placeholder="10" />
                      </div>
                    </div>
                  )}
                </div>

                {/* Upsell */}
                <div className={`p-4 rounded-lg border-2 transition-all ${upsellEnabled ? 'border-accent-primary bg-accent-subtle' : 'border-border-DEFAULT'}`}>
                  <div className="flex items-center gap-4">
                    <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                      <svg className="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                      </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className="text-sm font-semibold text-tx-primary">Upsell</h3>
                      <p className="text-xs text-tx-muted">Oferta pós-compra</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input {...register('upsell_enabled')} type="checkbox" className="sr-only peer" />
                      <div className="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent-primary rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-accent-primary"></div>
                    </label>
                  </div>
                  {upsellEnabled && (
                    <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <div>
                        <label className="block text-xs font-medium text-tx-secondary mb-1">Produto</label>
                        <select {...register('upsell_product_id')} className="input text-sm">
                          <option value="">Selecione...</option>
                          {availableProducts.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                        </select>
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-tx-secondary mb-1">Desconto (%)</label>
                        <input {...register('upsell_discount_percent')} type="number" min="0" max="90" className="input text-sm" placeholder="15" />
                      </div>
                    </div>
                  )}
                </div>

                {/* Área de Membros */}
                <div className={`p-4 rounded-lg border-2 transition-all ${memberAreaEnabled ? 'border-accent-primary bg-accent-subtle' : 'border-border-DEFAULT'}`}>
                  <div className="flex items-center gap-4">
                    <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                      <svg className="h-5 w-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                      </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                      <h3 className="text-sm font-semibold text-tx-primary">Área de Membros</h3>
                      <p className="text-xs text-tx-muted">Acesso automático ao curso</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input {...register('member_area_enabled')} type="checkbox" className="sr-only peer" />
                      <div className="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-accent-primary rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-accent-primary"></div>
                    </label>
                  </div>
                  {memberAreaEnabled && (
                    <div className="mt-4">
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Curso / Conteúdo</label>
                      <select {...register('course_id')} className="input text-sm">
                        <option value="" className="bg-white dark:bg-gray-800 text-black dark:text-white">Selecione um curso...</option>
                        {availableCourses.map((course) => (
                          <option key={course.id} value={course.id} className="bg-white dark:bg-gray-800 text-black dark:text-white">{course.name}</option>
                        ))}
                      </select>
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* Informações de Envio - Produtos Físicos */}
            {isPhysicalProduct && (
              <>
              <div className="card p-4 sm:p-6">
                <div className="flex items-center gap-2 mb-4">
                  <svg className="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                  </svg>
                  <h2 className="text-lg font-semibold text-tx-primary">
                    Informações de Envio
                  </h2>
                </div>
                <p className="text-sm text-tx-muted mb-4">
                  Configure as informações de envio para este produto físico
                </p>

                <div className="space-y-4">
                  {/* Dimensões do Produto */}
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary mb-2">
                      Dimensões do Pacote (cm)
                    </label>
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                      <div>
                        <input
                          {...register('shipping_length')}
                          type="number"
                          step="0.1"
                          className="input text-sm"
                          placeholder="Comp."
                        />
                        <p className="text-xs text-tx-muted mt-1">Compr.</p>
                      </div>
                      <div>
                        <input
                          {...register('shipping_width')}
                          type="number"
                          step="0.1"
                          className="input text-sm"
                          placeholder="Larg."
                        />
                        <p className="text-xs text-tx-muted mt-1">Largura</p>
                      </div>
                      <div>
                        <input
                          {...register('shipping_height')}
                          type="number"
                          step="0.1"
                          className="input text-sm"
                          placeholder="Alt."
                        />
                        <p className="text-xs text-tx-muted mt-1">Altura</p>
                      </div>
                      <div>
                        <input
                          {...register('shipping_weight')}
                          type="number"
                          step="0.001"
                          className="input text-sm"
                          placeholder="Peso"
                        />
                        <p className="text-xs text-tx-muted mt-1">Peso (kg)</p>
                      </div>
                    </div>
                  </div>

                  {/* Frete Grátis */}
                  <div className="flex items-center justify-between p-3 rounded-lg bg-status-success-subtle border border-green-200">
                    <div>
                      <p className="text-sm font-medium text-tx-primary">
                        Frete Grátis
                      </p>
                      <p className="text-xs text-tx-muted">
                        Oferecer frete grátis para este produto
                      </p>
                    </div>
                    <input
                      {...register('free_shipping')}
                      type="checkbox"
                      className="h-5 w-5 rounded border-border-DEFAULT text-status-success focus:ring-green-500"
                    />
                  </div>

                  {/* Preço do Frete */}
                  {!freeShipping && (
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">
                        Valor do Frete (R$)
                      </label>
                      <input
                        {...register('shipping_price')}
                        type="number"
                        step="0.01"
                        className="input"
                        placeholder="15.00"
                      />
                      <p className="mt-1 text-xs text-tx-muted">
                        Valor fixo de frete. Deixe em branco para calcular automaticamente.
                      </p>
                    </div>
                  )}

                  {/* Aviso sobre endereço no checkout */}
                  <div className="p-3 rounded-lg bg-accent-subtle border border-blue-200">
                    <p className="text-xs text-accent-primary flex items-center gap-2">
                      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      O endereço de entrega será solicitado automaticamente no checkout
                    </p>
                  </div>
                </div>
              </div>

              {/* Controle de Estoque - Produtos Físicos */}
              <div className="card p-4 sm:p-6">
                <div className="flex items-center gap-2 mb-4">
                  <svg className="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                  </svg>
                  <h2 className="text-lg font-semibold text-tx-primary">
                    Controle de Estoque
                  </h2>
                  <input
                    {...register('stock_enabled')}
                    type="checkbox"
                    className="ml-auto h-5 w-5 rounded border-border-DEFAULT text-purple-500 focus:ring-purple-500"
                  />
                </div>
                <p className="text-sm text-tx-muted mb-4">
                  Ative para controlar a quantidade disponível deste produto
                </p>

                {watch('stock_enabled') && (
                  <div className="space-y-4 pt-4 border-t border-border-DEFAULT">
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">
                        Quantidade em Estoque
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
                      <p className="mt-1 text-xs text-tx-muted">
                        Quantidade total disponível para venda
                      </p>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">
                        Alerta de Estoque Baixo
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
                      <p className="mt-1 text-xs text-tx-muted">
                        Você será notificado quando o estoque atingir este valor
                      </p>
                    </div>

                    {/* Preview do estoque */}
                    {watch('stock_quantity') !== undefined && (
                      <div className={`p-3 rounded-lg border-2 ${
                        (watch('stock_quantity') || 0) <= (watch('stock_alert_threshold') || 0)
                          ? 'bg-amber-50 border-amber-200'
                          : 'bg-green-50 border-green-200'
                      }`}>
                        <div className="flex items-center gap-2">
                          {(watch('stock_quantity') || 0) <= (watch('stock_alert_threshold') || 0) ? (
                            <>
                              <svg className="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                              </svg>
                              <p className="text-sm font-medium text-amber-700">
                                Estoque baixo: {watch('stock_quantity')} unidades
                              </p>
                            </>
                          ) : (
                            <>
                              <svg className="h-5 w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                              </svg>
                              <p className="text-sm font-medium text-green-700">
                                Estoque disponível: {watch('stock_quantity')} unidades
                              </p>
                            </>
                          )}
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
              </>
            )}
          </div>

          {/* Coluna Lateral - Configurações */}
          <div className="space-y-4 sm:space-y-6">
            {/* Desconto */}
            <div className="card p-4 sm:p-6">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-lg font-semibold text-tx-primary">
                  Desconto
                </h2>
                <input
                  {...register('discount_enabled')}
                  type="checkbox"
                  className="h-5 w-5 rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary/30"
                />
              </div>
              <p className="text-sm text-tx-muted mb-4">
                Aplicar desconto promocional neste produto
              </p>

              {watch('discount_enabled') && (
                <div className="space-y-4 pt-4 border-t border-border-DEFAULT">
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
                    {watch('discount_value') && watch('price') && (
                      <div className="mt-2 p-2 rounded-lg bg-status-success-subtle">
                        <p className="text-sm font-medium text-status-success">
                          Preço final: {formatCurrency(
                            watch('discount_type') === 'percentage'
                              ? (watch('price') * 100) - ((watch('price') * 100) * (watch('discount_value') || 0) / 100)
                              : (watch('price') * 100) - ((watch('discount_value') || 0) * 100)
                          )}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>

            {/* Afiliados e Vitrine */}
            <div className="card p-4 sm:p-6">
              <div className="space-y-6">
                {/* Programa de Afiliados */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <div>
                      <h3 className="text-lg font-semibold text-tx-primary">
                        Programa de Afiliados
                      </h3>
                      <p className="text-sm text-tx-muted">
                        Permitir que afiliados promovam este produto
                      </p>
                    </div>
                    <input
                      {...register('affiliate_enabled')}
                      type="checkbox"
                      className="h-5 w-5 rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary"
                    />
                  </div>

                  {affiliateEnabled && (
                    <div className="mt-4 space-y-4 p-4 rounded-lg bg-accent-subtle border border-border-DEFAULT">
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Comissão para Afiliados (%)
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
                          <p className="mt-1 text-sm text-accent-primary">
                            Comissão por venda: {formatCurrency(Math.round((watch('price') * 100) * (watch('affiliate_commission_percent') || 0) / 100))}
                          </p>
                        )}
                        {errors.affiliate_commission_percent && (
                          <p className="mt-1 text-sm text-status-error">
                            {errors.affiliate_commission_percent.message}
                          </p>
                        )}
                      </div>

                      {/* Categoria obrigatória para afiliados */}
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
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
                          className={`input ${errors.category ? 'border-red-500' : ''}`}
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
                          <p className="mt-1 text-sm text-status-error">
                            {errors.category.message}
                          </p>
                        )}
                        <p className="mt-1 text-xs text-tx-muted">
                          A categoria é necessária para seu produto aparecer na vitrine de afiliados
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Info: produto aparece automaticamente na vitrine */}
                  <div className="mt-4 p-4 rounded-lg bg-status-success-subtle border border-green-200">
                    <p className="text-sm text-status-success flex items-center gap-2">
                      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      Este produto aparecerá automaticamente na vitrine pública (/vitrine) para afiliados promoverem
                    </p>
                  </div>
                </div>
              </div>
            </div>

            {/* Página de Obrigado */}
            <div className="card p-4 sm:p-6">
              <h2 className="text-lg font-semibold text-tx-primary mb-2">
                Página de Obrigado <span className="text-xs font-normal text-tx-muted">(Opcional)</span>
              </h2>
              <p className="text-sm text-tx-muted mb-4">
                Configure o que acontece após a compra ser aprovada
              </p>

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
                    <p className="mt-1 text-xs text-tx-muted">
                      {thankYouPageType === 'redirect' 
                        ? 'O cliente será redirecionado para esta URL após a compra' 
                        : 'URL da sua página de obrigado personalizada'
                      }
                    </p>
                  </div>
                )}
              </div>
            </div>

            {/* Botões de ação */}
            <div className="card p-4 sm:p-6">
              <div className="flex flex-col gap-3">
                <button
                  type="submit"
                  disabled={mutation.isPending || isUploading}
                  className="btn-primary w-full justify-center"
                >
                  {isUploading ? 'Enviando imagem...' : mutation.isPending ? 'Salvando...' : isEditing ? 'Salvar Alterações' : 'Criar Produto'}
                </button>
                <button
                  type="button"
                  onClick={onClose}
                  className="btn-secondary w-full justify-center"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}
