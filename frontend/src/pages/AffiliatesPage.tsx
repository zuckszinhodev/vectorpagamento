import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency } from '@/lib/api';
import { Dialog, Transition, Menu, Tab } from '@headlessui/react';
import { Fragment } from 'react';
import { useForm } from 'react-hook-form';
import {
 PlusIcon,
 MagnifyingGlassIcon,
 EllipsisVerticalIcon,
 ArrowPathIcon,
 XMarkIcon,
 PencilIcon,
 TrashIcon,
 ClipboardDocumentIcon,
 UserGroupIcon,
 CurrencyDollarIcon,
 ChartBarIcon,
 CheckCircleIcon,
 ClockIcon,
 BanknotesIcon,
 ArrowTrendingUpIcon,
 LinkIcon,
 ShareIcon,
 DocumentTextIcon,
 Cog6ToothIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface SplitRule {
 id: string;
 affiliate_id?: string;
 product_id: string;
 product_name: string;
 split_type: 'percentage' | 'fixed';
 split_value: number;
 priority: number;
}

interface Affiliate {
 id: string;
 name: string;
 email: string;
 phone?: string;
 code: string;
 status: 'active' | 'pending' | 'inactive';
 commission_percent: number;
 total_sales: number;
 total_commission: number;
 pending_commission: number;
 paid_commission: number;
 clicks_count: number;
 conversions_count: number;
 split_rules: SplitRule[];
 pix_key?: string;
 bank_info?: {
 bank_code: string;
 bank_name: string;
 agency: string;
 account: string;
 account_type: 'checking' | 'savings';
 holder_name: string;
 holder_document: string;
 };
 created_at: string;
}

interface AffiliateForm {
 email: string;
 product_id: string;
}

// Função para carregar produtos do localStorage ou mock
const getProducts = (): Array<{ id: string; name: string; price: number }> => {
 try {
 const devProducts = localStorage.getItem('dev_products');
 if (devProducts) {
 const products = JSON.parse(devProducts);
 // Filtrar apenas produtos visíveis na vitrine
 return products
 .filter((p: any) => p.visible_on_marketplace)
 .map((p: any) => ({
 id: p.id,
 name: p.name,
 price: p.price,
 }));
 }
 } catch (error) {
   // Error handled silently
 }
 return [
 { id: 'prod_1', name: 'Curso Completo de Marketing Digital', price: 49700 },
 { id: 'prod_2', name: 'E-book Finanças Pessoais', price: 4990 },
 { id: 'prod_3', name: 'Mentoria Premium 1:1', price: 199700 },
 { id: 'prod_4', name: 'Template Pack Premium', price: 9900 },
 { id: 'prod_5', name: 'Curso de Tráfego Pago', price: 29700 },
 ];
};

const mockProducts = getProducts();

// Split Configuration Modal
function SplitConfigModal({
 affiliate,
 isOpen,
 onClose,
}: {
 affiliate: Affiliate | null;
 isOpen: boolean;
 onClose: () => void;
}) {
 const [splitRules, setSplitRules] = useState<SplitRule[]>(affiliate?.split_rules || []);
 const queryClient = useQueryClient();

 // Update split rules when affiliate changes
 useEffect(() => {
   if (affiliate) {
     setSplitRules(affiliate.split_rules || []);
   }
 }, [affiliate]);

 if (!affiliate) return null;

 const saveMutation = useMutation({
 mutationFn: async (rules: SplitRule[]) => {
 return api.put(`/affiliates/${affiliate.id}/splits`, { split_rules: rules });
 },
 onSuccess: () => {
 toast.success('Configurações de split salvas!');
 queryClient.invalidateQueries({ queryKey: ['affiliates'] });
 onClose();
 },
 });

 const addSplitRule = () => {
 setSplitRules([
 ...splitRules,
 {
 id: `temp_${Date.now()}`,
 product_id: '',
 product_name: '',
 split_type: 'percentage',
 split_value: 10,
 priority: splitRules.length + 1,
 },
 ]);
 };

 const removeSplitRule = (id: string) => {
 setSplitRules(splitRules.filter(r => r.id !== id));
 };

 const updateSplitRule = (id: string, field: keyof SplitRule, value: any) => {
 setSplitRules(splitRules.map(r => {
 if (r.id === id) {
 if (field === 'product_id') {
 const product = mockProducts.find(p => p.id === value);
 return { ...r, [field]: value, product_name: product?.name || '' };
 }
 return { ...r, [field]: value };
 }
 return r;
 }));
 };

 return (
 <Transition.Root show={isOpen} as={Fragment}>
 <Dialog as="div" className="relative z-50" onClose={onClose}>
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0"
 enterTo="opacity-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100"
 leaveTo="opacity-0"
 >
 <div className="fixed inset-0 bg-black/50 transition-opacity" />
 </Transition.Child>

 <div className="fixed inset-0 z-10 overflow-y-auto">
 <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
 enterTo="opacity-100 translate-y-0 sm:scale-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100 translate-y-0 sm:scale-100"
 leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
 >
 <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-3xl">
 <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <Dialog.Title className="text-base sm:text-lg font-semibold text-tx-primary">
 Configurar Split de Pagamento - {affiliate.name}
 </Dialog.Title>
 <button
 type="button"
 onClick={onClose}
 className="text-tx-muted hover:text-tx-muted"
 >
 <XMarkIcon className="h-6 w-6" />
 </button>
 </div>

 <div className="px-4 sm:px-6 py-4">
 <div className="mb-4 rounded-lg bg-accent-subtle p-3 sm:p-4">
 <p className="text-sm text-accent-primary">
 Configure as regras de divisão de pagamento por produto. O split será aplicado automaticamente em cada venda.
 </p>
 </div>

 <div className="space-y-4">
 {splitRules.map((rule) => (
 <div key={rule.id} className="rounded-element border border-border-DEFAULT p-4">
 <div className="grid grid-cols-1 gap-3 sm:gap-4 md:grid-cols-3">
 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Produto
 </label>
 <select
 value={rule.product_id}
 onChange={(e) => updateSplitRule(rule.id, 'product_id', e.target.value)}
 className="input mt-1"
 >
 <option value="">Selecione...</option>
 {mockProducts.map((product) => (
 <option key={product.id} value={product.id}>
 {product.name}
 </option>
 ))}
 </select>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Tipo de Split
 </label>
 <select
 value={rule.split_type}
 onChange={(e) => updateSplitRule(rule.id, 'split_type', e.target.value)}
 className="input mt-1"
 >
 <option value="percentage">Porcentagem (%)</option>
 <option value="fixed">Valor Fixo (R$)</option>
 </select>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 {rule.split_type === 'percentage' ? 'Porcentagem' : 'Valor'}
 </label>
 <div className="mt-1 flex items-center gap-2">
 <input
 type="number"
 value={rule.split_value}
 onChange={(e) => updateSplitRule(rule.id, 'split_value', Number(e.target.value))}
 step={rule.split_type === 'percentage' ? '0.1' : '0.01'}
 className="input flex-1"
 />
 <button
 onClick={() => removeSplitRule(rule.id)}
 className="btn-secondary p-2"
 >
 <TrashIcon className="h-5 w-5 text-status-error" />
 </button>
 </div>
 </div>
 </div>
 </div>
 ))}

 <button
 onClick={addSplitRule}
 className="btn-secondary inline-flex items-center"
 >
 <PlusIcon className="mr-2 h-5 w-5" />
 Adicionar Regra de Split
 </button>
 </div>
 </div>

 <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
 <button onClick={onClose} className="btn-secondary">
 Cancelar
 </button>
 <button
 onClick={() => saveMutation.mutate(splitRules)}
 disabled={saveMutation.isPending}
 className="btn-primary"
 >
 {saveMutation.isPending ? 'Salvando...' : 'Salvar Configurações'}
 </button>
 </div>
 </Dialog.Panel>
 </Transition.Child>
 </div>
 </div>
 </Dialog>
 </Transition.Root>
 );
}

function AffiliateModal({
 affiliate,
 isOpen,
 onClose,
 products,
}: {
 affiliate: Affiliate | null;
 isOpen: boolean;
 onClose: () => void;
 products: Array<{ id: string; name: string; price: number }>;
}) {
 const queryClient = useQueryClient();
 const isEditing = !!affiliate;

 const {
 register,
 handleSubmit,
 reset,
 formState: { errors },
 } = useForm<AffiliateForm>({
 defaultValues: affiliate
 ? {
 email: affiliate.email,
 product_id: '',
 }
 : {
 email: '',
 product_id: '',
 },
 });

 const mutation = useMutation({
 mutationFn: async (data: AffiliateForm) => {
 if (isEditing) {
 return api.put(`/affiliates/${affiliate!.id}`, data);
 }
 // Use invite endpoint for new affiliates
 return api.post('/affiliates/invite', data);
 },
 onSuccess: (response) => {
 const message = response.data?.data?.message || (isEditing ? 'Afiliado atualizado!' : 'Afiliado convidado!');
 toast.success(message);
 queryClient.invalidateQueries({ queryKey: ['affiliates'] });
 reset();
 onClose();
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao salvar afiliado');
 },
 });

 const onSubmit = (data: AffiliateForm) => {
 mutation.mutate(data);
 };

 return (
 <Transition.Root show={isOpen} as={Fragment}>
 <Dialog as="div" className="relative z-50" onClose={onClose}>
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0"
 enterTo="opacity-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100"
 leaveTo="opacity-0"
 >
 <div className="fixed inset-0 bg-black/50 transition-opacity" />
 </Transition.Child>

 <div className="fixed inset-0 z-10 overflow-y-auto">
 <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
 enterTo="opacity-100 translate-y-0 sm:scale-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100 translate-y-0 sm:scale-100"
 leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
 >
 <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
 <form onSubmit={handleSubmit(onSubmit)}>
 <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <Dialog.Title className="text-base sm:text-lg font-semibold text-tx-primary">
 {isEditing ? 'Editar Afiliado' : 'Convidar Afiliado'}
 </Dialog.Title>
 <button
 type="button"
 onClick={onClose}
 className="text-tx-muted hover:text-tx-muted"
 >
 <XMarkIcon className="h-6 w-6" />
 </button>
 </div>

 <div className="space-y-4 sm:space-y-6 px-4 sm:px-6 py-4">
 <div className="rounded-lg bg-accent-subtle p-3 sm:p-4">
 <p className="text-sm text-accent-primary">
 O afiliado deve possuir uma conta na plataforma. Informe o e-mail cadastrado e selecione o produto.
 </p>
 </div>

 <div className="space-y-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 E-mail do Afiliado *
 </label>
 <input
 {...register('email', {
 required: 'E-mail é obrigatório',
 pattern: {
 value: /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i,
 message: 'E-mail inválido',
 },
 })}
 type="email"
 className="input mt-1"
 placeholder="email@exemplo.com"
 disabled={isEditing}
 />
 {errors.email && (
 <p className="mt-1 text-sm text-status-error">{errors.email.message}</p>
 )}
 <p className="mt-1 text-xs text-tx-muted">
 O usuário será buscado pelo e-mail cadastrado
 </p>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Produto para Afiliação *
 </label>
 <select
 {...register('product_id', { required: 'Selecione um produto' })}
 className="input mt-1"
 >
 <option value="">Selecione um produto...</option>
 {products.map((product) => (
 <option key={product.id} value={product.id}>
 {product.name} - {formatCurrency(product.price)}
 </option>
 ))}
 </select>
 {errors.product_id && (
 <p className="mt-1 text-sm text-status-error">{errors.product_id.message}</p>
 )}
 <p className="mt-1 text-xs text-tx-muted">
 A comissão será definida automaticamente conforme configurado no produto
 </p>
 </div>
 </div>
 </div>

 <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
 <button type="button" onClick={onClose} className="btn-secondary">
 Cancelar
 </button>
 <button
 type="submit"
 disabled={mutation.isPending}
 className="btn-primary"
 >
 {mutation.isPending
 ? 'Salvando...'
 : isEditing
 ? 'Salvar Alterações'
 : 'Enviar Convite'}
 </button>
 </div>
 </form>
 </Dialog.Panel>
 </Transition.Child>
 </div>
 </div>
 </Dialog>
 </Transition.Root>
 );
}

function StatsCard({
 title,
 value,
 icon: Icon,
 color,
}: {
 title: string;
 value: string;
 icon: React.ElementType;
 color: string;
}) {
 return (
 <div className="card p-4 sm:p-6">
 <div className="flex items-center justify-between">
 <div>
 <p className="text-sm text-tx-secondary">{title}</p>
 <p className="mt-1 text-xl sm:text-2xl font-bold text-tx-primary">{value}</p>
 </div>
 <div className={`rounded-xl p-3 ${color}`}>
 <Icon className="h-6 w-6 text-white" />
 </div>
 </div>
 </div>
 );
}

// Dados mock para teste
const mockAffiliates: Affiliate[] = [
 {
 id: 'aff_1',
 name: 'Carlos Mendes',
 email: 'carlos@email.com',
 phone: '(11) 98765-4321',
 code: 'CARLOS10',
 status: 'active',
 commission_percent: 40,
 total_sales: 4589700,
 total_commission: 1835880,
 pending_commission: 245000,
 paid_commission: 1590880,
 clicks_count: 12450,
 conversions_count: 347,
 pix_key: 'carlos@email.com',
 split_rules: [
 { id: '1', product_id: 'prod_1', product_name: 'Curso Completo de Marketing Digital', split_type: 'percentage', split_value: 40, priority: 1 },
 { id: '2', product_id: 'prod_5', product_name: 'Curso de Tráfego Pago', split_type: 'percentage', split_value: 35, priority: 2 },
 ],
 created_at: '2025-11-15T10:00:00Z',
 },
 {
 id: 'aff_2',
 name: 'Ana Oliveira',
 email: 'ana@email.com',
 phone: '(21) 99876-5432',
 code: 'ANADIG',
 status: 'active',
 commission_percent: 35,
 total_sales: 2890500,
 total_commission: 1011675,
 pending_commission: 156000,
 paid_commission: 855675,
 clicks_count: 8920,
 conversions_count: 189,
 pix_key: '21998765432',
 split_rules: [
 { id: '3', product_id: 'prod_1', product_name: 'Curso Completo de Marketing Digital', split_type: 'percentage', split_value: 35, priority: 1 },
 ],
 created_at: '2025-12-01T14:30:00Z',
 },
 {
 id: 'aff_3',
 name: 'Roberto Silva',
 email: 'roberto@email.com',
 phone: '(31) 97654-3210',
 code: 'ROBPRO',
 status: 'active',
 commission_percent: 30,
 total_sales: 1567800,
 total_commission: 470340,
 pending_commission: 89000,
 paid_commission: 381340,
 clicks_count: 5670,
 conversions_count: 98,
 pix_key: '123.456.789-00',
 split_rules: [
 { id: '4', product_id: 'prod_3', product_name: 'Mentoria Premium 1:1', split_type: 'fixed', split_value: 50000, priority: 1 },
 ],
 created_at: '2025-12-10T09:00:00Z',
 },
 {
 id: 'aff_4',
 name: 'Mariana Costa',
 email: 'mariana@email.com',
 phone: '(41) 98765-1234',
 code: 'MARI25',
 status: 'pending',
 commission_percent: 25,
 total_sales: 0,
 total_commission: 0,
 pending_commission: 0,
 paid_commission: 0,
 clicks_count: 234,
 conversions_count: 0,
 split_rules: [],
 created_at: '2026-01-14T16:00:00Z',
 },
 {
 id: 'aff_5',
 name: 'Lucas Ferreira',
 email: 'lucas@email.com',
 phone: '(51) 99123-4567',
 code: 'LUCAS30',
 status: 'active',
 commission_percent: 30,
 total_sales: 987500,
 total_commission: 296250,
 pending_commission: 45000,
 paid_commission: 251250,
 clicks_count: 3890,
 conversions_count: 67,
 pix_key: 'lucas@email.com',
 split_rules: [
 { id: '5', product_id: 'prod_2', product_name: 'E-book Finanças Pessoais', split_type: 'percentage', split_value: 30, priority: 1 },
 { id: '6', product_id: 'prod_4', product_name: 'Template Pack Premium', split_type: 'percentage', split_value: 25, priority: 2 },
 ],
 created_at: '2026-01-02T11:00:00Z',
 },
 {
 id: 'aff_6',
 name: 'Juliana Santos',
 email: 'juliana@email.com',
 phone: '(61) 98888-7777',
 code: 'JULIDIG',
 status: 'inactive',
 commission_percent: 20,
 total_sales: 345000,
 total_commission: 69000,
 pending_commission: 0,
 paid_commission: 69000,
 clicks_count: 1560,
 conversions_count: 23,
 split_rules: [],
 created_at: '2025-10-20T08:00:00Z',
 },
];

export default function AffiliatesPage() {
 const [search, setSearch] = useState('');
 const [selectedAffiliate, setSelectedAffiliate] = useState<Affiliate | null>(null);
 const [isModalOpen, setIsModalOpen] = useState(false);
 const [isSplitModalOpen, setIsSplitModalOpen] = useState(false);
 const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'pending' | 'inactive'>('all');
 const queryClient = useQueryClient();

 const { data, refetch, isLoading } = useQuery({
 queryKey: ['affiliates', search],
 queryFn: async () => {
 const response = await api.get('/affiliates', {
 params: { search },
 });
 return response.data;
 },
 });

 const { data: statsData } = useQuery({
 queryKey: ['affiliates-stats'],
 queryFn: async () => {
 const response = await api.get('/reports/affiliates');
 const affiliatesData = response.data?.data || [];
 const active = affiliatesData.filter((a: any) => a.status === 'active');
 return {
 total_affiliates: affiliatesData.length,
 active_affiliates: active.length,
 total_sales: active.reduce((sum: number, a: any) => sum + (a.revenue || 0), 0),
 total_commissions: active.reduce((sum: number, a: any) => sum + (a.commission_earned || 0), 0),
 pending_payouts: 0,
 paid_out: 0,
 };
 },
 });

 // Buscar produtos com afiliados habilitados
 const { data: productsData } = useQuery({
 queryKey: ['products-for-affiliate'],
 queryFn: async () => {
 const response = await api.get('/products');
 return response.data?.data || [];
 },
 });

 const affiliateProducts = (productsData || [])
 .filter((p: any) => p.affiliate_enabled)
 .map((p: any) => ({
 id: p.id,
 name: p.name,
 price: p.price,
 }));

 const deleteMutation = useMutation({
 mutationFn: async (id: string) => {
 await api.delete(`/affiliates/${id}`);
 },
 onSuccess: () => {
 toast.success('Afiliado removido!');
 queryClient.invalidateQueries({ queryKey: ['affiliates'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao remover afiliado');
 },
 });

 const payCommissionMutation = useMutation({
 mutationFn: async (id: string) => {
 return api.post(`/affiliates/${id}/pay-commission`);
 },
 onSuccess: () => {
 toast.success('Comissão paga com sucesso!');
 queryClient.invalidateQueries({ queryKey: ['affiliates'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao pagar comissão');
 },
 });

 // Usar dados da API
 let affiliates: Affiliate[] = data?.data || [];
 
 // Apply filters
 affiliates = affiliates.filter(a => {
 const matchesSearch = !search || 
 a.name.toLowerCase().includes(search.toLowerCase()) || 
 a.email.toLowerCase().includes(search.toLowerCase()) ||
 a.code.toLowerCase().includes(search.toLowerCase());
 
 const matchesStatus = statusFilter === 'all' || a.status === statusFilter;
 
 return matchesSearch && matchesStatus;
 });

 const handleEdit = (affiliate: Affiliate) => {
 setSelectedAffiliate(affiliate);
 setIsModalOpen(true);
 };

 const handleCreate = () => {
 setSelectedAffiliate(null);
 setIsModalOpen(true);
 };

 const handleDelete = (affiliate: Affiliate) => {
 if (confirm('Tem certeza que deseja remover este afiliado?')) {
 deleteMutation.mutate(affiliate.id);
 }
 };

 const handleConfigureSplit = (affiliate: Affiliate) => {
 setSelectedAffiliate(affiliate);
 setIsSplitModalOpen(true);
 };

 const handlePayCommission = (affiliate: Affiliate) => {
 if (affiliate.pending_commission === 0) {
 toast.error('Não há comissão pendente para pagar');
 return;
 }
 if (confirm(`Confirmar pagamento de ${formatCurrency(affiliate.pending_commission)} para ${affiliate.name}?`)) {
 payCommissionMutation.mutate(affiliate.id);
 }
 };

 const copyLink = (affiliate: Affiliate) => {
 const link = `${window.location.origin}/r/${affiliate.code}`;
 navigator.clipboard.writeText(link);
 toast.success('Link de afiliado copiado!');
 };

 const conversionRate = (affiliate: Affiliate) => {
 if (affiliate.clicks_count === 0) return 0;
 return ((affiliate.conversions_count / affiliate.clicks_count) * 100).toFixed(1);
 };

 return (
 <div className="space-y-6">
 {/* Header */}
 <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Programa de Afiliados</h1>
 <p className="text-sm text-tx-muted mt-1">
 Gerencie afiliados, comissões e split de pagamentos
 </p>
 </div>
 <button onClick={handleCreate} className="btn-primary inline-flex items-center">
 <PlusIcon className="mr-2 h-5 w-5" />
 Convidar Afiliado
 </button>
 </div>

 {/* Stats */}
 <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-4">
 <StatsCard
 title="Total de Afiliados"
 value={`${statsData?.active_affiliates || 0}/${statsData?.total_affiliates || 0}`}
 icon={UserGroupIcon}
 color="bg-gradient-to-br from-emerald-500 to-emerald-600"
 />
 <StatsCard
 title="Vendas Totais"
 value={formatCurrency(statsData?.total_sales || 0)}
 icon={ChartBarIcon}
 color="bg-gradient-to-br from-green-500 to-green-600"
 />
 <StatsCard
 title="Comissões Geradas"
 value={formatCurrency(statsData?.total_commissions || 0)}
 icon={CurrencyDollarIcon}
 color="bg-gradient-to-br from-blue-500 to-blue-600"
 />
 <StatsCard
 title="A Pagar"
 value={formatCurrency(statsData?.pending_payouts || 0)}
 icon={BanknotesIcon}
 color="bg-gradient-to-br from-orange-500 to-orange-600"
 />
 </div>

 {/* Filters and Search */}
 <div className="card p-4">
 <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
 <div className="relative flex-1">
 <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar por nome, email ou código..."
 className="input pl-10"
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 />
 </div>
 
 <div className="flex gap-2">
 <select
 value={statusFilter}
 onChange={(e) => setStatusFilter(e.target.value as any)}
 className="input"
 >
 <option value="all">Todos Status</option>
 <option value="active">Ativos</option>
 <option value="pending">Pendentes</option>
 <option value="inactive">Inativos</option>
 </select>
 
 <button
 onClick={() => refetch()}
 className="btn-secondary inline-flex items-center"
 >
 <ArrowPathIcon className="h-5 w-5" />
 </button>
 </div>
 </div>
 </div>

 {/* Affiliates Table */}
 <div className="card overflow-hidden">
 <div className="overflow-x-auto">
 <table className="min-w-full divide-y divide-border-DEFAULT">
 <thead className="bg-surface-page">
 <tr>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Afiliado
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Código / Link
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Status
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Comissão
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Vendas
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Conversão
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 A Receber
 </th>
 <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Split Rules
 </th>
 <th className="relative px-3 sm:px-6 py-3">
 <span className="sr-only">Ações</span>
 </th>
 </tr>
 </thead>
 <tbody className="divide-y divide-border-DEFAULT bg-surface-card">
 {isLoading ? (
 <tr>
 <td colSpan={9} className="px-3 sm:px-6 py-12 text-center text-tx-muted">
 <div className="flex items-center justify-center">
 <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
 </div>
 </td>
 </tr>
 ) : affiliates.length === 0 ? (
 <tr>
 <td colSpan={9} className="px-3 sm:px-6 py-12 text-center text-tx-muted">
 Nenhum afiliado encontrado
 </td>
 </tr>
 ) : (
 affiliates.map((affiliate) => (
 <tr
 key={affiliate.id}
 className="hover:bg-surface-page"
 >
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <div>
 <p className="font-medium text-tx-primary">
 {affiliate.name}
 </p>
 <p className="text-sm text-tx-muted">
 {affiliate.email}
 </p>
 {affiliate.phone && (
 <p className="text-xs text-tx-muted">{affiliate.phone}</p>
 )}
 </div>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <div className="flex flex-col gap-1">
 <div className="flex items-center gap-2">
 <code className="rounded bg-surface-hover px-2 py-1 text-xs font-mono text-tx-primary">
 {affiliate.code}
 </code>
 <button
 onClick={() => copyLink(affiliate)}
 className="text-tx-muted hover:text-accent-primary"
 title="Copiar link"
 >
 <LinkIcon className="h-4 w-4" />
 </button>
 </div>
 {affiliate.pix_key && (
 <div className="flex items-center gap-1 text-xs text-tx-muted">
 <CheckCircleIcon className="h-3 w-3 text-status-success" />
 PIX configurado
 </div>
 )}
 </div>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <span
 className={`inline-flex items-center gap-1 badge ${
 affiliate.status === 'active'
 ? 'badge-success'
 : affiliate.status === 'pending'
 ? 'badge-warning'
 : 'badge-default'
 }`}
 >
 {affiliate.status === 'active' && <CheckCircleIcon className="h-3 w-3" />}
 {affiliate.status === 'pending' && <ClockIcon className="h-3 w-3" />}
 {affiliate.status === 'active'
 ? 'Ativo'
 : affiliate.status === 'pending'
 ? 'Pendente'
 : 'Inativo'}
 </span>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4 text-sm font-medium text-accent-primary">
 {affiliate.commission_percent}%
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <div className="flex flex-col">
 <span className="text-sm font-semibold text-tx-primary">
 {formatCurrency(affiliate.total_sales)}
 </span>
 <span className="text-xs text-tx-muted">
 {affiliate.conversions_count} vendas
 </span>
 </div>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <div className="flex items-center gap-2">
 <div className="flex-1">
 <div className="mb-1 flex items-center justify-between">
 <span className="text-sm font-medium text-tx-primary">
 {conversionRate(affiliate)}%
 </span>
 </div>
 <div className="h-1.5 w-full rounded-full bg-surface-hover ">
 <div
 className="h-1.5 rounded-full bg-gradient-to-r from-emerald-500 to-emerald-600"
 style={{ width: `${Math.min(Number(conversionRate(affiliate)), 100)}%` }}
 />
 </div>
 </div>
 </div>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <div className="flex flex-col">
 <span className="text-sm font-bold text-status-success">
 {formatCurrency(affiliate.pending_commission)}
 </span>
 <span className="text-xs text-tx-muted">
 Total: {formatCurrency(affiliate.total_commission)}
 </span>
 </div>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4">
 <button
 onClick={() => handleConfigureSplit(affiliate)}
 className="inline-flex items-center gap-1 rounded-lg bg-accent-subtle px-2 py-1 text-xs font-medium text-accent-primary hover:bg-accent-subtle"
 >
 <Cog6ToothIcon className="h-3 w-3" />
 {affiliate.split_rules?.length || 0} regras
 </button>
 </td>
 <td className="whitespace-nowrap px-3 sm:px-6 py-4 text-right text-sm font-medium">
 <Menu as="div" className="relative inline-block text-left">
 <Menu.Button className="rounded p-1 hover:bg-surface-hover">
 <EllipsisVerticalIcon className="h-5 w-5 text-tx-muted" />
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
 <Menu.Items className="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-lg bg-surface-card ring-1 ring-black ring-opacity-5 focus:outline-none">
 <div className="py-1">
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => copyLink(affiliate)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <LinkIcon className="mr-3 h-5 w-5" />
 Copiar link de afiliado
 </button>
 )}
 </Menu.Item>
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleConfigureSplit(affiliate)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <Cog6ToothIcon className="mr-3 h-5 w-5" />
 Configurar Split
 </button>
 )}
 </Menu.Item>
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleEdit(affiliate)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <PencilIcon className="mr-3 h-5 w-5" />
 Editar dados
 </button>
 )}
 </Menu.Item>
 {affiliate.pending_commission > 0 && (
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handlePayCommission(affiliate)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-status-success`}
 >
 <BanknotesIcon className="mr-3 h-5 w-5" />
 Pagar Comissão
 </button>
 )}
 </Menu.Item>
 )}
 <hr className="my-1" />
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleDelete(affiliate)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-status-error`}
 >
 <TrashIcon className="mr-3 h-5 w-5" />
 Remover afiliado
 </button>
 )}
 </Menu.Item>
 </div>
 </Menu.Items>
 </Transition>
 </Menu>
 </td>
 </tr>
 ))
 )}
 </tbody>
 </table>
 </div>
 </div>

 {/* Modals */}
 <AffiliateModal
 affiliate={selectedAffiliate}
 isOpen={isModalOpen}
 onClose={() => {
 setIsModalOpen(false);
 setSelectedAffiliate(null);
 }}
 products={affiliateProducts}
 />

 <SplitConfigModal
 affiliate={selectedAffiliate}
 isOpen={isSplitModalOpen && !!selectedAffiliate}
 onClose={() => {
 setIsSplitModalOpen(false);
 setSelectedAffiliate(null);
 }}
 />
 </div>
 );
}
