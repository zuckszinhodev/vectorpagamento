import { useState, useEffect } from 'react';
import { formatCurrency, api } from '@/lib/api';
import { domainsService, CustomDomain } from '@/services/api/domains.service';
import { useAuthStore } from '@/stores/authStore';
import { Spinner } from '@/components/ui/Spinner';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
 MagnifyingGlassIcon,
 Squares2X2Icon,
 ListBulletIcon,
 ArrowPathIcon,
 ShareIcon,
 HeartIcon,
 EyeIcon,
 EyeSlashIcon,
 ShoppingCartIcon,
 UserGroupIcon,
 CurrencyDollarIcon,
 TagIcon,
 ChevronDownIcon,
 FireIcon,
 ClockIcon,
 ShieldCheckIcon,
 CheckCircleIcon,
 XCircleIcon,
 TrashIcon,
 PencilSquareIcon,
 FunnelIcon,
 ChartBarIcon,
 GlobeAltIcon,
 BoltIcon,
 Cog6ToothIcon,
} from '@heroicons/react/24/outline';
import { HeartIcon as HeartSolidIcon } from '@heroicons/react/24/solid';
import toast from 'react-hot-toast';
import clsx from 'clsx';

interface VitrineProduct {
 id: string;
 name: string;
 description: string;
 price: number;
 currency: string;
 type: 'physical' | 'digital' | 'service';
 image_url?: string;
 seller: {
 id: string;
 name: string;
 avatar?: string;
 verified: boolean;
 };
 affiliate_commission_percent: number;
 category: string;
 is_hot: boolean;
 is_new: boolean;
 created_at: string;
}

interface ShowcaseProduct {
 id: string;
 user_id: string;
 user_name: string;
 user_email: string;
 product_name: string;
 description: string;
 price: number;
 commission_percentage: number;
 category: string;
 image_url?: string;
 status: 'pending' | 'approved' | 'rejected';
 visibility: 'public' | 'private';
 sales_count: number;
 created_at: string;
 updated_at?: string;
}

const categories = [
 'Todos',
 'Tecnologia',
 'Marketing',
 'Finanças',
 'Design',
 'Negócios',
 'Saúde',
 'Desenvolvimento Pessoal',
];

const sortOptions = [
 { value: 'popular', label: 'Mais populares' },
 { value: 'newest', label: 'Mais recentes' },
 { value: 'price_asc', label: 'Menor preço' },
 { value: 'price_desc', label: 'Maior preço' },
 { value: 'commission', label: 'Maior comissão' },
];

export default function VitrinePage() {
 const { user } = useAuthStore();
 const queryClient = useQueryClient();
 const isAdmin = user?.role === 'admin' || user?.role === 'owner';

 // Fetch verified domains to build checkout URLs
 const { data: domainsData } = useQuery({
  queryKey: ['domains'],
  queryFn: () => domainsService.list(),
 });
 const payBaseUrl = (() => {
  const verified = (domainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');
  const primary = verified.find((d: CustomDomain) => d.is_primary) || verified[0];
  return primary ? `https://${primary.domain}` : window.location.origin;
 })();
 
 const [activeTab, setActiveTab] = useState<'marketplace' | 'admin'>('marketplace');
 const [searchQuery, setSearchQuery] = useState('');
 const [selectedCategory, setSelectedCategory] = useState('Todos');
 const [sortBy, setSortBy] = useState('popular');
 const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
 const [favorites, setFavorites] = useState<string[]>([]);
 const [products, setProducts] = useState<VitrineProduct[]>([]);
 const [isLoading, setIsLoading] = useState(true);
 const [affiliatingProduct, setAffiliatingProduct] = useState<string | null>(null);
 const [refreshKey, setRefreshKey] = useState(0);
 const [adminStatusFilter, setAdminStatusFilter] = useState<'all' | 'pending' | 'approved' | 'rejected'>('all');
 const [adminSearch, setAdminSearch] = useState('');
 const [autoApprove, setAutoApprove] = useState<boolean>(() => {
 try {
 return localStorage.getItem('vitrine_auto_approve') === 'true';
 } catch { return false; }
 });

 // Load auto-approve setting from API
 useEffect(() => {
 if (isAdmin) {
 api.get('/admin/showcase/settings').then((res) => {
 if (res.data?.data?.auto_approve !== undefined) {
 setAutoApprove(res.data.data.auto_approve);
 localStorage.setItem('vitrine_auto_approve', String(res.data.data.auto_approve));
 }
 }).catch(() => { /* use localStorage fallback */ });
 }
 }, [isAdmin]);

 const [stats, setStats] = useState({
 total_products: 0,
 total_sellers: 0,
 avg_commission: 0,
 hot_products: 0,
 });

 // Admin: Query ALL products from ALL accounts
 const { data: adminProductsData, isLoading: isLoadingShowcase, refetch: refetchShowcase } = useQuery<{
 products: ShowcaseProduct[];
 stats: { total: number; active: number; showcase: number; pending: number; approved: number; rejected: number; affiliate: number };
 }>({
 queryKey: ['admin-all-products', adminStatusFilter, adminSearch],
 queryFn: async () => {
 try {
 const params: Record<string, string> = { per_page: '100' };
 if (adminStatusFilter !== 'all') {
 params.status = adminStatusFilter;
 }
 if (adminSearch) {
 params.search = adminSearch;
 }
 const response = await api.get('/admin/products', { params });
 return {
 products: response.data?.data || [],
 stats: response.data?.stats || { total: 0, active: 0, showcase: 0, pending: 0, approved: 0, rejected: 0, affiliate: 0 },
 };
 } catch {
 // Fallback to localStorage dev products
 let localProducts: ShowcaseProduct[] = [];
 try {
 const savedProducts = JSON.parse(localStorage.getItem('dev_products') || '[]');
 localProducts = savedProducts.map((p: any) => ({
 id: p.id,
 user_id: 'local',
 user_name: user?.name || 'Você',
 user_email: user?.email || '',
 product_name: p.name,
 description: p.description || '',
 price: p.price,
 commission_percentage: p.affiliate_commission_percent || 0,
 category: p.category || 'Outros',
 image_url: p.image_url,
 status: (p.is_active !== false ? 'approved' : 'pending') as 'approved' | 'pending',
 visibility: (p.visible_on_marketplace ? 'public' : 'private') as 'public' | 'private',
 sales_count: 0,
 created_at: p.created_at || new Date().toISOString(),
 }));
 } catch { /* ignore */ }
 return {
 products: localProducts,
 stats: { total: localProducts.length, active: localProducts.length, showcase: 0, pending: 0, approved: localProducts.length, rejected: 0, affiliate: 0 },
 };
 }
 },
 enabled: isAdmin && activeTab === 'admin',
 });

 const allManagedProducts = adminProductsData?.products || [];
 const filteredManagedProducts = allManagedProducts;

 // Admin stats from API
 const adminStats = {
 total: adminProductsData?.stats?.total || allManagedProducts.length,
 pending: adminProductsData?.stats?.pending || 0,
 approved: adminProductsData?.stats?.approved || 0,
 rejected: adminProductsData?.stats?.rejected || 0,
 };

 // Admin: Toggle auto-approve setting
 const toggleAutoApprove = async () => {
 const newValue = !autoApprove;
 setAutoApprove(newValue);
 localStorage.setItem('vitrine_auto_approve', String(newValue));
 try {
 await api.post('/admin/showcase/settings', { auto_approve: newValue });
 toast.success(newValue ? 'Aprovação automática ativada!' : 'Aprovação automática desativada');
 } catch {
 toast.success(newValue ? 'Aprovação automática ativada (local)' : 'Aprovação automática desativada (local)');
 }
 };

 // Admin: Approve ALL pending products
 const approveAllMutation = useMutation({
 mutationFn: async () => {
 const pendingProducts = allManagedProducts.filter(p => p.status === 'pending');
 await Promise.all(pendingProducts.map(p => api.post(`/admin/showcase/products/${p.id}/approve`)));
 },
 onSuccess: () => {
 toast.success('Todos os produtos pendentes foram aprovados!');
 queryClient.invalidateQueries({ queryKey: ['admin-all-products'] });
 },
 onError: () => {
 toast.error('Erro ao aprovar produtos');
 },
 });

 // Admin: Approve product mutation
 const approveProductMutation = useMutation({
 mutationFn: async (productId: string) => {
 return api.post(`/admin/showcase/products/${productId}/approve`);
 },
 onSuccess: () => {
 toast.success('Produto aprovado!');
 queryClient.invalidateQueries({ queryKey: ['admin-all-products'] });
 },
 onError: () => {
 toast.error('Erro ao aprovar produto');
 },
 });

 // Admin: Reject product mutation
 const rejectProductMutation = useMutation({
 mutationFn: async ({ productId, reason }: { productId: string; reason?: string }) => {
 return api.post(`/admin/showcase/products/${productId}/reject`, { reason });
 },
 onSuccess: () => {
 toast.success('Produto rejeitado');
 queryClient.invalidateQueries({ queryKey: ['admin-all-products'] });
 },
 onError: () => {
 toast.error('Erro ao rejeitar produto');
 },
 });

 // Admin: Delete product mutation
 const deleteProductMutation = useMutation({
 mutationFn: async (productId: string) => {
 return api.delete(`/admin/showcase/products/${productId}`);
 },
 onSuccess: () => {
 toast.success('Produto removido!');
 queryClient.invalidateQueries({ queryKey: ['admin-all-products'] });
 },
 onError: () => {
 toast.error('Erro ao remover produto');
 },
 });

 // Listen for localStorage product updates
 useEffect(() => {
 const handleProductsUpdate = () => {
 setRefreshKey(prev => prev + 1);
 };
 window.addEventListener('dev_products_updated', handleProductsUpdate);
 return () => window.removeEventListener('dev_products_updated', handleProductsUpdate);
 }, []);

 // Fetch marketplace stats (including localStorage products)
 useEffect(() => {
 const fetchStats = async () => {
 // Calcular stats dos produtos do localStorage
 let localStats = {
 total_products: 0,
 total_sellers: 0,
 avg_commission: 0,
 hot_products: 0,
 };

 try {
 const savedProducts = JSON.parse(localStorage.getItem('dev_products') || '[]');
 const affiliateProducts = savedProducts.filter((p: any) => p.affiliate_enabled && p.status === 'active');
 
 if (affiliateProducts.length > 0) {
 const totalCommission = affiliateProducts.reduce((sum: number, p: any) => sum + (p.affiliate_commission_percent || 0), 0);
 localStats = {
 total_products: affiliateProducts.length,
 total_sellers: 1, // Você como vendedor
 avg_commission: Math.round(totalCommission / affiliateProducts.length),
 hot_products: affiliateProducts.filter((p: any) => p.is_hot).length,
 };
 }
 } catch {
 // Ignorar erro de localStorage
 }

 // Tentar buscar stats da API
 try {
 const response = await api.get('/marketplace/stats');
 if (response.data.success) {
 const apiStats = response.data.data;
 // Combinar stats
 setStats({
 total_products: apiStats.total_products + localStats.total_products,
 total_sellers: apiStats.total_sellers + localStats.total_sellers,
 avg_commission: localStats.total_products > 0 
 ? Math.round((apiStats.avg_commission + localStats.avg_commission) / 2)
 : apiStats.avg_commission,
 hot_products: apiStats.hot_products + localStats.hot_products,
 });
 return;
 }
 } catch (error) {
   // Error handled silently
 }

 // Se API falhar, usar apenas stats locais
 setStats(localStats);
 };
 fetchStats();
 }, [refreshKey]);

 // Fetch products from marketplace API and localStorage
 useEffect(() => {
 const fetchProducts = async () => {
 setIsLoading(true);
 try {
 const params: Record<string, string> = {
 sort: sortBy,
 per_page: '50',
 };
 
 if (searchQuery) {
 params.search = searchQuery;
 }
 
 if (selectedCategory && selectedCategory !== 'Todos') {
 params.category = selectedCategory;
 }

 let allProducts: VitrineProduct[] = [];

 // Buscar produtos do localStorage com afiliado ativado
 try {
 const savedProducts = JSON.parse(localStorage.getItem('dev_products') || '[]');
 const localProducts = savedProducts
 .filter((p: any) => p.affiliate_enabled && p.status === 'active')
 .filter((p: any) => !searchQuery || p.name.toLowerCase().includes(searchQuery.toLowerCase()))
 .filter((p: any) => selectedCategory === 'Todos' || p.category === selectedCategory)
 .map((p: any) => ({
 id: p.id,
 name: p.name,
 description: p.description || '',
 price: p.price,
 currency: p.currency || 'BRL',
 type: p.type || 'digital',
 image_url: p.image_url,
 seller: {
 id: 'local_seller',
 name: 'Você',
 verified: true,
 },
 affiliate_commission_percent: p.affiliate_commission_percent || 10,
 category: p.category || 'Outros',
 is_hot: false,
 is_new: true,
 created_at: p.created_at,
 }));
 allProducts = [...localProducts];
 } catch {
 // Ignorar erro de localStorage
 }

 // Tentar buscar produtos da API
 try {
 // include_own=true para mostrar produtos do próprio usuário na vitrine
 const response = await api.get('/marketplace/products', { params: { ...params, include_own: 'true' } });
 
 if (response.data.success) {
 const marketplaceProducts = response.data.data.map((p: any) => ({
 id: p.id,
 name: p.name,
 description: p.description || '',
 price: p.price,
 currency: p.currency || 'BRL',
 type: p.type || 'digital',
 image_url: p.image_url,
 seller: {
 id: p.seller?.id || '',
 name: p.seller?.name || 'Vendedor',
 verified: p.seller?.verified || false,
 },
 affiliate_commission_percent: p.affiliate_commission_percent || 0,
 category: p.category || 'Outros',
 is_hot: false,
 is_new: new Date(p.created_at) > new Date(Date.now() - 7 * 24 * 60 * 60 * 1000),
 created_at: p.created_at,
 }));
 
 // Combinar sem duplicatas (localStorage tem prioridade)
 const localIds = new Set(allProducts.map(p => p.id));
 const uniqueApiProducts = marketplaceProducts.filter((p: VitrineProduct) => !localIds.has(p.id));
 allProducts = [...allProducts, ...uniqueApiProducts];
 }
 } catch (error: any) {
 // API pode falhar, mas ainda temos produtos do localStorage
 }

 setProducts(allProducts);
 } catch (error: any) {
 // Don't show error toast for 401 (not logged in) - marketplace is public
 if (error.response?.status !== 401) {
 toast.error('Erro ao carregar produtos');
 }
 } finally {
 setIsLoading(false);
 }
 };

 // Debounce search
 const timer = setTimeout(fetchProducts, searchQuery ? 300 : 0);
 return () => clearTimeout(timer);
 }, [sortBy, selectedCategory, searchQuery, refreshKey]);

 const filteredProducts = products;

 const toggleFavorite = (productId: string) => {
 setFavorites((prev) =>
 prev.includes(productId)
 ? prev.filter((id) => id !== productId)
 : [...prev, productId]
 );
 toast.success(
 favorites.includes(productId) ? 'Removido dos favoritos' : 'Adicionado aos favoritos'
 );
 };

 const copyAffiliateLink = (productId: string) => {
 const link = `${payBaseUrl}/pay/${productId}?ref=SEU_ID`;
 navigator.clipboard.writeText(link);
 toast.success('Link de afiliado copiado!');
 };

 const promoteProduct = async (productId: string) => {
 setAffiliatingProduct(productId);
 try {
 const response = await api.post(`/marketplace/products/${productId}/affiliate`);
 if (response.data.success) {
 const { code, affiliate_url } = response.data.data;
 toast.success(`Afiliação realizada! Seu código: ${code}`);
 navigator.clipboard.writeText(affiliate_url);
 toast.success('Link de afiliado copiado para a área de transferência!');
 }
 } catch (error: any) {
 const errorMessage = error.response?.data?.error?.message || 'Erro ao se afiliar';
 if (error.response?.status === 401) {
 toast.error('Faça login para se afiliar a produtos');
 } else {
 toast.error(errorMessage);
 }
 } finally {
 setAffiliatingProduct(null);
 }
 };

 return (
 <div className="space-y-4 sm:space-y-6">

 {/* Stats - Only show in marketplace tab */}
 {activeTab === 'marketplace' && (
 <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6">
 <div className="rounded-2xl bg-surface-card p-4 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex items-center gap-2 text-sm mb-1">
 <ShoppingCartIcon className="h-4 w-4 text-status-success" />
 <span className="text-status-success">Produtos</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{stats.total_products}</p>
 </div>
 <div className="rounded-2xl bg-surface-card p-4 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex items-center gap-2 text-sm mb-1">
 <UserGroupIcon className="h-4 w-4 text-accent-primary" />
 <span className="text-accent-primary">Produtores</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{stats.total_sellers}</p>
 </div>
 <div className="rounded-2xl bg-surface-card p-4 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex items-center gap-2 text-sm mb-1">
 <CurrencyDollarIcon className="h-4 w-4 text-blue-500" />
 <span className="text-blue-500">Comissão Média</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{stats.avg_commission}%</p>
 </div>
 <div className="rounded-2xl bg-surface-card p-4 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex items-center gap-2 text-sm mb-1">
 <FireIcon className="h-4 w-4 text-status-error animate-pulse" />
 <span className="text-status-error">Em Alta</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{stats.hot_products}</p>
 </div>
 </div>
 )}

 {/* Admin Management Section */}
 {isAdmin && activeTab === 'admin' && (
 <div className="space-y-4 sm:space-y-6">
 {/* Settings Card - Auto Approve Toggle */}
 <div className="rounded-2xl bg-surface-card p-4 sm:p-5 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
 <div className="flex items-center gap-3">
 <div className="p-2.5 rounded-xl bg-accent-subtle">
 <Cog6ToothIcon className="h-6 w-6 text-accent-primary" />
 </div>
 <div>
 <h2 className="text-lg font-semibold text-tx-primary">Gerenciamento da Vitrine</h2>
 <p className="text-sm text-tx-muted">Gerencie produtos marcados para exibição na vitrine</p>
 </div>
 </div>
 <div className="flex items-center gap-4">
 {adminStats.pending > 0 && (
 <button
 onClick={() => {
 if (confirm(`Aprovar todos os ${adminStats.pending} produtos pendentes?`)) {
 approveAllMutation.mutate();
 }
 }}
 disabled={approveAllMutation.isPending}
 className="flex items-center gap-2 px-4 py-2 rounded-lg bg-status-success-subtle text-status-success font-medium text-sm hover:bg-green-200 transition-colors disabled:opacity-50"
 >
 <CheckCircleIcon className="h-4 w-4" />
 {approveAllMutation.isPending ? 'Aprovando...' : `Aprovar Todos (${adminStats.pending})`}
 </button>
 )}
 <div className="flex items-center gap-3">
 <div className="flex items-center gap-2">
 <BoltIcon className={clsx('h-4 w-4', autoApprove ? 'text-status-success' : 'text-tx-muted')} />
 <span className="text-sm font-medium text-tx-secondary">Aprovação automática</span>
 </div>
 <button
 onClick={toggleAutoApprove}
 className={clsx(
 'relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-accent-primary/20',
 autoApprove ? 'bg-status-success' : 'bg-surface-hover'
 )}
 >
 <span
 className={clsx(
 'inline-block h-4 w-4 transform rounded-full bg-white transition-transform duration-200 shadow-sm',
 autoApprove ? 'translate-x-6' : 'translate-x-1'
 )}
 />
 </button>
 </div>
 </div>
 </div>
 {autoApprove && (
 <div className="mt-3 flex items-center gap-2 px-3 py-2 rounded-lg bg-status-success-subtle/50 border border-status-success/20">
 <BoltIcon className="h-4 w-4 text-status-success" />
 <p className="text-sm text-status-success">
 Novos produtos serão aprovados automaticamente na vitrine ao ativar o programa de afiliados.
 </p>
 </div>
 )}
 </div>

 {/* Admin Stats Cards */}
 <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
 <button
 onClick={() => setAdminStatusFilter('all')}
 className={clsx(
 'rounded-2xl p-4 shadow-slate-200/50 border transition-all text-left',
 adminStatusFilter === 'all'
 ? 'bg-accent-subtle border-accent-primary/50 ring-2 ring-accent-primary/20'
 : 'bg-surface-card border-border-DEFAULT/50/50 hover:border-accent-primary/30'
 )}
 >
 <div className="flex items-center gap-2 text-sm mb-1">
 <ChartBarIcon className="h-4 w-4 text-accent-primary" />
 <span className="text-accent-primary">Total</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{adminStats.total}</p>
 </button>
 <button
 onClick={() => setAdminStatusFilter('pending')}
 className={clsx(
 'rounded-2xl p-4 shadow-slate-200/50 border transition-all text-left',
 adminStatusFilter === 'pending'
 ? 'bg-yellow-500/10 border-yellow-500/50 ring-2 ring-yellow-500/20'
 : 'bg-surface-card border-border-DEFAULT/50/50 hover:border-yellow-500/30'
 )}
 >
 <div className="flex items-center gap-2 text-sm mb-1">
 <ClockIcon className="h-4 w-4 text-yellow-500" />
 <span className="text-yellow-500">Pendentes</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{adminStats.pending}</p>
 </button>
 <button
 onClick={() => setAdminStatusFilter('approved')}
 className={clsx(
 'rounded-2xl p-4 shadow-slate-200/50 border transition-all text-left',
 adminStatusFilter === 'approved'
 ? 'bg-green-500/10 border-green-500/50 ring-2 ring-green-500/20'
 : 'bg-surface-card border-border-DEFAULT/50/50 hover:border-green-500/30'
 )}
 >
 <div className="flex items-center gap-2 text-sm mb-1">
 <CheckCircleIcon className="h-4 w-4 text-status-success" />
 <span className="text-status-success">Aprovados</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{adminStats.approved}</p>
 </button>
 <button
 onClick={() => setAdminStatusFilter('rejected')}
 className={clsx(
 'rounded-2xl p-4 shadow-slate-200/50 border transition-all text-left',
 adminStatusFilter === 'rejected'
 ? 'bg-red-500/10 border-red-500/50 ring-2 ring-red-500/20'
 : 'bg-surface-card border-border-DEFAULT/50/50 hover:border-red-500/30'
 )}
 >
 <div className="flex items-center gap-2 text-sm mb-1">
 <XCircleIcon className="h-4 w-4 text-status-error" />
 <span className="text-status-error">Rejeitados</span>
 </div>
 <p className="text-2xl font-bold text-tx-primary">{adminStats.rejected}</p>
 </button>
 </div>

 {/* Search and Actions Bar */}
 <div className="rounded-2xl bg-surface-card p-4 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex flex-col sm:flex-row gap-3 items-center">
 <div className="relative flex-1 w-full">
 <MagnifyingGlassIcon className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar por nome, vendedor ou email..."
 value={adminSearch}
 onChange={(e) => setAdminSearch(e.target.value)}
 className="w-full rounded-card border border-border-DEFAULT bg-surface-page py-2.5 pl-12 pr-4 text-tx-primary placeholder-tx-muted focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20"
 />
 </div>
 <div className="flex items-center gap-2">
 <button
 onClick={() => {
 refetchShowcase();
 }}
 className="flex items-center gap-2 px-4 py-2.5 rounded-card border border-border-DEFAULT text-tx-secondary hover:bg-surface-hover transition-colors"
 >
 <ArrowPathIcon className={clsx('h-4 w-4', isLoadingShowcase && 'animate-spin')} />
 Atualizar
 </button>
 </div>
 </div>
 </div>

 {/* Products Table */}
 <div className="rounded-2xl bg-surface-card shadow-slate-200/50 border border-border-DEFAULT/50/50 overflow-hidden">
 <div className="border-b border-border-DEFAULT/50/50 px-4 sm:px-6 py-4 flex items-center justify-between">
 <div>
 <h2 className="text-lg font-semibold text-tx-primary">
 {adminStatusFilter === 'all' ? 'Todos os Produtos da Vitrine' :
 adminStatusFilter === 'pending' ? 'Produtos Pendentes de Aprovação' :
 adminStatusFilter === 'approved' ? 'Produtos Aprovados na Vitrine' :
 'Produtos Rejeitados da Vitrine'}
 </h2>
 <p className="text-sm text-tx-muted mt-1">
 {filteredManagedProducts.length} produto{filteredManagedProducts.length !== 1 ? 's' : ''} marcado{filteredManagedProducts.length !== 1 ? 's' : ''} na vitrine
 </p>
 </div>
 </div>

 <div className="overflow-x-auto">
 <table className="min-w-full divide-y divide-border-DEFAULT/50">
 <thead className="bg-surface-page">
 <tr>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Produto
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Vendedor
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Preço
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Comissão
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Vendas
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Visibilidade
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Status
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Ações
 </th>
 </tr>
 </thead>
 <tbody className="divide-y divide-border-DEFAULT/50">
 {isLoadingShowcase ? (
 <tr>
 <td colSpan={8} className="px-3 sm:px-6 py-12 text-center">
 <Spinner size="md" text="Carregando produtos..." />
 </td>
 </tr>
 ) : filteredManagedProducts.length > 0 ? (
 filteredManagedProducts.map((product) => (
 <tr key={product.id} className="hover:bg-surface-page/30 transition-colors">
 <td className="px-3 sm:px-6 py-4">
 <div className="flex items-center gap-3">
 <div className="h-12 w-12 rounded-lg overflow-hidden bg-surface-hover flex-shrink-0">
 {product.image_url ? (
 <img
 src={product.image_url}
 alt={product.product_name}
 className="h-full w-full object-cover"
 />
 ) : (
 <div className="h-full w-full flex items-center justify-center">
 <ShoppingCartIcon className="h-6 w-6 text-tx-muted" />
 </div>
 )}
 </div>
 <div className="min-w-0">
 <p className="font-medium text-tx-primary truncate max-w-[200px]">
 {product.product_name}
 </p>
 <p className="text-sm text-tx-muted line-clamp-1 max-w-[200px]">
 {product.description || 'Sem descrição'}
 </p>
 {product.category && (
 <span className="inline-flex items-center gap-1 mt-1 text-xs text-accent-primary">
 <TagIcon className="h-3 w-3" />
 {product.category}
 </span>
 )}
 </div>
 </div>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <div>
 <p className="text-sm font-medium text-tx-primary">
 {product.user_name}
 </p>
 <p className="text-xs text-tx-muted">
 {product.user_email}
 </p>
 </div>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <span className="text-sm font-semibold text-tx-primary">
 {formatCurrency(product.price)}
 </span>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <span className="inline-flex items-center rounded-full bg-accent-subtle px-2.5 py-0.5 text-xs font-medium text-accent-primary">
 {product.commission_percentage}%
 </span>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <span className="text-sm text-tx-primary font-medium">
 {product.sales_count}
 </span>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <span
 className={clsx(
 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
 product.visibility === 'public'
 ? 'bg-blue-500/10 text-blue-500'
 : 'bg-surface-hover text-tx-muted'
 )}
 >
 {product.visibility === 'public' ? (
 <><GlobeAltIcon className="h-3 w-3" /> Público</>
 ) : (
 <><EyeSlashIcon className="h-3 w-3" /> Privado</>
 )}
 </span>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <span
 className={clsx(
 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
 product.status === 'approved'
 ? 'bg-status-success-subtle text-status-success'
 : product.status === 'rejected'
 ? 'bg-status-error-subtle text-status-error'
 : 'bg-status-warning-subtle text-yellow-600'
 )}
 >
 {product.status === 'approved' ? (
 <><CheckCircleIcon className="h-3 w-3" /> Aprovado</>
 ) : product.status === 'rejected' ? (
 <><XCircleIcon className="h-3 w-3" /> Rejeitado</>
 ) : (
 <><ClockIcon className="h-3 w-3" /> Pendente</>
 )}
 </span>
 </td>
 <td className="px-3 sm:px-6 py-4">
 <div className="flex items-center gap-1.5">
 {product.status === 'pending' && (
 <>
 <button
 onClick={() => approveProductMutation.mutate(product.id)}
 disabled={approveProductMutation.isPending}
 className="rounded-lg bg-status-success-subtle p-2 text-status-success transition-colors hover:bg-green-200 disabled:opacity-50"
 title="Aprovar produto"
 >
 <CheckCircleIcon className="h-4 w-4" />
 </button>
 <button
 onClick={() => {
 const reason = prompt('Motivo da rejeição (opcional):');
 rejectProductMutation.mutate({ productId: product.id, reason: reason || undefined });
 }}
 disabled={rejectProductMutation.isPending}
 className="rounded-lg bg-status-error-subtle p-2 text-status-error transition-colors hover:bg-red-200 disabled:opacity-50"
 title="Rejeitar produto"
 >
 <XCircleIcon className="h-4 w-4" />
 </button>
 </>
 )}
 {product.status === 'rejected' && (
 <button
 onClick={() => approveProductMutation.mutate(product.id)}
 disabled={approveProductMutation.isPending}
 className="rounded-lg bg-status-success-subtle p-2 text-status-success transition-colors hover:bg-green-200 disabled:opacity-50"
 title="Reaprovar produto"
 >
 <CheckCircleIcon className="h-4 w-4" />
 </button>
 )}
 {product.status === 'approved' && (
 <button
 onClick={() => {
 const reason = prompt('Motivo da rejeição (opcional):');
 rejectProductMutation.mutate({ productId: product.id, reason: reason || undefined });
 }}
 disabled={rejectProductMutation.isPending}
 className="rounded-lg bg-yellow-500/10 p-2 text-yellow-600 transition-colors hover:bg-yellow-100 disabled:opacity-50"
 title="Suspender produto"
 >
 <EyeSlashIcon className="h-4 w-4" />
 </button>
 )}
 <button
 onClick={() => {
 const link = `${payBaseUrl}/pay/${product.id}`;
 navigator.clipboard.writeText(link);
 toast.success('Link do produto copiado!');
 }}
 className="rounded-lg bg-surface-hover p-2 text-tx-secondary transition-colors hover:bg-accent-subtle hover:text-accent-primary"
 title="Copiar link"
 >
 <ShareIcon className="h-4 w-4" />
 </button>
 <button
 onClick={() => {
 if (confirm(`Tem certeza que deseja remover "${product.product_name}"?`)) {
 deleteProductMutation.mutate(product.id);
 }
 }}
 disabled={deleteProductMutation.isPending}
 className="rounded-lg bg-surface-hover p-2 text-tx-secondary transition-colors hover:bg-status-error-subtle hover:text-status-error disabled:opacity-50"
 title="Remover produto"
 >
 <TrashIcon className="h-4 w-4" />
 </button>
 </div>
 </td>
 </tr>
 ))
 ) : (
 <tr>
 <td colSpan={8} className="px-3 sm:px-6 py-16 text-center">
 <ShoppingCartIcon className="mx-auto h-16 w-16 text-tx-muted mb-3" />
 <h3 className="text-lg font-semibold text-tx-primary mb-1">
 {adminSearch ? 'Nenhum produto encontrado' : 'Nenhum produto na vitrine'}
 </h3>
 <p className="text-sm text-tx-muted max-w-md mx-auto">
 {adminSearch 
 ? `Nenhum produto corresponde a "${adminSearch}". Tente outro termo.`
 : adminStatusFilter !== 'all'
 ? `Não há produtos com status "${adminStatusFilter === 'pending' ? 'pendente' : adminStatusFilter === 'approved' ? 'aprovado' : 'rejeitado'}".`
 : 'Os produtos aparecerão aqui quando os vendedores habilitarem o programa de afiliados.'}
 </p>
 {!adminSearch && adminStatusFilter !== 'all' && (
 <button
 onClick={() => setAdminStatusFilter('all')}
 className="mt-3 px-4 py-2 rounded-lg bg-accent-subtle text-accent-primary text-sm font-medium hover:bg-accent-subtle/80 transition-colors"
 >
 Ver todos os produtos
 </button>
 )}
 </td>
 </tr>
 )}
 </tbody>
 </table>
 </div>
 </div>
 </div>
 )}

 {/* Marketplace Section - Only show when marketplace tab is active */}
 {activeTab === 'marketplace' && (
 <>
 {/* Filters */}
 <div className="rounded-2xl bg-surface-card p-4 sm:p-6 shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <div className="flex flex-col lg:flex-row gap-4">
 {/* Search */}
 <div className="relative flex-1">
 <MagnifyingGlassIcon className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar produtos..."
 value={searchQuery}
 onChange={(e) => setSearchQuery(e.target.value)}
 className="w-full rounded-card border border-border-DEFAULT bg-surface-page py-3 pl-12 pr-4 text-tx-primary placeholder-tx-muted focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20"
 />
 </div>

 {/* Category Filter */}
 <div className="relative">
 <select
 value={selectedCategory}
 onChange={(e) => setSelectedCategory(e.target.value)}
 className="appearance-none rounded-card border border-border-DEFAULT bg-surface-page py-3 pl-4 pr-10 text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20"
 >
 {categories.map((category) => (
 <option key={category} value={category}>
 {category}
 </option>
 ))}
 </select>
 <ChevronDownIcon className="absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted pointer-events-none" />
 </div>

 {/* Sort */}
 <div className="relative">
 <select
 value={sortBy}
 onChange={(e) => setSortBy(e.target.value)}
 className="appearance-none rounded-card border border-border-DEFAULT bg-surface-page py-3 pl-4 pr-10 text-tx-primary focus:border-accent-primary focus:outline-none focus:ring-2 focus:ring-accent-primary/20"
 >
 {sortOptions.map((option) => (
 <option key={option.value} value={option.value}>
 {option.label}
 </option>
 ))}
 </select>
 <ChevronDownIcon className="absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted pointer-events-none" />
 </div>

 {/* View Toggle */}
 <div className="flex rounded-card border border-border-DEFAULT p-1">
 <button
 onClick={() => setViewMode('grid')}
 className={clsx(
 'p-2 rounded-element transition-colors',
 viewMode === 'grid'
 ? 'bg-accent-subtle0 text-white'
 : 'text-tx-muted hover:text-tx-secondary'
 )}
 >
 <Squares2X2Icon className="h-5 w-5" />
 </button>
 <button
 onClick={() => setViewMode('list')}
 className={clsx(
 'p-2 rounded-element transition-colors',
 viewMode === 'list'
 ? 'bg-accent-subtle0 text-white'
 : 'text-tx-muted hover:text-tx-secondary'
 )}
 >
 <ListBulletIcon className="h-5 w-5" />
 </button>
 </div>
 </div>

 {/* Category Pills */}
 <div className="flex flex-wrap gap-2 mt-4">
 {categories.map((category) => (
 <button
 key={category}
 onClick={() => setSelectedCategory(category)}
 className={clsx(
 'px-4 py-2 rounded-full text-sm font-medium transition-all',
 selectedCategory === category
 ? 'bg-accent-subtle0 text-white'
 : 'bg-surface-hover text-tx-secondary hover:bg-surface-hover'
 )}
 >
 {category}
 </button>
 ))}
 </div>
 </div>

 {/* Results Info */}
 <div className="flex items-center justify-between">
 <p className="text-sm text-tx-muted">
 Mostrando <span className="font-semibold text-tx-primary">{filteredProducts.length}</span> produtos
 </p>
 {isLoading && (
 <ArrowPathIcon className="h-5 w-5 animate-spin text-accent-primary" />
 )}
 </div>

 {/* Products Grid/List */}
 {isLoading ? (
 <div className="rounded-2xl bg-surface-card p-12 text-center shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <Spinner size="md" text="Carregando produtos..." />
 </div>
 ) : filteredProducts.length === 0 ? (
 <div className="rounded-2xl bg-surface-card p-12 text-center shadow-slate-200/50 border border-border-DEFAULT/50/50">
 <ShoppingCartIcon className="h-16 w-16 mx-auto text-tx-muted mb-4" />
 <h3 className="text-xl font-semibold text-tx-primary mb-2">Nenhum produto na vitrine</h3>
 <p className="text-tx-muted mb-4">
 Nenhum produtor disponibilizou produtos para afiliação ainda.
 </p>
 <p className="text-sm text-tx-muted">
 💡 Para adicionar seu produto aqui, vá em <span className="font-semibold text-accent-primary">Produtos &gt; Editar</span> e ative "Habilitar para afiliados" com uma comissão.
 </p>
 </div>
 ) : viewMode === 'grid' ? (
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
 {filteredProducts.map((product) => (
 <ProductCard
 key={product.id}
 product={product}
 isFavorite={favorites.includes(product.id)}
 onToggleFavorite={() => toggleFavorite(product.id)}
 onCopyLink={() => copyAffiliateLink(product.id)}
 onPromote={() => promoteProduct(product.id)}
 isAffiliating={affiliatingProduct === product.id}
 />
 ))}
 </div>
 ) : (
 <div className="space-y-4">
 {filteredProducts.map((product) => (
 <ProductListItem
 key={product.id}
 product={product}
 isFavorite={favorites.includes(product.id)}
 onToggleFavorite={() => toggleFavorite(product.id)}
 onCopyLink={() => copyAffiliateLink(product.id)}
 onPromote={() => promoteProduct(product.id)}
 isAffiliating={affiliatingProduct === product.id}
 />
 ))}
 </div>
 )}
 </>
 )}
 </div>
 );
}

function ProductCard({
 product,
 isFavorite,
 onToggleFavorite,
 onCopyLink,
 onPromote,
 isAffiliating,
}: {
 product: VitrineProduct;
 isFavorite: boolean;
 onToggleFavorite: () => void;
 onCopyLink: () => void;
 onPromote: () => void;
 isAffiliating?: boolean;
}) {
 return (
 <div className="group rounded-2xl bg-surface-card shadow-slate-200/50 border border-border-DEFAULT/50/50 overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-purple-500/10 hover:-translate-y-1 hover:border-accent-primary/30">
 {/* Image */}
 <div className="relative aspect-video overflow-hidden bg-surface-hover ">
 {product.image_url ? (
 <img
 src={product.image_url}
 alt={product.name}
 className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
 />
 ) : (
 <div className="w-full h-full flex items-center justify-center">
 <ShoppingCartIcon className="h-12 w-12 text-tx-muted" />
 </div>
 )}

 {/* Badges */}
 <div className="absolute top-3 left-3 flex gap-2">
 {product.is_hot && (
 <span className="px-2 py-1 rounded-full bg-orange-500 text-white text-xs font-semibold flex items-center gap-1">
 <FireIcon className="h-3 w-3" />
 Em Alta
 </span>
 )}
 {product.is_new && (
 <span className="px-2 py-1 rounded-full bg-status-success-subtle0 text-white text-xs font-semibold flex items-center gap-1">
 <ClockIcon className="h-3 w-3" />
 Novo
 </span>
 )}
 </div>

 {/* Favorite Button */}
 <button
 onClick={onToggleFavorite}
 className="absolute top-3 right-3 h-8 w-8 rounded-full bg-surface-card/90 backdrop-blur-sm flex items-center justify-center transition-transform hover:scale-110"
 >
 {isFavorite ? (
 <HeartSolidIcon className="h-5 w-5 text-status-error" />
 ) : (
 <HeartIcon className="h-5 w-5 text-tx-secondary" />
 )}
 </button>

 {/* Commission Badge */}
 <div className="absolute bottom-3 right-3">
 <span className="px-3 py-1.5 rounded-full bg-accent-subtle0 text-white text-sm font-bold">
 {product.affiliate_commission_percent}% comissão
 </span>
 </div>
 </div>

 {/* Content */}
 <div className="p-5">
 {/* Category */}
 <div className="flex items-center gap-2 mb-2">
 <TagIcon className="h-4 w-4 text-accent-primary" />
 <span className="text-xs font-medium text-accent-primary">{product.category}</span>
 </div>

 {/* Title */}
 <h3 className="font-semibold text-tx-primary mb-2 line-clamp-2 group-hover:text-accent-primary transition-colors">
 {product.name}
 </h3>

 {/* Description */}
 <p className="text-sm text-tx-muted mb-4 line-clamp-2">
 {product.description}
 </p>

 {/* Seller */}
 <div className="flex items-center gap-2 mb-4">
 <div className="h-6 w-6 rounded-full bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white text-xs font-bold">
 {product.seller.name.charAt(0)}
 </div>
 <span className="text-sm text-tx-secondary">{product.seller.name}</span>
 {product.seller.verified && (
 <span className="px-1.5 py-0.5 rounded bg-accent-subtle0/10 text-accent-primary text-xs font-medium">
 ✓ Verificado
 </span>
 )}
 </div>

 {/* Price */}
 <div className="flex items-center justify-between mb-4">
 <p className="text-2xl font-bold text-tx-primary">
 {formatCurrency(product.price)}
 </p>
 <p className="text-sm text-status-success font-semibold">
 Ganhe {formatCurrency(Math.round(product.price * product.affiliate_commission_percent / 100))}
 </p>
 </div>

 {/* Actions */}
 <div className="flex gap-2">
 <button
 onClick={onPromote}
 disabled={isAffiliating}
 className={clsx(
 "flex-1 py-3 rounded-xl bg-gradient-to-r from-purple-500 to-violet-600 text-white font-semibold transition-all",
 isAffiliating
 ? "opacity-50 cursor-not-allowed"
 : "hover:shadow-lg hover:shadow-purple-500/25 hover:scale-[1.02]"
 )}
 >
 {isAffiliating ? (
 <span className="flex items-center justify-center gap-2">
 <ArrowPathIcon className="h-4 w-4 animate-spin" />
 Afiliando...
 </span>
 ) : (
 'Promover'
 )}
 </button>
 <button
 onClick={onCopyLink}
 className="p-3 rounded-card border border-border-DEFAULT text-tx-secondary hover:bg-surface-hover transition-colors"
 >
 <ShareIcon className="h-5 w-5" />
 </button>
 </div>
 </div>
 </div>
 );
}

function ProductListItem({
 product,
 isFavorite,
 onToggleFavorite,
 onCopyLink,
 onPromote,
 isAffiliating,
}: {
 product: VitrineProduct;
 isFavorite: boolean;
 onToggleFavorite: () => void;
 onCopyLink: () => void;
 onPromote: () => void;
 isAffiliating?: boolean;
}) {
 return (
 <div className="group rounded-2xl bg-surface-card shadow-slate-200/50 border border-border-DEFAULT/50/50 overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-purple-500/10 hover:border-accent-primary/30">
 <div className="flex flex-col sm:flex-row">
 {/* Image */}
 <div className="relative w-full sm:w-64 h-48 sm:h-auto flex-shrink-0 overflow-hidden bg-surface-hover ">
 {product.image_url ? (
 <img
 src={product.image_url}
 alt={product.name}
 className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
 />
 ) : (
 <div className="w-full h-full flex items-center justify-center">
 <ShoppingCartIcon className="h-12 w-12 text-tx-muted" />
 </div>
 )}

 {/* Badges */}
 <div className="absolute top-3 left-3 flex gap-2">
 {product.is_hot && (
 <span className="px-2 py-1 rounded-full bg-orange-500 text-white text-xs font-semibold flex items-center gap-1">
 <FireIcon className="h-3 w-3" />
 Em Alta
 </span>
 )}
 {product.is_new && (
 <span className="px-2 py-1 rounded-full bg-status-success-subtle0 text-white text-xs font-semibold flex items-center gap-1">
 <ClockIcon className="h-3 w-3" />
 Novo
 </span>
 )}
 </div>
 </div>

 {/* Content */}
 <div className="flex-1 p-5">
 <div className="flex items-start justify-between">
 <div className="flex-1">
 {/* Category */}
 <div className="flex items-center gap-2 mb-2">
 <TagIcon className="h-4 w-4 text-accent-primary" />
 <span className="text-xs font-medium text-accent-primary">{product.category}</span>
 <span className="px-3 py-1 rounded-full bg-accent-subtle0/10 text-accent-primary text-xs font-bold">
 {product.affiliate_commission_percent}% comissão
 </span>
 </div>

 {/* Title */}
 <h3 className="font-semibold text-lg text-tx-primary mb-2 group-hover:text-accent-primary transition-colors">
 {product.name}
 </h3>

 {/* Description */}
 <p className="text-sm text-tx-muted mb-3 line-clamp-2">
 {product.description}
 </p>

 {/* Seller & Stats */}
 <div className="flex flex-wrap items-center gap-4 text-sm">
 <div className="flex items-center gap-2">
 <div className="h-6 w-6 rounded-full bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center text-white text-xs font-bold">
 {product.seller.name.charAt(0)}
 </div>
 <span className="text-tx-secondary">{product.seller.name}</span>
 {product.seller.verified && (
 <span className="text-blue-500 text-xs">✓</span>
 )}
 </div>
 </div>
 </div>

 {/* Price & Actions */}
 <div className="text-right ml-4">
 <p className="text-2xl font-bold text-tx-primary mb-1">
 {formatCurrency(product.price)}
 </p>
 <p className="text-sm text-status-success font-semibold mb-4">
 Ganhe {formatCurrency(Math.round(product.price * product.affiliate_commission_percent / 100))}
 </p>

 <div className="flex gap-2">
 <button
 onClick={onToggleFavorite}
 className="p-2 rounded-element border border-border-DEFAULT text-tx-muted hover:bg-surface-hover transition-colors"
 >
 {isFavorite ? (
 <HeartSolidIcon className="h-5 w-5 text-status-error" />
 ) : (
 <HeartIcon className="h-5 w-5" />
 )}
 </button>
 <button
 onClick={onCopyLink}
 className="p-2 rounded-element border border-border-DEFAULT text-tx-muted hover:bg-surface-hover transition-colors"
 >
 <ShareIcon className="h-5 w-5" />
 </button>
 <button
 onClick={onPromote}
 disabled={isAffiliating}
 className={clsx(
 "px-4 py-2 rounded-lg bg-gradient-to-r from-purple-500 to-violet-600 text-white font-semibold transition-all",
 isAffiliating
 ? "opacity-50 cursor-not-allowed"
 : "hover:shadow-lg hover:shadow-purple-500/25"
 )}
 >
 {isAffiliating ? 'Afiliando...' : 'Promover'}
 </button>
 </div>
 </div>
 </div>
 </div>
 </div>
 </div>
 );
}
