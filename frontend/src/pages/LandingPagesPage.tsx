import { useState, useEffect } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
 SparklesIcon,
 EyeIcon,
 PencilSquareIcon,
 TrashIcon,
 DocumentDuplicateIcon,
 GlobeAltIcon,
 ChartBarIcon,
 MagnifyingGlassIcon,
 ArrowTopRightOnSquareIcon,
 CheckCircleIcon,
 XCircleIcon,
 XMarkIcon,
} from '@heroicons/react/24/outline';
import { Dialog, Transition, Menu } from '@headlessui/react';
import { Fragment } from 'react';
import toast from 'react-hot-toast';
import { landingPagesService, type LandingPage } from '@/services/api';
import { api } from '@/lib/api';
import GenerateLandingPageModal from '@/components/GenerateLandingPageModal';

// Preview Modal
function PreviewModal({
 isOpen,
 onClose,
 landingPage,
}: {
 isOpen: boolean;
 onClose: () => void;
 landingPage: LandingPage | null;
}) {
 const [html, setHtml] = useState('');
 const [isLoading, setIsLoading] = useState(false);
 const [error, setError] = useState(false);

 useEffect(() => {
 if (isOpen && landingPage) {
 setIsLoading(true);
 setError(false);
 setHtml('');
 landingPagesService
 .preview(landingPage.id)
 .then((response) => {
 const htmlContent = response.data?.html || response?.html;
 if (htmlContent) {
 setHtml(htmlContent);
 } else {
 setError(true);
 toast.error('Preview vazio');
 }
 })
 .catch((err) => {
 setError(true);
 toast.error('Erro ao carregar preview');
 })
 .finally(() => {
 setIsLoading(false);
 });
 }
 }, [isOpen, landingPage]);

 return (
 <Transition appear show={isOpen} as={Fragment}>
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
 <div className="fixed inset-0 bg-black/80" />
 </Transition.Child>

 <div className="fixed inset-0 overflow-y-auto">
 <div className="flex min-h-full items-center justify-center p-4">
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0 scale-95"
 enterTo="opacity-100 scale-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100 scale-100"
 leaveTo="opacity-0 scale-95"
 >
 <Dialog.Panel className="w-full max-w-6xl h-[90vh] transform overflow-hidden rounded-2xl bg-surface-card shadow-2xl transition-all flex flex-col">
 <div className="flex items-center justify-between px-4 py-3 bg-surface-hover border-b">
 <div className="flex items-center gap-4">
 <div className="flex gap-2">
 <div className="w-3 h-3 rounded-full bg-status-error-subtle0" />
 <div className="w-3 h-3 rounded-full bg-status-warning-subtle0" />
 <div className="w-3 h-3 rounded-full bg-status-success-subtle0" />
 </div>
 <div className="bg-surface-card rounded-element px-4 py-1 text-sm text-tx-secondary flex items-center gap-2">
 <GlobeAltIcon className="h-4 w-4" />
 {landingPage?.slug ? `wazerpay.com/lp/${landingPage.slug}` : 'Preview'}
 </div>
 </div>
 <button
 onClick={onClose}
 className="text-tx-muted hover:text-tx-secondary"
 >
 ✕
 </button>
 </div>
 
 <div className="flex-1 overflow-hidden">
 {isLoading ? (
 <div className="flex items-center justify-center h-full">
 <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-accent-primary" />
 </div>
 ) : error ? (
 <div className="flex items-center justify-center h-full text-tx-muted">
 <div className="text-center">
 <p className="text-lg font-medium">Erro ao carregar preview</p>
 <p className="text-sm mt-1">Tente novamente mais tarde</p>
 </div>
 </div>
 ) : html ? (
 <iframe
 srcDoc={html}
 className="w-full h-full border-0"
 title="Landing Page Preview"
 sandbox="allow-scripts"
 />
 ) : (
 <div className="flex items-center justify-center h-full text-tx-muted">
 <p>Carregando...</p>
 </div>
 )}
 </div>
 </Dialog.Panel>
 </Transition.Child>
 </div>
 </div>
 </Dialog>
 </Transition>
 );
}

export default function LandingPagesPage() {
 const [searchParams, setSearchParams] = useSearchParams();
 const navigate = useNavigate();
 const checkoutIdFromUrl = searchParams.get('checkout_id');
 
 const [isGeneratorOpen, setIsGeneratorOpen] = useState(false);
 const [isPreviewOpen, setIsPreviewOpen] = useState(false);
 const [selectedLandingPage, setSelectedLandingPage] = useState<LandingPage | null>(null);
 const [search, setSearch] = useState('');
 const [statusFilter, setStatusFilter] = useState<string>('all');
 
 const queryClient = useQueryClient();

 // Auto-open generator modal when checkout_id is in URL
 useEffect(() => {
 if (checkoutIdFromUrl && !isGeneratorOpen) {
 setIsGeneratorOpen(true);
 }
 }, [checkoutIdFromUrl]);

 // Clear checkout_id from URL when modal closes
 const handleGeneratorClose = () => {
 setIsGeneratorOpen(false);
 if (checkoutIdFromUrl) {
 searchParams.delete('checkout_id');
 setSearchParams(searchParams, { replace: true });
 }
 };

 const { data, isLoading } = useQuery({
 queryKey: ['landing-pages', search, statusFilter],
 queryFn: async () => {
 const params: any = {};
 if (search) params.search = search;
 if (statusFilter !== 'all') params.status = statusFilter;
 return landingPagesService.list(params);
 },
 });

 const deleteMutation = useMutation({
 mutationFn: (id: string) => landingPagesService.delete(id),
 onSuccess: () => {
 toast.success('Landing page removida');
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 },
 onError: () => {
 toast.error('Erro ao remover landing page');
 },
 });

 const duplicateMutation = useMutation({
 mutationFn: (id: string) => landingPagesService.duplicate(id),
 onSuccess: () => {
 toast.success('Landing page duplicada');
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 },
 onError: () => {
 toast.error('Erro ao duplicar landing page');
 },
 });

 const publishMutation = useMutation({
 mutationFn: (id: string) => landingPagesService.publish(id),
 onSuccess: (response) => {
 toast.success('Landing page publicada!');
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 if (response.data.url) {
 navigator.clipboard.writeText(response.data.url);
 toast.success('URL copiada para a área de transferência');
 }
 },
 onError: () => {
 toast.error('Erro ao publicar');
 },
 });

 const unpublishMutation = useMutation({
 mutationFn: (id: string) => landingPagesService.unpublish(id),
 onSuccess: () => {
 toast.success('Landing page despublicada');
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 },
 onError: () => {
 toast.error('Erro ao despublicar');
 },
 });

 const handlePreview = (lp: LandingPage) => {
 setSelectedLandingPage(lp);
 setIsPreviewOpen(true);
 };

 const handleDelete = (lp: LandingPage) => {
 if (confirm(`Tem certeza que deseja remover "${lp.name}"?`)) {
 deleteMutation.mutate(lp.id);
 }
 };

 const handleOpenUrl = (lp: LandingPage) => {
 const backendUrl = import.meta.env.VITE_API_URL?.replace('/api/v1', '') || '';
 const url = `${backendUrl}/lp/${lp.slug}`;
 window.open(url, '_blank');
 };

 const landingPages = data?.data || [];

 const getStatusBadge = (status: string) => {
 switch (status) {
 case 'published':
 return (
 <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-status-success-subtle text-green-800">
 <CheckCircleIcon className="h-3 w-3" />
 Publicado
 </span>
 );
 case 'draft':
 return (
 <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-status-warning-subtle text-status-warning">
 <PencilSquareIcon className="h-3 w-3" />
 Rascunho
 </span>
 );
 case 'archived':
 return (
 <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-surface-hover text-tx-primary /30">
 <XCircleIcon className="h-3 w-3" />
 Arquivado
 </span>
 );
 default:
 return null;
 }
 };

 return (
 <div className="space-y-4 sm:space-y-6">
 {/* Header */}
 <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary flex items-center gap-2">
 <SparklesIcon className="h-7 w-7 text-accent-primary" />
 Gerador de Landing Pages
 </h1>
 <p className="text-sm text-tx-muted mt-1">
 Crie páginas de vendas de alta conversão com inteligência artificial
 </p>
 </div>
 <button
 onClick={() => setIsGeneratorOpen(true)}
 className="btn-primary inline-flex items-center gap-2"
 >
 <SparklesIcon className="h-5 w-5" />
 Gerar Nova Página
 </button>
 </div>

 {/* Filters */}
 <div className="flex flex-col sm:flex-row gap-4">
 <div className="relative flex-1">
 <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar landing pages..."
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 className="w-full pl-10 pr-4 py-2 rounded-element border border-border-DEFAULT bg-surface-card focus:ring-2 focus:ring-accent-primary"
 />
 </div>
 <select
 value={statusFilter}
 onChange={(e) => setStatusFilter(e.target.value)}
 className="px-4 py-2 rounded-element border border-border-DEFAULT bg-surface-card"
 >
 <option value="all">Todos os status</option>
 <option value="draft">Rascunho</option>
 <option value="published">Publicado</option>
 <option value="archived">Arquivado</option>
 </select>
 </div>

 {/* Landing Pages Grid */}
 {isLoading ? (
 <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6">
 {[1, 2, 3].map((i) => (
 <div
 key={i}
 className="bg-surface-card rounded-xl p-4 sm:p-6 animate-pulse"
 >
 <div className="h-32 bg-surface-hover rounded-lg mb-4" />
 <div className="h-4 bg-surface-hover rounded w-3/4 mb-2" />
 <div className="h-3 bg-surface-hover rounded w-1/2" />
 </div>
 ))}
 </div>
 ) : landingPages.length === 0 ? (
 <div className="text-center py-16">
 <div className="mx-auto w-24 h-24 rounded-full bg-accent-subtle flex items-center justify-center mb-6">
 <SparklesIcon className="h-12 w-12 text-accent-primary" />
 </div>
 <h3 className="text-xl font-bold text-tx-primary mb-2">
 Nenhuma landing page ainda
 </h3>
 <p className="text-tx-muted mb-6">
 Crie sua primeira página de vendas com o poder da inteligência artificial
 </p>
 <button
 onClick={() => setIsGeneratorOpen(true)}
 className="btn-primary inline-flex items-center gap-2"
 >
 <SparklesIcon className="h-5 w-5" />
 Gerar Minha Primeira Página
 </button>
 </div>
 ) : (
 <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6">
 {landingPages.map((lp) => (
 <div
 key={lp.id}
 className="bg-surface-card rounded-xl hover:shadow-xl transition-shadow"
 >
 {/* Preview Thumbnail */}
 <div
 className="h-40 relative cursor-pointer overflow-hidden rounded-t-xl"
 onClick={() => handlePreview(lp)}
 >
 {lp.product?.image_url ? (
 <img
 src={lp.product.image_url}
 alt={lp.product.name}
 className="w-full h-full object-cover"
 />
 ) : (
 <div
 className="w-full h-full flex flex-col items-center justify-center p-4 text-center"
 style={{
 background: `linear-gradient(135deg, ${lp.content?.theme?.primaryColor || '#6366f1'}22, ${lp.content?.theme?.secondaryColor || '#16a34a'}22)`,
 }}
 >
 {lp.content?.sections?.[0]?.content?.headline ? (
 <>
 <p
 className="text-sm font-bold line-clamp-2 mb-1"
 style={{ color: lp.content?.theme?.primaryColor || '#6366f1' }}
 >
 {lp.content.sections[0].content.headline}
 </p>
 <p className="text-xs text-tx-muted line-clamp-1">
 {lp.content.sections[0].content.subheadline || ''}
 </p>
 </>
 ) : (
 <EyeIcon className="h-8 w-8 text-accent-primary opacity-50" />
 )}
 </div>
 )}
 <div className="absolute top-3 right-3">
 {getStatusBadge(lp.status)}
 </div>
 </div>

 {/* Content */}
 <div className="p-4">
 <h3 className="font-bold text-tx-primary truncate">
 {lp.name}
 </h3>
 <p className="text-sm text-tx-muted truncate">
 /{lp.slug}
 </p>

 {/* Stats */}
 <div className="flex items-center gap-4 mt-3 text-sm text-tx-muted">
 <span className="flex items-center gap-1">
 <EyeIcon className="h-4 w-4" />
 {lp.views_count}
 </span>
 <span className="flex items-center gap-1">
 <ChartBarIcon className="h-4 w-4" />
 {lp.conversions_count > 0
 ? ((lp.conversions_count / lp.views_count) * 100).toFixed(1)
 : 0}
 %
 </span>
 </div>

 {/* Product/Checkout info */}
 {(lp.product || lp.checkout) && (
 <div className="mt-3 pt-3 border-t border-border-DEFAULT">
 {lp.product && (
 <p className="text-xs text-tx-muted">
 Produto: <span className="font-medium">{lp.product.name}</span>
 </p>
 )}
 {lp.checkout && (
 <p className="text-xs text-tx-muted">
 Checkout: <span className="font-medium">{lp.checkout.name}</span>
 </p>
 )}
 </div>
 )}

 {/* Actions */}
 <div className="flex items-center gap-2 mt-4">
 <button
 onClick={() => navigate(`/landing-pages/${lp.id}/edit`)}
 className="flex-1 px-3 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
 >
 <PencilSquareIcon className="h-4 w-4 inline mr-1" />
 Editar
 </button>
 <button
 onClick={() => handlePreview(lp)}
 className="px-3 py-2 text-sm font-medium text-tx-secondary bg-surface-hover rounded-element hover:bg-surface-hover"
 >
 <EyeIcon className="h-4 w-4 inline mr-1" />
 Preview
 </button>
 
 {lp.status === 'published' ? (
 <button
 onClick={() => handleOpenUrl(lp)}
 className="flex-1 px-3 py-2 text-sm font-medium text-white bg-green-600 rounded-element hover:bg-green-700"
 >
 <ArrowTopRightOnSquareIcon className="h-4 w-4 inline mr-1" />
 Abrir
 </button>
 ) : (
 <button
 onClick={() => publishMutation.mutate(lp.id)}
 className="flex-1 px-3 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
 >
 <GlobeAltIcon className="h-4 w-4 inline mr-1" />
 Publicar
 </button>
 )}

 <Menu as="div" className="relative">
 <Menu.Button className="p-2 text-tx-muted hover:text-tx-secondary rounded-element hover:bg-surface-hover">
 <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
 <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z" />
 </svg>
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
 <Menu.Items className="absolute right-0 mt-2 w-48 origin-top-right rounded-xl bg-surface-card ring-1 ring-black ring-opacity-5 focus:outline-none z-10">
 <div className="py-1">
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => duplicateMutation.mutate(lp.id)}
 className={`${
 active ? 'bg-surface-hover ' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <DocumentDuplicateIcon className="h-4 w-4 mr-3" />
 Duplicar
 </button>
 )}
 </Menu.Item>
 {lp.status === 'published' && (
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => unpublishMutation.mutate(lp.id)}
 className={`${
 active ? 'bg-surface-hover ' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <XCircleIcon className="h-4 w-4 mr-3" />
 Despublicar
 </button>
 )}
 </Menu.Item>
 )}
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleDelete(lp)}
 className={`${
 active ? 'bg-surface-hover ' : ''
 } flex w-full items-center px-4 py-2 text-sm text-status-error`}
 >
 <TrashIcon className="h-4 w-4 mr-3" />
 Remover
 </button>
 )}
 </Menu.Item>
 </div>
 </Menu.Items>
 </Transition>
 </Menu>
 </div>
 </div>
 </div>
 ))}
 </div>
 )}

 {/* Generator Modal */}
 <GenerateLandingPageModal
 isOpen={isGeneratorOpen}
 onClose={handleGeneratorClose}
 initialCheckoutId={checkoutIdFromUrl || undefined}
 onSuccess={(lp) => {
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 setSelectedLandingPage(lp);
 setIsPreviewOpen(true);
 handleGeneratorClose();
 }}
 />

 {/* Preview Modal */}
 <PreviewModal
 isOpen={isPreviewOpen}
 onClose={() => setIsPreviewOpen(false)}
 landingPage={selectedLandingPage}
 />
 </div>
 );
}


