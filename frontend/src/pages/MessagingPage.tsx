import { useState, useEffect, useRef } from 'react';
import { Spinner } from '@/components/ui/Spinner';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/stores/authStore';
import {
  ChatBubbleLeftRightIcon,
  PaperAirplaneIcon,
  DocumentTextIcon,
  ClockIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  TrashIcon,
  PencilIcon,
  BoltIcon,
  EyeIcon,
  ArrowPathIcon,
  XMarkIcon,
  PlusIcon,
  MagnifyingGlassIcon,
  UserGroupIcon,
  MegaphoneIcon,
  ChartBarIcon,
  ArrowUpTrayIcon,
  PhotoIcon,
  VideoCameraIcon,
  DocumentIcon,
  MusicalNoteIcon,
  StopIcon,
  PlayIcon,
  CalendarDaysIcon,
  PhoneIcon,
  SignalIcon,
  Cog6ToothIcon,
  InformationCircleIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  TagIcon,
  QueueListIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import {
  messagingService,
  type MessagingProvider,
  type MessageTemplate,
  type MessageLog,
  type TriggerOption,
  type WhatsAppStatusResponse,
  type Contact,
  type ContactList,
  type Campaign,
} from '@/services/api/messaging.service';

// ====== Types ======
type Tab = 'dashboard' | 'send' | 'campaigns' | 'contacts' | 'templates' | 'automations' | 'logs' | 'settings';

// ====== Helper: format phone ======
function formatPhone(phone: string): string {
  const digits = phone.replace(/\D/g, '');
  if (digits.length === 13) return `+${digits.slice(0,2)} (${digits.slice(2,4)}) ${digits.slice(4,9)}-${digits.slice(9)}`;
  if (digits.length === 12) return `+${digits.slice(0,2)} (${digits.slice(2,4)}) ${digits.slice(4,8)}-${digits.slice(8)}`;
  if (digits.length === 11) return `(${digits.slice(0,2)}) ${digits.slice(2,7)}-${digits.slice(7)}`;
  return phone;
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'agora';
  if (mins < 60) return `${mins}min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h`;
  const days = Math.floor(hours / 24);
  return `${days}d`;
}

const statusColors: Record<string, string> = {
  pending: 'bg-yellow-500/10 text-yellow-500',
  sent: 'bg-blue-500/10 text-blue-500',
  delivered: 'bg-green-500/10 text-green-500',
  read: 'bg-emerald-500/10 text-emerald-500',
  failed: 'bg-red-500/10 text-red-500',
};

const statusLabels: Record<string, string> = {
  pending: 'Pendente',
  sent: 'Enviado',
  delivered: 'Entregue',
  read: 'Lido',
  failed: 'Falhou',
};

const campaignStatusColors: Record<string, string> = {
  draft: 'bg-gray-500/10 text-gray-400',
  scheduled: 'bg-blue-500/10 text-blue-500',
  sending: 'bg-yellow-500/10 text-yellow-500',
  completed: 'bg-green-500/10 text-green-500',
  paused: 'bg-orange-500/10 text-orange-500',
  failed: 'bg-red-500/10 text-red-500',
};

const campaignStatusLabels: Record<string, string> = {
  draft: 'Rascunho',
  scheduled: 'Agendada',
  sending: 'Enviando',
  completed: 'Concluída',
  paused: 'Pausada',
  failed: 'Falhou',
};

// ============================================================
// MAIN COMPONENT
// ============================================================
export default function MessagingPage() {
  const [activeTab, setActiveTab] = useState<Tab>('dashboard');
  const queryClient = useQueryClient();
  const { user } = useAuthStore();
  const isAdmin = user?.role === 'admin' || user?.role === 'owner';

  // ---- Data queries ----
  const { data: stats, isLoading: loadingStats } = useQuery({
    queryKey: ['messaging-stats'],
    queryFn: () => messagingService.getStats(),
  });

  const { data: whatsappStatus } = useQuery({
    queryKey: ['whatsapp-status'],
    queryFn: () => messagingService.getWhatsAppStatus(),
    refetchInterval: 15000,
  });

  const { data: providers = [] } = useQuery({
    queryKey: ['messaging-providers'],
    queryFn: () => messagingService.getProviders(),
  });

  const { data: templates = [] } = useQuery({
    queryKey: ['messaging-templates'],
    queryFn: () => messagingService.getTemplates('whatsapp'),
  });

  const { data: triggers = [] } = useQuery({
    queryKey: ['messaging-triggers'],
    queryFn: () => messagingService.getTriggers(),
  });

  const { data: variables = {} } = useQuery({
    queryKey: ['messaging-variables'],
    queryFn: () => messagingService.getVariables(),
  });

  const isConnected = whatsappStatus?.connected ?? false;
  const whatsappProvider = providers.find(p => p.channel === 'whatsapp' && p.is_active);

  const allTabs: { key: Tab; label: string; icon: any; adminOnly?: boolean }[] = [
    { key: 'dashboard', label: 'Painel', icon: ChartBarIcon },
    { key: 'send', label: 'Enviar', icon: PaperAirplaneIcon },
    { key: 'campaigns', label: 'Campanhas', icon: MegaphoneIcon },
    { key: 'contacts', label: 'Contatos', icon: UserGroupIcon },
    { key: 'templates', label: 'Templates', icon: DocumentTextIcon },
    { key: 'automations', label: 'Automações', icon: BoltIcon },
    { key: 'logs', label: 'Relatórios', icon: QueueListIcon },
    { key: 'settings', label: 'Configuração', icon: Cog6ToothIcon, adminOnly: true },
  ];
  const tabs = allTabs.filter(t => !t.adminOnly || isAdmin);

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-green-500/10">
            <ChatBubbleLeftRightIcon className="h-7 w-7 text-green-500" />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">WhatsApp Disparo</h1>
            <p className="text-sm text-tx-muted">Plataforma de disparo via API Oficial da Meta</p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {/* Connection status pill */}
          <div className={clsx(
            'flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium',
            isConnected ? 'bg-green-500/10 text-green-500' : 'bg-red-500/10 text-red-400'
          )}>
            <span className={clsx('h-2 w-2 rounded-full', isConnected ? 'bg-green-500 animate-pulse' : 'bg-red-400')} />
            {isConnected ? 'Conectado' : 'Desconectado'}
            {whatsappStatus?.phone && <span className="text-xs opacity-70">({whatsappStatus.phone})</span>}
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 overflow-x-auto pb-1 scrollbar-hide">
        {tabs.map(tab => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={clsx(
              'flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-all',
              activeTab === tab.key
                ? 'bg-green-500/10 text-green-500 shadow-sm'
                : 'text-tx-muted hover:text-tx-secondary hover:bg-surface-hover'
            )}
          >
            <tab.icon className="h-4 w-4" />
            {tab.label}
          </button>
        ))}
      </div>

      {/* Content */}
      {activeTab === 'dashboard' && <DashboardTab stats={stats} loadingStats={loadingStats} whatsappStatus={whatsappStatus} />}
      {activeTab === 'send' && <SendTab whatsappProvider={whatsappProvider} isConnected={isConnected} />}
      {activeTab === 'campaigns' && <CampaignsTab />}
      {activeTab === 'contacts' && <ContactsTab />}
      {activeTab === 'templates' && <TemplatesTab templates={templates} triggers={triggers} variables={variables} />}
      {activeTab === 'automations' && <AutomationsTab templates={templates} triggers={triggers} variables={variables} />}
      {activeTab === 'logs' && <LogsTab />}
      {activeTab === 'settings' && isAdmin && <SettingsTab whatsappStatus={whatsappStatus} providers={providers} />}
    </div>
  );
}

// ============================================================
// DASHBOARD TAB
// ============================================================
function DashboardTab({ stats, loadingStats, whatsappStatus }: {
  stats: any;
  loadingStats: boolean;
  whatsappStatus: WhatsAppStatusResponse | undefined;
}) {
  if (loadingStats) return <div className="py-20"><Spinner size="md" text="Carregando estatísticas..." /></div>;

  const totalSent = stats?.total_sent || 0;
  const totalFailed = stats?.total_failed || 0;
  const whatsappSent = stats?.whatsapp?.sent || 0;
  const deliveryRate = totalSent > 0 ? Math.round(((totalSent - totalFailed) / totalSent) * 100) : 0;

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard icon={PaperAirplaneIcon} label="Mensagens Enviadas" value={whatsappSent} color="green" />
        <StatCard icon={CheckCircleIcon} label="Taxa de Entrega" value={`${deliveryRate}%`} color="blue" />
        <StatCard icon={ExclamationTriangleIcon} label="Falhas" value={totalFailed} color="red" />
        <StatCard icon={DocumentTextIcon} label="Templates Ativos" value={stats?.templates?.active || 0} color="purple" />
      </div>

      {/* Connection Info */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
        <h3 className="text-lg font-semibold text-tx-primary mb-4 flex items-center gap-2">
          <SignalIcon className="h-5 w-5 text-green-500" />
          Status da Conexão WhatsApp
        </h3>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Status</p>
            <div className="flex items-center gap-2">
              <span className={clsx('h-2.5 w-2.5 rounded-full', whatsappStatus?.connected ? 'bg-green-500' : 'bg-red-400')} />
              <span className="font-medium text-tx-primary">{whatsappStatus?.connected ? 'Online' : 'Offline'}</span>
            </div>
          </div>
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Número</p>
            <p className="font-medium text-tx-primary">{whatsappStatus?.phone ? formatPhone(whatsappStatus.phone) : '—'}</p>
          </div>
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Nome do Perfil</p>
            <p className="font-medium text-tx-primary">{whatsappStatus?.profile_name || '—'}</p>
          </div>
        </div>
      </div>

      {/* Recent Activity Chart placeholder */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
        <h3 className="text-lg font-semibold text-tx-primary mb-4">Atividade Recente (7 dias)</h3>
        <div className="grid grid-cols-7 gap-2 h-32">
          {Object.entries(stats?.recent_logs || {}).slice(-7).map(([date, count]: [string, any]) => {
            const maxCount = Math.max(...Object.values(stats?.recent_logs || {}).map(Number), 1);
            const height = Math.max((count / maxCount) * 100, 4);
            return (
              <div key={date} className="flex flex-col items-center justify-end gap-1">
                <div className="w-full bg-green-500/20 rounded-t-lg relative" style={{ height: `${height}%` }}>
                  <div className="absolute inset-0 bg-green-500/40 rounded-t-lg" style={{ height: `${Math.min(height, 100)}%` }} />
                </div>
                <span className="text-[10px] text-tx-muted">{new Date(date).toLocaleDateString('pt-BR', { weekday: 'short' })}</span>
              </div>
            );
          })}
          {Object.keys(stats?.recent_logs || {}).length === 0 && (
            <div className="col-span-7 flex items-center justify-center text-sm text-tx-muted">Sem dados de atividade</div>
          )}
        </div>
      </div>
    </div>
  );
}

function StatCard({ icon: Icon, label, value, color }: { icon: any; label: string; value: string | number; color: string }) {
  const colors: Record<string, string> = {
    green: 'bg-green-500/10 text-green-500',
    blue: 'bg-blue-500/10 text-blue-500',
    red: 'bg-red-500/10 text-red-400',
    purple: 'bg-purple-500/10 text-purple-500',
    yellow: 'bg-yellow-500/10 text-yellow-500',
  };
  return (
    <div className="rounded-2xl bg-surface-card p-3 sm:p-5 border border-border-DEFAULT/50">
      <div className="flex items-center gap-2 mb-3">
        <div className={clsx('p-2 rounded-lg', colors[color])}>
          <Icon className="h-4 w-4" />
        </div>
        <span className="text-sm text-tx-muted">{label}</span>
      </div>
      <p className="text-xl sm:text-2xl font-bold text-tx-primary">{value}</p>
    </div>
  );
}

// ============================================================
// SEND MESSAGE TAB
// ============================================================
function SendTab({ whatsappProvider, isConnected }: { whatsappProvider?: MessagingProvider; isConnected: boolean }) {
  const [mode, setMode] = useState<'single' | 'bulk'>('single');
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [bulkPhones, setBulkPhones] = useState('');
  const [mediaUrl, setMediaUrl] = useState('');
  const [mediaType, setMediaType] = useState<string>('');
  const [delayMs, setDelayMs] = useState(3000);
  const [sending, setSending] = useState(false);

  const handleSendSingle = async () => {
    if (!phone || !message) return toast.error('Preencha o número e a mensagem');
    setSending(true);
    try {
      if (whatsappProvider) {
        await messagingService.sendTestMessage(whatsappProvider.id, phone.replace(/\D/g, ''), message);
      } else {
        await messagingService.sendMessage({ phone: phone.replace(/\D/g, ''), message, media_url: mediaUrl || undefined, media_type: mediaType || undefined });
      }
      toast.success('Mensagem enviada!');
      setPhone('');
      setMessage('');
      setMediaUrl('');
    } catch (err: any) {
      toast.error(err?.response?.data?.error?.message || 'Erro ao enviar mensagem');
    } finally {
      setSending(false);
    }
  };

  const handleSendBulk = async () => {
    const phones = bulkPhones.split('\n').map(p => p.trim().replace(/\D/g, '')).filter(Boolean);
    if (phones.length === 0 || !message) return toast.error('Adicione números e uma mensagem');
    setSending(true);
    try {
      await messagingService.sendBulk({ phones, message, media_url: mediaUrl || undefined, media_type: mediaType || undefined, delay_ms: delayMs });
      toast.success(`Disparo iniciado para ${phones.length} contatos!`);
      setBulkPhones('');
      setMessage('');
    } catch (err: any) {
      toast.error(err?.response?.data?.error?.message || 'Erro ao enviar em massa');
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      {!isConnected && (
        <div className="flex items-center gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20">
          <ExclamationTriangleIcon className="h-5 w-5 text-red-400 flex-shrink-0" />
          <p className="text-sm text-red-400">WhatsApp não conectado. Vá em <strong>Configuração</strong> para conectar.</p>
        </div>
      )}

      {/* Mode toggle */}
      <div className="flex gap-2">
        <button onClick={() => setMode('single')} className={clsx('px-4 py-2 rounded-xl text-sm font-medium transition-all', mode === 'single' ? 'bg-green-500/10 text-green-500' : 'text-tx-muted hover:bg-surface-hover')}>
          <PhoneIcon className="inline h-4 w-4 mr-1.5" />Envio Único
        </button>
        <button onClick={() => setMode('bulk')} className={clsx('px-4 py-2 rounded-xl text-sm font-medium transition-all', mode === 'bulk' ? 'bg-green-500/10 text-green-500' : 'text-tx-muted hover:bg-surface-hover')}>
          <UserGroupIcon className="inline h-4 w-4 mr-1.5" />Envio em Massa
        </button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        {/* Left: Form */}
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-5">
          <h3 className="font-semibold text-tx-primary text-lg">
            {mode === 'single' ? 'Enviar Mensagem' : 'Disparo em Massa'}
          </h3>

          {mode === 'single' ? (
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1.5">Número do WhatsApp</label>
              <input
                type="text"
                value={phone}
                onChange={e => setPhone(e.target.value)}
                placeholder="5511999999999"
                className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none"
              />
            </div>
          ) : (
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1.5">
                Números (um por linha)
              </label>
              <textarea
                value={bulkPhones}
                onChange={e => setBulkPhones(e.target.value)}
                placeholder={"5511999999999\n5521888888888\n5531777777777"}
                rows={5}
                className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none resize-none font-mono text-sm"
              />
              <p className="text-xs text-tx-muted mt-1">
                {bulkPhones.split('\n').filter(l => l.trim()).length} números adicionados
              </p>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1.5">Mensagem</label>
            <textarea
              value={message}
              onChange={e => setMessage(e.target.value)}
              placeholder="Digite sua mensagem aqui... Use *negrito*, _itálico_ e ~tachado~"
              rows={5}
              className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none resize-none"
            />
            <p className="text-xs text-tx-muted mt-1">{message.length} caracteres</p>
          </div>

          {/* Media attachment */}
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1.5">Mídia (opcional)</label>
            <div className="flex gap-2 mb-2">
              {[
                { type: 'image', icon: PhotoIcon, label: 'Imagem' },
                { type: 'video', icon: VideoCameraIcon, label: 'Vídeo' },
                { type: 'document', icon: DocumentIcon, label: 'Documento' },
                { type: 'audio', icon: MusicalNoteIcon, label: 'Áudio' },
              ].map(m => (
                <button
                  key={m.type}
                  onClick={() => setMediaType(mediaType === m.type ? '' : m.type)}
                  className={clsx(
                    'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all',
                    mediaType === m.type ? 'bg-green-500/10 text-green-500 ring-1 ring-green-500/30' : 'bg-surface-hover text-tx-muted hover:text-tx-secondary'
                  )}
                >
                  <m.icon className="h-3.5 w-3.5" />{m.label}
                </button>
              ))}
            </div>
            {mediaType && (
              <input
                type="text"
                value={mediaUrl}
                onChange={e => setMediaUrl(e.target.value)}
                placeholder="URL da mídia (ex: https://...)"
                className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none text-sm"
              />
            )}
          </div>

          {mode === 'bulk' && (
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1.5">Delay entre mensagens (ms)</label>
              <input
                type="number"
                value={delayMs}
                onChange={e => setDelayMs(Number(e.target.value))}
                min={1000}
                max={30000}
                step={500}
                className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none"
              />
              <p className="text-xs text-tx-muted mt-1">Recomendado: 3000ms+ para evitar bloqueio</p>
            </div>
          )}

          <button
            onClick={mode === 'single' ? handleSendSingle : handleSendBulk}
            disabled={sending || !isConnected}
            className="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-green-600 text-white font-semibold hover:bg-green-700 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {sending ? <ArrowPathIcon className="h-5 w-5 animate-spin" /> : <PaperAirplaneIcon className="h-5 w-5" />}
            {sending ? 'Enviando...' : mode === 'single' ? 'Enviar Mensagem' : 'Iniciar Disparo'}
          </button>
        </div>

        {/* Right: Preview */}
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
          <h3 className="font-semibold text-tx-primary mb-4">Pré-visualização</h3>
          <div className="rounded-2xl bg-[#0b141a] p-4 min-h-[300px] flex flex-col">
            {/* WhatsApp-style chat preview */}
            <div className="flex-1 flex flex-col justify-end">
              {message && (
                <div className="self-end max-w-[80%] bg-[#005c4b] rounded-xl rounded-tr-sm px-4 py-2.5 shadow-sm">
                  {mediaType && mediaUrl && (
                    <div className="mb-2 rounded-lg overflow-hidden bg-black/20 p-3 text-center">
                      {mediaType === 'image' && <PhotoIcon className="h-8 w-8 text-green-300/50 mx-auto" />}
                      {mediaType === 'video' && <VideoCameraIcon className="h-8 w-8 text-green-300/50 mx-auto" />}
                      {mediaType === 'document' && <DocumentIcon className="h-8 w-8 text-green-300/50 mx-auto" />}
                      {mediaType === 'audio' && <MusicalNoteIcon className="h-8 w-8 text-green-300/50 mx-auto" />}
                      <p className="text-[10px] text-green-300/50 mt-1">{mediaType}</p>
                    </div>
                  )}
                  <p className="text-sm text-white whitespace-pre-wrap break-words">{message}</p>
                  <p className="text-[10px] text-green-300/50 text-right mt-1">
                    {new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })} ✓✓
                  </p>
                </div>
              )}
              {!message && (
                <p className="text-center text-sm text-green-300/30">Digite uma mensagem para ver a pré-visualização</p>
              )}
            </div>
          </div>

          {/* Variables helper */}
          <div className="mt-4">
            <p className="text-xs font-medium text-tx-muted mb-2">Variáveis disponíveis:</p>
            <div className="flex flex-wrap gap-1.5">
              {['{{nome_cliente}}', '{{telefone_cliente}}', '{{valor}}', '{{produto}}', '{{link_pagamento}}', '{{codigo_pix}}'].map(v => (
                <button
                  key={v}
                  onClick={() => setMessage(prev => prev + v)}
                  className="px-2 py-1 rounded-md bg-surface-hover text-xs text-tx-muted hover:text-green-500 transition-colors"
                >
                  {v}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ============================================================
// CAMPAIGNS TAB
// ============================================================
function CampaignsTab() {
  const queryClient = useQueryClient();
  const [showCreate, setShowCreate] = useState(false);
  const [newCampaign, setNewCampaign] = useState({ name: '', message: '', scheduled_at: '' });

  const { data: campaignsData, isLoading } = useQuery({
    queryKey: ['messaging-campaigns'],
    queryFn: () => messagingService.getCampaigns(),
  });

  const { data: contactLists = [] } = useQuery({
    queryKey: ['messaging-contact-lists'],
    queryFn: () => messagingService.getContactLists(),
  });

  const campaigns = campaignsData?.data || [];

  const createMutation = useMutation({
    mutationFn: (data: Record<string, any>) => messagingService.createCampaign(data),
    onSuccess: () => {
      toast.success('Campanha criada!');
      queryClient.invalidateQueries({ queryKey: ['messaging-campaigns'] });
      setShowCreate(false);
      setNewCampaign({ name: '', message: '', scheduled_at: '' });
    },
    onError: () => toast.error('Erro ao criar campanha'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => messagingService.deleteCampaign(id),
    onSuccess: () => {
      toast.success('Campanha removida');
      queryClient.invalidateQueries({ queryKey: ['messaging-campaigns'] });
    },
  });

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-tx-primary">Campanhas de Disparo</h2>
        <button onClick={() => setShowCreate(!showCreate)} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition-all">
          <PlusIcon className="h-4 w-4" />Nova Campanha
        </button>
      </div>

      {showCreate && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
          <h3 className="font-semibold text-tx-primary">Nova Campanha</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Nome da campanha</label>
              <input type="text" value={newCampaign.name} onChange={e => setNewCampaign(p => ({ ...p, name: e.target.value }))} placeholder="Ex: Black Friday 2026" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
            </div>
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Agendar envio (opcional)</label>
              <input type="datetime-local" value={newCampaign.scheduled_at} onChange={e => setNewCampaign(p => ({ ...p, scheduled_at: e.target.value }))} className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Mensagem</label>
            <textarea value={newCampaign.message} onChange={e => setNewCampaign(p => ({ ...p, message: e.target.value }))} placeholder="Mensagem da campanha..." rows={4} className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none resize-none" />
          </div>
          <div className="flex gap-2">
            <button onClick={() => createMutation.mutate(newCampaign)} disabled={!newCampaign.name || !newCampaign.message || createMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
              {createMutation.isPending ? 'Criando...' : 'Criar Campanha'}
            </button>
            <button onClick={() => setShowCreate(false)} className="px-6 py-2.5 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all">
              Cancelar
            </button>
          </div>
        </div>
      )}

      {isLoading ? (
        <div className="py-12"><Spinner size="md" text="Carregando campanhas..." /></div>
      ) : campaigns.length === 0 ? (
        <div className="rounded-2xl bg-surface-card p-12 border border-border-DEFAULT/50 text-center">
          <MegaphoneIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
          <h3 className="font-semibold text-tx-primary mb-1">Nenhuma campanha</h3>
          <p className="text-sm text-tx-muted">Crie sua primeira campanha de disparo em massa.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {campaigns.map(campaign => (
            <div key={campaign.id} className="rounded-2xl bg-surface-card p-5 border border-border-DEFAULT/50">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <MegaphoneIcon className="h-5 w-5 text-green-500" />
                  <div>
                    <h4 className="font-medium text-tx-primary">{campaign.name}</h4>
                    <p className="text-xs text-tx-muted mt-0.5">
                      {campaign.total_recipients} destinatários
                      {campaign.scheduled_at && ` · Agendada: ${new Date(campaign.scheduled_at).toLocaleString('pt-BR')}`}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <span className={clsx('px-2.5 py-1 rounded-full text-xs font-medium', campaignStatusColors[campaign.status])}>
                    {campaignStatusLabels[campaign.status]}
                  </span>
                  {campaign.status === 'sending' && (
                    <div className="text-xs text-tx-muted">
                      {campaign.sent_count}/{campaign.total_recipients} enviadas
                    </div>
                  )}
                  {campaign.status === 'completed' && (
                    <div className="text-xs text-tx-muted">
                      ✓ {campaign.delivered_count} entregues · {campaign.read_count} lidas
                    </div>
                  )}
                  <button onClick={() => { if (confirm('Remover campanha?')) deleteMutation.mutate(campaign.id); }} className="p-2 rounded-lg text-tx-muted hover:text-red-400 hover:bg-red-500/10 transition-colors">
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
              </div>
              {campaign.status === 'sending' && (
                <div className="mt-3 h-2 rounded-full bg-surface-hover overflow-hidden">
                  <div className="h-full bg-green-500 rounded-full transition-all" style={{ width: `${campaign.total_recipients > 0 ? (campaign.sent_count / campaign.total_recipients) * 100 : 0}%` }} />
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ============================================================
// CONTACTS TAB
// ============================================================
function ContactsTab() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [showAddContact, setShowAddContact] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const [showCreateList, setShowCreateList] = useState(false);
  const [newContact, setNewContact] = useState({ name: '', phone: '', email: '' });
  const [newListName, setNewListName] = useState('');
  const [importText, setImportText] = useState('');
  const [selectedList, setSelectedList] = useState<string>('');

  const { data: contactsData, isLoading } = useQuery({
    queryKey: ['messaging-contacts', search, selectedList],
    queryFn: () => messagingService.getContacts({ search: search || undefined, list_id: selectedList || undefined, per_page: 50 }),
  });

  const { data: contactLists = [] } = useQuery({
    queryKey: ['messaging-contact-lists'],
    queryFn: () => messagingService.getContactLists(),
  });

  const contacts = contactsData?.data || [];

  const createContactMutation = useMutation({
    mutationFn: (data: any) => messagingService.createContact(data),
    onSuccess: () => {
      toast.success('Contato adicionado!');
      queryClient.invalidateQueries({ queryKey: ['messaging-contacts'] });
      setShowAddContact(false);
      setNewContact({ name: '', phone: '', email: '' });
    },
    onError: () => toast.error('Erro ao adicionar contato'),
  });

  const importMutation = useMutation({
    mutationFn: (data: any) => messagingService.importContacts(data),
    onSuccess: (result) => {
      toast.success(`${result.imported} contatos importados, ${result.duplicates} duplicados`);
      queryClient.invalidateQueries({ queryKey: ['messaging-contacts'] });
      queryClient.invalidateQueries({ queryKey: ['messaging-contact-lists'] });
      setShowImport(false);
      setImportText('');
    },
    onError: () => toast.error('Erro ao importar contatos'),
  });

  const createListMutation = useMutation({
    mutationFn: (data: any) => messagingService.createContactList(data),
    onSuccess: () => {
      toast.success('Lista criada!');
      queryClient.invalidateQueries({ queryKey: ['messaging-contact-lists'] });
      setShowCreateList(false);
      setNewListName('');
    },
    onError: () => toast.error('Erro ao criar lista'),
  });

  const deleteContactMutation = useMutation({
    mutationFn: (id: string) => messagingService.deleteContact(id),
    onSuccess: () => {
      toast.success('Contato removido');
      queryClient.invalidateQueries({ queryKey: ['messaging-contacts'] });
    },
  });

  const handleImport = () => {
    const lines = importText.split('\n').filter(l => l.trim());
    const contactsList = lines.map(line => {
      const parts = line.split(/[;,\t]/).map(p => p.trim());
      return { name: parts[0] || '', phone: parts[1] || parts[0] || '', email: parts[2] || '' };
    }).filter(c => c.phone);
    if (contactsList.length === 0) return toast.error('Nenhum contato encontrado');
    importMutation.mutate({ contacts: contactsList, list_id: selectedList || undefined });
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-tx-primary">Contatos</h2>
        <div className="flex gap-2">
          <button onClick={() => setShowImport(!showImport)} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all">
            <ArrowUpTrayIcon className="h-4 w-4" />Importar
          </button>
          <button onClick={() => setShowCreateList(!showCreateList)} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all">
            <TagIcon className="h-4 w-4" />Nova Lista
          </button>
          <button onClick={() => setShowAddContact(!showAddContact)} className="flex items-center gap-2 px-3 py-2 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition-all">
            <PlusIcon className="h-4 w-4" />Adicionar
          </button>
        </div>
      </div>

      {/* Import Section */}
      {showImport && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
          <h3 className="font-semibold text-tx-primary">Importar Contatos</h3>
          <p className="text-xs text-tx-muted">Cole os contatos no formato: <code className="text-green-500">Nome;Telefone;Email</code> (um por linha)</p>
          <textarea value={importText} onChange={e => setImportText(e.target.value)} placeholder={"João Silva;5511999999999;joao@email.com\nMaria Santos;5521888888888"} rows={6} className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none resize-none font-mono text-sm" />
          <div className="flex gap-2">
            <button onClick={handleImport} disabled={importMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
              {importMutation.isPending ? 'Importando...' : `Importar ${importText.split('\n').filter(l => l.trim()).length} contatos`}
            </button>
            <button onClick={() => setShowImport(false)} className="px-6 py-2.5 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium">Cancelar</button>
          </div>
        </div>
      )}

      {/* Create List */}
      {showCreateList && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
          <h3 className="font-semibold text-tx-primary">Nova Lista de Contatos</h3>
          <input type="text" value={newListName} onChange={e => setNewListName(e.target.value)} placeholder="Nome da lista" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
          <div className="flex gap-2">
            <button onClick={() => createListMutation.mutate({ name: newListName })} disabled={!newListName || createListMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">Criar</button>
            <button onClick={() => setShowCreateList(false)} className="px-6 py-2.5 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium">Cancelar</button>
          </div>
        </div>
      )}

      {/* Add Contact */}
      {showAddContact && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
          <h3 className="font-semibold text-tx-primary">Adicionar Contato</h3>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <input type="text" value={newContact.name} onChange={e => setNewContact(p => ({ ...p, name: e.target.value }))} placeholder="Nome" className="px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
            <input type="text" value={newContact.phone} onChange={e => setNewContact(p => ({ ...p, phone: e.target.value }))} placeholder="5511999999999" className="px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
            <input type="email" value={newContact.email} onChange={e => setNewContact(p => ({ ...p, email: e.target.value }))} placeholder="email@exemplo.com" className="px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
          </div>
          <div className="flex gap-2">
            <button onClick={() => createContactMutation.mutate(newContact)} disabled={!newContact.name || !newContact.phone || createContactMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">Adicionar</button>
            <button onClick={() => setShowAddContact(false)} className="px-6 py-2.5 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium">Cancelar</button>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <MagnifyingGlassIcon className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
          <input type="text" value={search} onChange={e => setSearch(e.target.value)} placeholder="Buscar contatos..." className="w-full pl-11 pr-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
        </div>
        <select value={selectedList} onChange={e => setSelectedList(e.target.value)} className="px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none">
          <option value="">Todas as listas</option>
          {contactLists.map(list => (
            <option key={list.id} value={list.id}>{list.name} ({list.contacts_count})</option>
          ))}
        </select>
      </div>

      {/* Contact Lists Summary */}
      {contactLists.length > 0 && (
        <div className="flex gap-3 overflow-x-auto pb-1">
          {contactLists.map(list => (
            <button
              key={list.id}
              onClick={() => setSelectedList(selectedList === list.id ? '' : list.id)}
              className={clsx(
                'flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-all border',
                selectedList === list.id ? 'bg-green-500/10 text-green-500 border-green-500/30' : 'bg-surface-card text-tx-secondary border-border-DEFAULT/50 hover:border-green-500/20'
              )}
            >
              <TagIcon className="h-4 w-4" />
              {list.name}
              <span className="text-xs opacity-60">({list.contacts_count})</span>
            </button>
          ))}
        </div>
      )}

      {/* Contacts Table */}
      {isLoading ? (
        <div className="py-12"><Spinner size="md" text="Carregando contatos..." /></div>
      ) : contacts.length === 0 ? (
        <div className="rounded-2xl bg-surface-card p-12 border border-border-DEFAULT/50 text-center">
          <UserGroupIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
          <h3 className="font-semibold text-tx-primary mb-1">Nenhum contato</h3>
          <p className="text-sm text-tx-muted">Adicione ou importe contatos para começar a enviar mensagens.</p>
        </div>
      ) : (
        <div className="rounded-2xl bg-surface-card border border-border-DEFAULT/50 overflow-x-auto">
          <table className="min-w-full divide-y divide-border-DEFAULT/50">
            <thead className="bg-surface-page">
              <tr>
                <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Nome</th>
                <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Telefone</th>
                <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Email</th>
                <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Adicionado</th>
                <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-tx-muted">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT/50">
              {contacts.map(contact => (
                <tr key={contact.id} className="hover:bg-surface-page/30 transition-colors">
                  <td className="px-3 sm:px-6 py-3 text-sm font-medium text-tx-primary">{contact.name}</td>
                  <td className="px-3 sm:px-6 py-3 text-sm text-tx-secondary font-mono">{formatPhone(contact.phone)}</td>
                  <td className="px-3 sm:px-6 py-3 text-sm text-tx-muted">{contact.email || '—'}</td>
                  <td className="px-3 sm:px-6 py-3 text-sm text-tx-muted">{timeAgo(contact.created_at)}</td>
                  <td className="px-3 sm:px-6 py-3 text-right">
                    <button onClick={() => deleteContactMutation.mutate(contact.id)} className="p-1.5 rounded-lg text-tx-muted hover:text-red-400 hover:bg-red-500/10 transition-colors">
                      <TrashIcon className="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ============================================================
// TEMPLATES TAB
// ============================================================
function TemplatesTab({ templates, triggers, variables }: { templates: MessageTemplate[]; triggers: TriggerOption[]; variables: Record<string, string> }) {
  const queryClient = useQueryClient();
  const [showCreate, setShowCreate] = useState(false);
  const [editingTemplate, setEditingTemplate] = useState<MessageTemplate | null>(null);
  const [form, setForm] = useState({ name: '', trigger_event: '', message_body: '' });

  const createMutation = useMutation({
    mutationFn: (data: Record<string, any>) => messagingService.createTemplate({ ...data, channel: 'whatsapp' }),
    onSuccess: () => {
      toast.success('Template criado!');
      queryClient.invalidateQueries({ queryKey: ['messaging-templates'] });
      setShowCreate(false);
      setForm({ name: '', trigger_event: '', message_body: '' });
    },
    onError: () => toast.error('Erro ao criar template'),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Record<string, any> }) => messagingService.updateTemplate(id, data),
    onSuccess: () => {
      toast.success('Template atualizado!');
      queryClient.invalidateQueries({ queryKey: ['messaging-templates'] });
      setEditingTemplate(null);
    },
    onError: () => toast.error('Erro ao atualizar template'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => messagingService.deleteTemplate(id),
    onSuccess: () => {
      toast.success('Template removido');
      queryClient.invalidateQueries({ queryKey: ['messaging-templates'] });
    },
  });

  const openEdit = (t: MessageTemplate) => {
    setEditingTemplate(t);
    setForm({ name: t.name, trigger_event: t.trigger_event, message_body: t.message_body });
  };

  const whatsappTemplates = templates.filter(t => t.channel === 'whatsapp');

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-tx-primary">Templates de Mensagem</h2>
        <button onClick={() => { setShowCreate(!showCreate); setEditingTemplate(null); }} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition-all">
          <PlusIcon className="h-4 w-4" />Novo Template
        </button>
      </div>

      {/* Create / Edit form */}
      {(showCreate || editingTemplate) && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
          <h3 className="font-semibold text-tx-primary">{editingTemplate ? 'Editar Template' : 'Novo Template'}</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Nome</label>
              <input type="text" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} placeholder="Ex: Confirmação de pagamento" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
            </div>
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Evento gatilho</label>
              <select value={form.trigger_event} onChange={e => setForm(p => ({ ...p, trigger_event: e.target.value }))} className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none">
                <option value="">Sem gatilho (manual)</option>
                {triggers.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Mensagem</label>
            <textarea value={form.message_body} onChange={e => setForm(p => ({ ...p, message_body: e.target.value }))} placeholder="Olá {{nome_cliente}}, seu pagamento de {{valor}} foi confirmado!" rows={5} className="w-full px-4 py-3 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none resize-none" />
            <div className="flex flex-wrap gap-1.5 mt-2">
              {Object.keys(variables).map(v => (
                <button key={v} onClick={() => setForm(p => ({ ...p, message_body: p.message_body + `{{${v}}}` }))} className="px-2 py-1 rounded-md bg-surface-hover text-xs text-tx-muted hover:text-green-500 transition-colors">
                  {`{{${v}}}`}
                </button>
              ))}
            </div>
          </div>
          <div className="flex gap-2">
            {editingTemplate ? (
              <button onClick={() => updateMutation.mutate({ id: editingTemplate.id, data: form })} disabled={updateMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
                {updateMutation.isPending ? 'Salvando...' : 'Salvar'}
              </button>
            ) : (
              <button onClick={() => createMutation.mutate(form)} disabled={!form.name || !form.message_body || createMutation.isPending} className="px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
                {createMutation.isPending ? 'Criando...' : 'Criar Template'}
              </button>
            )}
            <button onClick={() => { setShowCreate(false); setEditingTemplate(null); }} className="px-6 py-2.5 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium">Cancelar</button>
          </div>
        </div>
      )}

      {/* Templates list */}
      {whatsappTemplates.length === 0 ? (
        <div className="rounded-2xl bg-surface-card p-12 border border-border-DEFAULT/50 text-center">
          <DocumentTextIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
          <h3 className="font-semibold text-tx-primary mb-1">Nenhum template</h3>
          <p className="text-sm text-tx-muted">Crie templates para automatizar suas mensagens.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {whatsappTemplates.map(template => (
            <div key={template.id} className="rounded-2xl bg-surface-card p-5 border border-border-DEFAULT/50 hover:border-green-500/20 transition-colors">
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <h4 className="font-medium text-tx-primary">{template.name}</h4>
                    {template.is_active ? (
                      <span className="px-2 py-0.5 rounded-full bg-green-500/10 text-green-500 text-xs font-medium">Ativo</span>
                    ) : (
                      <span className="px-2 py-0.5 rounded-full bg-gray-500/10 text-gray-400 text-xs font-medium">Inativo</span>
                    )}
                  </div>
                  {template.trigger_event && (
                    <p className="text-xs text-tx-muted mb-2">
                      <BoltIcon className="inline h-3 w-3 mr-1" />
                      Gatilho: {triggers.find(t => t.value === template.trigger_event)?.label || template.trigger_event}
                    </p>
                  )}
                  <p className="text-sm text-tx-secondary line-clamp-2 whitespace-pre-wrap">{template.message_body}</p>
                  <p className="text-xs text-tx-muted mt-2">Enviado {template.times_sent}x</p>
                </div>
                <div className="flex items-center gap-1.5">
                  <button onClick={() => openEdit(template)} className="p-2 rounded-lg text-tx-muted hover:text-green-500 hover:bg-green-500/10 transition-colors">
                    <PencilIcon className="h-4 w-4" />
                  </button>
                  <button onClick={() => { if (confirm('Remover template?')) deleteMutation.mutate(template.id); }} className="p-2 rounded-lg text-tx-muted hover:text-red-400 hover:bg-red-500/10 transition-colors">
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ============================================================
// AUTOMATIONS TAB
// ============================================================
function AutomationsTab({ templates, triggers, variables }: { templates: MessageTemplate[]; triggers: TriggerOption[]; variables: Record<string, string> }) {
  const automations = templates.filter(t => t.channel === 'whatsapp' && t.trigger_event);

  return (
    <div className="space-y-4 sm:space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-tx-primary">Automações de WhatsApp</h2>
        <p className="text-sm text-tx-muted mt-1">Mensagens automáticas disparadas por eventos do sistema</p>
      </div>

      {/* How it works */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
        <h3 className="font-medium text-tx-primary mb-3 flex items-center gap-2">
          <InformationCircleIcon className="h-5 w-5 text-green-500" />
          Como funciona
        </h3>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div className="text-center p-4 rounded-xl bg-surface-page">
            <BoltIcon className="h-8 w-8 text-yellow-500 mx-auto mb-2" />
            <p className="text-sm font-medium text-tx-primary">1. Evento dispara</p>
            <p className="text-xs text-tx-muted mt-1">Pagamento aprovado, PIX gerado, etc.</p>
          </div>
          <div className="text-center p-4 rounded-xl bg-surface-page">
            <DocumentTextIcon className="h-8 w-8 text-blue-500 mx-auto mb-2" />
            <p className="text-sm font-medium text-tx-primary">2. Template é selecionado</p>
            <p className="text-xs text-tx-muted mt-1">Variáveis são preenchidas com dados reais</p>
          </div>
          <div className="text-center p-4 rounded-xl bg-surface-page">
            <PaperAirplaneIcon className="h-8 w-8 text-green-500 mx-auto mb-2" />
            <p className="text-sm font-medium text-tx-primary">3. Mensagem enviada</p>
            <p className="text-xs text-tx-muted mt-1">WhatsApp enviado automaticamente ao cliente</p>
          </div>
        </div>
      </div>

      {/* Active automations */}
      <div>
        <h3 className="font-medium text-tx-primary mb-3">Automações ativas ({automations.length})</h3>
        {automations.length === 0 ? (
          <div className="rounded-2xl bg-surface-card p-8 border border-border-DEFAULT/50 text-center">
            <BoltIcon className="h-10 w-10 text-tx-muted mx-auto mb-2" />
            <p className="text-sm text-tx-muted">Nenhuma automação configurada. Crie um template com gatilho de evento na aba Templates.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {automations.map(auto => (
              <div key={auto.id} className="rounded-2xl bg-surface-card p-5 border border-border-DEFAULT/50 flex items-center justify-between">
                <div className="flex items-center gap-4">
                  <div className="p-2.5 rounded-xl bg-yellow-500/10">
                    <BoltIcon className="h-5 w-5 text-yellow-500" />
                  </div>
                  <div>
                    <h4 className="font-medium text-tx-primary">{auto.name}</h4>
                    <p className="text-xs text-tx-muted mt-0.5">
                      Quando: {triggers.find(t => t.value === auto.trigger_event)?.label || auto.trigger_event}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-xs text-tx-muted">{auto.times_sent} envios</span>
                  {auto.is_active ? (
                    <span className="px-2.5 py-1 rounded-full bg-green-500/10 text-green-500 text-xs font-medium">Ativo</span>
                  ) : (
                    <span className="px-2.5 py-1 rounded-full bg-gray-500/10 text-gray-400 text-xs font-medium">Inativo</span>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Available triggers */}
      <div>
        <h3 className="font-medium text-tx-primary mb-3">Eventos disponíveis</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {triggers.map(trigger => {
            const hasAutomation = automations.some(a => a.trigger_event === trigger.value);
            return (
              <div key={trigger.value} className={clsx('flex items-center gap-3 p-3 rounded-xl border transition-colors', hasAutomation ? 'bg-green-500/5 border-green-500/20' : 'bg-surface-card border-border-DEFAULT/50')}>
                <BoltIcon className={clsx('h-4 w-4', hasAutomation ? 'text-green-500' : 'text-tx-muted')} />
                <span className="text-sm text-tx-primary flex-1">{trigger.label}</span>
                {hasAutomation ? (
                  <CheckCircleIcon className="h-4 w-4 text-green-500" />
                ) : (
                  <span className="text-xs text-tx-muted">Não configurado</span>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

// ============================================================
// LOGS TAB
// ============================================================
function LogsTab() {
  const [logFilter, setLogFilter] = useState<string>('');
  const [logPage, setLogPage] = useState(1);

  const { data: logsData, isLoading } = useQuery({
    queryKey: ['messaging-logs', logFilter, logPage],
    queryFn: () => messagingService.getLogs({ channel: 'whatsapp', status: logFilter || undefined, per_page: 20, page: logPage }),
  });

  const logs = logsData?.data || [];
  const meta = logsData?.meta;

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-tx-primary">Relatório de Envios</h2>
        <div className="flex gap-2">
          {['', 'sent', 'delivered', 'read', 'failed'].map(status => (
            <button
              key={status}
              onClick={() => { setLogFilter(status); setLogPage(1); }}
              className={clsx(
                'px-3 py-1.5 rounded-lg text-xs font-medium transition-all',
                logFilter === status ? 'bg-green-500/10 text-green-500' : 'bg-surface-hover text-tx-muted hover:text-tx-secondary'
              )}
            >
              {status === '' ? 'Todos' : statusLabels[status]}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? (
        <div className="py-12"><Spinner size="md" text="Carregando logs..." /></div>
      ) : logs.length === 0 ? (
        <div className="rounded-2xl bg-surface-card p-12 border border-border-DEFAULT/50 text-center">
          <QueueListIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
          <h3 className="font-semibold text-tx-primary mb-1">Nenhum registro</h3>
          <p className="text-sm text-tx-muted">Os envios aparecerão aqui após o primeiro disparo.</p>
        </div>
      ) : (
        <>
          <div className="rounded-2xl bg-surface-card border border-border-DEFAULT/50 overflow-x-auto">
            <table className="min-w-full divide-y divide-border-DEFAULT/50">
              <thead className="bg-surface-page">
                <tr>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Destinatário</th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Mensagem</th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Evento</th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Status</th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">Data</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-DEFAULT/50">
                {logs.map(log => (
                  <tr key={log.id} className="hover:bg-surface-page/30 transition-colors">
                    <td className="px-3 sm:px-6 py-3">
                      <p className="text-sm font-medium text-tx-primary">{log.recipient_name || 'Desconhecido'}</p>
                      <p className="text-xs text-tx-muted font-mono">{formatPhone(log.recipient_phone)}</p>
                    </td>
                    <td className="px-3 sm:px-6 py-3">
                      <p className="text-sm text-tx-secondary line-clamp-2 max-w-xs">{log.message_body}</p>
                    </td>
                    <td className="px-3 sm:px-6 py-3">
                      <span className="text-xs text-tx-muted">{log.trigger_event || 'Manual'}</span>
                    </td>
                    <td className="px-3 sm:px-6 py-3">
                      <span className={clsx('px-2.5 py-1 rounded-full text-xs font-medium', statusColors[log.status])}>
                        {statusLabels[log.status]}
                      </span>
                    </td>
                    <td className="px-3 sm:px-6 py-3 text-sm text-tx-muted">{timeAgo(log.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-tx-muted">
                Página {meta.current_page} de {meta.last_page} ({meta.total} registros)
              </p>
              <div className="flex gap-2">
                <button onClick={() => setLogPage(p => Math.max(1, p - 1))} disabled={logPage <= 1} className="p-2 rounded-lg bg-surface-hover text-tx-secondary disabled:opacity-50">
                  <ChevronLeftIcon className="h-4 w-4" />
                </button>
                <button onClick={() => setLogPage(p => Math.min(meta.last_page, p + 1))} disabled={logPage >= meta.last_page} className="p-2 rounded-lg bg-surface-hover text-tx-secondary disabled:opacity-50">
                  <ChevronRightIcon className="h-4 w-4" />
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

// ============================================================
// SETTINGS TAB
// ============================================================
function SettingsTab({ whatsappStatus, providers }: { whatsappStatus?: WhatsAppStatusResponse; providers: MessagingProvider[] }) {
  const queryClient = useQueryClient();
  const [showSetup, setShowSetup] = useState(false);
  const [setupForm, setSetupForm] = useState({ provider_name: 'evolution_api', api_url: '', api_key: '', instance_name: '', instance_token: '' });
  const [metaForm, setMetaForm] = useState({ phone_number_id: '', access_token: '', business_account_id: '', webhook_verify_token: '' });
  const [configMode, setConfigMode] = useState<'meta' | 'evolution'>('meta');

  const whatsappProviders = providers.filter(p => p.channel === 'whatsapp');

  const autoSetupMutation = useMutation({
    mutationFn: () => messagingService.autoSetupProvider({ channel: 'whatsapp' }),
    onSuccess: () => {
      toast.success('WhatsApp configurado automaticamente!');
      queryClient.invalidateQueries({ queryKey: ['messaging-providers'] });
      queryClient.invalidateQueries({ queryKey: ['whatsapp-status'] });
    },
    onError: () => toast.error('Erro na configuração automática'),
  });

  const createProviderMutation = useMutation({
    mutationFn: (data: Record<string, any>) => messagingService.createProvider(data),
    onSuccess: () => {
      toast.success('Provedor configurado!');
      queryClient.invalidateQueries({ queryKey: ['messaging-providers'] });
      queryClient.invalidateQueries({ queryKey: ['whatsapp-status'] });
      setShowSetup(false);
    },
    onError: () => toast.error('Erro ao configurar provedor'),
  });

  const deleteProviderMutation = useMutation({
    mutationFn: (id: string) => messagingService.deleteProvider(id),
    onSuccess: () => {
      toast.success('Provedor removido');
      queryClient.invalidateQueries({ queryKey: ['messaging-providers'] });
      queryClient.invalidateQueries({ queryKey: ['whatsapp-status'] });
    },
  });

  const disconnectMutation = useMutation({
    mutationFn: () => messagingService.disconnectWhatsApp(),
    onSuccess: () => {
      toast.success('WhatsApp desconectado');
      queryClient.invalidateQueries({ queryKey: ['whatsapp-status'] });
    },
  });

  const restartMutation = useMutation({
    mutationFn: () => messagingService.restartWhatsApp(),
    onSuccess: () => {
      toast.success('Reiniciando conexão...');
      queryClient.invalidateQueries({ queryKey: ['whatsapp-status'] });
    },
  });

  const handleSaveMetaConfig = () => {
    createProviderMutation.mutate({
      channel: 'whatsapp',
      provider_name: 'meta_official',
      api_url: 'https://graph.facebook.com/v21.0',
      api_key: metaForm.access_token,
      instance_name: metaForm.phone_number_id,
      instance_token: metaForm.webhook_verify_token,
      sender_number: metaForm.phone_number_id,
      settings: {
        business_account_id: metaForm.business_account_id,
        phone_number_id: metaForm.phone_number_id,
        api_version: 'v21.0',
        type: 'meta_official',
      },
    });
  };

  const handleSaveEvolutionConfig = () => {
    createProviderMutation.mutate({
      channel: 'whatsapp',
      provider_name: 'evolution_api',
      api_url: setupForm.api_url,
      api_key: setupForm.api_key,
      instance_name: setupForm.instance_name,
      instance_token: setupForm.instance_token,
    });
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Configuração do WhatsApp</h2>

      {/* Connection Status */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
        <h3 className="font-medium text-tx-primary mb-4 flex items-center gap-2">
          <SignalIcon className="h-5 w-5 text-green-500" />
          Status da Conexão
        </h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Status</p>
            <div className="flex items-center gap-2">
              <span className={clsx('h-2.5 w-2.5 rounded-full', whatsappStatus?.connected ? 'bg-green-500 animate-pulse' : 'bg-red-400')} />
              <span className="font-medium text-tx-primary">{whatsappStatus?.connected ? 'Conectado' : 'Desconectado'}</span>
            </div>
          </div>
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Número</p>
            <p className="font-medium text-tx-primary">{whatsappStatus?.phone ? formatPhone(whatsappStatus.phone) : '—'}</p>
          </div>
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Perfil</p>
            <p className="font-medium text-tx-primary">{whatsappStatus?.profile_name || '—'}</p>
          </div>
          <div className="p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
            <p className="text-xs text-tx-muted mb-1">Instância</p>
            <p className="font-medium text-tx-primary">{whatsappStatus?.instance_name || '—'}</p>
          </div>
        </div>

        {whatsappStatus?.connected && (
          <div className="flex gap-2 mt-4">
            <button onClick={() => restartMutation.mutate()} disabled={restartMutation.isPending} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page disabled:opacity-50 transition-all">
              <ArrowPathIcon className={clsx('h-4 w-4', restartMutation.isPending && 'animate-spin')} />Reiniciar
            </button>
            <button onClick={() => { if (confirm('Desconectar WhatsApp?')) disconnectMutation.mutate(); }} disabled={disconnectMutation.isPending} className="flex items-center gap-2 px-4 py-2 rounded-xl bg-red-500/10 text-red-400 text-sm font-medium hover:bg-red-500/20 disabled:opacity-50 transition-all">
              <XMarkIcon className="h-4 w-4" />Desconectar
            </button>
          </div>
        )}
      </div>

      {/* Configuration */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
        <h3 className="font-medium text-tx-primary mb-4 flex items-center gap-2">
          <Cog6ToothIcon className="h-5 w-5 text-green-500" />
          Configurar Provedor
        </h3>

        {/* Mode toggle */}
        <div className="flex gap-2 mb-6">
          <button onClick={() => setConfigMode('meta')} className={clsx('px-4 py-2 rounded-xl text-sm font-medium transition-all', configMode === 'meta' ? 'bg-green-500/10 text-green-500 ring-1 ring-green-500/30' : 'bg-surface-hover text-tx-muted')}>
            API Oficial da Meta
          </button>
          <button onClick={() => setConfigMode('evolution')} className={clsx('px-4 py-2 rounded-xl text-sm font-medium transition-all', configMode === 'evolution' ? 'bg-green-500/10 text-green-500 ring-1 ring-green-500/30' : 'bg-surface-hover text-tx-muted')}>
            Evolution API
          </button>
        </div>

        {configMode === 'meta' ? (
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-4 rounded-xl bg-blue-500/5 border border-blue-500/20">
              <InformationCircleIcon className="h-5 w-5 text-blue-500 flex-shrink-0 mt-0.5" />
              <div className="text-sm text-blue-400">
                <p className="font-medium mb-1">API Oficial do WhatsApp Business (Meta)</p>
                <p className="text-xs opacity-80">Obtenha suas credenciais em <a href="https://developers.facebook.com" target="_blank" rel="noopener noreferrer" className="underline">developers.facebook.com</a> &gt; WhatsApp &gt; API Setup</p>
              </div>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Phone Number ID</label>
                <input type="text" value={metaForm.phone_number_id} onChange={e => setMetaForm(p => ({ ...p, phone_number_id: e.target.value }))} placeholder="Ex: 123456789012345" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Business Account ID</label>
                <input type="text" value={metaForm.business_account_id} onChange={e => setMetaForm(p => ({ ...p, business_account_id: e.target.value }))} placeholder="Ex: 987654321098765" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Access Token (permanente)</label>
                <input type="password" value={metaForm.access_token} onChange={e => setMetaForm(p => ({ ...p, access_token: e.target.value }))} placeholder="EAAxxxxxxx..." className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Webhook Verify Token</label>
                <input type="text" value={metaForm.webhook_verify_token} onChange={e => setMetaForm(p => ({ ...p, webhook_verify_token: e.target.value }))} placeholder="Token personalizado para webhook" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
            </div>
            <button onClick={handleSaveMetaConfig} disabled={!metaForm.phone_number_id || !metaForm.access_token || createProviderMutation.isPending} className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
              {createProviderMutation.isPending ? <ArrowPathIcon className="h-4 w-4 animate-spin" /> : <CheckCircleIcon className="h-4 w-4" />}
              {createProviderMutation.isPending ? 'Salvando...' : 'Salvar Configuração Meta'}
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="flex items-start gap-3 p-4 rounded-xl bg-purple-500/5 border border-purple-500/20">
              <InformationCircleIcon className="h-5 w-5 text-purple-500 flex-shrink-0 mt-0.5" />
              <div className="text-sm text-purple-400">
                <p className="font-medium mb-1">Evolution API</p>
                <p className="text-xs opacity-80">API open-source para WhatsApp. Configure sua instância ou use o setup automático.</p>
              </div>
            </div>

            <button onClick={() => autoSetupMutation.mutate()} disabled={autoSetupMutation.isPending} className="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-purple-600 text-white text-sm font-medium hover:bg-purple-700 disabled:opacity-50 transition-all">
              {autoSetupMutation.isPending ? <ArrowPathIcon className="h-4 w-4 animate-spin" /> : <BoltIcon className="h-4 w-4" />}
              {autoSetupMutation.isPending ? 'Configurando...' : 'Setup Automático'}
            </button>

            <div className="relative flex items-center gap-3">
              <div className="flex-1 h-px bg-border-DEFAULT" />
              <span className="text-xs text-tx-muted">ou configure manualmente</span>
              <div className="flex-1 h-px bg-border-DEFAULT" />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">URL da API</label>
                <input type="text" value={setupForm.api_url} onChange={e => setSetupForm(p => ({ ...p, api_url: e.target.value }))} placeholder="https://evolution.seudominio.com" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">API Key</label>
                <input type="password" value={setupForm.api_key} onChange={e => setSetupForm(p => ({ ...p, api_key: e.target.value }))} placeholder="Sua chave de API" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Nome da Instância</label>
                <input type="text" value={setupForm.instance_name} onChange={e => setSetupForm(p => ({ ...p, instance_name: e.target.value }))} placeholder="wazerpay-whatsapp" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Token da Instância</label>
                <input type="password" value={setupForm.instance_token} onChange={e => setSetupForm(p => ({ ...p, instance_token: e.target.value }))} placeholder="Token da instância" className="w-full px-4 py-2.5 rounded-xl bg-surface-page border border-border-DEFAULT text-tx-primary placeholder-tx-muted focus:border-green-500 focus:ring-2 focus:ring-green-500/20 focus:outline-none" />
              </div>
            </div>
            <button onClick={handleSaveEvolutionConfig} disabled={!setupForm.api_url || !setupForm.api_key || createProviderMutation.isPending} className="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50 transition-all">
              {createProviderMutation.isPending ? <ArrowPathIcon className="h-4 w-4 animate-spin" /> : <CheckCircleIcon className="h-4 w-4" />}
              {createProviderMutation.isPending ? 'Salvando...' : 'Salvar Configuração'}
            </button>
          </div>
        )}
      </div>

      {/* Existing providers */}
      {whatsappProviders.length > 0 && (
        <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50">
          <h3 className="font-medium text-tx-primary mb-4">Provedores Configurados</h3>
          <div className="space-y-3">
            {whatsappProviders.map(provider => (
              <div key={provider.id} className="flex items-center justify-between p-4 rounded-xl bg-surface-page border border-border-DEFAULT/30">
                <div className="flex items-center gap-3">
                  <div className={clsx('h-3 w-3 rounded-full', provider.is_active ? 'bg-green-500' : 'bg-gray-400')} />
                  <div>
                    <p className="font-medium text-tx-primary capitalize">{provider.provider_name.replace(/_/g, ' ')}</p>
                    <p className="text-xs text-tx-muted">
                      {provider.instance_name || provider.sender_number || 'Sem instância'}
                      {' · '}{provider.messages_sent} enviadas
                    </p>
                  </div>
                </div>
                <button onClick={() => { if (confirm('Remover provedor?')) deleteProviderMutation.mutate(provider.id); }} className="p-2 rounded-lg text-tx-muted hover:text-red-400 hover:bg-red-500/10 transition-colors">
                  <TrashIcon className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
