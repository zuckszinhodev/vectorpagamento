import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  BanknotesIcon,
  MagnifyingGlassIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  FunnelIcon,
  ArrowPathIcon,
  XMarkIcon,
  CreditCardIcon,
  BuildingLibraryIcon,
  BoltIcon,
  Cog6ToothIcon,
  CalendarDaysIcon,
  ShieldCheckIcon,
  DocumentTextIcon,
  CurrencyDollarIcon,
  EyeIcon,
  ClipboardDocumentIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Withdrawal {
  id: string;
  customer_id: string;
  customer_name: string;
  customer_email?: string;
  amount: number;
  fee?: number;
  net_amount?: number;
  method?: string;
  is_instant?: boolean;
  pix_key?: string;
  bank_name?: string;
  bank_account?: string;
  bank_agency?: string;
  status: 'pending' | 'approved' | 'rejected' | 'processing' | 'completed' | 'cancelled';
  requested_at: string;
  processed_at?: string;
  notes?: string;
  failure_reason?: string;
  cancel_reason?: string;
}

interface WithdrawalConfig {
  fee_percentage: number;
  fee_fixed: number;
  standard_withdrawal_fee_pix: number;
  standard_withdrawal_fee_bank: number;
  instant_withdrawal_enabled: boolean;
  instant_withdrawal_fee_type: 'percentage' | 'fixed';
  instant_withdrawal_fee_value: number;
  withdrawal_min_amount: number;
  withdrawal_max_amount: number;
  auto_approve_limit: number;
  withdrawals_enabled: boolean;
  withdrawal_processing_enabled: boolean;
  payments_enabled: boolean;
  daily_withdrawal_limit: number;
  large_withdrawal_threshold: number;
  withdrawal_cooldown_seconds: number;
  max_pending_withdrawals_per_account: number;
  settlement_days_pix: number;
  settlement_days_ted: number;
  processing_days: number;
  auto_withdrawal_enabled: boolean;
  auto_withdrawal_schedule: 'manual' | 'daily' | 'weekly' | 'biweekly' | 'monthly';
  auto_withdrawal_min_amount: number;
  holdback_enabled: boolean;
  holdback_days: number;
  holdback_percentage: number;
}

export default function AdminFinance() {
  const queryClient = useQueryClient();
  const [activeMainTab, setActiveMainTab] = useState<'withdrawals' | 'transactions'>('transactions');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('pending');
  const [page, setPage] = useState(1);
  const [selectedWithdrawal, setSelectedWithdrawal] = useState<Withdrawal | null>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showConfigModal, setShowConfigModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');

  // Transaction tab states
  const [txSearch, setTxSearch] = useState('');
  const [txStatus, setTxStatus] = useState('all');
  const [txMethod, setTxMethod] = useState('all');
  const [txAcquirer, setTxAcquirer] = useState('all');
  const [txDateFrom, setTxDateFrom] = useState('');
  const [txDateTo, setTxDateTo] = useState('');
  const [txPage, setTxPage] = useState(1);
  const [selectedCharge, setSelectedCharge] = useState<any>(null);
  const [showChargeDetailModal, setShowChargeDetailModal] = useState(false);

  // Fetch withdrawals
  const { data: withdrawalsData, isLoading, refetch } = useQuery({
    queryKey: ['admin-withdrawals', search, statusFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (search) params.append('search', search);
      if (statusFilter !== 'all') {
        params.append('status', statusFilter === 'rejected' ? 'cancelled' : statusFilter);
      }

      const response = await api.get(`/admin/withdrawals?${params.toString()}`);
      return response.data;
    },
  });

  const withdrawals: Withdrawal[] = withdrawalsData?.data ?? [];
  const pagination = withdrawalsData?.meta ?? { current_page: 1, last_page: 1, total: 0 };

  const { data: configData } = useQuery({
    queryKey: ['admin-withdrawals-config'],
    queryFn: async () => {
      const response = await api.get('/admin/withdrawals/config');
      return response.data;
    },
  });

  const config: WithdrawalConfig | undefined = configData?.data;

  const { data: chargesData, isLoading: isLoadingCharges, refetch: refetchCharges } = useQuery({
    queryKey: ['admin-charges', txSearch, txStatus, txMethod, txAcquirer, txDateFrom, txDateTo, txPage],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', txPage.toString());
      params.append('per_page', '20');
      if (txSearch) params.append('search', txSearch);
      if (txStatus !== 'all') params.append('status', txStatus);
      if (txMethod !== 'all') params.append('payment_method', txMethod);
      if (txAcquirer !== 'all') params.append('acquirer', txAcquirer);
      if (txDateFrom) params.append('date_from', txDateFrom);
      if (txDateTo) params.append('date_to', txDateTo);

      const response = await api.get(`/admin/charges?${params.toString()}`);
      return response.data;
    },
  });

  const charges = chargesData?.data ?? [];
  const chargesPagination = chargesData?.meta ?? { current_page: 1, last_page: 1, total: 0 };

  const processMutation = useMutation({
    mutationFn: async ({ withdrawalId, action, notes }: { withdrawalId: string; action: 'approve' | 'reject'; notes?: string }) => {
      if (action === 'approve') {
        const response = await api.post(`/admin/withdrawals/${withdrawalId}/process`);
        return response.data;
      }

      const response = await api.post(`/admin/withdrawals/${withdrawalId}/cancel`, {
        reason: notes || 'Rejeitado pela equipe financeira.',
      });
      return response.data;
    },
    onSuccess: (payload, variables) => {
      if (payload?.success === false && payload?.error?.code === 'dual_control_required') {
        const approvalId = payload?.error?.approval_id;
        if (approvalId) {
          navigator.clipboard?.writeText(approvalId).catch(() => undefined);
        }
        toast.success(
          approvalId
            ? `Ação crítica enviada para aprovação dupla. ID: ${approvalId} (copiado).`
            : 'Ação crítica enviada para aprovação dupla.'
        );
      } else {
          if (variables.action === 'reject') {
            const cancelReason = payload?.cancel_reason || payload?.data?.failure_reason || payload?.data?.notes || variables.notes;
            toast.success(cancelReason ? `Saque cancelado. Motivo: ${cancelReason}` : 'Saque cancelado com sucesso.');
          } else {
            toast.success('Operação concluída com sucesso.');
          }
      }

      queryClient.invalidateQueries({ queryKey: ['admin-withdrawals'] });
      setShowDetailModal(false);
      setSelectedWithdrawal(null);
      setRejectReason('');
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || error?.response?.data?.error?.message || 'Erro ao processar saque.';
      toast.error(message);
    },
  });

  const processAllMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/admin/withdrawals/process-all');
      return response.data;
    },
    onSuccess: (payload) => {
      if (payload?.success === false && payload?.error?.code === 'dual_control_required') {
        const approvalId = payload?.error?.approval_id;
        if (approvalId) {
          navigator.clipboard?.writeText(approvalId).catch(() => undefined);
        }
        toast.success(
          approvalId
            ? `Lote enviado para aprovação dupla. ID: ${approvalId} (copiado).`
            : 'Lote enviado para aprovação dupla.'
        );
      } else {
        toast.success(payload?.message || 'Processamento em lote executado.');
      }
      queryClient.invalidateQueries({ queryKey: ['admin-withdrawals'] });
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || error?.response?.data?.error?.message || 'Erro ao processar saques em lote.';
      toast.error(message);
    },
  });

  const updateConfigMutation = useMutation({
    mutationFn: async (newConfig: Partial<WithdrawalConfig>) => {
      const response = await api.put('/admin/withdrawals/config', newConfig);
      return response.data;
    },
    onSuccess: () => {
      toast.success('Configurações financeiras atualizadas.');
      queryClient.invalidateQueries({ queryKey: ['admin-withdrawals-config'] });
      setShowConfigModal(false);
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || 'Erro ao salvar configurações.';
      toast.error(message);
    },
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Concluido
          </span>
        );
      case 'approved':
      case 'processing':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2.5 py-1 text-xs font-medium text-blue-500">
            <ArrowPathIcon className="h-3.5 w-3.5" />
            Processando
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <ClockIcon className="h-3.5 w-3.5" />
            Pendente
          </span>
        );
      case 'rejected':
      case 'cancelled':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            {status === 'rejected' ? 'Rejeitado' : 'Cancelado'}
          </span>
        );
      default:
        return null;
    }
  };

  const pendingCount = withdrawals.filter((w: Withdrawal) => w.status === 'pending').length;
  const pendingTotal = withdrawals
    .filter((w: Withdrawal) => w.status === 'pending')
    .reduce((sum: number, w: Withdrawal) => sum + w.amount, 0);
  const completedWithdrawalsTotal = withdrawals
    .filter((w: Withdrawal) => w.status === 'completed')
    .reduce((sum: number, w: Withdrawal) => sum + (w.net_amount ?? w.amount), 0);
  const approvalsCount = charges.filter((c: any) => c.status === 'approved').length;
  const successRate = charges.length > 0 ? Math.round((approvalsCount / charges.length) * 100) : 0;

  const getChargeStatusBadge = (status: string) => {
    switch (status) {
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Aprovado
          </span>
        );
      case 'pending':
      case 'processing':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <ClockIcon className="h-3.5 w-3.5" />
            {status === 'pending' ? 'Pendente' : 'Processando'}
          </span>
        );
      case 'refunded':
      case 'partially_refunded':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2.5 py-1 text-xs font-medium text-blue-500">
            <ArrowPathIcon className="h-3.5 w-3.5" />
            {status === 'refunded' ? 'Reembolsado' : 'Reembolso Parcial'}
          </span>
        );
      case 'chargeback':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            Chargeback
          </span>
        );
      case 'declined':
      case 'failed':
      case 'cancelled':
      case 'expired':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            {status === 'declined' ? 'Recusado' : status === 'failed' ? 'Falhou' : status === 'cancelled' ? 'Cancelado' : 'Expirado'}
          </span>
        );
      default:
        return <span className="text-xs text-tx-muted">{status}</span>;
    }
  };

  const getPaymentMethodLabel = (method: string) => {
    switch (method?.toLowerCase()) {
      case 'pix': return 'PIX';
      case 'credit_card': case 'card': case 'cartao': return 'Cartão';
      case 'boleto': return 'Boleto';
      default: return method || '—';
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={BanknotesIcon}
        title="Financeiro"
        subtitle="Gerencie saques, transacoes e configuracoes financeiras"
        actions={
          activeMainTab === 'withdrawals' ? (
            <div className="flex items-center gap-2">
              <button
                onClick={() => setShowConfigModal(true)}
                className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
              >
                Configuracoes
              </button>
              {pendingCount > 0 && (
                <button
                  onClick={() => processAllMutation.mutate()}
                  disabled={processAllMutation.isPending}
                  className="flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-200 transition-colors"
                >
                  <BoltIcon className="h-4 w-4" />
                  Processar Todos ({pendingCount})
                </button>
              )}
            </div>
          ) : null
        }
      />

      {/* Main Tabs */}
      <div className="flex items-center gap-1 rounded-xl bg-surface-card p-1 shadow-sm">
        <button
          onClick={() => setActiveMainTab('transactions')}
          className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
            activeMainTab === 'transactions'
              ? 'bg-emerald-600 text-white shadow-sm'
              : 'text-tx-secondary hover:bg-surface-hover'
          }`}
        >
          <DocumentTextIcon className="h-4 w-4" />
          Transações
          {chargesPagination.total > 0 && (
            <span className={`rounded-full px-2 py-0.5 text-xs ${
              activeMainTab === 'transactions' ? 'bg-white/20' : 'bg-emerald-500/10 text-emerald-600'
            }`}>
              {chargesPagination.total}
            </span>
          )}
        </button>
        <button
          onClick={() => setActiveMainTab('withdrawals')}
          className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
            activeMainTab === 'withdrawals'
              ? 'bg-emerald-600 text-white shadow-sm'
              : 'text-tx-secondary hover:bg-surface-hover'
          }`}
        >
          <BanknotesIcon className="h-4 w-4" />
          Saques
        </button>
      </div>

      {activeMainTab === 'withdrawals' && (
        <>
      {/* Stats Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div className="rounded-xl bg-amber-500/10 p-4">
          <p className="text-xs text-amber-600 mb-1">Saques Pendentes</p>
          <p className="text-2xl font-bold text-amber-600">{pendingCount}</p>
        </div>
        <div className="rounded-xl bg-orange-500/10 p-4">
          <p className="text-xs text-orange-600 mb-1">Total Pendente</p>
          <p className="text-2xl font-bold text-orange-600">{formatCurrency(pendingTotal)}</p>
        </div>
        <div className="rounded-xl bg-emerald-500/10 p-4">
          <p className="text-xs text-emerald-600 mb-1">Taxa de Saque</p>
          <p className="text-2xl font-bold text-emerald-600">
            {config?.fee_percentage || 0}% + {formatCurrency(config?.fee_fixed || 0)}
          </p>
        </div>
        <div className="rounded-xl bg-emerald-500/10 p-4">
          <p className="text-xs text-emerald-600 mb-1">Limite Auto-Aprovação</p>
          <p className="text-2xl font-bold text-emerald-600">
            {config?.auto_approve_limit ? formatCurrency(config.auto_approve_limit) : 'Manual'}
          </p>
        </div>
        <div className="rounded-xl bg-blue-500/10 p-4">
          <p className="text-xs text-blue-600 mb-1">Saque Automático</p>
          <p className="text-2xl font-bold text-blue-600">
            {config?.auto_withdrawal_enabled ? (
              <span className="text-emerald-600">Ativo</span>
            ) : (
              <span className="text-tx-muted">Desativado</span>
            )}
          </p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-xl bg-emerald-500/10 p-4">
          <p className="text-xs text-emerald-600 mb-1">Taxa de Aprovação (transações filtradas)</p>
          <p className="text-2xl font-bold text-emerald-600">{successRate}%</p>
        </div>
        <div className="rounded-xl bg-sky-500/10 p-4">
          <p className="text-xs text-sky-600 mb-1">Saques Concluídos (página atual)</p>
          <p className="text-2xl font-bold text-sky-600">{formatCurrency(completedWithdrawalsTotal)}</p>
        </div>
        <div className="rounded-xl bg-red-500/10 p-4">
          <p className="text-xs text-red-600 mb-1">Alertas Operacionais</p>
          <p className="text-sm font-semibold text-red-600 mt-1">
            {pendingCount > 20 ? 'Fila de saques acima do ideal' : 'Sem alertas críticos'}
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar por nome ou email..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            />
          </div>

          <div className="flex items-center gap-2">
            <FunnelIcon className="h-4 w-4 text-tx-muted" />
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
            >
              <option value="all">Todos os status</option>
              <option value="pending">Pendentes</option>
              <option value="processing">Processando</option>
              <option value="completed">Concluídos</option>
              <option value="cancelled">Cancelados</option>
            </select>
          </div>

          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-border-DEFAULT transition-colors"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
          </div>
        ) : withdrawals.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20">
            <BanknotesIcon className="h-12 w-12 text-tx-muted" />
            <p className="mt-4 text-sm text-tx-muted">Nenhum saque encontrado</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border-DEFAULT bg-surface-page">
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Cliente
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Valor
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Método
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
                  {withdrawals.map((withdrawal: Withdrawal) => (
                    <tr
                      key={withdrawal.id}
                      className="hover:bg-surface-hover transition-colors"
                    >
                      <td className="px-6 py-4">
                        <div>
                          <p className="text-sm font-medium text-tx-primary">{withdrawal.customer_name}</p>
                          <p className="text-xs text-tx-muted">{withdrawal.customer_email}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div>
                          <p className="text-sm font-semibold text-tx-primary">
                            {formatCurrency(withdrawal.amount)}
                          </p>
                          {withdrawal.fee && withdrawal.fee > 0 && (
                            <p className="text-xs text-tx-muted">
                              Taxa: {formatCurrency(withdrawal.fee)}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          {withdrawal.is_instant && (
                            <BoltIcon className="h-4 w-4 text-amber-500" title="Saque Instantâneo" />
                          )}
                          <span className="text-sm text-tx-primary">
                            {withdrawal.method === 'pix' ? 'PIX' : 'TED'}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1">
                          {getStatusBadge(withdrawal.status)}
                          {(withdrawal.status === 'cancelled' || withdrawal.status === 'rejected') && (withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason) && (
                            <p className="max-w-[280px] truncate text-xs text-tx-muted" title={withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason}>
                              Motivo: {withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-tx-muted">
                          {formatDate(withdrawal.requested_at)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          {withdrawal.status === 'pending' && (
                            <>
                              <button
                                onClick={() => processMutation.mutate({ withdrawalId: withdrawal.id, action: 'approve' })}
                                className="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600 transition-colors"
                              >
                                Aprovar
                              </button>
                              <button
                                onClick={() => {
                                  setSelectedWithdrawal(withdrawal);
                                  setShowDetailModal(true);
                                }}
                                className="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-600 transition-colors"
                              >
                                Rejeitar
                              </button>
                            </>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                <p className="text-sm text-tx-muted">
                  Página {pagination.current_page} de {pagination.last_page}
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
                    disabled={page >= pagination.last_page}
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

      {/* Transactions Tab */}
      {activeMainTab === 'transactions' && (
        <>
          {/* Filters */}
          <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
            <div className="flex flex-col gap-4">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                <div className="relative flex-1">
                  <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                  <input
                    type="text"
                    placeholder="Buscar por ID, cliente..."
                    value={txSearch}
                    onChange={(e) => { setTxSearch(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                  />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <select
                    value={txStatus}
                    onChange={(e) => { setTxStatus(e.target.value); setTxPage(1); }}
                    className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="all">Todos os status</option>
                    <option value="approved">Aprovados</option>
                    <option value="pending">Pendentes</option>
                    <option value="processing">Processando</option>
                    <option value="refunded">Reembolsados</option>
                    <option value="chargeback">Chargebacks</option>
                    <option value="declined">Recusados</option>
                    <option value="failed">Falhas</option>
                    <option value="cancelled">Cancelados</option>
                    <option value="expired">Expirados</option>
                  </select>

                  <select
                    value={txMethod}
                    onChange={(e) => { setTxMethod(e.target.value); setTxPage(1); }}
                    className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="all">Todos os métodos</option>
                    <option value="pix">PIX</option>
                    <option value="credit_card">Cartão</option>
                    <option value="boleto">Boleto</option>
                  </select>

                  <select
                    value={txAcquirer}
                    onChange={(e) => { setTxAcquirer(e.target.value); setTxPage(1); }}
                    className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="all">Todos os gateways</option>
                    <option value="payfort">PayFort</option>
                    <option value="blupay">BluPay</option>
                    <option value="stripe">Stripe</option>
                    <option value="pagarme">Pagar.me</option>
                    <option value="babylon">Babylon</option>
                    <option value="mercadopago">MercadoPago</option>
                    <option value="turboscash">TurbosCash</option>
                    <option value="ascentpay">AscentPay</option>
                  </select>

                  <button
                    onClick={() => refetchCharges()}
                    className="flex items-center gap-2 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-border-DEFAULT transition-colors"
                  >
                    <ArrowPathIcon className="h-4 w-4" />
                    Atualizar
                  </button>
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                  <CalendarDaysIcon className="h-4 w-4 text-tx-muted" />
                  <input
                    type="date"
                    value={txDateFrom}
                    onChange={(e) => { setTxDateFrom(e.target.value); setTxPage(1); }}
                    className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  />
                  <span className="text-sm text-tx-muted">até</span>
                  <input
                    type="date"
                    value={txDateTo}
                    onChange={(e) => { setTxDateTo(e.target.value); setTxPage(1); }}
                    className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  />
                </div>
                {(txSearch || txStatus !== 'all' || txMethod !== 'all' || txAcquirer !== 'all' || txDateFrom || txDateTo) && (
                  <button
                    onClick={() => {
                      setTxSearch('');
                      setTxStatus('all');
                      setTxMethod('all');
                      setTxAcquirer('all');
                      setTxDateFrom('');
                      setTxDateTo('');
                      setTxPage(1);
                    }}
                    className="flex items-center gap-1 rounded-lg bg-red-500/10 px-3 py-2 text-xs font-medium text-red-500 hover:bg-red-500/20 transition-colors"
                  >
                    <XMarkIcon className="h-3.5 w-3.5" />
                    Limpar filtros
                  </button>
                )}
              </div>
            </div>
          </div>

          {/* Transactions Table */}
          <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
            {isLoadingCharges ? (
              <div className="flex items-center justify-center py-20">
                <div className="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
              </div>
            ) : charges.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20">
                <DocumentTextIcon className="h-12 w-12 text-tx-muted" />
                <p className="mt-4 text-sm text-tx-muted">Nenhuma transação encontrada</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b border-border-DEFAULT bg-surface-page">
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Cliente
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Vendedor
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Produto
                        </th>
                        <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Valor
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Método
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Gateway
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Status
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Data
                        </th>
                        <th className="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Ações
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-DEFAULT">
                      {charges.map((charge: any) => (
                        <tr key={charge.id} className="hover:bg-surface-hover transition-colors cursor-pointer" onClick={() => { setSelectedCharge(charge); setShowChargeDetailModal(true); }}>
                          <td className="px-6 py-4">
                            <div>
                              <p className="text-sm font-medium text-tx-primary">{charge.customer_name || '—'}</p>
                              <p className="text-xs text-tx-muted">{charge.customer_email || ''}</p>
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-primary">{charge.account_name}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-primary truncate max-w-[150px] block">{charge.product_name || '—'}</span>
                          </td>
                          <td className="px-6 py-4 text-right">
                            <div>
                              <p className="text-sm font-semibold text-tx-primary">{formatCurrency(charge.amount)}</p>
                              {charge.fee_amount > 0 && (
                                <p className="text-xs text-tx-muted">
                                  Taxa: {formatCurrency(charge.fee_amount)}
                                </p>
                              )}
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-primary">
                              {getPaymentMethodLabel(charge.payment_method)}
                              {charge.installments > 1 && ` ${charge.installments}x`}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-xs text-tx-muted">{charge.acquirer || '—'}</span>
                          </td>
                          <td className="px-6 py-4">
                            {getChargeStatusBadge(charge.status)}
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-tx-muted">{formatDate(charge.created_at)}</span>
                          </td>
                          <td className="px-6 py-4 text-center">
                            <button
                              onClick={(event) => {
                                event.stopPropagation();
                                setSelectedCharge(charge);
                                setShowChargeDetailModal(true);
                              }}
                              className="rounded-lg p-2 text-emerald-500 hover:bg-emerald-50 transition-colors"
                              title="Ver detalhes"
                            >
                              <EyeIcon className="h-4 w-4" />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Pagination */}
                {chargesPagination.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                    <p className="text-sm text-tx-muted">
                      Página {chargesPagination.current_page} de {chargesPagination.last_page} ({chargesPagination.total} transações)
                    </p>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setTxPage(txPage - 1)}
                        disabled={txPage === 1}
                        className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                      >
                        <ChevronLeftIcon className="h-5 w-5" />
                      </button>
                      <button
                        onClick={() => setTxPage(txPage + 1)}
                        disabled={txPage >= chargesPagination.last_page}
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

      {/* Charge Detail Modal */}
      {showChargeDetailModal && selectedCharge && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowChargeDetailModal(false)}>
          <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-surface-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-bold text-tx-primary">Detalhes da Transação</h2>
              <button
                onClick={() => setShowChargeDetailModal(false)}
                className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              {/* Status & Amount */}
              <div className="flex items-center justify-between rounded-xl bg-surface-page p-4">
                <div>
                  <p className="text-xs text-tx-muted mb-1">Valor</p>
                  <p className="text-2xl font-bold text-tx-primary">{formatCurrency(selectedCharge.amount)}</p>
                  {selectedCharge.fee_amount > 0 && (
                    <p className="text-xs text-tx-muted mt-1">
                      Taxa: {formatCurrency(selectedCharge.fee_amount)} | Líquido: {formatCurrency(selectedCharge.amount - selectedCharge.fee_amount)}
                    </p>
                  )}
                </div>
                <div>{getChargeStatusBadge(selectedCharge.status)}</div>
              </div>

              {/* IDs */}
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">ID da Transação</p>
                  <p className="text-sm font-mono text-tx-primary break-all">{selectedCharge.id}</p>
                </div>
                {selectedCharge.external_id && (
                  <div className="rounded-xl bg-surface-page p-4">
                    <p className="text-xs text-tx-muted mb-1">ID Externo</p>
                    <p className="text-sm font-mono text-tx-primary break-all">{selectedCharge.external_id}</p>
                  </div>
                )}
              </div>

              {/* Customer Info */}
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-3">Cliente</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-tx-muted">Nome</p>
                    <p className="text-sm font-medium text-tx-primary">{selectedCharge.customer_name || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-tx-muted">Email</p>
                    <p className="text-sm text-tx-primary">{selectedCharge.customer_email || '—'}</p>
                  </div>
                  {selectedCharge.customer_document && (
                    <div>
                      <p className="text-xs text-tx-muted">Documento</p>
                      <p className="text-sm text-tx-primary">{selectedCharge.customer_document}</p>
                    </div>
                  )}
                  {selectedCharge.customer_phone && (
                    <div>
                      <p className="text-xs text-tx-muted">Telefone</p>
                      <p className="text-sm text-tx-primary">{selectedCharge.customer_phone}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Seller & Product */}
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-3">Vendedor & Produto</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-tx-muted">Vendedor</p>
                    <p className="text-sm font-medium text-tx-primary">{selectedCharge.account_name || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-tx-muted">Produto</p>
                    <p className="text-sm text-tx-primary">{selectedCharge.product_name || '—'}</p>
                  </div>
                  {selectedCharge.checkout_name && (
                    <div>
                      <p className="text-xs text-tx-muted">Checkout</p>
                      <p className="text-sm text-tx-primary">{selectedCharge.checkout_name}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Payment Details */}
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-3">Pagamento</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-tx-muted">Método</p>
                    <p className="text-sm font-medium text-tx-primary">
                      {getPaymentMethodLabel(selectedCharge.payment_method)}
                      {selectedCharge.installments > 1 && ` ${selectedCharge.installments}x`}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-tx-muted">Gateway</p>
                    <p className="text-sm text-tx-primary">{selectedCharge.acquirer || '—'}</p>
                  </div>
                  {selectedCharge.card_brand && (
                    <div>
                      <p className="text-xs text-tx-muted">Bandeira</p>
                      <p className="text-sm text-tx-primary">{selectedCharge.card_brand}</p>
                    </div>
                  )}
                  {selectedCharge.card_last4 && (
                    <div>
                      <p className="text-xs text-tx-muted">Cartão</p>
                      <p className="text-sm text-tx-primary">**** {selectedCharge.card_last4}</p>
                    </div>
                  )}
                  {selectedCharge.pix_txid && (
                    <div className="col-span-2">
                      <p className="text-xs text-tx-muted">PIX TxID</p>
                      <p className="text-sm font-mono text-tx-primary break-all">{selectedCharge.pix_txid}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Dates */}
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-3">Datas</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-tx-muted">Criado em</p>
                    <p className="text-sm text-tx-primary">{formatDate(selectedCharge.created_at)}</p>
                  </div>
                  {selectedCharge.paid_at && (
                    <div>
                      <p className="text-xs text-tx-muted">Pago em</p>
                      <p className="text-sm text-tx-primary">{formatDate(selectedCharge.paid_at)}</p>
                    </div>
                  )}
                  {selectedCharge.refunded_at && (
                    <div>
                      <p className="text-xs text-tx-muted">Reembolsado em</p>
                      <p className="text-sm text-tx-primary">{formatDate(selectedCharge.refunded_at)}</p>
                    </div>
                  )}
                  {selectedCharge.updated_at && selectedCharge.updated_at !== selectedCharge.created_at && (
                    <div>
                      <p className="text-xs text-tx-muted">Atualizado em</p>
                      <p className="text-sm text-tx-primary">{formatDate(selectedCharge.updated_at)}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Affiliate */}
              {selectedCharge.affiliate_name && (
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs font-semibold text-tx-muted uppercase tracking-wider mb-3">Afiliado</p>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <p className="text-xs text-tx-muted">Nome</p>
                      <p className="text-sm font-medium text-tx-primary">{selectedCharge.affiliate_name}</p>
                    </div>
                    {selectedCharge.affiliate_commission != null && (
                      <div>
                        <p className="text-xs text-tx-muted">Comissão</p>
                        <p className="text-sm text-tx-primary">{formatCurrency(selectedCharge.affiliate_commission)}</p>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Metadata / Notes */}
              {selectedCharge.notes && (
                <div className="rounded-xl bg-surface-page p-4">
                  <p className="text-xs text-tx-muted mb-1">Observações</p>
                  <p className="text-sm text-tx-primary">{selectedCharge.notes}</p>
                </div>
              )}
            </div>

            <div className="mt-6 flex justify-end">
              <button
                onClick={() => setShowChargeDetailModal(false)}
                className="rounded-lg bg-surface-hover px-5 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
              >
                Fechar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showDetailModal && selectedWithdrawal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-2xl bg-surface-card p-6 shadow-xl">
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-bold text-tx-primary">Rejeitar Saque</h2>
              <button
                onClick={() => {
                  setShowDetailModal(false);
                  setSelectedWithdrawal(null);
                  setRejectReason('');
                }}
                className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs text-tx-muted mb-1">Cliente</p>
                <p className="text-sm font-medium text-tx-primary">{selectedWithdrawal.customer_name}</p>
              </div>
              <div className="rounded-xl bg-surface-page p-4">
                <p className="text-xs text-tx-muted mb-1">Valor</p>
                <p className="text-lg font-bold text-tx-primary">{formatCurrency(selectedWithdrawal.amount)}</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-2">
                  Motivo da rejeição
                </label>
                <textarea
                  value={rejectReason}
                  onChange={(e) => setRejectReason(e.target.value)}
                  placeholder="Informe o motivo..."
                  rows={3}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-3 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
                />
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  onClick={() => {
                    setShowDetailModal(false);
                    setRejectReason('');
                  }}
                  className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
                >
                  Cancelar
                </button>
                <button
                  onClick={() => processMutation.mutate({
                    withdrawalId: selectedWithdrawal.id,
                    action: 'reject',
                    notes: rejectReason,
                  })}
                  disabled={processMutation.isPending}
                  className="flex-1 rounded-lg bg-red-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50 transition-colors"
                >
                  {processMutation.isPending ? 'Rejeitando...' : 'Confirmar Rejeição'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Config Modal */}
      {showConfigModal && config && (
        <ConfigModal
          config={config}
          onClose={() => setShowConfigModal(false)}
          onSave={(newConfig) => updateConfigMutation.mutate(newConfig)}
          isSaving={updateConfigMutation.isPending}
        />
      )}
    </AdminPageShell>
  );
}

function ConfigModal({
  config,
  onClose,
  onSave,
  isSaving,
}: {
  config: WithdrawalConfig;
  onClose: () => void;
  onSave: (config: Partial<WithdrawalConfig>) => void;
  isSaving: boolean;
}) {
  const [localConfig, setLocalConfig] = useState<WithdrawalConfig>({
    fee_percentage: config.fee_percentage ?? 0,
    fee_fixed: config.fee_fixed ?? 0,
    standard_withdrawal_fee_pix: config.standard_withdrawal_fee_pix ?? 0,
    standard_withdrawal_fee_bank: config.standard_withdrawal_fee_bank ?? 200,
    instant_withdrawal_enabled: config.instant_withdrawal_enabled ?? false,
    instant_withdrawal_fee_type: config.instant_withdrawal_fee_type ?? 'percentage',
    instant_withdrawal_fee_value: config.instant_withdrawal_fee_value ?? 2.5,
    withdrawal_min_amount: config.withdrawal_min_amount ?? 1000,
    withdrawal_max_amount: config.withdrawal_max_amount ?? 0,
    auto_approve_limit: config.auto_approve_limit ?? 0,
    withdrawals_enabled: config.withdrawals_enabled ?? true,
    withdrawal_processing_enabled: config.withdrawal_processing_enabled ?? true,
    payments_enabled: config.payments_enabled ?? true,
    daily_withdrawal_limit: config.daily_withdrawal_limit ?? 5000000,
    large_withdrawal_threshold: config.large_withdrawal_threshold ?? 1000000,
    withdrawal_cooldown_seconds: config.withdrawal_cooldown_seconds ?? 60,
    max_pending_withdrawals_per_account: config.max_pending_withdrawals_per_account ?? 10,
    settlement_days_pix: config.settlement_days_pix ?? 1,
    settlement_days_ted: config.settlement_days_ted ?? 3,
    processing_days: config.processing_days ?? 1,
    auto_withdrawal_enabled: config.auto_withdrawal_enabled ?? false,
    auto_withdrawal_schedule: config.auto_withdrawal_schedule ?? 'manual',
    auto_withdrawal_min_amount: config.auto_withdrawal_min_amount ?? 5000,
    holdback_enabled: config.holdback_enabled ?? false,
    holdback_days: config.holdback_days ?? 14,
    holdback_percentage: config.holdback_percentage ?? 0,
  });
  const [activeTab, setActiveTab] = useState<'fees' | 'limits' | 'controls' | 'settlement' | 'auto' | 'holdback'>('fees');

  const tabs = [
    { key: 'fees' as const, label: 'Taxas', icon: BanknotesIcon },
    { key: 'limits' as const, label: 'Limites', icon: ShieldCheckIcon },
    { key: 'controls' as const, label: 'Controle', icon: Cog6ToothIcon },
    { key: 'settlement' as const, label: 'Liquidação', icon: CalendarDaysIcon },
    { key: 'auto' as const, label: 'Automático', icon: BoltIcon },
    { key: 'holdback' as const, label: 'Holdback', icon: Cog6ToothIcon },
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-3xl max-h-[90vh] flex flex-col rounded-2xl bg-surface-card shadow-xl">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-border-DEFAULT">
          <div>
            <h2 className="text-lg font-bold text-tx-primary">Configurações de Saque</h2>
            <p className="text-xs text-tx-muted mt-0.5">Configure taxas, limites, liquidação e automação</p>
          </div>
          <button onClick={onClose} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex border-b border-border-DEFAULT px-6 overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                activeTab === tab.key
                  ? 'border-emerald-500 text-emerald-600'
                  : 'border-transparent text-tx-muted hover:text-tx-primary'
              }`}
            >
              <tab.icon className="h-4 w-4" />
              {tab.label}
            </button>
          ))}
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {/* Taxas */}
          {activeTab === 'fees' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Taxa de Saque Padrão</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Taxa Percentual (%)</label>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      max="100"
                      value={localConfig.fee_percentage}
                      onChange={(e) => setLocalConfig({ ...localConfig, fee_percentage: parseFloat(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Aplicada sobre o valor do saque</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Taxa Fixa (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.fee_fixed}
                      onChange={(e) => setLocalConfig({ ...localConfig, fee_fixed: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Ex: 200 = R$ 2,00</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-border-DEFAULT pt-6">
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Taxa por Método</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Taxa PIX (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.standard_withdrawal_fee_pix}
                      onChange={(e) => setLocalConfig({ ...localConfig, standard_withdrawal_fee_pix: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Taxa adicional para saque via PIX</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Taxa TED (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.standard_withdrawal_fee_bank}
                      onChange={(e) => setLocalConfig({ ...localConfig, standard_withdrawal_fee_bank: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Taxa adicional para saque via TED</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-border-DEFAULT pt-6">
                <div className="flex items-center justify-between mb-3">
                  <h3 className="text-sm font-semibold text-tx-primary">Saque Instantâneo</h3>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.instant_withdrawal_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                  </label>
                </div>
                {localConfig.instant_withdrawal_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Tipo da Taxa</label>
                      <select
                        value={localConfig.instant_withdrawal_fee_type}
                        onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_fee_type: e.target.value as 'percentage' | 'fixed' })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      >
                        <option value="percentage">Percentual (%)</option>
                        <option value="fixed">Valor Fixo (centavos)</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">
                        Valor da Taxa {localConfig.instant_withdrawal_fee_type === 'percentage' ? '(%)' : '(centavos)'}
                      </label>
                      <input
                        type="number"
                        step={localConfig.instant_withdrawal_fee_type === 'percentage' ? '0.01' : '1'}
                        min="0"
                        value={localConfig.instant_withdrawal_fee_value}
                        onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_fee_value: parseFloat(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      />
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Limites */}
          {activeTab === 'limits' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Limites de Saque</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Mínimo por Saque (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.withdrawal_min_amount}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_min_amount: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Ex: 1000 = R$ 10,00</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Máximo por Saque (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.withdrawal_max_amount}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_max_amount: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">0 = sem limite</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-border-DEFAULT pt-6">
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Auto-Aprovação</h3>
                <div>
                  <label className="block text-xs font-medium text-tx-secondary mb-1">Limite Auto-Aprovação (centavos)</label>
                  <input
                    type="number"
                    min="0"
                    value={localConfig.auto_approve_limit}
                    onChange={(e) => setLocalConfig({ ...localConfig, auto_approve_limit: parseInt(e.target.value) || 0 })}
                    className="w-full max-w-xs rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  />
                  <p className="text-xs text-tx-muted mt-1">Saques abaixo desse valor são aprovados automaticamente. 0 = aprovação manual sempre</p>
                </div>
              </div>
            </div>
          )}

          {/* Controle */}
          {activeTab === 'controls' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Controles Operacionais</h3>
                <p className="text-xs text-tx-muted mb-4">Esses controles aplicam bloqueio e governança em tempo real para pagamentos e saques.</p>
                <div className="space-y-4">
                  <div className="flex items-center justify-between rounded-xl border border-border-DEFAULT bg-surface-page p-4">
                    <div>
                      <p className="text-sm font-medium text-tx-primary">Aceitar Novos Saques</p>
                      <p className="text-xs text-tx-muted">Desative para congelar solicitações de saque no painel do seller/API.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.withdrawals_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, withdrawals_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                    </label>
                  </div>

                  <div className="flex items-center justify-between rounded-xl border border-border-DEFAULT bg-surface-page p-4">
                    <div>
                      <p className="text-sm font-medium text-tx-primary">Processar Saques Automaticamente</p>
                      <p className="text-xs text-tx-muted">Desative para pausar envio PIX OUT e manter saques em fila de contingência.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.withdrawal_processing_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_processing_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                    </label>
                  </div>

                  <div className="flex items-center justify-between rounded-xl border border-border-DEFAULT bg-surface-page p-4">
                    <div>
                      <p className="text-sm font-medium text-tx-primary">Aceitar Novos Pagamentos</p>
                      <p className="text-xs text-tx-muted">Kill switch global para checkout público e cobranças via API.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.payments_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, payments_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                    </label>
                  </div>
                </div>
              </div>

              <div className="border-t border-border-DEFAULT pt-6">
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Proteções de Risco</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Limite Diário por Conta (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.daily_withdrawal_limit}
                      onChange={(e) => setLocalConfig({ ...localConfig, daily_withdrawal_limit: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">0 desabilita o limite diário.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Limiar de Aprovação Manual (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.large_withdrawal_threshold}
                      onChange={(e) => setLocalConfig({ ...localConfig, large_withdrawal_threshold: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Saques acima desse valor entram em pending_approval.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Cooldown Entre Saques (segundos)</label>
                    <input
                      type="number"
                      min="0"
                      max="3600"
                      value={localConfig.withdrawal_cooldown_seconds}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_cooldown_seconds: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">0 desativa cooldown.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Máximo de Saques em Aberto por Conta</label>
                    <input
                      type="number"
                      min="0"
                      max="500"
                      value={localConfig.max_pending_withdrawals_per_account}
                      onChange={(e) => setLocalConfig({ ...localConfig, max_pending_withdrawals_per_account: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">0 remove o limite.</p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Liquidação */}
          {activeTab === 'settlement' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-tx-primary mb-3">Dias para Liquidação</h3>
                <p className="text-xs text-tx-muted mb-4">Tempo que o saldo do vendedor fica retido após a venda antes de ficar disponível para saque</p>
                <div className="grid gap-4 sm:grid-cols-3">
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">PIX (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.settlement_days_pix}
                      onChange={(e) => setLocalConfig({ ...localConfig, settlement_days_pix: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Vendas via PIX</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">TED/Boleto (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.settlement_days_ted}
                      onChange={(e) => setLocalConfig({ ...localConfig, settlement_days_ted: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Vendas via TED/Boleto</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-tx-secondary mb-1">Processamento (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.processing_days}
                      onChange={(e) => setLocalConfig({ ...localConfig, processing_days: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                    />
                    <p className="text-xs text-tx-muted mt-1">Dias para processar o saque</p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Automático */}
          {activeTab === 'auto' && (
            <div className="space-y-6">
              <div>
                <div className="flex items-center justify-between mb-3">
                  <div>
                    <h3 className="text-sm font-semibold text-tx-primary">Saque Automático</h3>
                    <p className="text-xs text-tx-muted mt-0.5">Transfere automaticamente o saldo disponível para o vendedor</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.auto_withdrawal_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                  </label>
                </div>

                {localConfig.auto_withdrawal_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2 mt-4">
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Frequência</label>
                      <select
                        value={localConfig.auto_withdrawal_schedule}
                        onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_schedule: e.target.value as WithdrawalConfig['auto_withdrawal_schedule'] })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      >
                        <option value="daily">Diário</option>
                        <option value="weekly">Semanal</option>
                        <option value="biweekly">Quinzenal</option>
                        <option value="monthly">Mensal</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Valor Mínimo (centavos)</label>
                      <input
                        type="number"
                        min="0"
                        value={localConfig.auto_withdrawal_min_amount}
                        onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_min_amount: parseInt(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      />
                      <p className="text-xs text-tx-muted mt-1">Saldo mínimo para disparar saque automático</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Holdback */}
          {activeTab === 'holdback' && (
            <div className="space-y-6">
              <div>
                <div className="flex items-center justify-between mb-3">
                  <div>
                    <h3 className="text-sm font-semibold text-tx-primary">Holdback / Reserva</h3>
                    <p className="text-xs text-tx-muted mt-0.5">Retém uma porcentagem do saldo como reserva contra chargebacks</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.holdback_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, holdback_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600" />
                  </label>
                </div>

                {localConfig.holdback_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2 mt-4">
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Porcentagem Retida (%)</label>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={localConfig.holdback_percentage}
                        onChange={(e) => setLocalConfig({ ...localConfig, holdback_percentage: parseFloat(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-tx-secondary mb-1">Dias de Retenção</label>
                      <input
                        type="number"
                        min="0"
                        max="365"
                        value={localConfig.holdback_days}
                        onChange={(e) => setLocalConfig({ ...localConfig, holdback_days: parseInt(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                      />
                      <p className="text-xs text-tx-muted mt-1">Após esse período a reserva é liberada</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex gap-3 p-6 border-t border-border-DEFAULT">
          <button
            onClick={onClose}
            className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
          >
            Cancelar
          </button>
          <button
            onClick={() => onSave(localConfig)}
            disabled={isSaving}
            className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
          >
            {isSaving ? 'Salvando...' : 'Salvar Configurações'}
          </button>
        </div>
      </div>
    </div>
  );
}
