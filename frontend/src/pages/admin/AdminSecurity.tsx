import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ShieldExclamationIcon,
  ExclamationTriangleIcon,
  NoSymbolIcon,
  ArrowPathIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
  TrashIcon,
  PlusIcon,
  GlobeAltIcon,
  EnvelopeIcon,
  DevicePhoneMobileIcon,
  CheckCircleIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface SecurityThreat {
  id: string;
  type: 'fraud_attempt' | 'brute_force' | 'suspicious_activity' | 'bot_detected' | 'card_testing';
  severity: 'low' | 'medium' | 'high' | 'critical';
  ip_address: string;
  user_agent?: string;
  email?: string;
  description: string;
  blocked: boolean;
  created_at: string;
}

interface BlockedItem {
  id: string;
  type: 'ip' | 'email' | 'phone' | 'card_bin' | 'document';
  value: string;
  reason?: string;
  blocked_by?: string;
  created_at: string;
  expires_at?: string;
}

interface SecurityStats {
  threats_today: number;
  threats_week: number;
  blocked_ips: number;
  blocked_emails: number;
  fraud_prevented_amount: number;
}

export default function AdminSecurity() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'threats' | 'blocklist'>('threats');
  const [search, setSearch] = useState('');
  const [severityFilter, setSeverityFilter] = useState<string>('all');
  const [blocklistType, setBlocklistType] = useState<string>('all');
  const [page, setPage] = useState(1);
  const [showAddBlockModal, setShowAddBlockModal] = useState(false);

  // Fetch stats
  const { data: stats } = useQuery<SecurityStats>({
    queryKey: ['security-stats'],
    queryFn: async () => {
      const response = await api.get('/admin/security/stats');
      return response.data?.data || {
        threats_today: 0,
        threats_week: 0,
        blocked_ips: 0,
        blocked_emails: 0,
        fraud_prevented_amount: 0,
      };
    },
  });

  // Fetch threats
  const { data: threatsData, isLoading: threatsLoading, refetch: refetchThreats } = useQuery({
    queryKey: ['security-threats', search, severityFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (search) params.append('search', search);
      if (severityFilter !== 'all') params.append('severity', severityFilter);
      
      const response = await api.get(`/admin/security/threats?${params.toString()}`);
      return response.data;
    },
    enabled: activeTab === 'threats',
  });

  const threats = threatsData?.data || [];
  const threatsPagination = threatsData?.meta || { current_page: 1, last_page: 1, total: 0 };

  // Fetch blocklist
  const { data: blocklistData, isLoading: blocklistLoading, refetch: refetchBlocklist } = useQuery({
    queryKey: ['security-blocklist', blocklistType, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (blocklistType !== 'all') params.append('type', blocklistType);
      
      const response = await api.get(`/admin/security/blocklist?${params.toString()}`);
      return response.data;
    },
    enabled: activeTab === 'blocklist',
  });

  const blocklist = blocklistData?.data || [];
  const blocklistPagination = blocklistData?.meta || { current_page: 1, last_page: 1, total: 0 };

  // Block threat
  const blockThreatMutation = useMutation({
    mutationFn: async (threatId: string) => {
      await api.post(`/admin/security/threats/${threatId}/block`);
    },
    onSuccess: () => {
      toast.success('IP bloqueado');
      queryClient.invalidateQueries({ queryKey: ['security-threats'] });
      queryClient.invalidateQueries({ queryKey: ['security-stats'] });
    },
    onError: () => {
      toast.error('Erro ao bloquear');
    },
  });

  // Dismiss threat
  const dismissThreatMutation = useMutation({
    mutationFn: async (threatId: string) => {
      await api.post(`/admin/security/threats/${threatId}/dismiss`);
    },
    onSuccess: () => {
      toast.success('Ameaça descartada');
      queryClient.invalidateQueries({ queryKey: ['security-threats'] });
    },
    onError: () => {
      toast.error('Erro ao descartar');
    },
  });

  // Add to blocklist
  const addBlockMutation = useMutation({
    mutationFn: async (data: { type: string; value: string; reason?: string; expires_at?: string }) => {
      await api.post('/admin/security/blocklist', data);
    },
    onSuccess: () => {
      toast.success('Item bloqueado');
      queryClient.invalidateQueries({ queryKey: ['security-blocklist'] });
      queryClient.invalidateQueries({ queryKey: ['security-stats'] });
      setShowAddBlockModal(false);
    },
    onError: () => {
      toast.error('Erro ao bloquear');
    },
  });

  // Remove from blocklist
  const removeBlockMutation = useMutation({
    mutationFn: async (blockId: string) => {
      await api.delete(`/admin/security/blocklist/${blockId}`);
    },
    onSuccess: () => {
      toast.success('Desbloqueado');
      queryClient.invalidateQueries({ queryKey: ['security-blocklist'] });
      queryClient.invalidateQueries({ queryKey: ['security-stats'] });
    },
    onError: () => {
      toast.error('Erro ao desbloquear');
    },
  });

  const getSeverityBadge = (severity: string) => {
    switch (severity) {
      case 'critical':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500 px-2 py-0.5 text-xs font-medium text-white">
            Crítico
          </span>
        );
      case 'high':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-medium text-orange-500">
            Alto
          </span>
        );
      case 'medium':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-500">
            Médio
          </span>
        );
      case 'low':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2 py-0.5 text-xs font-medium text-blue-500">
            Baixo
          </span>
        );
      default:
        return null;
    }
  };

  const getThreatIcon = (type: string) => {
    switch (type) {
      case 'fraud_attempt':
        return <ShieldExclamationIcon className="h-5 w-5 text-red-500" />;
      case 'brute_force':
        return <ExclamationTriangleIcon className="h-5 w-5 text-orange-500" />;
      case 'suspicious_activity':
        return <ExclamationTriangleIcon className="h-5 w-5 text-amber-500" />;
      case 'bot_detected':
        return <NoSymbolIcon className="h-5 w-5 text-green-500" />;
      case 'card_testing':
        return <ShieldExclamationIcon className="h-5 w-5 text-red-500" />;
      default:
        return <ExclamationTriangleIcon className="h-5 w-5 text-tx-muted" />;
    }
  };

  const getBlockTypeIcon = (type: string) => {
    switch (type) {
      case 'ip':
        return <GlobeAltIcon className="h-4 w-4" />;
      case 'email':
        return <EnvelopeIcon className="h-4 w-4" />;
      case 'phone':
        return <DevicePhoneMobileIcon className="h-4 w-4" />;
      default:
        return <NoSymbolIcon className="h-4 w-4" />;
    }
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value / 100);
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={ShieldExclamationIcon}
        title="Seguranca"
        subtitle="Monitore ameacas e gerencie bloqueios"
        actions={
          <button
            onClick={() => activeTab === 'threats' ? refetchThreats() : refetchBlocklist()}
            className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        }
      />

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-5">
        <div className="rounded-xl bg-red-500/10 p-4">
          <p className="text-xs text-red-600 mb-1">Ameaças Hoje</p>
          <p className="text-2xl font-bold text-red-600">{stats?.threats_today || 0}</p>
        </div>
        <div className="rounded-xl bg-orange-500/10 p-4">
          <p className="text-xs text-orange-600 mb-1">Ameaças 7 dias</p>
          <p className="text-2xl font-bold text-orange-600">{stats?.threats_week || 0}</p>
        </div>
        <div className="rounded-xl bg-green-500/10 p-4">
          <p className="text-xs text-green-600 mb-1">IPs Bloqueados</p>
          <p className="text-2xl font-bold text-green-600">{stats?.blocked_ips || 0}</p>
        </div>
        <div className="rounded-xl bg-blue-500/10 p-4">
          <p className="text-xs text-blue-600 mb-1">Emails Bloqueados</p>
          <p className="text-2xl font-bold text-blue-600">{stats?.blocked_emails || 0}</p>
        </div>
        <div className="rounded-xl bg-emerald-500/10 p-4">
          <p className="text-xs text-emerald-600 mb-1">Fraude Prevenida</p>
          <p className="text-2xl font-bold text-emerald-600">{formatCurrency(stats?.fraud_prevented_amount || 0)}</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-2 border-b border-border-DEFAULT">
        <button
          onClick={() => {
            setActiveTab('threats');
            setPage(1);
          }}
          className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
            activeTab === 'threats'
              ? 'border-green-500 text-green-500'
              : 'border-transparent text-tx-muted hover:text-tx-primary'
          }`}
        >
          Ameaças Detectadas
        </button>
        <button
          onClick={() => {
            setActiveTab('blocklist');
            setPage(1);
          }}
          className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
            activeTab === 'blocklist'
              ? 'border-green-500 text-green-500'
              : 'border-transparent text-tx-muted hover:text-tx-primary'
          }`}
        >
          Lista de Bloqueio
        </button>
      </div>

      {/* Threats Tab */}
      {activeTab === 'threats' && (
        <>
          {/* Filters */}
          <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
              <div className="relative flex-1">
                <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                <input
                  type="text"
                  placeholder="Buscar por IP ou email..."
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    setPage(1);
                  }}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
                />
              </div>
              <select
                value={severityFilter}
                onChange={(e) => {
                  setSeverityFilter(e.target.value);
                  setPage(1);
                }}
                className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
              >
                <option value="all">Todas severidades</option>
                <option value="critical">Crítico</option>
                <option value="high">Alto</option>
                <option value="medium">Médio</option>
                <option value="low">Baixo</option>
              </select>
            </div>
          </div>

          {/* Threats Table */}
          <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
            {threatsLoading ? (
              <div className="flex items-center justify-center py-20">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
              </div>
            ) : threats.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20">
                <CheckCircleIcon className="h-12 w-12 text-emerald-500" />
                <p className="mt-4 text-sm text-tx-muted">Nenhuma ameaça detectada</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border-DEFAULT bg-surface-page">
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Tipo
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Detalhes
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Severidade
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Status
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Data
                        </th>
                        <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Ações
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-DEFAULT">
                      {threats.map((threat: SecurityThreat) => (
                        <tr key={threat.id} className="hover:bg-surface-hover transition-colors">
                          <td className="px-6 py-4">
                            <div className="flex items-center gap-3">
                              {getThreatIcon(threat.type)}
                              <span className="text-sm font-medium text-tx-primary capitalize">
                                {threat.type.replace(/_/g, ' ')}
                              </span>
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <div>
                              <p className="text-sm text-tx-primary font-mono">{threat.ip_address}</p>
                              {threat.email && (
                                <p className="text-xs text-tx-muted">{threat.email}</p>
                              )}
                              <p className="text-xs text-tx-muted mt-1">{threat.description}</p>
                            </div>
                          </td>
                          <td className="px-6 py-4">{getSeverityBadge(threat.severity)}</td>
                          <td className="px-6 py-4">
                            {threat.blocked ? (
                              <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-500">
                                <NoSymbolIcon className="h-3.5 w-3.5" />
                                Bloqueado
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-500">
                                Ativo
                              </span>
                            )}
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">{formatDate(threat.created_at)}</span>
                          </td>
                          <td className="px-6 py-4">
                            <div className="flex items-center justify-end gap-2">
                              {!threat.blocked && (
                                <button
                                  onClick={() => blockThreatMutation.mutate(threat.id)}
                                  className="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-600 transition-colors"
                                >
                                  Bloquear IP
                                </button>
                              )}
                              <button
                                onClick={() => dismissThreatMutation.mutate(threat.id)}
                                className="rounded-lg bg-surface-hover px-3 py-1.5 text-xs font-medium text-tx-secondary hover:bg-border-DEFAULT transition-colors"
                              >
                                Descartar
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                {threatsPagination.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                    <p className="text-sm text-tx-muted">
                      Página {threatsPagination.current_page} de {threatsPagination.last_page}
                    </p>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setPage(page - 1)}
                        disabled={page === 1}
                        className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                      >
                        <ChevronLeftIcon className="h-5 w-5" />
                      </button>
                      <button
                        onClick={() => setPage(page + 1)}
                        disabled={page >= threatsPagination.last_page}
                        className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                      >
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

      {/* Blocklist Tab */}
      {activeTab === 'blocklist' && (
        <>
          {/* Filters */}
          <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
              <select
                value={blocklistType}
                onChange={(e) => {
                  setBlocklistType(e.target.value);
                  setPage(1);
                }}
                className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
              >
                <option value="all">Todos os tipos</option>
                <option value="ip">IPs</option>
                <option value="email">Emails</option>
                <option value="phone">Telefones</option>
                <option value="card_bin">Card BINs</option>
                <option value="document">Documentos</option>
              </select>

              <button
                onClick={() => setShowAddBlockModal(true)}
                className="flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 transition-colors"
              >
                <PlusIcon className="h-4 w-4" />
                Adicionar Bloqueio
              </button>
            </div>
          </div>

          {/* Blocklist Table */}
          <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
            {blocklistLoading ? (
              <div className="flex items-center justify-center py-20">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
              </div>
            ) : blocklist.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20">
                <NoSymbolIcon className="h-12 w-12 text-tx-muted" />
                <p className="mt-4 text-sm text-tx-muted">Nenhum bloqueio encontrado</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border-DEFAULT bg-surface-page">
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Tipo
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Valor
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Motivo
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Adicionado em
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Expira em
                        </th>
                        <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Ações
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-DEFAULT">
                      {blocklist.map((item: BlockedItem) => (
                        <tr key={item.id} className="hover:bg-surface-hover transition-colors">
                          <td className="px-6 py-4">
                            <div className="flex items-center gap-2">
                              {getBlockTypeIcon(item.type)}
                              <span className="text-sm font-medium text-tx-primary uppercase">{item.type}</span>
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm font-mono text-tx-primary">{item.value}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">{item.reason || '-'}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">{formatDate(item.created_at)}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">
                              {item.expires_at ? formatDate(item.expires_at) : 'Permanente'}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <div className="flex items-center justify-end">
                              <button
                                onClick={() => removeBlockMutation.mutate(item.id)}
                                className="rounded-lg p-2 text-red-500 hover:bg-red-500/10 transition-colors"
                                title="Remover bloqueio"
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

                {/* Pagination */}
                {blocklistPagination.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                    <p className="text-sm text-tx-muted">
                      Página {blocklistPagination.current_page} de {blocklistPagination.last_page}
                    </p>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setPage(page - 1)}
                        disabled={page === 1}
                        className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                      >
                        <ChevronLeftIcon className="h-5 w-5" />
                      </button>
                      <button
                        onClick={() => setPage(page + 1)}
                        disabled={page >= blocklistPagination.last_page}
                        className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                      >
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

      {/* Add Block Modal */}
      {showAddBlockModal && (
        <AddBlockModal
          onClose={() => setShowAddBlockModal(false)}
          onSave={(data) => addBlockMutation.mutate(data)}
          isSaving={addBlockMutation.isPending}
        />
      )}
    </AdminPageShell>
  );
}

function AddBlockModal({
  onClose,
  onSave,
  isSaving,
}: {
  onClose: () => void;
  onSave: (data: { type: string; value: string; reason?: string; expires_at?: string }) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState({
    type: 'ip',
    value: '',
    reason: '',
    expires_at: '',
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-md rounded-2xl bg-surface-card p-6 shadow-xl">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-bold text-tx-primary">Adicionar Bloqueio</h2>
          <button onClick={onClose} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Tipo</label>
            <select
              value={form.type}
              onChange={(e) => setForm({ ...form, type: e.target.value })}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
            >
              <option value="ip">IP Address</option>
              <option value="email">Email</option>
              <option value="phone">Telefone</option>
              <option value="card_bin">Card BIN</option>
              <option value="document">Documento</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Valor</label>
            <input
              type="text"
              value={form.value}
              onChange={(e) => setForm({ ...form, value: e.target.value })}
              placeholder={
                form.type === 'ip' ? '192.168.1.1' :
                form.type === 'email' ? 'exemplo@email.com' :
                form.type === 'phone' ? '+5511999999999' :
                form.type === 'card_bin' ? '411111' :
                '000.000.000-00'
              }
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Motivo (opcional)</label>
            <input
              type="text"
              value={form.reason}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
              placeholder="Tentativa de fraude"
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">Expira em (opcional)</label>
            <input
              type="datetime-local"
              value={form.expires_at}
              onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
            />
            <p className="text-xs text-tx-muted mt-1">Deixe vazio para bloqueio permanente</p>
          </div>

          <div className="flex gap-3 pt-2">
            <button
              onClick={onClose}
              className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
            >
              Cancelar
            </button>
            <button
              onClick={() => onSave(form)}
              disabled={isSaving || !form.value}
              className="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
            >
              {isSaving ? 'Adicionando...' : 'Adicionar'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}


