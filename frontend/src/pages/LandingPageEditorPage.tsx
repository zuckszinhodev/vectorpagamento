import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
 ArrowLeftIcon,
 EyeIcon,
 DevicePhoneMobileIcon,
 ComputerDesktopIcon,
 PaperAirplaneIcon,
 SparklesIcon,
 GlobeAltIcon,
 ArrowTopRightOnSquareIcon,
 LinkIcon,
 XMarkIcon,
 CursorArrowRaysIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { landingPagesService } from '@/services/api/landingPages.service';
import { domainsService } from '@/services/api/domains.service';

interface ChatMessage {
 id: string;
 role: 'user' | 'assistant';
 content: string;
 timestamp: Date;
 isLoading?: boolean;
}

const quickActions = [
 { label: 'Mudar cores para mais vibrante', icon: '\u{1F3A8}' },
 { label: 'Melhorar o titulo principal', icon: '\u{270F}\u{FE0F}' },
 { label: 'Adicionar mais depoimentos', icon: '\u{1F4AC}' },
 { label: 'Mudar tom para urgente', icon: '\u{26A1}' },
 { label: 'Melhorar texto do botao CTA', icon: '\u{1F3AF}' },
 { label: 'Adicionar secao de FAQ', icon: '\u{2753}' },
 { label: 'Reescrever beneficios', icon: '\u{2705}' },
 { label: 'Mudar fundo para escuro', icon: '\u{1F319}' },
];

export default function LandingPageEditorPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const chatEndRef = useRef<HTMLDivElement>(null);
 const inputRef = useRef<HTMLTextAreaElement>(null);

 const [messages, setMessages] = useState<ChatMessage[]>([]);
 const [inputValue, setInputValue] = useState('');
 const [previewHtml, setPreviewHtml] = useState('');
 const [isPreviewLoading, setIsPreviewLoading] = useState(false);
 const [viewMode, setViewMode] = useState<'desktop' | 'mobile'>('desktop');
 const [showDomainDropdown, setShowDomainDropdown] = useState(false);
 const [inspectMode, setInspectMode] = useState(false);
 const iframeRef = useRef<HTMLIFrameElement>(null);

 // Fetch landing page
 const { data, isLoading, error } = useQuery({
 queryKey: ['landing-page', id],
 queryFn: () => landingPagesService.get(id!),
 enabled: !!id,
 });

 const landingPage = data?.data;

 // Fetch verified domains
 const { data: domainsData } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });

 const verifiedDomains = (domainsData?.data || []).filter((d: any) => d.status === 'verified');

 // Set domain mutation
 const domainMutation = useMutation({
 mutationFn: async (domain: string | null) => {
 return domainsService.setLandingPageDomain(id!, domain);
 },
 onSuccess: () => {
 queryClient.invalidateQueries({ queryKey: ['landing-page', id] });
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 setShowDomainDropdown(false);
 toast.success(
 'Domínio atualizado!'
 );
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao configurar domínio');
 },
 });

 // Initialize welcome message
 useEffect(() => {
 if (landingPage && messages.length === 0) {
 const sections = landingPage.content?.sections || [];
 const labelMap: Record<string, string> = {
 hero: 'Hero', benefits: 'Beneficios', testimonials: 'Depoimentos',
 pricing: 'Precos', faq: 'FAQ', guarantee: 'Garantia', cta: 'CTA',
 features: 'Funcionalidades', video: 'Video', about: 'Sobre',
 };
 const sectionNames = sections.map((s: any) => labelMap[s.type] || s.type).join(', ');

 setMessages([{
 id: 'welcome',
 role: 'assistant',
 content: `Ola! Eu sou seu assistente para editar a landing page **"${landingPage.name}"**.\n\nSua pagina tem ${sections.length} secoes: ${sectionNames}.\n\nMe diga o que voce quer mudar! Posso alterar textos, cores, adicionar ou remover secoes, melhorar o copy, e muito mais.`,
 timestamp: new Date(),
 }]);
 }
 }, [landingPage]);

 // Load preview
 const loadPreview = async () => {
 if (!id) return;
 setIsPreviewLoading(true);
 try {
 const response = await landingPagesService.preview(id);
 setPreviewHtml(response.data?.html || '');
 } catch {
 // Preview may not be available
 } finally {
 setIsPreviewLoading(false);
 }
 };

 useEffect(() => {
 if (id) loadPreview();
 }, [id]);

 // Scroll to bottom on new messages
 useEffect(() => {
 chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
 }, [messages]);

 // Send inspect mode toggle to iframe via postMessage
 useEffect(() => {
 const iframe = iframeRef.current;
 if (iframe?.contentWindow) {
 iframe.contentWindow.postMessage({ type: 'toggle-inspect', enabled: inspectMode }, window.location.origin);
 }
 }, [inspectMode]);

 // Listen for inspect clicks from iframe
 useEffect(() => {
 const sectionLabels: Record<string, string> = {
 hero: 'Hero', benefits: 'Benefícios', testimonials: 'Depoimentos',
 pricing: 'Preços', faq: 'FAQ', guarantee: 'Garantia', cta: 'CTA',
 features: 'Funcionalidades', video: 'Vídeo', about: 'Sobre',
 };

 const handleMessage = (e: MessageEvent) => {
 if (e.origin !== window.location.origin) return;
 if (e.data?.type === 'inspect-click') {
 const { sectionType, elementType } = e.data;
 const label = sectionLabels[sectionType] || sectionType;

 let prefix = `Na seção "${label}": `;
 if (elementType === 'image') prefix = `Na seção "${label}", mude a imagem para `;
 else if (elementType === 'title') prefix = `Na seção "${label}", mude o título para `;
 else if (elementType === 'text') prefix = `Na seção "${label}", mude o texto para `;
 else if (elementType === 'button') prefix = `Na seção "${label}", mude o botão para `;

 setInputValue(prefix);
 setInspectMode(false);
 inputRef.current?.focus();
 }
 };

 window.addEventListener('message', handleMessage);
 return () => window.removeEventListener('message', handleMessage);
 }, []);

 // Chat edit mutation
 const chatMutation = useMutation({
 mutationFn: async (message: string) => {
 const history = messages
 .filter(m => m.id !== 'welcome' && !m.isLoading)
 .map(m => ({ role: m.role, content: m.content }));

 return landingPagesService.chatEdit(id!, message, history);
 },
 onSuccess: (response) => {
 setMessages(prev => {
 const withoutLoading = prev.filter(m => !m.isLoading);
 return [...withoutLoading, {
 id: `ai-${Date.now()}`,
 role: 'assistant' as const,
 content: 'Alteracoes aplicadas! Veja o preview atualizado ao lado.\n\nO que mais voce gostaria de mudar?',
 timestamp: new Date(),
 }];
 });

 if (response.data?.html) {
 setPreviewHtml(response.data.html);
 }

 queryClient.invalidateQueries({ queryKey: ['landing-page', id] });
 queryClient.invalidateQueries({ queryKey: ['landing-pages'] });
 },
 onError: (error: any) => {
 const errorMsg = error.response?.data?.message || 'Erro ao processar sua solicitacao';
 setMessages(prev => {
 const withoutLoading = prev.filter(m => !m.isLoading);
 return [...withoutLoading, {
 id: `error-${Date.now()}`,
 role: 'assistant' as const,
 content: `Erro: ${errorMsg}\n\nTente novamente com uma instrucao diferente.`,
 timestamp: new Date(),
 }];
 });
 toast.error('Erro ao editar com IA');
 },
 });

 const handleSendMessage = (text?: string) => {
 const message = (text || inputValue).trim();
 if (!message || chatMutation.isPending) return;

 const userMsg: ChatMessage = {
 id: `user-${Date.now()}`,
 role: 'user',
 content: message,
 timestamp: new Date(),
 };

 const loadingMsg: ChatMessage = {
 id: `loading-${Date.now()}`,
 role: 'assistant',
 content: '',
 timestamp: new Date(),
 isLoading: true,
 };

 setMessages(prev => [...prev, userMsg, loadingMsg]);
 setInputValue('');

 chatMutation.mutate(message);
 };

 const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
 if (e.key === 'Enter' && !e.shiftKey) {
 e.preventDefault();
 handleSendMessage();
 }
 };

 const handleOpenUrl = () => {
 if (!landingPage) return;
 if (landingPage.custom_domain) {
 window.open(`https://${landingPage.custom_domain}`, '_blank');
 } else {
 const backendUrl = (import.meta as any).env?.VITE_API_URL?.replace('/api/v1', '') || '';
 window.open(`${backendUrl}/lp/${landingPage.slug}`, '_blank');
 }
 };

 const handlePreviewUrl = () => {
 if (!landingPage) return;
 const backendUrl = (import.meta as any).env?.VITE_API_URL?.replace('/api/v1', '') || '';
 window.open(`${backendUrl}/lp/${landingPage.slug}/preview`, '_blank');
 };

 // Inject inspect overlay script into preview HTML
 const getPreviewHtml = () => {
 if (!previewHtml) return '';
 const inspectScript = `<script>
(function(){
 var enabled=false;
 var ov=document.createElement('div');
 ov.style.cssText='position:fixed;pointer-events:none;border:2px solid #10b981;background:rgba(139,92,246,0.08);z-index:99999;display:none;transition:all 0.1s ease;border-radius:4px;';
 document.body.appendChild(ov);
 var lb=document.createElement('div');
 lb.style.cssText='position:fixed;pointer-events:none;z-index:100000;display:none;background:#10b981;color:#fff;font-size:11px;font-weight:600;padding:2px 8px;border-radius:4px 4px 0 0;font-family:Inter,sans-serif;white-space:nowrap;';
 document.body.appendChild(lb);
 var labels={hero:'Hero',benefits:'Benefícios',testimonials:'Depoimentos',pricing:'Preços',faq:'FAQ',guarantee:'Garantia',cta:'CTA',features:'Funcionalidades',video:'Vídeo',about:'Sobre'};
 window.addEventListener('message',function(e){
 if(e.data&&e.data.type==='toggle-inspect'){
 enabled=e.data.enabled;
 if(!enabled){ov.style.display='none';lb.style.display='none';document.body.style.cursor='';}
 else{document.body.style.cursor='crosshair';}
 }
 });
 document.addEventListener('mousemove',function(e){
 if(!enabled)return;
 var sec=e.target.closest('section[data-section-type]');
 if(sec){
 var r=sec.getBoundingClientRect();
 ov.style.display='block';ov.style.top=r.top+'px';ov.style.left=r.left+'px';ov.style.width=r.width+'px';ov.style.height=r.height+'px';
 var t=sec.getAttribute('data-section-type');
 lb.textContent=labels[t]||t;lb.style.display='block';lb.style.top=(r.top-22)+'px';lb.style.left=r.left+'px';
 }else{ov.style.display='none';lb.style.display='none';}
 });
 document.addEventListener('click',function(e){
 if(!enabled)return;
 e.preventDefault();e.stopPropagation();
 var sec=e.target.closest('section[data-section-type]');
 if(sec){
 var el=e.target;var et='section';
 if(el.tagName==='IMG')et='image';
 else if(/^H[1-6]$/.test(el.tagName)||el.closest('h1,h2,h3'))et='title';
 else if(el.tagName==='P')et='text';
 else if(el.tagName==='A'||el.tagName==='BUTTON'||el.closest('a,button'))et='button';
 window.parent.postMessage({type:'inspect-click',sectionType:sec.getAttribute('data-section-type'),sectionId:sec.id,elementType:et},window.location.origin);
 }
 },true);
})();
<\/script>`;
 return previewHtml.replace('</body>', inspectScript + '</body>');
 };

 if (isLoading) {
 return (
 <div className="flex items-center justify-center h-[60vh]">
 <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-accent-primary" />
 </div>
 );
 }

 if (error || !landingPage) {
 return (
 <div className="flex flex-col items-center justify-center h-[60vh] text-tx-muted">
 <p className="text-lg font-medium mb-2">Landing page nao encontrada</p>
 <button onClick={() => navigate('/landing-pages')} className="text-accent-primary hover:underline">
 Voltar para a lista
 </button>
 </div>
 );
 }

 return (
 <div className="h-[calc(100vh-64px)] flex flex-col">
 {/* Top Bar */}
 <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 px-3 sm:px-4 py-2.5 border-b border-border-DEFAULT bg-surface-card shrink-0">
 <div className="flex items-center gap-3">
 <button
 onClick={() => navigate('/landing-pages')}
 className="p-2 rounded-element hover:bg-surface-hover"
 >
 <ArrowLeftIcon className="h-5 w-5 text-tx-secondary" />
 </button>
 <div>
 <h1 className="text-sm font-bold text-tx-primary flex items-center gap-2">
 <SparklesIcon className="h-4 w-4 text-accent-primary" />
 {'Editor IA \u2014 ' + landingPage.name}
 </h1>
 <div className="flex items-center gap-1.5">
 <p className="text-xs text-tx-muted">/{landingPage.slug}</p>
 {landingPage.custom_domain && (
 <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-medium bg-status-success-subtle text-status-success rounded">
 <GlobeAltIcon className="h-3 w-3" />
 {landingPage.custom_domain}
 </span>
 )}
 <div className="relative">
 <button
 onClick={() => setShowDomainDropdown(!showDomainDropdown)}
 className="p-0.5 rounded hover:bg-surface-hover text-tx-muted hover:text-accent-primary"
 title="Configurar domínio personalizado"
 >
 <LinkIcon className="h-3.5 w-3.5" />
 </button>
 {showDomainDropdown && (
 <div className="absolute top-full left-0 mt-1 w-72 bg-surface-card border border-border-DEFAULT rounded-lg shadow-xl z-50 p-3">
 <div className="flex items-center justify-between mb-2">
 <h3 className="text-xs font-semibold text-tx-secondary">Domínio Personalizado</h3>
 <button onClick={() => setShowDomainDropdown(false)} className="text-tx-muted hover:text-tx-secondary">
 <XMarkIcon className="h-4 w-4" />
 </button>
 </div>

 {verifiedDomains.length === 0 ? (
 <div className="text-center py-3">
 <GlobeAltIcon className="h-8 w-8 text-tx-muted mx-auto mb-1.5" />
 <p className="text-xs text-tx-muted mb-2">Nenhum domínio verificado</p>
 <Link
 to="/domains"
 className="text-xs text-accent-primary hover:text-accent-primary font-medium hover:underline"
 >
 Adicionar domínio →
 </Link>
 </div>
 ) : (
 <div className="space-y-1.5">
 {/* Option to remove domain */}
 {landingPage.custom_domain && (
 <button
 onClick={() => domainMutation.mutate(null)}
 disabled={domainMutation.isPending}
 className="w-full flex items-center gap-2 px-2.5 py-2 text-xs text-status-error hover:bg-status-error-subtle rounded-md transition-colors disabled:opacity-50"
 >
 <XMarkIcon className="h-3.5 w-3.5" />
 Remover domínio personalizado
 </button>
 )}

 {/* Verified domains list */}
 {verifiedDomains.map((domain: any) => (
 <button
 key={domain.id}
 onClick={() => domainMutation.mutate(domain.domain)}
 disabled={domainMutation.isPending || landingPage.custom_domain === domain.domain}
 className={`w-full flex items-center gap-2 px-2.5 py-2 text-xs rounded-md transition-colors disabled:opacity-50 ${
 landingPage.custom_domain === domain.domain
 ? 'bg-accent-subtle text-accent-primary font-medium'
 : 'text-tx-secondary hover:bg-surface-hover'
 }`}
 >
 <GlobeAltIcon className="h-3.5 w-3.5 shrink-0" />
 <span className="truncate">{domain.domain}</span>
 {landingPage.custom_domain === domain.domain && (
 <span className="ml-auto text-[10px] text-accent-primary">Ativo</span>
 )}
 </button>
 ))}

 <div className="border-t border-border-subtle pt-1.5 mt-1.5">
 <Link
 to="/domains"
 className="block text-center text-[10px] text-tx-muted hover:text-accent-primary"
 >
 Gerenciar domínios
 </Link>
 </div>
 </div>
 )}
 </div>
 )}
 </div>
 </div>
 </div>
 </div>

 <div className="flex items-center gap-2">
 {/* Inspect mode toggle */}
 <button
 onClick={() => setInspectMode(!inspectMode)}
 className={`p-1.5 rounded-element transition-colors ${
 inspectMode
 ? 'bg-accent-subtle text-accent-primary ring-2 ring-accent-primary'
 : 'hover:bg-surface-hover text-tx-muted'
 }`}
 title={inspectMode ? 'Desativar modo inspeção' : 'Inspecionar elemento'}
 >
 <CursorArrowRaysIcon className="h-5 w-5" />
 </button>

 {/* Device toggle */}
 <div className="flex items-center bg-surface-hover rounded-lg p-0.5">
 <button
 onClick={() => setViewMode('desktop')}
 className={`p-1.5 rounded-md transition-colors ${viewMode === 'desktop' ? 'bg-surface-card' : ''}`}
 >
 <ComputerDesktopIcon className="h-4 w-4 text-tx-secondary" />
 </button>
 <button
 onClick={() => setViewMode('mobile')}
 className={`p-1.5 rounded-md transition-colors ${viewMode === 'mobile' ? 'bg-surface-card' : ''}`}
 >
 <DevicePhoneMobileIcon className="h-4 w-4 text-tx-secondary" />
 </button>
 </div>

 {/* Preview full page in new tab */}
 <button
 onClick={handlePreviewUrl}
 className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-accent-primary bg-accent-subtle rounded-element hover:bg-accent-subtle"
 title="Visualizar página completa em nova aba"
 >
 <EyeIcon className="h-3.5 w-3.5" />
 Visualizar página
 </button>

 {landingPage.status === 'published' && (
 <button
 onClick={handleOpenUrl}
 className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-status-success bg-status-success-subtle rounded-element hover:bg-status-success-subtle"
 >
 <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5" />
 Abrir ao vivo
 </button>
 )}
 </div>
 </div>

 {/* Main Content: Chat Left + Preview Right */}
 <div className="flex flex-col sm:flex-row flex-1 overflow-hidden">
 {/* Left Panel - Chat */}
 <div className="w-full sm:w-[400px] shrink-0 border-b sm:border-b-0 sm:border-r border-border-DEFAULT bg-surface-card flex flex-col max-h-[50vh] sm:max-h-none">
 {/* Chat Messages */}
 <div className="flex-1 overflow-y-auto p-4 space-y-4">
 {messages.map((msg) => (
 <div key={msg.id} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
 {msg.isLoading ? (
 <div className="flex items-center gap-2 px-4 py-3 bg-surface-hover rounded-2xl rounded-bl-sm max-w-[85%]">
 <div className="flex gap-1">
 <div className="w-2 h-2 bg-accent-subtle0 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
 <div className="w-2 h-2 bg-accent-subtle0 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
 <div className="w-2 h-2 bg-accent-subtle0 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
 </div>
 <span className="text-xs text-tx-muted ml-1">A IA esta editando...</span>
 </div>
 ) : (
 <div
 className={`px-4 py-2.5 rounded-2xl max-w-[85%] text-sm leading-relaxed whitespace-pre-wrap ${
 msg.role === 'user'
 ? 'bg-accent-primary text-white rounded-br-sm'
 : 'bg-surface-hover text-tx-primary rounded-bl-sm'
 }`}
 >
 {msg.content.split('**').map((part, i) =>
 i % 2 === 1 ? <strong key={i}>{part}</strong> : <span key={i}>{part}</span>
 )}
 </div>
 )}
 </div>
 ))}
 <div ref={chatEndRef} />
 </div>

 {/* Quick Actions */}
 {messages.length <= 1 && (
 <div className="px-4 pb-2">
 <p className="text-[10px] font-medium text-tx-muted uppercase tracking-wide mb-2">Acoes rapidas</p>
 <div className="flex flex-wrap gap-1.5">
 {quickActions.map((action) => (
 <button
 key={action.label}
 onClick={() => handleSendMessage(action.label)}
 disabled={chatMutation.isPending}
 className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-tx-secondary bg-surface-hover rounded-full hover:bg-accent-subtle hover:text-accent-primary transition-colors disabled:opacity-50"
 >
 <span>{action.icon}</span>
 {action.label}
 </button>
 ))}
 </div>
 </div>
 )}

 {/* Input Area */}
 <div className="border-t border-border-DEFAULT p-3">
 {/* Inspect mode indicator */}
 {inspectMode && (
 <div className="flex items-center gap-2 mb-2 px-3 py-2 bg-accent-subtle border border-border-DEFAULT rounded-lg">
 <CursorArrowRaysIcon className="h-4 w-4 text-accent-primary shrink-0 animate-pulse" />
 <p className="text-xs text-accent-primary">
 Clique em um elemento no preview para selecionar o que editar
 </p>
 <button
 onClick={() => setInspectMode(false)}
 className="ml-auto text-accent-primary hover:text-accent-primary shrink-0"
 >
 <XMarkIcon className="h-4 w-4" />
 </button>
 </div>
 )}
 <div className="flex items-end gap-2">
 <div className="flex-1 relative">
 <textarea
 ref={inputRef}
 value={inputValue}
 onChange={(e) => setInputValue(e.target.value)}
 onKeyDown={handleKeyDown}
 placeholder="Digite o que quer mudar na pagina..."
 rows={1}
 disabled={chatMutation.isPending}
 className="w-full px-4 py-2.5 text-sm rounded-card border border-border-DEFAULT bg-surface-page text-tx-primary placeholder-tx-muted focus:ring-2 focus:ring-accent-primary focus:border-transparent resize-none disabled:opacity-50"
 style={{ minHeight: '40px', maxHeight: '120px' }}
 onInput={(e) => {
 const target = e.target as HTMLTextAreaElement;
 target.style.height = 'auto';
 target.style.height = Math.min(target.scrollHeight, 120) + 'px';
 }}
 />
 </div>
 <button
 onClick={() => handleSendMessage()}
 disabled={!inputValue.trim() || chatMutation.isPending}
 className="shrink-0 p-2.5 rounded-xl bg-accent-primary text-white hover:bg-accent-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
 >
 <PaperAirplaneIcon className="h-5 w-5" />
 </button>
 </div>
 <p className="text-[10px] text-tx-muted mt-1.5 px-1">
 Enter para enviar - Shift+Enter para nova linha
 </p>
 </div>
 </div>

 {/* Right Panel - Preview */}
 <div className="flex-1 bg-surface-hover overflow-hidden flex items-center justify-center p-4">
 <div
 className={`bg-surface-card rounded-lg shadow-2xl overflow-hidden transition-all duration-300 h-full ${
 viewMode === 'mobile' ? 'w-[375px]' : 'w-full'
 }`}
 >
 {/* Browser bar */}
 <div className="flex items-center gap-3 px-3 py-2 bg-surface-hover border-b shrink-0">
 <div className="flex gap-1.5">
 <div className="w-2.5 h-2.5 rounded-full bg-red-400" />
 <div className="w-2.5 h-2.5 rounded-full bg-yellow-400" />
 <div className="w-2.5 h-2.5 rounded-full bg-green-400" />
 </div>
 <div className="flex-1 bg-surface-card rounded px-3 py-0.5 text-xs text-tx-muted flex items-center gap-1">
 <GlobeAltIcon className="h-3 w-3" />
 {landingPage.custom_domain
 ? `https://${landingPage.custom_domain}`
 : `/lp/${landingPage.slug}`
 }
 </div>
 <button
 onClick={loadPreview}
 disabled={isPreviewLoading}
 className="text-xs text-accent-primary hover:text-accent-primary font-medium disabled:opacity-50"
 >
 {isPreviewLoading ? '...' : 'Atualizar'}
 </button>
 </div>

 {/* Iframe */}
 <div className="h-[calc(100%-36px)] overflow-hidden">
 {isPreviewLoading ? (
 <div className="flex items-center justify-center h-full">
 <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-accent-primary" />
 </div>
 ) : previewHtml ? (
 <iframe
 ref={iframeRef}
 srcDoc={getPreviewHtml()}
 className="w-full h-full border-0"
 title="Preview"
 sandbox="allow-scripts"
 onLoad={() => {
 // Re-send inspect state after iframe reloads
 if (inspectMode && iframeRef.current?.contentWindow) {
 iframeRef.current.contentWindow.postMessage(
 { type: 'toggle-inspect', enabled: true }, window.location.origin
 );
 }
 }}
 />
 ) : (
 <div className="flex flex-col items-center justify-center h-full text-tx-muted">
 <EyeIcon className="h-12 w-12 mb-3 opacity-50" />
 <p className="text-sm">Carregando preview...</p>
 </div>
 )}
 </div>
 </div>
 </div>
 </div>
 </div>
 );
}
