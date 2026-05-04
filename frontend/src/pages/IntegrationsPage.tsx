import { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import {
 MagnifyingGlassIcon,
 PlusIcon,
 XMarkIcon,
 BoltIcon,
 EnvelopeIcon,
 ChatBubbleLeftIcon,
 ChatBubbleLeftRightIcon,
 DevicePhoneMobileIcon,
 UsersIcon,
 LinkIcon,
 SignalIcon,
 DocumentTextIcon,
 TruckIcon,
 CheckCircleIcon,
} from '@heroicons/react/24/outline';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { api } from '../lib/api';

// --------------- types ---------------
interface IntegrationApp {
 id: string;
 name: string;
 description: string;
 longDescription: string;
 category: string;
 logo: string;
 color: string;
 connected: boolean;
 popular: boolean;
 badge?: string;
 disabled?: boolean;
 route?: string;
 fields?: { key: string; label: string; placeholder: string; type?: string }[];
}

// --------------- sidebar categories ---------------
const sidebarCategories = [
 { key: 'Todas', label: 'TODAS', icon: MagnifyingGlassIcon },
 { key: 'Traqueamento', label: 'TRAQUEAMENTO', icon: SignalIcon },
 { key: 'Automações', label: 'AUTOMAÇÕES', icon: BoltIcon },
 { key: 'Mensagens', label: 'MENSAGENS', icon: ChatBubbleLeftRightIcon },
];

// --------------- data ---------------
const integrationApps: IntegrationApp[] = [
 {
 id: 'facebook-pixel',
 name: 'Facebook Pixel',
 description: 'Integre o Facebook Pixel e a Conversions API para rastrear vendas, otimizar campanhas e criar públicos personalizados diretamente no Facebook/Meta Ads.',
 longDescription:
 'Configure seu Facebook Pixel e Access Token da Conversions API para enviar eventos de conversão (Purchase, InitiateCheckout, AddPaymentInfo) diretamente para o Facebook. Isso permite rastreamento server-side preciso, imune a bloqueadores de anúncios, e otimiza suas campanhas de Facebook/Instagram Ads automaticamente.',
 category: 'Traqueamento',
 logo: 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/05/Facebook_Logo_%282019%29.png/1200px-Facebook_Logo_%282019%29.png',
 color: 'bg-accent-primary',
 connected: false,
 popular: true,
 badge: 'Popular',
 fields: [
 {
 key: 'pixel_id',
 label: 'Pixel ID',
 placeholder: '123456789012345',
 },
 {
 key: 'access_token',
 label: 'Access Token (Conversions API)',
 placeholder: 'EAABsbCS...',
 type: 'password',
 },
 {
 key: 'test_event_code',
 label: 'Test Event Code (opcional)',
 placeholder: 'TEST12345',
 },
 ],
 },
 {
 id: 'utmify',
 name: 'UTMify',
 description: 'A UTMify é uma plataforma popular de rastreamento preciso que identifica a origem das vendas online, centralizando métricas de lucro (ROI/ROAS) para superar as falhas de rastreio do Facebook.',
 longDescription:
 'Integre com a UTMify para rastrear a origem de cada venda com precisão. Acompanhe UTMs, atribuição multi-touch e otimize seu investimento em mídia paga. Envia automaticamente os pedidos (pix gerado, pago, cartão aprovado, reembolso e chargeback) para o dashboard da UTMify.',
 category: 'Traqueamento',
 logo: 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQk4OaR8Mp51TVRGT_wYC3Wyyx_gWSSvZlkzw&s',
 color: 'bg-accent-primary',
 connected: false,
 popular: true,
 badge: 'Popular',
 fields: [
 {
 key: 'api_token',
 label: 'Credencial de API (x-api-token)',
 placeholder: 'KVRxalfMiBfm8Rm1nP5YxfwYzArNsA0VLeWC',
 },
 ],
 },
 {
 id: 'xtracky',
 name: 'XTracky',
 description: 'A XTracky é uma plataforma de rastreamento avançado que identifica a origem exata de cada venda, centralizando métricas de ROI/ROAS para otimização de campanhas.',
 longDescription:
 'Integre com a XTracky para rastrear UTMs e atribuição de vendas com precisão. Envia automaticamente os eventos de pagamento (pix gerado, pago, reembolso, chargeback) para o dashboard da XTracky. Documentação: https://docs.xtracky.com/',
 category: 'Traqueamento',
 logo: 'https://lp.xtracky.com/wp-content/uploads/2025/01/icon-xtracky-300x300.webp',
 color: 'bg-[#1a1a2e]',
 connected: false,
 popular: true,
 badge: 'Novo',
 fields: [
 {
 key: 'api_token',
 label: 'ID da Integração (UUID)',
 placeholder: '62a3b4c5-4d50-4ba7-8aeb-94698ab08c0c',
 },
 ],
 },
 {
 id: 'n8n',
 name: 'n8n',
 description: 'Automatize fluxos de trabalho conectando seu gateway a centenas de apps via n8n. Dispare ações quando pagamentos forem aprovados, reembolsados ou criados.',
 longDescription:
 'O n8n é uma plataforma de automação workflow open-source. Configure webhooks para receber eventos do gateway (pagamento aprovado, PIX gerado, reembolso, etc.) e automatize tarefas como envio de e-mails, atualizações de CRM, notificações no Slack/Telegram e mais.',
 category: 'Automações',
 logo: 'https://avatars.githubusercontent.com/u/45487711?s=200&v=4',
 color: 'bg-orange-500',
 connected: false,
 popular: true,
 badge: 'Popular',
 fields: [
 {
 key: 'webhook_url',
 label: 'URL do Webhook n8n',
 placeholder: 'https://seu-n8n.app/webhook/...',
 },
 ],
 },
 {
 id: 'whatsapp-messaging',
 name: 'Disparo no WhatsApp',
 description: 'Envie mensagens em massa automáticas via WhatsApp para seus clientes.',
 longDescription:
 'Configure o disparo automático de mensagens via WhatsApp para eventos como pagamento aprovado, PIX gerado, cobrança criada e mais. Suporte a Evolution API e Z-API.',
 category: 'Mensagens',
 logo: 'https://upload.wikimedia.org/wikipedia/commons/6/6b/WhatsApp.svg',
 color: 'bg-status-success-subtle0',
 connected: false,
 popular: true,
 badge: 'Em breve',
 disabled: true,
 route: '/messaging',
 fields: [],
 },
 {
 id: 'tiktok-pixel',
 name: 'TikTok Pixel',
 description: 'Integre o TikTok Pixel para rastrear conversões, otimizar campanhas do TikTok Ads e criar públicos personalizados.',
 longDescription:
 'Configure seu TikTok Pixel ID e Access Token (opcional) para enviar eventos de conversão (CompletePayment, InitiateCheckout, PageView) para o TikTok. Isso permite rastreamento preciso e otimização de campanhas do TikTok Ads.',
 category: 'Traqueamento',
 logo: 'https://sf16-scmcdn-sg.ibytedtos.com/goofy/tiktok/web/node/_next/static/images/logo-whole-c555aa707602e714ec956ac96e9db366.svg',
 color: 'bg-black',
 connected: false,
 popular: true,
 badge: 'Novo',
 fields: [
 {
 key: 'pixel_id',
 label: 'Pixel ID',
 placeholder: 'C5XXXXXXXXXXXXXXXXX',
 },
 {
 key: 'access_token',
 label: 'Access Token (Events API - opcional)',
 placeholder: 'Seu access token do TikTok',
 type: 'password',
 },
 ],
 },
 {
 id: 'google-analytics',
 name: 'Google Analytics (GA4)',
 description: 'Integre o Google Analytics 4 para rastrear conversões, comportamento de usuários e otimizar suas campanhas do Google Ads.',
 longDescription:
 'Configure seu Measurement ID (GA4) e API Secret (opcional) para enviar eventos de conversão (purchase, begin_checkout, page_view) para o Google Analytics. Permite acompanhar todo o funil de vendas e integrar com Google Ads.',
 category: 'Traqueamento',
 logo: 'https://www.gstatic.com/analytics-suite/header/suite/v2/ic_analytics.svg',
 color: 'bg-orange-500',
 connected: false,
 popular: true,
 badge: 'Novo',
 fields: [
 {
 key: 'measurement_id',
 label: 'Measurement ID (GA4)',
 placeholder: 'G-XXXXXXXXXX',
 },
 {
 key: 'api_secret',
 label: 'API Secret (Measurement Protocol - opcional)',
 placeholder: 'Seu API secret do GA4',
 type: 'password',
 },
 ],
 },
];

// --------------- component ---------------
export default function IntegrationsPage() {
 const navigate = useNavigate();
 const [search, setSearch] = useState('');
 const [activeCategory, setActiveCategory] = useState('Todas');
 const [apps, setApps] = useState(integrationApps);
 const [selectedApp, setSelectedApp] = useState<IntegrationApp | null>(null);
 const [formData, setFormData] = useState<Record<string, string>>({});
 const [saving, setSaving] = useState(false);
 const [utmifyMaskedToken, setUtmifyMaskedToken] = useState<string | null>(null);
 const [utmifyInfo, setUtmifyInfo] = useState<{ success_count: number; failure_count: number; last_sent_at: string | null } | null>(null);
 const [fbPixelInfo, setFbPixelInfo] = useState<{ pixel_id: string; masked_token: string; success_count: number; failure_count: number; last_sent_at: string | null } | null>(null);
 const [xtrackyMaskedToken, setXtrackyMaskedToken] = useState<string | null>(null);
 const [xtrackyInfo, setXtrackyInfo] = useState<{ success_count: number; failure_count: number; last_sent_at: string | null } | null>(null);
 const [tiktokPixelInfo, setTiktokPixelInfo] = useState<{ pixel_id: string; masked_token: string; success_count: number; failure_count: number; last_sent_at: string | null } | null>(null);
 const [gaInfo, setGaInfo] = useState<{ measurement_id: string; masked_secret: string; success_count: number; failure_count: number; last_sent_at: string | null } | null>(null);

 // Lock body scroll when modal is open
 useEffect(() => {
 if (selectedApp) {
 document.body.style.overflow = 'hidden';
 } else {
 document.body.style.overflow = '';
 }
 return () => {
 document.body.style.overflow = '';
 };
 }, [selectedApp]);

 // Load UTMify integration status from backend
 useEffect(() => {
 api.get('/integrations/utmify').then((res) => {
 const data = res.data?.data;
 if (data) {
 if (data.is_active) {
 setApps((prev) =>
 prev.map((a) => (a.id === 'utmify' ? { ...a, connected: true } : a)),
 );
 }
 if (data.masked_token) {
 setUtmifyMaskedToken(data.masked_token);
 }
 setUtmifyInfo({
 success_count: data.success_count ?? 0,
 failure_count: data.failure_count ?? 0,
 last_sent_at: data.last_sent_at ?? null,
 });
 }
 }).catch(() => { /* not connected */ });

 // Load Facebook Pixel integration status
 api.get('/integrations/facebook-pixel').then((res) => {
 const data = res.data?.data;
 if (data) {
 if (data.is_active) {
 setApps((prev) =>
 prev.map((a) => (a.id === 'facebook-pixel' ? { ...a, connected: true } : a)),
 );
 }
 setFbPixelInfo({
 pixel_id: data.pixel_id ?? '',
 masked_token: data.masked_token ?? '',
 success_count: data.success_count ?? 0,
 failure_count: data.failure_count ?? 0,
 last_sent_at: data.last_sent_at ?? null,
 });
 }
 }).catch(() => { /* not connected */ });

 // Load XTracky integration status
 api.get('/integrations/xtracky').then((res) => {
 const data = res.data?.data;
 if (data) {
 if (data.is_active) {
 setApps((prev) =>
 prev.map((a) => (a.id === 'xtracky' ? { ...a, connected: true } : a)),
 );
 }
 if (data.masked_token) {
 setXtrackyMaskedToken(data.masked_token);
 }
 setXtrackyInfo({
 success_count: data.success_count ?? 0,
 failure_count: data.failure_count ?? 0,
 last_sent_at: data.last_sent_at ?? null,
 });
 }
 }).catch(() => { /* not connected */ });

 // Load TikTok Pixel integration status
 api.get('/integrations/tiktok-pixel').then((res) => {
 const data = res.data?.data;
 if (data) {
 if (data.is_active) {
 setApps((prev) =>
 prev.map((a) => (a.id === 'tiktok-pixel' ? { ...a, connected: true } : a)),
 );
 }
 setTiktokPixelInfo({
 pixel_id: data.pixel_id ?? '',
 masked_token: data.masked_token ?? '',
 success_count: data.success_count ?? 0,
 failure_count: data.failure_count ?? 0,
 last_sent_at: data.last_sent_at ?? null,
 });
 }
 }).catch(() => { /* not connected */ });

 // Load Google Analytics integration status
 api.get('/integrations/google-analytics').then((res) => {
 const data = res.data?.data;
 if (data) {
 if (data.is_active) {
 setApps((prev) =>
 prev.map((a) => (a.id === 'google-analytics' ? { ...a, connected: true } : a)),
 );
 }
 setGaInfo({
 measurement_id: data.measurement_id ?? '',
 masked_secret: data.masked_secret ?? '',
 success_count: data.success_count ?? 0,
 failure_count: data.failure_count ?? 0,
 last_sent_at: data.last_sent_at ?? null,
 });
 }
 }).catch(() => { /* not connected */ });


 }, []);

 // ---- derived ----
 const filteredApps = apps.filter((app) => {
 if (search) {
 return (
 app.name.toLowerCase().includes(search.toLowerCase()) ||
 app.description.toLowerCase().includes(search.toLowerCase())
 );
 }
 if (activeCategory === 'Todas') return true;
 return app.category === activeCategory;
 });

 const connectedCount = apps.filter((a) => a.connected).length;

 // ---- handlers ----
 const handleConnect = (app: IntegrationApp) => {

 if (app.fields && app.fields.length > 0) {
 setSelectedApp(app);
 // Pre-fill with masked token if UTMify is already connected
 if (app.id === 'utmify' && app.connected && utmifyMaskedToken) {
 setFormData({ api_token: utmifyMaskedToken });
 } else if (app.id === 'facebook-pixel' && app.connected && fbPixelInfo) {
 setFormData({ pixel_id: fbPixelInfo.pixel_id, access_token: fbPixelInfo.masked_token });
 } else if (app.id === 'xtracky' && app.connected && xtrackyMaskedToken) {
 setFormData({ api_token: xtrackyMaskedToken });
 } else if (app.id === 'tiktok-pixel' && app.connected && tiktokPixelInfo) {
 setFormData({ pixel_id: tiktokPixelInfo.pixel_id, access_token: tiktokPixelInfo.masked_token });
 } else if (app.id === 'google-analytics' && app.connected && gaInfo) {
 setFormData({ measurement_id: gaInfo.measurement_id, api_secret: gaInfo.masked_secret });
 } else {
 setFormData({});
 }
 } else {
 toggleConnection(app.id);
 }
 };

 const handleDisconnect = async (appId: string) => {
 if (appId === 'utmify') {
 try {
 await api.delete('/integrations/utmify');
 setApps((prev) => prev.map((a) => (a.id === 'utmify' ? { ...a, connected: false } : a)));
 setUtmifyMaskedToken(null);
 setUtmifyInfo(null);
 toast.success('UTMify desconectado');
 } catch {
 toast.error('Erro ao desconectar UTMify');
 }
 return;
 }
 if (appId === 'facebook-pixel') {
 try {
 await api.delete('/integrations/facebook-pixel');
 setApps((prev) => prev.map((a) => (a.id === 'facebook-pixel' ? { ...a, connected: false } : a)));
 setFbPixelInfo(null);
 toast.success('Facebook Pixel desconectado');
 } catch {
 toast.error('Erro ao desconectar Facebook Pixel');
 }
 return;
 }
 if (appId === 'xtracky') {
 try {
 await api.delete('/integrations/xtracky');
 setApps((prev) => prev.map((a) => (a.id === 'xtracky' ? { ...a, connected: false } : a)));
 setXtrackyMaskedToken(null);
 setXtrackyInfo(null);
 toast.success('XTracky desconectado');
 } catch {
 toast.error('Erro ao desconectar XTracky');
 }
 return;
 }
 if (appId === 'tiktok-pixel') {
 try {
 await api.delete('/integrations/tiktok-pixel');
 setApps((prev) => prev.map((a) => (a.id === 'tiktok-pixel' ? { ...a, connected: false } : a)));
 setTiktokPixelInfo(null);
 toast.success('TikTok Pixel desconectado');
 } catch {
 toast.error('Erro ao desconectar TikTok Pixel');
 }
 return;
 }
 if (appId === 'google-analytics') {
 try {
 await api.delete('/integrations/google-analytics');
 setApps((prev) => prev.map((a) => (a.id === 'google-analytics' ? { ...a, connected: false } : a)));
 setGaInfo(null);
 toast.success('Google Analytics desconectado');
 } catch {
 toast.error('Erro ao desconectar Google Analytics');
 }
 return;
 }
 toggleConnection(appId);
 };

 const toggleConnection = (appId: string) => {
 setApps((prev) =>
 prev.map((a) => (a.id === appId ? { ...a, connected: !a.connected } : a)),
 );
 const app = apps.find((a) => a.id === appId);
 if (app?.connected) {
 toast.success(`${app.name} desconectado`);
 } else {
 toast.success(`${app?.name} conectado com sucesso!`);
 }
 };

 const handleSaveConnection = async () => {
 if (!selectedApp) return;

 if (selectedApp.fields && selectedApp.fields.length > 0) {
 const firstField = selectedApp.fields[0];
 if (!formData[firstField.key]?.trim()) {
 toast.error(`Preencha o campo ${firstField.label}`);
 return;
 }
 }

 // UTMify: call real backend
 if (selectedApp.id === 'utmify') {
 setSaving(true);
 try {
 const res = await api.post('/integrations/utmify', { api_token: formData['api_token'] });
 setApps((prev) => prev.map((a) => (a.id === 'utmify' ? { ...a, connected: true } : a)));
 // Update masked token from response
 if (res.data?.data?.masked_token) {
 setUtmifyMaskedToken(res.data.data.masked_token);
 }
 if (res.data?.warning) {
 toast.success('Token salvo!');
 toast(res.data.warning, { icon: '⚠️', duration: 5000 });
 } else {
 toast.success('UTMify conectado com sucesso!');
 }
 setSelectedApp(null);
 setFormData({});
 } catch (err: any) {
 const msg = err?.response?.data?.message || 'Erro ao conectar UTMify';
 toast.error(msg);
 } finally {
 setSaving(false);
 }
 return;
 }

 // Facebook Pixel: call real backend
 if (selectedApp.id === 'facebook-pixel') {
 setSaving(true);
 try {
 const res = await api.post('/integrations/facebook-pixel', {
 pixel_id: formData['pixel_id'],
 access_token: formData['access_token'],
 test_event_code: formData['test_event_code'] || null,
 });
 setApps((prev) => prev.map((a) => (a.id === 'facebook-pixel' ? { ...a, connected: true } : a)));
 if (res.data?.data) {
 setFbPixelInfo({
 pixel_id: res.data.data.pixel_id ?? formData['pixel_id'],
 masked_token: res.data.data.masked_token ?? '',
 success_count: 0,
 failure_count: 0,
 last_sent_at: null,
 });
 }
 if (res.data?.warning) {
 toast.success('Configuração salva!');
 toast(res.data.warning, { icon: '⚠️', duration: 6000 });
 } else {
 toast.success('Facebook Pixel conectado com sucesso!');
 }
 setSelectedApp(null);
 setFormData({});
 } catch (err: any) {
 const msg = err?.response?.data?.message || 'Erro ao configurar Facebook Pixel';
 toast.error(msg);
 } finally {
 setSaving(false);
 }
 return;
 }

 // XTracky: call real backend
 if (selectedApp.id === 'xtracky') {
 setSaving(true);
 try {
 const res = await api.post('/integrations/xtracky', { api_token: formData['api_token'] });
 setApps((prev) => prev.map((a) => (a.id === 'xtracky' ? { ...a, connected: true } : a)));
 if (res.data?.data?.masked_token) {
 setXtrackyMaskedToken(res.data.data.masked_token);
 }
 if (res.data?.warning) {
 toast.success('Token salvo!');
 toast(res.data.warning, { icon: '⚠️', duration: 5000 });
 } else {
 toast.success('XTracky conectado com sucesso!');
 }
 setSelectedApp(null);
 setFormData({});
 } catch (err: any) {
 const msg = err?.response?.data?.message || 'Erro ao conectar XTracky';
 toast.error(msg);
 } finally {
 setSaving(false);
 }
 return;
 }

 // TikTok Pixel: call real backend
 if (selectedApp.id === 'tiktok-pixel') {
 setSaving(true);
 try {
 const res = await api.post('/integrations/tiktok-pixel', {
 pixel_id: formData['pixel_id'],
 access_token: formData['access_token'] || null,
 });
 setApps((prev) => prev.map((a) => (a.id === 'tiktok-pixel' ? { ...a, connected: true } : a)));
 if (res.data?.data) {
 setTiktokPixelInfo({
 pixel_id: res.data.data.pixel_id ?? formData['pixel_id'],
 masked_token: res.data.data.masked_token ?? '',
 success_count: 0,
 failure_count: 0,
 last_sent_at: null,
 });
 }
 toast.success('TikTok Pixel conectado com sucesso!');
 setSelectedApp(null);
 setFormData({});
 } catch (err: any) {
 const msg = err?.response?.data?.message || 'Erro ao configurar TikTok Pixel';
 toast.error(msg);
 } finally {
 setSaving(false);
 }
 return;
 }

 // Google Analytics: call real backend
 if (selectedApp.id === 'google-analytics') {
 setSaving(true);
 try {
 const res = await api.post('/integrations/google-analytics', {
 measurement_id: formData['measurement_id'],
 api_secret: formData['api_secret'] || null,
 });
 setApps((prev) => prev.map((a) => (a.id === 'google-analytics' ? { ...a, connected: true } : a)));
 if (res.data?.data) {
 setGaInfo({
 measurement_id: res.data.data.measurement_id ?? formData['measurement_id'],
 masked_secret: res.data.data.masked_secret ?? '',
 success_count: 0,
 failure_count: 0,
 last_sent_at: null,
 });
 }
 toast.success('Google Analytics conectado com sucesso!');
 setSelectedApp(null);
 setFormData({});
 } catch (err: any) {
 const msg = err?.response?.data?.message || 'Erro ao configurar Google Analytics';
 toast.error(msg);
 } finally {
 setSaving(false);
 }
 return;
 }

 toggleConnection(selectedApp.id);
 setSelectedApp(null);
 setFormData({});
 };

 // count per category
 const getCategoryCount = (key: string) =>
 key === 'Todas'
 ? apps.filter((a) => a.connected).length
 : apps.filter((a) => a.category === key && a.connected).length;

 // ---- render ----
 return (
 <div className="space-y-4 sm:space-y-6">
 {/* Header */}
 <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Integrações</h1>
 <p className="mt-1 text-sm text-tx-muted">
 Conecte seus apps favoritos e automatize seu negócio
 </p>
 </div>
 <span className="text-xs text-tx-muted bg-surface-hover px-3 py-1.5 rounded-full">
 {connectedCount} {connectedCount === 1 ? 'conectado' : 'conectados'}
 </span>
 </div>

 {/* Search */}
 <div className="relative">
 <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-tx-muted" />
 <input
 type="text"
 placeholder="Buscar integração..."
 value={search}
 onChange={(e) => setSearch(e.target.value)}
 className="w-full pl-10 pr-4 py-2.5 bg-surface-card border border-border-DEFAULT rounded-xl text-sm text-tx-primary placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-accent-primary/20 focus:border-accent-primary transition-all"
 />
 </div>

 {/* Mobile Category Selector */}
 <div className="flex gap-2 overflow-x-auto pb-1 scrollbar-hide md:hidden">
 {sidebarCategories.map((cat) => (
 <button
 key={cat.key}
 onClick={() => {
 setActiveCategory(cat.key);
 setSearch('');
 }}
 className={`px-4 py-2 rounded-xl text-xs font-medium whitespace-nowrap transition-all ${
 activeCategory === cat.key && !search
 ? 'bg-accent-primary text-white'
 : 'bg-surface-hover text-tx-secondary hover:bg-surface-hover'
 }`}
 >
 {cat.label}
 </button>
 ))}
 </div>

 {/* Sidebar + Content */}
 <div className="flex gap-4 sm:gap-6">
 {/* Sidebar */}
 <div className="w-56 shrink-0 hidden md:block">
 <nav className="bg-surface-card rounded-2xl border border-border-DEFAULT overflow-hidden">
 {sidebarCategories.map((cat) => {
 const isActive = activeCategory === cat.key && !search;
 const count = getCategoryCount(cat.key);
 return (
 <button
 key={cat.key}
 onClick={() => {
 setActiveCategory(cat.key);
 setSearch('');
 }}
 className={`relative w-full flex items-center gap-3 px-4 py-3.5 text-xs font-semibold tracking-wider transition-all ${
 isActive
 ? 'bg-accent-subtle text-accent-primary'
 : 'text-tx-muted hover:bg-surface-page/50 hover:text-tx-secondary'
 }`}
 >
 {/* Active indicator */}
 {isActive && (
 <div className="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-6 bg-accent-primary0 rounded-r-full" />
 )}
 <cat.icon className="h-4.5 w-4.5 shrink-0" style={{ width: 18, height: 18 }} />
 <span className="flex-1 text-left">{cat.label}</span>
 {count > 0 && (
 <span className="bg-status-success-subtle text-status-success text-[10px] font-bold px-1.5 py-0.5 rounded-full">
 {count}
 </span>
 )}
 </button>
 );
 })}
 </nav>
 </div>

 {/* Content */}
 <div className="flex-1 min-w-0">
 {/* Category title */}
 {!search && (
 <div className="mb-4">
 <h2 className="text-lg font-semibold text-tx-primary">
 {sidebarCategories.find((c) => c.key === activeCategory)?.label}
 </h2>
 <p className="text-xs text-tx-muted mt-0.5">
 {filteredApps.length} {filteredApps.length === 1 ? 'integração disponível' : 'integrações disponíveis'}
 </p>
 </div>
 )}

 {search && (
 <div className="mb-4">
 <h2 className="text-lg font-semibold text-tx-primary">
 Resultados para "{search}"
 </h2>
 <p className="text-xs text-tx-muted mt-0.5">
 {filteredApps.length} {filteredApps.length === 1 ? 'resultado' : 'resultados'}
 </p>
 </div>
 )}

 {/* Apps Grid */}
 <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
 {filteredApps.map((app) => (
 <div
 key={app.id}
 className={`relative bg-surface-card rounded-2xl border transition-all hover:shadow-lg hover:shadow-slate-200/50 ${
 app.connected
 ? 'border-green-200'
 : 'border-border-DEFAULT hover:border-accent-primary/50'
 }`}
 >
 {/* Connected dot */}
 {app.connected && (
 <div className="absolute top-3 right-3">
 <span className="flex h-2.5 w-2.5">
 <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75" />
 <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-status-success-subtle0" />
 </span>
 </div>
 )}

 <div className="p-5">
 {/* Logo + Info */}
 <div className="flex items-start gap-4">
 <div
 className="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 overflow-hidden"
 >
 {app.logo.startsWith('http') ? (
 <img src={app.logo} alt={app.name} className="w-12 h-12 object-contain rounded-2xl" />
 ) : (
 app.logo
 )}
 </div>
 <div className="flex-1 min-w-0">
 <h3 className="text-sm font-semibold text-tx-primary">
 {app.name}
 </h3>
 <span className="inline-flex items-center mt-1 px-2 py-0.5 rounded-md text-[10px] font-medium bg-surface-hover text-tx-muted">
 {app.category}
 </span>
 </div>
 </div>

 <p className="mt-3 text-xs text-tx-muted line-clamp-2">
 {app.description}
 </p>

 {/* Action */}
 <div className="mt-4">
 {app.disabled ? (
 <button
 disabled
 className="w-full flex items-center justify-center gap-1.5 px-3 py-2.5 text-xs font-medium rounded-xl text-tx-muted bg-surface-hover cursor-not-allowed opacity-60"
 >
 Em breve
 </button>
 ) : app.route ? (
 <button
 onClick={() => navigate(app.route!)}
 className="w-full flex items-center justify-center gap-1.5 px-3 py-2.5 text-xs font-medium text-white bg-accent-primary rounded-xl hover:bg-accent-hover transition-all hover:shadow-green-500/40"
 >
 Acessar
 </button>
 ) : app.connected ? (
 <div className="flex gap-2">
 <button
 onClick={() => handleConnect(app)}
 className="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium text-tx-secondary bg-surface-hover rounded-xl hover:bg-surface-hover transition-colors"
 >
 Configurar
 </button>
 <button
 onClick={() => handleDisconnect(app.id)}
 className="px-3 py-2 text-xs font-medium text-status-error bg-status-error-subtle rounded-xl hover:bg-status-error-subtle transition-colors"
 >
 Desconectar
 </button>
 </div>
 ) : (
 <button
 onClick={() => handleConnect(app)}
 className="w-full flex items-center justify-center gap-1.5 px-3 py-2.5 text-xs font-medium text-white bg-accent-primary rounded-xl hover:bg-accent-hover transition-all hover:shadow-green-500/40"
 >
 <PlusIcon className="h-3.5 w-3.5" />
 Conectar
 </button>
 )}
 </div>
 </div>
 </div>
 ))}
 </div>

 {filteredApps.length === 0 && (
 <div className="text-center py-16">
 <MagnifyingGlassIcon className="mx-auto h-10 w-10 text-tx-muted" />
 <p className="mt-3 text-sm text-tx-muted">
 Nenhuma integração encontrada
 </p>
 </div>
 )}
 </div>
 </div>

 {/* ---- Connection Modal ---- */}
 {selectedApp && createPortal(
 <div className="fixed inset-0 z-[9999] flex items-center justify-center p-4">
 {/* Backdrop */}
 <div
 className="absolute inset-0 bg-black/50 backdrop-blur-md transition-all"
 onClick={() => {
 setSelectedApp(null);
 setFormData({});
 }}
 />

 {/* Modal */}
 <div className="relative w-full max-w-md max-h-[90vh] overflow-y-auto bg-surface-card rounded-3xl shadow-2xl border border-border-DEFAULT">
 {/* Header */}
 <div className="relative p-4 sm:p-6 pb-4">
 <button
 onClick={() => {
 setSelectedApp(null);
 setFormData({});
 }}
 className="absolute top-4 right-4 p-1 text-tx-muted hover:text-tx-secondary transition-colors"
 >
 <XMarkIcon className="h-5 w-5" />
 </button>

 <div className="flex items-center gap-4">
 <div
 className="w-14 h-14 rounded-2xl flex items-center justify-center overflow-hidden"
 >
 {selectedApp.logo.startsWith('http') ? (
 <img src={selectedApp.logo} alt={selectedApp.name} className="w-14 h-14 object-contain rounded-2xl" />
 ) : (
 selectedApp.logo
 )}
 </div>
 <div>
 <h3 className="text-lg font-bold text-tx-primary">
 {selectedApp.name}
 </h3>
 <span className="text-xs text-tx-muted">
 {selectedApp.category}
 </span>
 </div>
 </div>

 <p className="mt-4 text-sm text-tx-secondary">
 {selectedApp.longDescription}
 </p>
 </div>

 {/* Connection Status (when already connected) */}
 {selectedApp.id === 'utmify' && selectedApp.connected && utmifyMaskedToken && (
 <div className="px-4 sm:px-6 pb-4">
 <div className="bg-status-success-subtle border border-green-200/30 rounded-xl p-3">
 <div className="flex items-center gap-2 mb-1">
 <span className="flex h-2 w-2">
 <span className="relative inline-flex rounded-full h-2 w-2 bg-status-success-subtle0" />
 </span>
 <span className="text-xs font-semibold text-status-success">Conectado</span>
 </div>
 <p className="text-xs text-status-success font-mono">
 Token: {utmifyMaskedToken}
 </p>
 {utmifyInfo && (
 <div className="flex gap-4 mt-2 text-[10px] text-tx-muted">
 <span>Enviados: {utmifyInfo.success_count}</span>
 {utmifyInfo.failure_count > 0 && <span className="text-status-error">Erros: {utmifyInfo.failure_count}</span>}
 {utmifyInfo.last_sent_at && <span>Último: {new Date(utmifyInfo.last_sent_at).toLocaleString('pt-BR')}</span>}
 </div>
 )}
 </div>
 <p className="text-[11px] text-tx-muted mt-2">
 Para trocar o token, digite o novo abaixo e clique em Salvar.
 </p>
 </div>
 )}

 {/* Connection Status for XTracky */}
 {selectedApp.id === 'xtracky' && selectedApp.connected && xtrackyMaskedToken && (
 <div className="px-4 sm:px-6 pb-4">
 <div className="bg-status-success-subtle border border-green-200/30 rounded-xl p-3">
 <div className="flex items-center gap-2 mb-1">
 <span className="flex h-2 w-2">
 <span className="relative inline-flex rounded-full h-2 w-2 bg-status-success-subtle0" />
 </span>
 <span className="text-xs font-semibold text-status-success">Conectado</span>
 </div>
 <p className="text-xs text-status-success font-mono">
 Token: {xtrackyMaskedToken}
 </p>
 {xtrackyInfo && (
 <div className="flex gap-4 mt-2 text-[10px] text-tx-muted">
 <span>Enviados: {xtrackyInfo.success_count}</span>
 {xtrackyInfo.failure_count > 0 && <span className="text-status-error">Erros: {xtrackyInfo.failure_count}</span>}
 {xtrackyInfo.last_sent_at && <span>Último: {new Date(xtrackyInfo.last_sent_at).toLocaleString('pt-BR')}</span>}
 </div>
 )}
 </div>
 <p className="text-[11px] text-tx-muted mt-2">
 Para trocar o ID da integração, digite o novo abaixo e clique em Salvar.
 </p>
 </div>
 )}

 {/* Connection Status for Facebook Pixel */}
 {selectedApp.id === 'facebook-pixel' && selectedApp.connected && fbPixelInfo && (
 <div className="px-4 sm:px-6 pb-4">
 <div className="bg-status-success-subtle border border-green-200/30 rounded-xl p-3">
 <div className="flex items-center gap-2 mb-1">
 <span className="flex h-2 w-2">
 <span className="relative inline-flex rounded-full h-2 w-2 bg-status-success-subtle0" />
 </span>
 <span className="text-xs font-semibold text-status-success">Conectado</span>
 </div>
 <p className="text-xs text-status-success font-mono">
 Pixel ID: {fbPixelInfo.pixel_id}
 </p>
 <p className="text-xs text-status-success font-mono">
 Token: {fbPixelInfo.masked_token}
 </p>
 {fbPixelInfo && (
 <div className="flex gap-4 mt-2 text-[10px] text-tx-muted">
 <span>Eventos enviados: {fbPixelInfo.success_count}</span>
 {fbPixelInfo.failure_count > 0 && <span className="text-status-error">Erros: {fbPixelInfo.failure_count}</span>}
 {fbPixelInfo.last_sent_at && <span>Último: {new Date(fbPixelInfo.last_sent_at).toLocaleString('pt-BR')}</span>}
 </div>
 )}
 </div>
 <p className="text-[11px] text-tx-muted mt-2">
 Para atualizar, preencha os campos abaixo e clique em Salvar.
 </p>
 </div>
 )}

 {/* Connection Status for TikTok Pixel */}
 {selectedApp.id === 'tiktok-pixel' && selectedApp.connected && tiktokPixelInfo && (
 <div className="px-4 sm:px-6 pb-4">
 <div className="bg-status-success-subtle border border-green-200/30 rounded-xl p-3">
 <div className="flex items-center gap-2 mb-1">
 <span className="flex h-2 w-2">
 <span className="relative inline-flex rounded-full h-2 w-2 bg-status-success-subtle0" />
 </span>
 <span className="text-xs font-semibold text-status-success">Conectado</span>
 </div>
 <p className="text-xs text-status-success font-mono">
 Pixel ID: {tiktokPixelInfo.pixel_id}
 </p>
 {tiktokPixelInfo.masked_token && (
 <p className="text-xs text-status-success font-mono">
 Token: {tiktokPixelInfo.masked_token}
 </p>
 )}
 {tiktokPixelInfo && (
 <div className="flex gap-4 mt-2 text-[10px] text-tx-muted">
 <span>Eventos enviados: {tiktokPixelInfo.success_count}</span>
 {tiktokPixelInfo.failure_count > 0 && <span className="text-status-error">Erros: {tiktokPixelInfo.failure_count}</span>}
 {tiktokPixelInfo.last_sent_at && <span>Último: {new Date(tiktokPixelInfo.last_sent_at).toLocaleString('pt-BR')}</span>}
 </div>
 )}
 </div>
 <p className="text-[11px] text-tx-muted mt-2">
 Para atualizar, preencha os campos abaixo e clique em Salvar.
 </p>
 </div>
 )}

 {/* Connection Status for Google Analytics */}
 {selectedApp.id === 'google-analytics' && selectedApp.connected && gaInfo && (
 <div className="px-4 sm:px-6 pb-4">
 <div className="bg-status-success-subtle border border-green-200/30 rounded-xl p-3">
 <div className="flex items-center gap-2 mb-1">
 <span className="flex h-2 w-2">
 <span className="relative inline-flex rounded-full h-2 w-2 bg-status-success-subtle0" />
 </span>
 <span className="text-xs font-semibold text-status-success">Conectado</span>
 </div>
 <p className="text-xs text-status-success font-mono">
 Measurement ID: {gaInfo.measurement_id}
 </p>
 {gaInfo.masked_secret && (
 <p className="text-xs text-status-success font-mono">
 API Secret: {gaInfo.masked_secret}
 </p>
 )}
 {gaInfo && (
 <div className="flex gap-4 mt-2 text-[10px] text-tx-muted">
 <span>Eventos enviados: {gaInfo.success_count}</span>
 {gaInfo.failure_count > 0 && <span className="text-status-error">Erros: {gaInfo.failure_count}</span>}
 {gaInfo.last_sent_at && <span>Último: {new Date(gaInfo.last_sent_at).toLocaleString('pt-BR')}</span>}
 </div>
 )}
 </div>
 <p className="text-[11px] text-tx-muted mt-2">
 Para atualizar, preencha os campos abaixo e clique em Salvar.
 </p>
 </div>
 )}

 {/* Fields */}
 {selectedApp.fields && selectedApp.fields.length > 0 && (
 <div className="px-4 sm:px-6 pb-4 space-y-3">
 {selectedApp.fields.map((field) => (
 <div key={field.key}>
 <label className="block text-xs font-medium text-tx-secondary mb-1.5">
 {field.label}
 </label>
 <input
 type={field.type || 'text'}
 placeholder={field.placeholder}
 value={formData[field.key] || ''}
 onChange={(e) =>
 setFormData({ ...formData, [field.key]: e.target.value })
 }
 className="w-full px-3.5 py-2.5 bg-surface-page border border-border-DEFAULT rounded-xl text-sm text-tx-primary placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-accent-primary/20 focus:border-accent-primary transition-all"
 />
 </div>
 ))}
 </div>
 )}

 {/* Events Info */}
 <div className="px-4 sm:px-6 pb-4">
 <p className="text-xs font-medium text-tx-secondary mb-2">
 Eventos enviados:
 </p>
 <div className="flex flex-wrap gap-1.5">
 {(selectedApp.id === 'facebook-pixel'
 ? ['Purchase', 'InitiateCheckout', 'AddPaymentInfo', 'PageView']
 : selectedApp.id === 'tiktok-pixel'
 ? ['CompletePayment', 'InitiateCheckout', 'PageView']
 : selectedApp.id === 'google-analytics'
 ? ['purchase', 'begin_checkout', 'page_view']
 : selectedApp.id === 'xtracky'
 ? ['waiting_payment', 'paid', 'refunded', 'expired', 'refused']
 : ['payment.approved', 'payment.refused', 'payment.expired', 'charge.created']
 ).map((event) => (
 <span
 key={event}
 className="text-[10px] font-mono bg-surface-hover text-accent-primary px-2 py-1 rounded-md"
 >
 {event}
 </span>
 ),
 )}
 </div>
 </div>

 {/* Actions */}
 <div className="p-4 sm:p-6 pt-2 flex gap-3">
 <button
 onClick={() => {
 setSelectedApp(null);
 setFormData({});
 }}
 className="flex-1 px-4 py-2.5 text-sm font-medium text-tx-secondary bg-surface-hover rounded-xl hover:bg-surface-hover transition-colors"
 >
 Cancelar
 </button>
 <button
 onClick={handleSaveConnection}
 disabled={saving}
 className="flex-1 px-4 py-2.5 text-sm font-medium text-white bg-accent-primary rounded-xl hover:bg-accent-hover transition-all disabled:opacity-50 disabled:cursor-not-allowed"
 >
 {saving ? 'Validando...' : selectedApp.connected ? 'Salvar' : 'Conectar'}
 </button>
 </div>
 </div>
 </div>,
 document.body
 )}
 </div>
 );
}


