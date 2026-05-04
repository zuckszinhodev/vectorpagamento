import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate } from '@/lib/api';
import {
  SignalIcon,
  MagnifyingGlassIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  FunnelIcon,
  EyeIcon,
  XMarkIcon,
  DocumentDuplicateIcon,
  ExclamationTriangleIcon,
  PlusIcon,
  TrashIcon,
  ShieldCheckIcon,
  GlobeAltIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface WebhookConfig {
  id: string;
  url: string;
  events: string[];
  secret?: string;
  is_active: boolean;
  created_at: string;
}

interface WebhookLog {
  id: string;
  webhook_id: string;
  webhook_url: string;
  event: string;
  payload: Record<string, any>;
  response_status: number | null;
  response_body: string | null;
  status: 'success' | 'failed' | 'pending';
  attempts: number;
  last_attempt_at: string;
  created_at: string;
}

export default function WebhookLogsPage() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'logs' | 'config'>('logs');
  
  // Log filters
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [eventFilter, setEventFilter] = useState('all');
  const [page, setPage] = useState(1);
  const [selectedLog, setSelectedLog] = useState<WebhookLog | null>(null);
  
  // Config state
  const [showAddWebhook, setShowAddWebhook] = useState(false);
  const [newUrl, setNewUrl] = useState('');
  const [newEvents, setNewEvents] = useState<string[]>(['*']);

  const availableEvents = [
    { value: '*', label: 'Todos os eventos' },
    { value: 'charge.created', label: 'Cobrança criada' },
    { value: 'charge.paid', label: 'Cobrança paga' },
    { value: 'charge.failed', label: 'Cobrança falhou' },
    { value: 'charge.refunded', label: 'Cobrança reembolsada' },
    { value: 'charge.disputed', label: 'Cobrança disputada' },
    { value: 'payment.approved', label: 'Pagamento aprovado' },
    { value: 'payment.rejected', label: 'Pagamento rejeitado' },
    { value: 'payment.refunded', label: 'Pagamento reembolsado' },
    { value: 'payment.expired', label: 'Pagamento expirado' },
    { value: 'payment.cancelled', label: 'Pagamento cancelado' },
    { value: 'payment.chargedback', label: 'Chargeback' },
    { value: 'withdrawal.requested', label: 'Saque solicitado' },
    { value: 'withdrawal.completed', label: 'Saque concluído' },
  ];

  // Fetch webhook configs
  const { data: webhooksData } = useQuery({
    queryKey: ['user-webhooks'],
    queryFn: async () => {
      const response = await api.get('/webhooks');
      return response.data;
    },
  });

  const webhooks: WebhookConfig[] = webhooksData?.data || [];

  // Fetch webhook logs
  const { data: logsData, isLoading } = useQuery({
    queryKey: ['webhook-logs', search, statusFilter, eventFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '20');
      if (search) params.append('search', search);
      if (statusFilter !== 'all') params.append('status', statusFilter);
      if (eventFilter !== 'all') params.append('event', eventFilter);
      const response = await api.get(`/webhook-logs?${params.toString()}`);
      return response.data;
    },
    enabled: activeTab === 'logs',
  });

  const logs: WebhookLog[] = logsData?.data || [];
  const pagination = logsData?.meta || { current_page: 1, last_page: 1, total: 0 };

  // Create webhook
  const createMutation = useMutation({
    mutationFn: async () => {
      await api.post('/webhooks', { url: newUrl, events: newEvents });
    },
    onSuccess: () => {
      toast.success('Webhook criado!');
      queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
      setShowAddWebhook(false);
      setNewUrl('');
      setNewEvents(['*']);
    },
    onError: () => toast.error('Erro ao criar webhook'),
  });

  // Delete webhook
  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/webhooks/${id}`);
    },
    onSuccess: () => {
      toast.success('Webhook removido');
      queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
    },
    onError: () => toast.error('Erro ao remover webhook'),
  });

  // Toggle webhook
  const toggleMutation = useMutation({
    mutationFn: async ({ id, is_active }: { id: string; is_active: boolean }) => {
      await api.put(`/webhooks/${id}`, { is_active });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
    },
  });

  // Retry webhook
  const retryMutation = useMutation({
    mutationFn: async (logId: string) => {
      await api.post(`/webhook-logs/${logId}/retry`);
    },
    onSuccess: () => {
      toast.success('Reenvio agendado');
      queryClient.invalidateQueries({ queryKey: ['webhook-logs'] });
    },
    onError: () => toast.error('Erro ao reenviar'),
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'success':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" /> Sucesso
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" /> Falhou
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <ClockIcon className="h-3.5 w-3.5" /> Pendente
          </span>
        );
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Copiado!');
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-tx-primary">Webhook Logs</h1>
          <p className="text-sm text-tx-muted">Monitore entregas de webhooks e gerencie endpoints</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex items-center gap-1 rounded-xl bg-surface-card p-1 shadow-sm">
        <button
          onClick={() => setActiveTab('logs')}
          className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
            activeTab === 'logs' ? 'bg-green-600 text-white shadow-sm' : 'text-tx-secondary hover:bg-surface-hover'
          }`}
        >
          <SignalIcon className="h-4 w-4" /> Logs de Entrega
        </button>
        <button
          onClick={() => setActiveTab('config')}
          className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
            activeTab === 'config' ? 'bg-green-600 text-white shadow-sm' : 'text-tx-secondary hover:bg-surface-hover'
          }`}
        >
          <GlobeAltIcon className="h-4 w-4" /> Endpoints ({webhooks.length})
        </button>
      </div>

      {/* Logs Tab */}
      {activeTab === 'logs' && (
        <>
          {/* Filters */}
          <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
              <div className="relative flex-1">
                <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                <input
                  type="text"
                  placeholder="Buscar por URL ou evento..."
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
                />
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <select
                  value={statusFilter}
                  onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
                  className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                >
                  <option value="all">Todos os status</option>
                  <option value="success">Sucesso</option>
                  <option value="failed">Falhas</option>
                  <option value="pending">Pendentes</option>
                </select>
                <select
                  value={eventFilter}
                  onChange={(e) => { setEventFilter(e.target.value); setPage(1); }}
                  className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                >
                  <option value="all">Todos os eventos</option>
                  {availableEvents.filter(e => e.value !== '*').map(e => (
                    <option key={e.value} value={e.value}>{e.label}</option>
                  ))}
                </select>
                <button
                  onClick={() => queryClient.invalidateQueries({ queryKey: ['webhook-logs'] })}
                  className="flex items-center gap-2 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-border-DEFAULT transition-colors"
                >
                  <ArrowPathIcon className="h-4 w-4" /> Atualizar
                </button>
              </div>
            </div>
          </div>

          {/* Logs Table */}
          <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
            {isLoading ? (
              <div className="flex items-center justify-center py-20">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
              </div>
            ) : logs.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20">
                <SignalIcon className="h-12 w-12 text-tx-muted" />
                <p className="mt-4 text-sm text-tx-muted">Nenhum log encontrado</p>
                <p className="text-xs text-tx-muted mt-1">Configure um webhook e faça uma transação para ver os logs</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border-DEFAULT bg-surface-page">
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Evento</th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">URL</th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Status</th>
                        <th className="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-tx-muted">HTTP</th>
                        <th className="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-tx-muted">Tentativas</th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Data</th>
                        <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">Ações</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-DEFAULT">
                      {logs.map((log) => (
                        <tr key={log.id} className="hover:bg-surface-hover transition-colors">
                          <td className="px-6 py-4">
                            <span className="inline-flex rounded-lg bg-green-500/10 px-2.5 py-1 text-xs font-medium text-green-600">
                              {log.event}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-primary truncate max-w-[200px] block" title={log.webhook_url}>
                              {log.webhook_url}
                            </span>
                          </td>
                          <td className="px-6 py-4">{getStatusBadge(log.status)}</td>
                          <td className="px-6 py-4 text-center">
                            <span className={`text-sm font-mono ${
                              log.response_status && log.response_status >= 200 && log.response_status < 300
                                ? 'text-emerald-500'
                                : log.response_status
                                  ? 'text-red-500'
                                  : 'text-tx-muted'
                            }`}>
                              {log.response_status || '—'}
                            </span>
                          </td>
                          <td className="px-6 py-4 text-center">
                            <span className="text-sm text-tx-muted">{log.attempts}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">{formatDate(log.created_at)}</span>
                          </td>
                          <td className="px-6 py-4">
                            <div className="flex items-center justify-end gap-1">
                              <button
                                onClick={() => setSelectedLog(log)}
                                className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover hover:text-tx-primary transition-colors"
                                title="Ver detalhes"
                              >
                                <EyeIcon className="h-4 w-4" />
                              </button>
                              {log.status === 'failed' && (
                                <button
                                  onClick={() => retryMutation.mutate(log.id)}
                                  className="rounded-lg p-2 text-amber-500 hover:bg-amber-500/10 transition-colors"
                                  title="Reenviar"
                                >
                                  <ArrowPathIcon className="h-4 w-4" />
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {pagination.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                    <p className="text-sm text-tx-muted">
                      Página {pagination.current_page} de {pagination.last_page} ({pagination.total} logs)
                    </p>
                    <div className="flex items-center gap-2">
                      <button onClick={() => setPage(page - 1)} disabled={page === 1} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50">
                        <ChevronLeftIcon className="h-5 w-5" />
                      </button>
                      <button onClick={() => setPage(page + 1)} disabled={page >= pagination.last_page} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50">
                        <ChevronRightIcon className="h-5 w-5" />
                      </button>
                    </div>
                  </div>
                )}
              </>
            )}
          </div>
        </>
      )}

      {/* Config Tab */}
      {activeTab === 'config' && (
        <>
          <div className="flex items-center justify-between">
            <p className="text-sm text-tx-muted">Máximo de 5 endpoints por conta</p>
            {webhooks.length < 5 && (
              <button
                onClick={() => setShowAddWebhook(true)}
                className="flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors"
              >
                <PlusIcon className="h-4 w-4" /> Novo Webhook
              </button>
            )}
          </div>

          {/* Add webhook form */}
          {showAddWebhook && (
            <div className="rounded-2xl bg-surface-card p-6 shadow-sm border border-border-DEFAULT space-y-4">
              <h3 className="font-semibold text-tx-primary">Novo Webhook</h3>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Endpoint</label>
                <input
                  type="url"
                  value={newUrl}
                  onChange={(e) => setNewUrl(e.target.value)}
                  placeholder="https://seu-servidor.com/webhook"
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-2">Eventos</label>
                <div className="grid grid-cols-2 gap-2">
                  {availableEvents.map(e => (
                    <label key={e.value} className="flex items-center gap-2 text-sm text-tx-secondary cursor-pointer">
                      <input
                        type="checkbox"
                        checked={newEvents.includes(e.value)}
                        onChange={(ev) => {
                          if (e.value === '*') {
                            setNewEvents(ev.target.checked ? ['*'] : []);
                          } else {
                            const filtered = newEvents.filter(v => v !== '*');
                            setNewEvents(
                              ev.target.checked
                                ? [...filtered, e.value]
                                : filtered.filter(v => v !== e.value)
                            );
                          }
                        }}
                        className="rounded border-border-DEFAULT text-green-600 focus:ring-green-500"
                      />
                      {e.label}
                    </label>
                  ))}
                </div>
              </div>
              <div className="flex gap-3">
                <button
                  onClick={() => setShowAddWebhook(false)}
                  className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
                >
                  Cancelar
                </button>
                <button
                  onClick={() => createMutation.mutate()}
                  disabled={!newUrl || newEvents.length === 0 || createMutation.isPending}
                  className="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
                >
                  {createMutation.isPending ? 'Criando...' : 'Criar Webhook'}
                </button>
              </div>
            </div>
          )}

          {/* Webhook list */}
          <div className="space-y-3">
            {webhooks.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 rounded-2xl bg-surface-card">
                <GlobeAltIcon className="h-12 w-12 text-tx-muted" />
                <p className="mt-4 text-sm text-tx-muted">Nenhum webhook configurado</p>
                <p className="text-xs text-tx-muted mt-1">Crie um endpoint para receber notificações de eventos</p>
              </div>
            ) : (
              webhooks.map((wh) => (
                <div key={wh.id} className="rounded-2xl bg-surface-card p-5 shadow-sm border border-border-DEFAULT">
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-2">
                        <span className={`h-2.5 w-2.5 rounded-full ${wh.is_active ? 'bg-emerald-500' : 'bg-red-500'}`} />
                        <span className="text-sm font-medium text-tx-primary truncate">{wh.url}</span>
                      </div>
                      <div className="flex flex-wrap gap-1.5">
                        {wh.events.map(e => (
                          <span key={e} className="rounded-lg bg-green-500/10 px-2 py-0.5 text-[11px] font-medium text-green-600">
                            {e}
                          </span>
                        ))}
                      </div>
                      {wh.secret && (
                        <div className="mt-2 flex items-center gap-2">
                          <ShieldCheckIcon className="h-3.5 w-3.5 text-tx-muted" />
                          <span className="text-xs text-tx-muted font-mono">
                            {wh.secret.substring(0, 8)}...
                          </span>
                          <button onClick={() => copyToClipboard(wh.secret!)} className="text-tx-muted hover:text-tx-primary">
                            <DocumentDuplicateIcon className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => toggleMutation.mutate({ id: wh.id, is_active: !wh.is_active })}
                        className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                          wh.is_active
                            ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20'
                            : 'bg-red-500/10 text-red-600 hover:bg-red-500/20'
                        }`}
                      >
                        {wh.is_active ? 'Ativo' : 'Inativo'}
                      </button>
                      <button
                        onClick={() => deleteMutation.mutate(wh.id)}
                        className="rounded-lg p-2 text-red-500 hover:bg-red-500/10 transition-colors"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </>
      )}

      {/* Detail Modal */}
      {selectedLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-2xl max-h-[80vh] rounded-2xl bg-surface-card shadow-xl flex flex-col">
            <div className="flex items-center justify-between p-6 border-b border-border-DEFAULT">
              <div>
                <h2 className="text-lg font-bold text-tx-primary">Detalhes do Webhook</h2>
                <p className="text-xs text-tx-muted mt-1">{selectedLog.event}</p>
              </div>
              <button onClick={() => setSelectedLog(null)} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">Status</p>
                  {getStatusBadge(selectedLog.status)}
                </div>
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">HTTP Status</p>
                  <p className="text-lg font-bold text-tx-primary">{selectedLog.response_status || 'N/A'}</p>
                </div>
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">Tentativas</p>
                  <p className="text-lg font-bold text-tx-primary">{selectedLog.attempts}</p>
                </div>
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">Data</p>
                  <p className="text-sm font-medium text-tx-primary">{formatDate(selectedLog.created_at)}</p>
                </div>
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <p className="text-sm font-semibold text-tx-secondary">URL</p>
                  <button onClick={() => copyToClipboard(selectedLog.webhook_url)} className="text-xs text-green-500 hover:text-green-400">
                    Copiar
                  </button>
                </div>
                <p className="rounded-xl bg-surface-page p-3 text-sm text-tx-primary font-mono break-all">{selectedLog.webhook_url}</p>
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <p className="text-sm font-semibold text-tx-secondary">Payload Enviado</p>
                  <button onClick={() => copyToClipboard(JSON.stringify(selectedLog.payload, null, 2))} className="text-xs text-green-500 hover:text-green-400">
                    Copiar
                  </button>
                </div>
                <pre className="rounded-xl bg-surface-page p-4 text-xs text-tx-primary font-mono overflow-x-auto max-h-60">
                  {JSON.stringify(selectedLog.payload, null, 2)}
                </pre>
              </div>

              {selectedLog.response_body && (
                <div>
                  <p className="text-sm font-semibold text-tx-secondary mb-2">Resposta</p>
                  <pre className="rounded-xl bg-surface-page p-4 text-xs text-tx-primary font-mono overflow-x-auto max-h-40">
                    {selectedLog.response_body}
                  </pre>
                </div>
              )}
            </div>
            <div className="flex gap-3 p-6 border-t border-border-DEFAULT">
              <button onClick={() => setSelectedLog(null)} className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors">
                Fechar
              </button>
              {selectedLog.status === 'failed' && (
                <button
                  onClick={() => { retryMutation.mutate(selectedLog.id); setSelectedLog(null); }}
                  className="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 transition-colors"
                >
                  Reenviar Webhook
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}


