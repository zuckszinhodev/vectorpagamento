import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
 GlobeAltIcon,
 PlusIcon,
 TrashIcon,
 CheckCircleIcon,
 ExclamationTriangleIcon,
 ClockIcon,
 ArrowPathIcon,
 StarIcon,
 ClipboardDocumentIcon,
 ShieldCheckIcon,
 XMarkIcon,
 ShoppingCartIcon,
 AcademicCapIcon,
 QuestionMarkCircleIcon,
 BookOpenIcon,
 ChevronDownIcon,
 ChevronUpIcon,
} from '@heroicons/react/24/outline';
import { domainsService, CustomDomain, DomainPurpose, DomainType } from '@/services/api/domains.service';

const VECTOR_BASE_DOMAIN = 'wazerpay.com.br';

export default function DomainsPage() {
 const queryClient = useQueryClient();
 const [showAddModal, setShowAddModal] = useState(false);
 const [newDomain, setNewDomain] = useState('');
 const [newSubdomain, setNewSubdomain] = useState('');
 const [newDomainType, setNewDomainType] = useState<DomainType>('wazerpay');
 const [newUseFor, setNewUseFor] = useState<DomainPurpose[]>(['checkout', 'members']);
 const [selectedDomain, setSelectedDomain] = useState<CustomDomain | null>(null);
 const [showTutorial, setShowTutorial] = useState(false);

 // Fetch domains
 const { data, isLoading } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });

 const domains = data?.data || [];

 // Create domain
 const createMutation = useMutation({
 mutationFn: ({ domain, useFor }: { domain: string; useFor: DomainPurpose[] }) => domainsService.create(domain, useFor),
 onSuccess: (result) => {
 toast.success(result.message || 'Domínio adicionado');
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 closeAddModal();
 if (result.data) {
 setSelectedDomain(result.data);
 }
 },
 onError: (err: any) => {
 toast.error(err.response?.data?.error?.message || err.response?.data?.message || 'Erro ao adicionar domínio');
 },
 });

 // Create Vector Pagamentos subdomain
 const createSubdomainMutation = useMutation({
 mutationFn: ({ subdomain, useFor }: { subdomain: string; useFor: DomainPurpose[] }) => domainsService.createSubdomain(subdomain, useFor),
 onSuccess: (result) => {
 toast.success(result.message || 'Subdomínio Vector Pagamentos criado!');
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 closeAddModal();
 },
 onError: (err: any) => {
 toast.error(err.response?.data?.error?.message || err.response?.data?.message || 'Erro ao criar subdomínio');
 },
 });

 const closeAddModal = () => {
 setShowAddModal(false);
 setNewDomain('');
 setNewSubdomain('');
 setNewDomainType('wazerpay');
 setNewUseFor(['checkout', 'members']);
 };

 const handleCreate = () => {
 if (newDomainType === 'wazerpay') {
 if (newSubdomain && newUseFor.length > 0) {
 createSubdomainMutation.mutate({ subdomain: newSubdomain, useFor: newUseFor });
 }
 } else {
 if (newDomain && newUseFor.length > 0) {
 createMutation.mutate({ domain: newDomain, useFor: newUseFor });
 }
 }
 };

 const isCreateDisabled = newDomainType === 'wazerpay'
 ? !newSubdomain || newUseFor.length === 0 || createSubdomainMutation.isPending
 : !newDomain || newUseFor.length === 0 || createMutation.isPending;

 const isCreating = createMutation.isPending || createSubdomainMutation.isPending;

 // Verify domain
 const verifyMutation = useMutation({
 mutationFn: (id: string) => domainsService.verify(id),
 onSuccess: (result) => {
 if (result.success) {
 toast.success(result.message || 'Domínio verificado!');
 } else {
 toast.error(result.error?.details?.join(', ') || 'Verificação falhou');
 }
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 },
 onError: (err: any) => {
 const details = err.response?.data?.error?.details;
 if (details && Array.isArray(details)) {
 toast.error(details.join('\n'));
 } else {
 toast.error(err.response?.data?.error?.message || 'Erro na verificação');
 }
 },
 });

 // Set primary
 const setPrimaryMutation = useMutation({
 mutationFn: (id: string) => domainsService.setPrimary(id),
 onSuccess: () => {
 toast.success('Domínio definido como principal');
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 },
 onError: (err: any) => {
 toast.error(err.response?.data?.error?.message || 'Erro ao definir como principal');
 },
 });

 // Delete domain
 const deleteMutation = useMutation({
 mutationFn: (id: string) => domainsService.remove(id),
 onSuccess: () => {
 toast.success('Domínio removido');
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 setSelectedDomain(null);
 },
 onError: (err: any) => {
 const errorData = err.response?.data?.error;
 if (errorData?.code === 'domain_has_linked_pages') {
 toast.error(errorData.message, { duration: 6000 });
 } else {
 toast.error(err.response?.data?.error?.message || err.response?.data?.message || 'Erro ao remover domínio');
 }
 },
 });

 // Update use_for
 const updateUseForMutation = useMutation({
 mutationFn: ({ id, useFor }: { id: string; useFor: DomainPurpose[] }) => domainsService.updateUseFor(id, useFor),
 onSuccess: (result) => {
 toast.success(result.message || 'Uso do domínio atualizado');
 queryClient.invalidateQueries({ queryKey: ['domains'] });
 if (selectedDomain && result.data) {
 setSelectedDomain(result.data);
 }
 },
 onError: (err: any) => {
 toast.error(err.response?.data?.error?.message || 'Erro ao atualizar uso do domínio');
 },
 });

 const copyToClipboard = (text: string) => {
 navigator.clipboard.writeText(text);
 toast.success('Copiado!');
 };

 const statusBadge = (status: string) => {
 switch (status) {
 case 'verified':
 return (
 <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-status-success-subtle text-status-success">
 <CheckCircleIcon className="h-3.5 w-3.5" />
 Verificado
 </span>
 );
 case 'pending':
 return (
 <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-status-warning-subtle text-status-warning">
 <ClockIcon className="h-3.5 w-3.5" />
 Pendente
 </span>
 );
 case 'failed':
 return (
 <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-status-error-subtle text-status-error">
 <ExclamationTriangleIcon className="h-3.5 w-3.5" />
 Falhou
 </span>
 );
 default:
 return (
 <span className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-full bg-surface-hover text-tx-secondary /30">
 {status}
 </span>
 );
 }
 };

 return (
 <div className="space-y-6">
 {/* Header */}
 <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
 <div>
 <h1 className="text-2xl font-bold text-tx-primary">Domínios Personalizados</h1>
 <p className="text-sm text-tx-muted mt-1">
 Configure seus próprios domínios para checkouts, páginas de vendas e área de membros.
 </p>
 </div>
 <button
 onClick={() => setShowAddModal(true)}
 className="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-green-600 to-green-700 px-4 py-2.5 text-sm font-semibold text-white hover:from-green-700 hover:to-green-800 transition-all"
 >
 <PlusIcon className="h-5 w-5" />
 Adicionar Domínio
 </button>
 </div>

 {/* Tutorial Section */}
 <div className="rounded-card border border-border-DEFAULT bg-surface-card overflow-hidden">
 <button
 onClick={() => setShowTutorial(!showTutorial)}
 className="w-full flex items-center justify-between p-4 hover:bg-surface-hover transition-colors"
 >
 <div className="flex items-center gap-3">
 <div className="rounded-lg bg-green-50 p-2">
 <BookOpenIcon className="h-5 w-5 text-green-600" />
 </div>
 <div className="text-left">
 <p className="text-sm font-semibold text-tx-primary">Tutorial: Como configurar seu domínio personalizado</p>
 <p className="text-xs text-tx-muted">Guia passo a passo com exemplos para Cloudflare, Hostinger, GoDaddy e outros</p>
 </div>
 </div>
 {showTutorial ? (
 <ChevronUpIcon className="h-5 w-5 text-tx-muted flex-shrink-0" />
 ) : (
 <ChevronDownIcon className="h-5 w-5 text-tx-muted flex-shrink-0" />
 )}
 </button>

 {showTutorial && (
 <div className="border-t border-border-DEFAULT p-4 sm:p-6 space-y-8">
 {/* Passo 1 */}
 <div className="space-y-3">
 <div className="flex items-center gap-2">
 <span className="flex items-center justify-center h-7 w-7 rounded-full bg-green-100 text-green-700 text-sm font-bold">1</span>
 <h3 className="text-base font-semibold text-tx-primary">Escolha seu domínio ou subdomínio</h3>
 </div>
 <p className="text-sm text-tx-secondary ml-0 sm:ml-9">
 Você pode usar um <strong>domínio raiz</strong> (ex: <code className="px-1.5 py-0.5 bg-surface-hover rounded text-xs font-mono">meusite.com.br</code>) ou um <strong>subdomínio</strong> (ex: <code className="px-1.5 py-0.5 bg-surface-hover rounded text-xs font-mono">pay.meusite.com.br</code>).
 </p>
 <div className="ml-0 sm:ml-9 rounded-lg bg-surface-page border border-border-DEFAULT p-4">
 <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-2">Exemplos comuns</p>
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
 <div className="flex items-center gap-2">
 <ShoppingCartIcon className="h-4 w-4 text-green-500" />
 <code className="text-tx-primary font-mono text-xs">pay.meusite.com.br</code>
 <span className="text-tx-muted text-xs">— Checkout</span>
 </div>
 <div className="flex items-center gap-2">
 <ShoppingCartIcon className="h-4 w-4 text-green-500" />
 <code className="text-tx-primary font-mono text-xs">comprar.meusite.com.br</code>
 <span className="text-tx-muted text-xs">— Checkout</span>
 </div>
 <div className="flex items-center gap-2">
 <AcademicCapIcon className="h-4 w-4 text-green-500" />
 <code className="text-tx-primary font-mono text-xs">alunos.meusite.com.br</code>
 <span className="text-tx-muted text-xs">— Membros</span>
 </div>
 <div className="flex items-center gap-2">
 <AcademicCapIcon className="h-4 w-4 text-green-500" />
 <code className="text-tx-primary font-mono text-xs">cursos.meusite.com.br</code>
 <span className="text-tx-muted text-xs">— Membros</span>
 </div>
 </div>
 </div>
 </div>

 {/* Passo 2 */}
 <div className="space-y-3">
 <div className="flex items-center gap-2">
 <span className="flex items-center justify-center h-7 w-7 rounded-full bg-green-100 text-green-700 text-sm font-bold">2</span>
 <h3 className="text-base font-semibold text-tx-primary">Adicione o domínio na Vector Pagamentos</h3>
 </div>
 <p className="text-sm text-tx-secondary ml-0 sm:ml-9">
 Clique em <strong>"Adicionar Domínio"</strong> acima, digite seu domínio e escolha se quer usar no Checkout, Área de Membros ou ambos. Após adicionar, o sistema vai gerar os registros DNS que você precisa configurar.
 </p>
 </div>

 {/* Passo 3 */}
 <div className="space-y-3">
 <div className="flex items-center gap-2">
 <span className="flex items-center justify-center h-7 w-7 rounded-full bg-green-100 text-green-700 text-sm font-bold">3</span>
 <h3 className="text-base font-semibold text-tx-primary">Configure os registros DNS</h3>
 </div>
 <p className="text-sm text-tx-secondary ml-0 sm:ml-9">
 Acesse o painel de DNS do seu provedor e adicione <strong>2 registros</strong>:
 </p>

 {/* DNS Records Table */}
 <div className="ml-0 sm:ml-9 overflow-x-auto rounded-lg border border-border-DEFAULT">
 <table className="w-full text-sm min-w-[400px]">
 <thead>
 <tr className="bg-surface-hover">
 <th className="px-3 sm:px-4 py-2.5 text-left text-xs font-semibold text-tx-muted uppercase tracking-wider">Tipo</th>
 <th className="px-3 sm:px-4 py-2.5 text-left text-xs font-semibold text-tx-muted uppercase tracking-wider">Nome / Host</th>
 <th className="px-3 sm:px-4 py-2.5 text-left text-xs font-semibold text-tx-muted uppercase tracking-wider">Valor / Destino</th>
 </tr>
 </thead>
 <tbody className="divide-y divide-border-DEFAULT">
 <tr className="bg-surface-card">
 <td className="px-3 sm:px-4 py-3">
 <span className="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700">CNAME</span>
 </td>
 <td className="px-3 sm:px-4 py-3 font-mono text-xs text-tx-primary">seu-subdominio</td>
 <td className="px-3 sm:px-4 py-3 font-mono text-xs text-tx-primary">checkout.wazerpay.com.br</td>
 </tr>
 <tr className="bg-surface-card">
 <td className="px-3 sm:px-4 py-3">
 <span className="inline-flex px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">TXT</span>
 </td>
 <td className="px-3 sm:px-4 py-3 font-mono text-xs text-tx-primary break-all">_wazerpay-verification.seudominio.com</td>
 <td className="px-3 sm:px-4 py-3 font-mono text-xs text-tx-primary break-all">wazerpay-verify=SEU_TOKEN</td>
 </tr>
 </tbody>
 </table>
 </div>

 <div className="ml-0 sm:ml-9 rounded-lg bg-amber-50 border border-amber-200 p-3">
 <p className="text-xs text-amber-800">
 <strong>Importante:</strong> Os valores exatos (host e token) serão mostrados após adicionar o domínio. A tabela acima é apenas um exemplo.
 </p>
 </div>

 {/* Provider-specific guides */}
 <div className="ml-0 sm:ml-9 space-y-3">
 <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider">Guia por provedor</p>

 {/* Cloudflare */}
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="flex items-center gap-3 px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
 <img src="https://cdn.simpleicons.org/cloudflare/F38020" alt="Cloudflare" className="h-5 w-5" />
 <span className="text-sm font-medium text-tx-primary">Cloudflare</span>
 <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
 </summary>
 <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-sm text-tx-secondary space-y-2">
 <ol className="list-decimal list-inside space-y-1.5 text-xs">
 <li>Acesse <strong>dash.cloudflare.com</strong> e selecione seu domínio</li>
 <li>Vá em <strong>DNS → Records → Add Record</strong></li>
 <li>Adicione o registro <strong>CNAME</strong>: Nome = seu subdomínio, Destino = <code className="px-1 py-0.5 bg-surface-hover rounded">checkout.wazerpay.com.br</code></li>
 <li><strong>Ative o proxy (nuvem laranja)</strong> — isso garante SSL automático</li>
 <li>Adicione o registro <strong>TXT</strong>: Nome = <code className="px-1 py-0.5 bg-surface-hover rounded">_wazerpay-verification.seudominio.com</code>, Conteúdo = seu token</li>
 <li>Aguarde 1-5 minutos e clique em <strong>"Verificar"</strong> na Vector Pagamentos</li>
 </ol>
 <div className="mt-2 p-2 rounded bg-green-50 border border-green-200">
 <p className="text-xs text-green-700"><strong>Recomendado:</strong> Cloudflare é o provedor ideal pois fornece SSL gratuito e CDN automaticamente. O proxy (nuvem laranja) DEVE estar ativo.</p>
 </div>
 </div>
 </details>

 {/* Hostinger */}
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="flex items-center gap-3 px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
 <span className="flex items-center justify-center h-5 w-5 rounded bg-green-600 text-white text-xs font-bold">H</span>
 <span className="text-sm font-medium text-tx-primary">Hostinger</span>
 <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
 </summary>
 <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-sm text-tx-secondary space-y-2">
 <ol className="list-decimal list-inside space-y-1.5 text-xs">
 <li>Acesse o <strong>hPanel</strong> e vá em <strong>Domínios → Zona DNS</strong></li>
 <li>Em <strong>"Gerenciar registros DNS"</strong>, clique em <strong>"Adicionar registro"</strong></li>
 <li>Selecione tipo <strong>CNAME</strong>, preencha o nome e destino</li>
 <li>Adicione outro registro tipo <strong>TXT</strong> com o token de verificação</li>
 <li>Salve e aguarde até 15 minutos para propagação</li>
 <li>Volte na Vector Pagamentos e clique em <strong>"Verificar"</strong></li>
 </ol>
 </div>
 </details>

 {/* GoDaddy */}
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="flex items-center gap-3 px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
 <span className="flex items-center justify-center h-5 w-5 rounded bg-black text-white text-xs font-bold">G</span>
 <span className="text-sm font-medium text-tx-primary">GoDaddy</span>
 <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
 </summary>
 <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-sm text-tx-secondary space-y-2">
 <ol className="list-decimal list-inside space-y-1.5 text-xs">
 <li>Acesse <strong>dcc.godaddy.com</strong> e selecione seu domínio</li>
 <li>Vá em <strong>DNS → DNS Records</strong></li>
 <li>Clique em <strong>"Add New Record"</strong></li>
 <li>Tipo: <strong>CNAME</strong>, Nome: seu subdomínio, Valor: <code className="px-1 py-0.5 bg-surface-hover rounded">checkout.wazerpay.com.br</code></li>
 <li>Adicione outro: Tipo: <strong>TXT</strong>, Nome e Valor conforme exibido na Vector Pagamentos</li>
 <li>Salve e aguarde entre 15-60 minutos para propagação</li>
 </ol>
 </div>
 </details>

 {/* Registro.br */}
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="flex items-center gap-3 px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
 <span className="flex items-center justify-center h-5 w-5 rounded bg-green-600 text-white text-xs font-bold">R</span>
 <span className="text-sm font-medium text-tx-primary">Registro.br</span>
 <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
 </summary>
 <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-sm text-tx-secondary space-y-2">
 <ol className="list-decimal list-inside space-y-1.5 text-xs">
 <li>Acesse <strong>registro.br</strong> e vá em <strong>seus domínios</strong></li>
 <li>Clique no domínio e vá em <strong>"DNS" → "Editar zona"</strong></li>
 <li>Clique em <strong>"Nova entrada"</strong> e adicione o CNAME e TXT</li>
 <li>Salve a zona e aguarde a propagação (pode levar até 24h)</li>
 </ol>
 <div className="mt-2 p-2 rounded bg-blue-50 border border-blue-200">
 <p className="text-xs text-blue-700"><strong>Dica:</strong> Se seu domínio .com.br está no Registro.br mas o DNS está em outro lugar (ex: Cloudflare), configure lá e não no Registro.br.</p>
 </div>
 </div>
 </details>

 {/* Outros */}
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="flex items-center gap-3 px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
 <QuestionMarkCircleIcon className="h-5 w-5 text-tx-muted" />
 <span className="text-sm font-medium text-tx-primary">Outro provedor</span>
 <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
 </summary>
 <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-sm text-tx-secondary space-y-2">
 <p className="text-xs">O processo é semelhante em qualquer provedor de DNS:</p>
 <ol className="list-decimal list-inside space-y-1.5 text-xs">
 <li>Acesse o painel de controle do DNS do seu provedor</li>
 <li>Encontre a seção de <strong>Registros DNS</strong> ou <strong>Zona DNS</strong></li>
 <li>Adicione um registro <strong>CNAME</strong> apontando para <code className="px-1 py-0.5 bg-surface-hover rounded">checkout.wazerpay.com.br</code></li>
 <li>Adicione um registro <strong>TXT</strong> com o token de verificação</li>
 <li>Salve e aguarde a propagação DNS (pode variar de minutos a 48h)</li>
 </ol>
 </div>
 </details>
 </div>
 </div>

 {/* Passo 4 */}
 <div className="space-y-3">
 <div className="flex items-center gap-2">
 <span className="flex items-center justify-center h-7 w-7 rounded-full bg-green-100 text-green-700 text-sm font-bold">4</span>
 <h3 className="text-base font-semibold text-tx-primary">Verifique o domínio</h3>
 </div>
 <p className="text-sm text-tx-secondary ml-9">
 Após configurar os DNS, volte aqui e clique no botão <strong>"Verificar"</strong> ao lado do domínio. O sistema vai consultar seus registros DNS em tempo real.
 </p>
 <div className="ml-9 grid grid-cols-1 sm:grid-cols-2 gap-3">
 <div className="flex items-start gap-2 p-3 rounded-lg bg-green-50 border border-green-200">
 <CheckCircleIcon className="h-5 w-5 text-green-600 flex-shrink-0 mt-0.5" />
 <div>
 <p className="text-xs font-semibold text-green-800">CNAME válido</p>
 <p className="text-xs text-green-700">Seu domínio aponta corretamente para a Vector Pagamentos</p>
 </div>
 </div>
 <div className="flex items-start gap-2 p-3 rounded-lg bg-green-50 border border-green-200">
 <CheckCircleIcon className="h-5 w-5 text-green-600 flex-shrink-0 mt-0.5" />
 <div>
 <p className="text-xs font-semibold text-green-800">TXT verificado</p>
 <p className="text-xs text-green-700">O token de propriedade foi confirmado</p>
 </div>
 </div>
 </div>
 </div>

 {/* Passo 5 */}
 <div className="space-y-3">
 <div className="flex items-center gap-2">
 <span className="flex items-center justify-center h-7 w-7 rounded-full bg-green-100 text-green-700 text-sm font-bold">5</span>
 <h3 className="text-base font-semibold text-tx-primary">Pronto! Domínio ativo</h3>
 </div>
 <p className="text-sm text-tx-secondary ml-9">
 Após a verificação, seu domínio ficará com status <strong>"Verificado"</strong> e estará automaticamente disponível nos serviços selecionados (Checkout e/ou Área de Membros).
 </p>
 <div className="ml-9 rounded-lg bg-surface-page border border-border-DEFAULT p-4 space-y-2">
 <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider">Onde seu domínio aparecerá</p>
 <div className="flex flex-col sm:flex-row gap-3">
 <div className="flex items-center gap-2 p-2 rounded bg-green-50">
 <ShoppingCartIcon className="h-4 w-4 text-green-600" />
 <span className="text-xs text-green-800"><strong>Checkout:</strong> Seus clientes pagam via seu domínio</span>
 </div>
 <div className="flex items-center gap-2 p-2 rounded bg-green-50">
 <AcademicCapIcon className="h-4 w-4 text-green-600" />
 <span className="text-xs text-green-800"><strong>Membros:</strong> Selecione na configuração da Área de Membros</span>
 </div>
 </div>
 </div>
 </div>

 {/* FAQ */}
 <div className="border-t border-border-DEFAULT pt-6 space-y-3">
 <h3 className="text-base font-semibold text-tx-primary">Perguntas Frequentes</h3>
 <div className="space-y-2">
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
 Quanto tempo leva a propagação DNS?
 </summary>
 <p className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary">
 Com <strong>Cloudflare</strong>: 1-5 minutos. Com outros provedores: entre 15 minutos e 48 horas. Na maioria dos casos, funciona em menos de 1 hora.
 </p>
 </details>
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
 Preciso de SSL (HTTPS)?
 </summary>
 <p className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary">
 Sim, e o SSL é fornecido automaticamente pelo <strong>Cloudflare</strong>. Se seu domínio passa pelo Cloudflare com o proxy ativado (nuvem laranja), o HTTPS funciona sem configuração extra.
 </p>
 </details>
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
 Posso usar o mesmo domínio no Checkout e na Área de Membros?
 </summary>
 <p className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary">
 Sim! Ao adicionar o domínio, marque ambas as opções. Recomendamos usar subdomínios diferentes para cada serviço (ex: <code className="px-1 py-0.5 bg-surface-hover rounded">pay.meusite.com</code> para checkout e <code className="px-1 py-0.5 bg-surface-hover rounded">alunos.meusite.com</code> para membros).
 </p>
 </details>
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
 A verificação falhou. O que fazer?
 </summary>
 <p className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary">
 Verifique: 1) Se o registro CNAME aponta para <code className="px-1 py-0.5 bg-surface-hover rounded">checkout.wazerpay.com.br</code> corretamente. 2) Se o registro TXT foi criado com o nome e valor exatos mostrados aqui. 3) Aguarde a propagação e tente novamente. Use <a href="https://dnschecker.org" target="_blank" rel="noopener noreferrer" className="text-accent-primary underline">dnschecker.org</a> para verificar se os registros já propagaram.
 </p>
 </details>
 <details className="group rounded-lg border border-border-DEFAULT overflow-hidden">
 <summary className="px-4 py-3 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
 Posso usar um domínio raiz (sem subdomínio)?
 </summary>
 <p className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary">
 Tecnicamente sim, mas <strong>não recomendamos</strong>. Domínios raiz (ex: <code className="px-1 py-0.5 bg-surface-hover rounded">meusite.com.br</code>) não suportam CNAME em todos os provedores. Use subdomínios como <code className="px-1 py-0.5 bg-surface-hover rounded">pay.meusite.com.br</code> para melhor compatibilidade. Se usar Cloudflare, domínios raiz funcionam normalmente com CNAME flattening.
 </p>
 </details>
 </div>
 </div>
 </div>
 )}
 </div>

 {/* Domains List */}
 <div className="rounded-card border border-border-DEFAULT bg-surface-card ">
 {isLoading ? (
 <div className="flex items-center justify-center py-12">
 <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
 </div>
 ) : domains.length === 0 ? (
 <div className="py-12 text-center">
 <GlobeAltIcon className="mx-auto h-12 w-12 text-tx-muted" />
 <p className="mt-4 text-tx-muted">Nenhum domínio configurado</p>
 <p className="mt-1 text-sm text-tx-muted">
 Adicione seu primeiro domínio personalizado para começar
 </p>
 </div>
 ) : (
 <div className="divide-y divide-border-DEFAULT">
 {domains.map((domain) => (
 <div
 key={domain.id}
 className="p-5 hover:bg-surface-page transition-colors cursor-pointer"
 onClick={() => setSelectedDomain(selectedDomain?.id === domain.id ? null : domain)}
 >
 <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
 <div className="flex items-center gap-3">
 <div className={`rounded-lg p-2 shrink-0 ${domain.status === 'verified' ? 'bg-status-success-subtle' : 'bg-surface-hover '}`}>
 <GlobeAltIcon className={`h-5 w-5 ${domain.status === 'verified' ? 'text-status-success' : 'text-tx-muted'}`} />
 </div>
 <div className="min-w-0">
 <div className="flex items-center gap-2 flex-wrap">
 <span className="font-semibold text-tx-primary break-all">{domain.domain}</span>
 {domain.type === 'wazerpay' && (
 <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-50 text-emerald-700">
 Vector Pagamentos
 </span>
 )}
 {domain.is_primary && (
 <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-accent-subtle text-accent-primary">
 <StarIcon className="h-3 w-3" />
 Principal
 </span>
 )}
 </div>
 <p className="text-sm text-tx-muted">
 {domain.type === 'wazerpay'
 ? 'Subdomínio Vector Pagamentos • Verificado automaticamente'
 : domain.verified_at
 ? `Verificado em ${new Date(domain.verified_at).toLocaleDateString('pt-BR')}`
 : 'Aguardando verificação DNS'}
 </p>
 </div>
 </div>
 <div className="flex items-center gap-2 flex-wrap mt-3 sm:mt-0">
 {/* Use_for badges */}
 {domain.use_for?.includes('checkout') && (
 <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-green-50 text-green-700">
 <ShoppingCartIcon className="h-3 w-3" />
 Checkout
 </span>
 )}
 {domain.use_for?.includes('members') && (
 <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-green-50 text-green-700">
 <AcademicCapIcon className="h-3 w-3" />
 Membros
 </span>
 )}
 {statusBadge(domain.status)}
 {domain.status !== 'verified' && domain.type !== 'wazerpay' && (
 <button
 onClick={(e) => {
 e.stopPropagation();
 verifyMutation.mutate(domain.id);
 }}
 disabled={verifyMutation.isPending}
 className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-accent-subtle text-accent-primary hover:bg-blue-200 transition-colors disabled:opacity-50"
 >
 <ArrowPathIcon className={`h-3.5 w-3.5 ${verifyMutation.isPending ? 'animate-spin' : ''}`} />
 Verificar
 </button>
 )}
 {domain.status === 'verified' && !domain.is_primary && (
 <button
 onClick={(e) => {
 e.stopPropagation();
 setPrimaryMutation.mutate(domain.id);
 }}
 className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-accent-subtle text-accent-primary hover:bg-accent-subtle transition-colors"
 >
 <StarIcon className="h-3.5 w-3.5" />
 <span className="hidden sm:inline">Definir Principal</span>
 <span className="sm:hidden">Principal</span>
 </button>
 )}
 <button
 onClick={(e) => {
 e.stopPropagation();
 if (confirm('Tem certeza que deseja remover este domínio?')) {
 deleteMutation.mutate(domain.id);
 }
 }}
 className="p-1.5 text-tx-muted hover:text-status-error transition-colors rounded-element hover:bg-status-error-subtle"
 >
 <TrashIcon className="h-4 w-4" />
 </button>
 </div>
 </div>

 {/* DNS Config (expandable) - only for custom domains */}
 {selectedDomain?.id === domain.id && domain.dns_config && domain.type !== 'wazerpay' && (
 <div className="mt-4 space-y-3 border-t border-border-DEFAULT pt-4">
 <p className="text-sm font-medium text-tx-secondary">
 Configuração DNS necessária:
 </p>

 {/* CNAME Record */}
 <div className="rounded-element border border-border-DEFAULT p-3 bg-surface-page /50">
 <div className="flex items-center justify-between mb-2">
 <span className="text-xs font-semibold text-tx-muted uppercase tracking-wider">
 Registro CNAME
 </span>
 <button
 onClick={(e) => {
 e.stopPropagation();
 copyToClipboard(domain.dns_config!.cname.value);
 }}
 className="text-xs text-accent-primary hover:text-accent-primary flex items-center gap-1"
 >
 <ClipboardDocumentIcon className="h-3.5 w-3.5" />
 Copiar
 </button>
 </div>
 <div className="grid grid-cols-3 gap-2 text-sm">
 <div>
 <span className="text-xs text-tx-muted">Tipo</span>
 <p className="font-mono text-tx-primary">CNAME</p>
 </div>
 <div>
 <span className="text-xs text-tx-muted">Host</span>
 <p className="font-mono text-tx-primary break-all">{domain.dns_config.cname.host}</p>
 </div>
 <div>
 <span className="text-xs text-tx-muted">Valor</span>
 <p className="font-mono text-tx-primary break-all">{domain.dns_config.cname.value}</p>
 </div>
 </div>
 </div>

 {/* TXT Record */}
 <div className="rounded-element border border-border-DEFAULT p-3 bg-surface-page /50">
 <div className="flex items-center justify-between mb-2">
 <span className="text-xs font-semibold text-tx-muted uppercase tracking-wider">
 Registro TXT (Verificação)
 </span>
 <button
 onClick={(e) => {
 e.stopPropagation();
 copyToClipboard(domain.dns_config!.txt.value);
 }}
 className="text-xs text-accent-primary hover:text-accent-primary flex items-center gap-1"
 >
 <ClipboardDocumentIcon className="h-3.5 w-3.5" />
 Copiar
 </button>
 </div>
 <div className="grid grid-cols-3 gap-2 text-sm">
 <div>
 <span className="text-xs text-tx-muted">Tipo</span>
 <p className="font-mono text-tx-primary">TXT</p>
 </div>
 <div>
 <span className="text-xs text-tx-muted">Host</span>
 <p className="font-mono text-tx-primary break-all">{domain.dns_config.txt.host}</p>
 </div>
 <div>
 <span className="text-xs text-tx-muted">Valor</span>
 <p className="font-mono text-tx-primary break-all">{domain.dns_config.txt.value}</p>
 </div>
 </div>
 </div>

 <p className="text-xs text-tx-muted italic">
 A propagação DNS pode levar até 48 horas. Aguarde e tente verificar novamente.
 </p>
 </div>
 )}

 {/* Verified domain info */}
 {selectedDomain?.id === domain.id && domain.status === 'verified' && (
 <div className="mt-4 border-t border-border-DEFAULT pt-4">
 <div className="flex items-center gap-2 text-sm text-status-success">
 <ShieldCheckIcon className="h-5 w-5" />
 <span>Domínio verificado e pronto para uso no {domain.use_for?.join(' e ').replace('checkout', 'Checkout').replace('members', 'Área de Membros') || 'Checkout e Área de Membros'}.</span>
 </div>

 {/* Use_for toggles */}
 <div className="mt-3 space-y-2">
 <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider">Usar em:</p>
 <div className="flex gap-4">
 <label className="flex items-center gap-2 cursor-pointer" onClick={(e) => e.stopPropagation()}>
 <input
 type="checkbox"
 checked={domain.use_for?.includes('checkout') ?? true}
 onChange={(e) => {
 const current = domain.use_for || ['checkout', 'members'];
 const updated = e.target.checked
 ? [...current, 'checkout' as DomainPurpose]
 : current.filter(u => u !== 'checkout');
 if (updated.length > 0) updateUseForMutation.mutate({ id: domain.id, useFor: updated });
 else toast.error('O domínio deve ter pelo menos um uso');
 }}
 className="rounded border-gray-300 text-green-600 focus:ring-green-500"
 />
 <ShoppingCartIcon className="h-4 w-4 text-green-600" />
 <span className="text-sm text-tx-secondary">Checkout</span>
 </label>
 <label className="flex items-center gap-2 cursor-pointer" onClick={(e) => e.stopPropagation()}>
 <input
 type="checkbox"
 checked={domain.use_for?.includes('members') ?? true}
 onChange={(e) => {
 const current = domain.use_for || ['checkout', 'members'];
 const updated = e.target.checked
 ? [...current, 'members' as DomainPurpose]
 : current.filter(u => u !== 'members');
 if (updated.length > 0) updateUseForMutation.mutate({ id: domain.id, useFor: updated });
 else toast.error('O domínio deve ter pelo menos um uso');
 }}
 className="rounded border-gray-300 text-green-600 focus:ring-green-500"
 />
 <AcademicCapIcon className="h-4 w-4 text-green-600" />
 <span className="text-sm text-tx-secondary">Área de Membros</span>
 </label>
 </div>
 </div>
 {domain.ssl_enabled && (
 <p className="mt-2 text-xs text-tx-muted flex items-center gap-1">
 <ShieldCheckIcon className="h-3.5 w-3.5 text-status-success" />
 SSL ativo
 </p>
 )}
 </div>
 )}
 </div>
 ))}
 </div>
 )}
 </div>

 {/* Add Domain Modal */}
 {showAddModal && (
 <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
 <div className="bg-surface-card rounded-2xl shadow-2xl w-full max-w-md p-6">
 <div className="flex items-center justify-between mb-6">
 <h2 className="text-lg font-bold text-tx-primary">Adicionar Domínio</h2>
 <button
 onClick={closeAddModal}
 className="text-tx-muted hover:text-tx-secondary"
 >
 <XMarkIcon className="h-5 w-5" />
 </button>
 </div>

 <div className="space-y-4">
 {/* Domain Type Tabs */}
 <div className="flex rounded-lg border border-border-DEFAULT overflow-hidden">
 <button
 onClick={() => setNewDomainType('wazerpay')}
 className={`flex-1 px-4 py-2.5 text-sm font-medium transition-colors ${
 newDomainType === 'wazerpay'
 ? 'bg-emerald-50 text-emerald-700 border-r border-border-DEFAULT'
 : 'bg-surface-page text-tx-muted hover:bg-surface-hover border-r border-border-DEFAULT'
 }`}
 >
 Subdomínio Vector Pagamentos
 </button>
 <button
 onClick={() => setNewDomainType('custom')}
 className={`flex-1 px-4 py-2.5 text-sm font-medium transition-colors ${
 newDomainType === 'custom'
 ? 'bg-accent-subtle text-accent-primary'
 : 'bg-surface-page text-tx-muted hover:bg-surface-hover'
 }`}
 >
 Domínio Próprio
 </button>
 </div>

 {/* Vector Pagamentos Subdomain Input */}
 {newDomainType === 'wazerpay' && (
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">
 Escolha seu subdomínio
 </label>
 <div className="flex items-center gap-0">
 <input
 type="text"
 value={newSubdomain}
 onChange={(e) => setNewSubdomain(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
 placeholder="meusite"
 className="flex-1 rounded-l-element border border-r-0 border-border-DEFAULT bg-surface-card px-4 py-2.5 text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none"
 />
 <span className="inline-flex items-center px-3 py-2.5 border border-border-DEFAULT bg-surface-page rounded-r-element text-sm text-tx-muted">
 .{VECTOR_BASE_DOMAIN}
 </span>
 </div>
 {newSubdomain && (
 <p className="mt-1.5 text-xs text-emerald-600 font-medium">
 Seu endereço será: <strong>{newSubdomain}.{VECTOR_BASE_DOMAIN}</strong>
 </p>
 )}
 <p className="mt-1 text-xs text-tx-muted">
 Mínimo 3 caracteres. Apenas letras, números e hífens.
 </p>
 <div className="mt-2 rounded-lg bg-emerald-50 border border-emerald-200 p-3">
 <p className="text-xs text-emerald-700">
 <strong>Gratuito e instantâneo!</strong> Subdomínios Vector Pagamentos são verificados automaticamente, sem necessidade de configuração DNS.
 </p>
 </div>
 </div>
 )}

 {/* Custom Domain Input */}
 {newDomainType === 'custom' && (
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">
 Domínio ou subdomínio
 </label>
 <input
 type="text"
 value={newDomain}
 onChange={(e) => setNewDomain(e.target.value.toLowerCase())}
 placeholder="vendas.meusite.com"
 className="w-full rounded-element border border-border-DEFAULT bg-surface-card px-4 py-2.5 text-tx-primary placeholder:text-tx-muted focus:border-accent-primary focus:ring-2 focus:ring-accent-primary/20 focus:outline-none"
 />
 <p className="mt-1.5 text-xs text-tx-muted">
 Exemplo: vendas.meusite.com, lp.meusite.com, meusite.com.br
 </p>
 </div>
 )}

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Usar em
 </label>
 <div className="flex gap-4">
 <label className="flex items-center gap-2 cursor-pointer">
 <input
 type="checkbox"
 checked={newUseFor.includes('checkout')}
 onChange={(e) => {
 setNewUseFor(prev =>
 e.target.checked
 ? [...prev, 'checkout' as DomainPurpose]
 : prev.filter(u => u !== 'checkout')
 );
 }}
 className="rounded border-gray-300 text-green-600 focus:ring-green-500"
 />
 <ShoppingCartIcon className="h-4 w-4 text-green-600" />
 <span className="text-sm text-tx-secondary">Checkout</span>
 </label>
 <label className="flex items-center gap-2 cursor-pointer">
 <input
 type="checkbox"
 checked={newUseFor.includes('members')}
 onChange={(e) => {
 setNewUseFor(prev =>
 e.target.checked
 ? [...prev, 'members' as DomainPurpose]
 : prev.filter(u => u !== 'members')
 );
 }}
 className="rounded border-gray-300 text-green-600 focus:ring-green-500"
 />
 <AcademicCapIcon className="h-4 w-4 text-green-600" />
 <span className="text-sm text-tx-secondary">Área de Membros</span>
 </label>
 </div>
 <p className="mt-1.5 text-xs text-tx-muted">
 Por padrão, o domínio fica disponível para ambos os serviços.
 </p>
 </div>

 {newDomainType === 'custom' && (
 <div className="rounded-lg bg-status-warning-subtle border border-amber-200 p-3">
 <p className="text-xs text-status-warning">
 <strong>Importante:</strong> Você precisará ter acesso ao painel DNS do seu domínio para configurar os registros CNAME e TXT.
 </p>
 </div>
 )}

 <div className="flex gap-3 pt-2">
 <button
 onClick={closeAddModal}
 className="flex-1 px-4 py-2.5 text-sm font-medium text-tx-secondary bg-surface-hover rounded-element hover:bg-surface-hover transition-colors"
 >
 Cancelar
 </button>
 <button
 onClick={handleCreate}
 disabled={isCreateDisabled}
 className={`flex-1 px-4 py-2.5 text-sm font-semibold text-white rounded-element transition-all disabled:opacity-50 disabled:cursor-not-allowed ${
 newDomainType === 'wazerpay'
 ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700'
 : 'bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800'
 }`}
 >
 {isCreating ? 'Criando...' : newDomainType === 'wazerpay' ? 'Criar Subdomínio' : 'Adicionar Domínio'}
 </button>
 </div>
 </div>
 </div>
 </div>
 )}
 </div>
 );
}


