import { useState, useRef, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate, formatCurrency } from '@/lib/api';
import { Dialog, Transition, Switch, Listbox } from '@headlessui/react';
import { Fragment } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
import {
 PlusIcon,
 MagnifyingGlassIcon,
 EllipsisVerticalIcon,
 ArrowPathIcon,
 XMarkIcon,
 PencilIcon,
 TrashIcon,
 EyeIcon,
 DocumentDuplicateIcon,
 ClipboardDocumentIcon,
 ChartBarIcon,
 ChevronUpDownIcon,
 CheckIcon,
 PhotoIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { domainsService, CustomDomain } from '@/services/api/domains.service';

interface Checkout {
 id: string;
 name: string;
 slug: string;
 url?: string;
 status: 'active' | 'inactive';
 product_id?: string;
 product?: {
 id: string;
 name: string;
 price: number;
 };
 settings: {
 theme_color: string;
 collect_address: boolean;
 collect_phone: boolean;
 payment_methods: string[];
 max_installments: number;
 exit_popup_enabled: boolean;
 timer_enabled: boolean;
 timer_minutes: number;
 testimonials_enabled: boolean;
 custom_css?: string;
 };
 views_count: number;
 conversions_count: number;
 created_at: string;
}

interface CheckoutForm {
 name: string;
 product_id: string;
 domain_id?: string;
 settings: {
 theme_color: string;
 collect_cpf: boolean;
 collect_address: boolean;
 collect_phone: boolean;
 payment_methods: string[];
 max_installments: number;
 exit_popup_enabled: boolean;
 timer_enabled: boolean;
 timer_minutes: number;
 testimonials_enabled: boolean;
 };
}

const defaultSettings = {
 theme_color: '#22c55e',
 collect_cpf: true,
 collect_address: true,
 collect_phone: true,
 payment_methods: ['credit_card', 'pix', 'boleto'],
 max_installments: 12,
 exit_popup_enabled: false,
 timer_enabled: false,
 timer_minutes: 15,
 testimonials_enabled: false,
};

// Componente de Dropdown com Portal para evitar overflow issues
function CheckoutDropdown({
 checkout,
 onClone,
 onDelete,
 onCopyLink,
 checkoutBaseUrl,
}: {
 checkout: Checkout;
 onClone: () => void;
 onDelete: () => void;
 onCopyLink: () => void;
 checkoutBaseUrl: string;
}) {
 const [isOpen, setIsOpen] = useState(false);
 const [position, setPosition] = useState({ top: 0, left: 0 });
 const buttonRef = useRef<HTMLButtonElement>(null);
 const menuRef = useRef<HTMLDivElement>(null);

 useEffect(() => {
 if (isOpen && buttonRef.current) {
 const rect = buttonRef.current.getBoundingClientRect();
 setPosition({
 top: rect.bottom + window.scrollY + 8,
 left: rect.right - 192 + window.scrollX,
 });
 }
 }, [isOpen]);

 useEffect(() => {
 const handleClickOutside = (event: MouseEvent) => {
 if (
 menuRef.current &&
 !menuRef.current.contains(event.target as Node) &&
 buttonRef.current &&
 !buttonRef.current.contains(event.target as Node)
 ) {
 setIsOpen(false);
 }
 };

 const handleEscape = (event: KeyboardEvent) => {
 if (event.key === 'Escape') {
 setIsOpen(false);
 }
 };

 if (isOpen) {
 document.addEventListener('mousedown', handleClickOutside);
 document.addEventListener('keydown', handleEscape);
 }

 return () => {
 document.removeEventListener('mousedown', handleClickOutside);
 document.removeEventListener('keydown', handleEscape);
 };
 }, [isOpen]);

 const menuContent = isOpen ? createPortal(
 <div
 ref={menuRef}
 className="fixed z-[9999] w-48 rounded-lg bg-surface-card ring-1 ring-black ring-opacity-5 focus:outline-none"
 style={{ top: position.top, left: position.left }}
 >
 <div className="py-1">
 <a
 href={`${checkoutBaseUrl}/checkout/${checkout.slug}`}
 target="_blank"
 rel="noopener noreferrer"
 className="flex w-full items-center px-4 py-2 text-sm text-tx-secondary hover:bg-surface-hover"
 onClick={() => setIsOpen(false)}
 >
 <EyeIcon className="mr-3 h-5 w-5" />
 Visualizar
 </a>
 <button
 onClick={() => { onCopyLink(); setIsOpen(false); }}
 className="flex w-full items-center px-4 py-2 text-sm text-tx-secondary hover:bg-surface-hover"
 >
 <ClipboardDocumentIcon className="mr-3 h-5 w-5" />
 Copiar link
 </button>
 <Link
 to={`/checkout-builder/${checkout.id}`}
 className="flex w-full items-center px-4 py-2 text-sm text-tx-secondary hover:bg-surface-hover"
 onClick={() => setIsOpen(false)}
 >
 <PencilIcon className="mr-3 h-5 w-5" />
 Editar
 </Link>
 <button
 onClick={() => { onClone(); setIsOpen(false); }}
 className="flex w-full items-center px-4 py-2 text-sm text-tx-secondary hover:bg-surface-hover"
 >
 <DocumentDuplicateIcon className="mr-3 h-5 w-5" />
 Duplicar
 </button>
 <Link
 to={`/checkouts/${checkout.id}/analytics`}
 className="flex w-full items-center px-4 py-2 text-sm text-tx-secondary hover:bg-surface-hover"
 onClick={() => setIsOpen(false)}
 >
 <ChartBarIcon className="mr-3 h-5 w-5" />
 Analytics
 </Link>
 <hr className="my-1" />
 <button
 onClick={() => { onDelete(); setIsOpen(false); }}
 className="flex w-full items-center px-4 py-2 text-sm text-status-error hover:bg-surface-hover"
 >
 <TrashIcon className="mr-3 h-5 w-5" />
 Remover
 </button>
 </div>
 </div>,
 document.body
 ) : null;

 return (
 <>
 <button
 ref={buttonRef}
 onClick={() => setIsOpen(!isOpen)}
 className="rounded p-1 hover:bg-surface-hover"
 >
 <EllipsisVerticalIcon className="h-5 w-5 text-tx-muted" />
 </button>
 {menuContent}
 </>
 );
}

function CheckoutModal({
 checkout,
 isOpen,
 onClose,
}: {
 checkout: Checkout | null;
 isOpen: boolean;
 onClose: () => void;
}) {
 const queryClient = useQueryClient();
 const isEditing = !!checkout;

 const { data: productsData, refetch: refetchProducts } = useQuery({
 queryKey: ['products-list'],
 queryFn: async () => {
 const response = await api.get('/products', { params: { per_page: 100 } });
 return response.data;
 },
 staleTime: 0, // Sempre buscar dados frescos
 });

 const products = productsData?.data || [];

 // Refetch products when modal opens
 useEffect(() => {
 if (isOpen) {
 refetchProducts();
 }
 }, [isOpen, refetchProducts]);

 // Fetch verified domains from API
 const { data: domainsData } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });

 const allDomains = domainsData?.data || [];

 const {
 register,
 handleSubmit,
 watch,
 setValue,
 reset,
 formState: { errors },
 } = useForm<CheckoutForm>({
 defaultValues: checkout
 ? {
 name: checkout.name,
 product_id: checkout.product_id,
 settings: checkout.settings,
 }
 : {
 settings: defaultSettings,
 },
 });

 const settings = watch('settings');

 const mutation = useMutation({
 mutationFn: async (data: CheckoutForm) => {
 if (isEditing) {
 return await api.put(`/checkouts/${checkout!.id}`, data);
 }
 return await api.post('/checkouts', data);
 },
 onSuccess: () => {
 toast.success(isEditing ? 'Checkout atualizado!' : 'Checkout criado!');
 queryClient.invalidateQueries({ queryKey: ['checkouts'] });
 reset();
 onClose();
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao salvar checkout');
 },
 });

 const onSubmit = (data: CheckoutForm) => {
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
 {isEditing ? 'Editar Checkout' : 'Novo Checkout'}
 </Dialog.Title>
 <button
 type="button"
 onClick={onClose}
 className="text-tx-muted hover:text-tx-muted"
 >
 <XMarkIcon className="h-6 w-6" />
 </button>
 </div>

 <div className="max-h-[60vh] space-y-4 overflow-y-auto px-4 sm:px-6 py-4">
 {/* Basic Info */}
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Nome do Checkout
 </label>
 <input
 {...register('name', { required: 'Nome é obrigatório' })}
 type="text"
 className="input mt-1"
 placeholder="Ex: Checkout Principal"
 />
 {errors.name && (
 <p className="mt-1 text-sm text-status-error">{errors.name.message}</p>
 )}
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Produto
 </label>
 <input type="hidden" {...register('product_id', { required: 'Selecione um produto' })} />
 <Listbox
 value={watch('product_id') || ''}
 onChange={(value) => setValue('product_id', value)}
 >
 <div className="relative mt-1">
 <Listbox.Button className="input relative w-full cursor-pointer pr-10 text-left">
 {watch('product_id') ? (
 <div className="flex items-center gap-3">
 {(() => {
 const selectedProduct = products.find((p: any) => p.id === watch('product_id'));
 return selectedProduct ? (
 <>
 {selectedProduct.image_url ? (
 <img
 src={selectedProduct.image_url}
 alt={selectedProduct.name}
 className="h-8 w-8 rounded object-cover"
 />
 ) : (
 <div className="flex h-8 w-8 items-center justify-center rounded bg-surface-hover ">
 <PhotoIcon className="h-4 w-4 text-tx-muted" />
 </div>
 )}
 <div className="flex-1 truncate">
 <span className="block truncate">{selectedProduct.name}</span>
 <span className="text-xs text-accent-primary">
 {formatCurrency(selectedProduct.price)}
 </span>
 </div>
 </>
 ) : 'Selecione...';
 })()}
 </div>
 ) : (
 <span className="text-tx-muted">Selecione...</span>
 )}
 <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
 <ChevronUpDownIcon className="h-5 w-5 text-tx-muted" />
 </span>
 </Listbox.Button>
 <Transition
 as={Fragment}
 leave="transition ease-in duration-100"
 leaveFrom="opacity-100"
 leaveTo="opacity-0"
 >
 <Listbox.Options className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-surface-card py-1 ring-1 ring-black ring-opacity-5 focus:outline-none">
 {products.length === 0 ? (
 <div className="px-4 py-2 text-sm text-tx-muted">
 Nenhum produto encontrado
 </div>
 ) : (
 products.map((product: any) => (
 <Listbox.Option
 key={product.id}
 value={product.id}
 className={({ active }) =>
 `relative cursor-pointer select-none py-2 pl-3 pr-9 ${active ? 'bg-accent-subtle' : ''}`
 }
 >
 {({ selected }) => (
 <div className="flex items-center gap-3">
 {product.image_url ? (
 <img
 src={product.image_url}
 alt={product.name}
 className="h-10 w-10 rounded object-cover"
 />
 ) : (
 <div className="flex h-10 w-10 items-center justify-center rounded bg-surface-hover ">
 <PhotoIcon className="h-5 w-5 text-tx-muted" />
 </div>
 )}
 <div className="flex-1">
 <span className={`block truncate ${selected ? 'font-semibold text-accent-primary' : 'text-tx-primary'}`}>
 {product.name}
 </span>
 <span className="text-sm text-accent-primary">
 {formatCurrency(product.price)}
 </span>
 </div>
 {selected && (
 <CheckIcon className="h-5 w-5 text-accent-primary" />
 )}
 </div>
 )}
 </Listbox.Option>
 ))
 )}
 </Listbox.Options>
 </Transition>
 </div>
 </Listbox>
 {errors.product_id && (
 <p className="mt-1 text-sm text-status-error">{errors.product_id.message}</p>
 )}
 </div>
 </div>

 {/* Domain Selection */}
 <div className="rounded-element border border-border-DEFAULT p-4">
 <h4 className="font-medium text-tx-primary">Domínio</h4>
 <div className="mt-3">
 <label className="block text-sm font-medium text-tx-secondary">
 Domínio do Checkout
 </label>
 <select
 {...register('domain_id')}
 className="input mt-1"
 >
 <option value="">Domínio Padrão (pay.Vector Pagamentos.com)</option>
 {allDomains.map((domain: any) => (
 <option
 key={domain.id}
 value={domain.id}
 disabled={domain.status !== 'verified'}
 >
 {domain.domain}
 {domain.is_primary ? ' (Principal)' : ''}
 {domain.status === 'pending' ? ' ⏳ Pendente' : ''}
 {domain.status === 'failed' ? ' ❌ Falhou' : ''}
 </option>
 ))}
 </select>
 <p className="mt-1 text-xs text-tx-muted">
 <Link to="/domains" className="text-accent-primary hover:underline">Gerenciar domínios personalizados</Link>
 </p>
 </div>
 </div>

 {/* Data Collection */}
 <div className="rounded-xl border border-border-DEFAULT bg-gradient-to-br from-surface-card to-surface-hover/30 p-5">
 <div className="flex items-center gap-3 mb-4">
 <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-primary/10">
 <svg className="h-5 w-5 text-accent-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
 </svg>
 </div>
 <div>
 <h4 className="font-semibold text-tx-primary">Coleta de Dados</h4>
 <p className="text-xs text-tx-muted">Configure quais informações serão solicitadas</p>
 </div>
 </div>
 <div className="space-y-4">
 <div className="flex items-center justify-between p-3 rounded-lg bg-surface-card hover:bg-surface-hover/50 transition-colors">
 <div className="flex items-center gap-3">
 <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-500/10">
 <svg className="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
 </svg>
 </div>
 <div>
 <span className="text-sm font-medium text-tx-primary">Dados pessoais</span>
 <p className="text-xs text-tx-muted">Nome completo e CPF</p>
 </div>
 </div>
 <Switch
 checked={settings?.collect_cpf !== false}
 onChange={(checked) =>
 setValue('settings.collect_cpf', checked)
 }
 className={`${
 settings?.collect_cpf !== false ? 'bg-accent-primary' : 'bg-gray-300'
 } relative inline-flex h-7 w-12 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent-primary focus:ring-offset-2`}
 >
 <span
 className={`${
 settings?.collect_cpf !== false ? 'translate-x-6' : 'translate-x-1'
 } inline-block h-5 w-5 transform rounded-full bg-white shadow-lg transition-transform`}
 />
 </Switch>
 </div>
 <div className="flex items-center justify-between p-3 rounded-lg bg-surface-card hover:bg-surface-hover/50 transition-colors">
 <div className="flex items-center gap-3">
 <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-green-500/10">
 <svg className="h-4 w-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
 </svg>
 </div>
 <div>
 <span className="text-sm font-medium text-tx-primary">Endereço completo</span>
 <p className="text-xs text-tx-muted">CEP, rua, número e complemento</p>
 </div>
 </div>
 <Switch
 checked={settings?.collect_address !== false}
 onChange={(checked) =>
 setValue('settings.collect_address', checked)
 }
 className={`${
 settings?.collect_address !== false ? 'bg-accent-primary' : 'bg-gray-300'
 } relative inline-flex h-7 w-12 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent-primary focus:ring-offset-2`}
 >
 <span
 className={`${
 settings?.collect_address !== false ? 'translate-x-6' : 'translate-x-1'
 } inline-block h-5 w-5 transform rounded-full bg-white shadow-lg transition-transform`}
 />
 </Switch>
 </div>
 <div className="flex items-center justify-between p-3 rounded-lg bg-surface-card hover:bg-surface-hover/50 transition-colors">
 <div className="flex items-center gap-3">
 <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/10">
 <svg className="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
 </svg>
 </div>
 <div>
 <span className="text-sm font-medium text-tx-primary">Telefone</span>
 <p className="text-xs text-tx-muted">Número de contato com DDD</p>
 </div>
 </div>
 <Switch
 checked={settings?.collect_phone !== false}
 onChange={(checked) =>
 setValue('settings.collect_phone', checked)
 }
 className={`${
 settings?.collect_phone !== false ? 'bg-accent-primary' : 'bg-gray-300'
 } relative inline-flex h-7 w-12 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent-primary focus:ring-offset-2`}
 >
 <span
 className={`${
 settings?.collect_phone !== false ? 'translate-x-6' : 'translate-x-1'
 } inline-block h-5 w-5 transform rounded-full bg-white shadow-lg transition-transform`}
 />
 </Switch>
 </div>
 </div>
 </div>

 {/* Payment Options */}
 <div className="rounded-element border border-border-DEFAULT p-4">
 <h4 className="font-medium text-tx-primary">Pagamento</h4>
 <div className="mt-3">
 <label className="block text-sm font-medium text-tx-secondary">
 Máximo de parcelas
 </label>
 <select
 {...register('settings.max_installments')}
 className="input mt-1"
 >
 {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((n) => (
 <option key={n} value={n}>
 {n}x
 </option>
 ))}
 </select>
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
 {mutation.isPending ? 'Salvando...' : isEditing ? 'Salvar' : 'Criar Checkout'}
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

export default function CheckoutsPage() {
 const [search, setSearch] = useState('');
 const [selectedCheckout, setSelectedCheckout] = useState<Checkout | null>(null);
 const [isModalOpen, setIsModalOpen] = useState(false);
 const queryClient = useQueryClient();

 // Fetch verified domains to build checkout URLs
 const { data: mainDomainsData } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });
 
 const checkoutBaseUrl = (() => {
 const verified = (mainDomainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');
 const primary = verified.find((d: CustomDomain) => d.is_primary) || verified[0];
 return primary ? `https://${primary.domain}` : window.location.origin;
 })();

 const { data, refetch, isLoading } = useQuery({
 queryKey: ['checkouts', search],
 queryFn: async () => {
 const response = await api.get('/checkouts', {
 params: { search },
 });
 return response.data;
 },
 });

 const cloneMutation = useMutation({
 mutationFn: async (id: string) => {
 return api.post(`/checkouts/${id}/clone`);
 },
 onSuccess: () => {
 toast.success('Checkout clonado!');
 queryClient.invalidateQueries({ queryKey: ['checkouts'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao clonar checkout');
 },
 });

 const deleteMutation = useMutation({
 mutationFn: async (id: string) => {
 await api.delete(`/checkouts/${id}`);
 },
 onSuccess: () => {
 toast.success('Checkout removido!');
 queryClient.invalidateQueries({ queryKey: ['checkouts'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao remover checkout');
 },
 });

 // Usar dados da API
 const checkouts: Checkout[] = data?.data || [];

 const handleCreate = () => {
 setSelectedCheckout(null);
 setIsModalOpen(true);
 };

 const handleClone = (checkout: Checkout) => {
 cloneMutation.mutate(checkout.id);
 };

 const handleDelete = (checkout: Checkout) => {
 if (confirm('Tem certeza que deseja remover este checkout?')) {
 deleteMutation.mutate(checkout.id);
 }
 };

 const copyLink = (checkout: Checkout) => {
 const link = `${checkoutBaseUrl}/checkout/${checkout.slug}`;
 navigator.clipboard.writeText(link);
 toast.success('Link copiado!');
 };

 const conversionRate = (checkout: Checkout) => {
 if (checkout.views_count === 0) return 0;
 return ((checkout.conversions_count / checkout.views_count) * 100).toFixed(1);
 };

 return (
 <div className="space-y-4 lg:space-y-6">
 {/* Header */}
 <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
 <div>
 <h1 className="text-xl lg:text-2xl font-bold text-tx-primary">Checkouts</h1>
 <p className="text-xs lg:text-sm text-tx-muted mt-1">
 Gerencie seus links de checkout personalizadas
 </p>
 </div>
 <div className="flex gap-3">
 <button onClick={handleCreate} className="btn-primary inline-flex items-center text-sm lg:text-base">
 <PlusIcon className="mr-2 h-4 w-4 lg:h-5 lg:w-5" />
 Novo Checkout
 </button>
 </div>
 </div>

 {/* Search */}
 <div className="card p-3 lg:p-4">
 <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
 <div className="relative flex-1">
 <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar checkouts..."
 className="input pl-10 w-full text-sm"
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 />
 </div>
 <button
 onClick={() => refetch()}
 className="btn-secondary inline-flex items-center justify-center text-sm"
 >
 <ArrowPathIcon className="mr-2 h-4 w-4" />
 Atualizar
 </button>
 </div>
 </div>

 {/* Mobile Card View */}
 <div className="lg:hidden space-y-3">
 {isLoading ? (
 <div className="card p-8 text-center">
 <div className="flex items-center justify-center">
 <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
 </div>
 </div>
 ) : checkouts.length === 0 ? (
 <div className="card p-8 text-center">
 <p className="text-sm text-tx-muted">Nenhum checkout encontrado</p>
 </div>
 ) : (
 checkouts.map((checkout) => (
 <div
 key={checkout.id}
 className="card p-4 hover:bg-surface-hover/50 transition-colors"
 >
 <div className="flex items-start justify-between mb-3">
 <div className="min-w-0 flex-1">
 <p className="font-medium text-tx-primary text-sm truncate">
 {checkout.name}
 </p>
 <p className="text-xs text-tx-muted truncate">
 {checkout.product?.name || '-'}
 </p>
 </div>
 <CheckoutDropdown
 checkout={checkout}
 onClone={() => handleClone(checkout)}
 onDelete={() => handleDelete(checkout)}
 onCopyLink={() => copyLink(checkout)}
 checkoutBaseUrl={checkoutBaseUrl}
 />
 </div>
 <div className="flex items-center justify-between mb-3">
 {checkout.status === 'active' ? (
 <span className="badge badge-success text-xs">Ativo</span>
 ) : (
 <Link
 to={`/checkout-builder/${checkout.id}`}
 className="inline-flex items-center gap-1 rounded-lg bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-500"
 >
 Configurar
 </Link>
 )}
 <span className="text-xs text-tx-muted">
 {formatDate(checkout.created_at)}
 </span>
 </div>
 <div className="flex items-center justify-between pt-3 border-t border-border-DEFAULT">
 <div>
 <p className="text-xs text-tx-muted">Views</p>
 <p className="font-semibold text-tx-primary text-sm">
 {checkout.views_count.toLocaleString()}
 </p>
 </div>
 <div className="text-right">
 <p className="text-xs text-tx-muted">Conversão</p>
 <div className="flex items-center gap-2">
 <span className="text-sm font-semibold text-tx-primary">
 {conversionRate(checkout)}%
 </span>
 <div className="h-2 w-12 overflow-hidden rounded-full bg-surface-hover">
 <div
 className="h-full bg-status-success-subtle0"
 style={{ width: `${conversionRate(checkout)}%` }}
 />
 </div>
 </div>
 </div>
 </div>
 </div>
 ))
 )}
 </div>

 {/* Desktop Table */}
 <div className="hidden lg:block card overflow-visible">
 <div className="overflow-x-auto" style={{ overflowY: 'visible' }}>
 <table className="min-w-full divide-y divide-border-DEFAULT">
 <thead className="bg-surface-page">
 <tr>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Checkout
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Produto
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Status
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Views
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Conversão
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Criado em
 </th>
 <th className="relative px-6 py-3">
 <span className="sr-only">Ações</span>
 </th>
 </tr>
 </thead>
 <tbody className="divide-y divide-border-DEFAULT bg-surface-card">
 {isLoading ? (
 <tr>
 <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
 <div className="flex items-center justify-center">
 <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
 </div>
 </td>
 </tr>
 ) : checkouts.length === 0 ? (
 <tr>
 <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
 Nenhum link de pagamento encontrado
 </td>
 </tr>
 ) : (
 checkouts.map((checkout) => (
 <tr
 key={checkout.id}
 className="hover:bg-surface-page"
 >
 <td className="whitespace-nowrap px-6 py-4">
 <p className="font-medium text-tx-primary">
 {checkout.name}
 </p>
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
 {checkout.product?.name || '-'}
 </td>
 <td className="whitespace-nowrap px-6 py-4">
 {checkout.status === 'active' ? (
 <span className="badge badge-success">Ativo</span>
 ) : (
 <Link
 to={`/checkout-builder/${checkout.id}`}
 className="inline-flex items-center gap-1 rounded-lg bg-amber-500/10 px-3 py-1.5 text-xs font-medium text-amber-500 hover:bg-amber-500/20 transition-colors"
 >
 Configure link de pagamento
 </Link>
 )}
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-primary">
 {checkout.views_count.toLocaleString()}
 </td>
 <td className="whitespace-nowrap px-6 py-4">
 <div className="flex items-center">
 <span className="text-sm font-medium text-tx-primary">
 {conversionRate(checkout)}%
 </span>
 <div className="ml-2 h-2 w-16 overflow-hidden rounded-full bg-surface-hover">
 <div
 className="h-full bg-status-success-subtle0"
 style={{ width: `${conversionRate(checkout)}%` }}
 />
 </div>
 </div>
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
 {formatDate(checkout.created_at)}
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
 <CheckoutDropdown
 checkout={checkout}
 onClone={() => handleClone(checkout)}
 onDelete={() => handleDelete(checkout)}
 onCopyLink={() => copyLink(checkout)}
 checkoutBaseUrl={checkoutBaseUrl}
 />
 </td>
 </tr>
 ))
 )}
 </tbody>
 </table>
 </div>
 </div>

 {/* Checkout Modal */}
 <CheckoutModal
 checkout={selectedCheckout}
 isOpen={isModalOpen}
 onClose={() => {
 setIsModalOpen(false);
 setSelectedCheckout(null);
 }}
 />

 </div>
 );
}
