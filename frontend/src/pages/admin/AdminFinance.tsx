import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
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

type AdminFinanceMode = 'all' | 'transactions' | 'withdrawals';

interface AdminFinanceProps {
  mode?: AdminFinanceMode;
}

export default function AdminFinance({ mode = 'all' }: AdminFinanceProps) {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const lockedMainTab = mode === 'transactions' ? 'transactions' : mode === 'withdrawals' ? 'withdrawals' : null;
  const [activeMainTab, setActiveMainTab] = useState<'withdrawals' | 'transactions'>(lockedMainTab ?? 'transactions');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('pending');
  const [methodFilter, setMethodFilter] = useState<string>('all');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
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
  const [chargeStatusForm, setChargeStatusForm] = useState({
    status: '',
    reason: '',
    reason_code: 'customer_dispute',
  });

  useEffect(() => {
    if (lockedMainTab && activeMainTab !== lockedMainTab) {
      setActiveMainTab(lockedMainTab);
    }
  }, [activeMainTab, lockedMainTab]);

  // Fetch withdrawals
  const { data: withdrawalsData, isLoading, refetch } = useQuery({
    queryKey: ['admin-withdrawals', search, statusFilter, methodFilter, dateFrom, dateTo, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (search) params.append('search', search);
      if (statusFilter !== 'all') {
        params.append('status', statusFilter === 'rejected' ? 'cancelled' : statusFilter);
      }
      if (methodFilter !== 'all') params.append('method', methodFilter);
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);

      const response = await api.get(`/admin/withdrawals?${params.toString()}`);
      return response.data;
    },
    enabled: activeMainTab === 'withdrawals',
  });

  const withdrawals: Withdrawal[] = withdrawalsData?.data ?? [];
  const pagination = withdrawalsData?.meta ?? { current_page: 1, last_page: 1, total: 0 };

  const { data: configData } = useQuery({
    queryKey: ['admin-withdrawals-config'],
    queryFn: async () => {
      const response = await api.get('/admin/withdrawals/config');
      return response.data;
    },
    enabled: activeMainTab === 'withdrawals',
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
    enabled: activeMainTab === 'transactions',
  });

  const charges = chargesData?.data ?? [];
  const chargesPagination = chargesData?.meta ?? { current_page: 1, last_page: 1, total: 0 };
  const deletableStatuses = ['expired', 'failed', 'declined'];
  const canBulkDeleteCharges = deletableStatuses.includes(txStatus);

  const deleteFailedExpiredChargesMutation = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams();
      params.append('status', txStatus);
      if (txSearch) params.append('search', txSearch);
      if (txMethod !== 'all') params.append('payment_method', txMethod);
      if (txAcquirer !== 'all') params.append('acquirer', txAcquirer);
      if (txDateFrom) params.append('date_from', txDateFrom);
      if (txDateTo) params.append('date_to', txDateTo);

      const query = params.toString();
      const response = await api.delete(`/admin/charges/failed-expired${query ? `?${query}` : ''}`);
      return response.data;
    },
    onSuccess: (payload) => {
      toast.success(payload?.message || 'Transações falhadas e expiradas removidas com sucesso.');
      queryClient.invalidateQueries({ queryKey: ['admin-charges'] });
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Erro ao remover transações falhadas e expiradas.');
    },
  });

  const countDeletableChargesMutation = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams();
      params.append('status', txStatus);
      if (txSearch) params.append('search', txSearch);
      if (txMethod !== 'all') params.append('payment_method', txMethod);
      if (txAcquirer !== 'all') params.append('acquirer', txAcquirer);
      if (txDateFrom) params.append('date_from', txDateFrom);
      if (txDateTo) params.append('date_to', txDateTo);

      const query = params.toString();
      const response = await api.get(`/admin/charges/failed-expired/count${query ? `?${query}` : ''}`);
      return response.data;
    },
  });

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
      if (variables.action === 'reject') {
        const cancelReason = payload?.cancel_reason || payload?.data?.failure_reason || payload?.data?.notes || variables.notes;
        toast.success(cancelReason ? `Saque cancelado. Motivo: ${cancelReason}` : 'Saque cancelado com sucesso.');
      } else {
        toast.success('Operação concluída com sucesso.');
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
      toast.success(payload?.message || 'Processamento em lote executado.');
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

  const openChargeDetail = (charge: any) => {
    setSelectedCharge(charge);
    setChargeStatusForm({ status: charge.status || 'pending', reason: '', reason_code: 'customer_dispute' });
    setShowChargeDetailModal(true);
  };

  const updateChargeStatusMutation = useMutation({
    mutationFn: async ({ chargeId, status, reason, reason_code }: { chargeId: string; status: string; reason: string; reason_code?: string }) => {
      const response = await api.post(`/admin/charges/${chargeId}/status`, { status, reason, reason_code });
      return response.data;
    },
    onSuccess: (_, variables) => {
      toast.success(variables.status === 'chargeback' ? 'Disputa de chargeback aberta.' : 'Status da transacao atualizado.');
      queryClient.invalidateQueries({ queryKey: ['admin-charges'] });
      queryClient.invalidateQueries({ queryKey: ['admin-chargebacks'] });
      setSelectedCharge((current: any) => current ? { ...current, status: variables.status, updated_at: new Date().toISOString() } : current);
      setChargeStatusForm({ status: variables.status, reason: '', reason_code: 'customer_dispute' });
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || error?.response?.data?.error?.message || 'Erro ao atualizar status da transacao.';
      toast.error(message);
    },
  });

  const chargeStatusOptions = [
    { value: 'pending', label: 'Pendente' },
    { value: 'processing', label: 'Processando' },
    { value: 'authorized', label: 'Autorizado' },
    { value: 'approved', label: 'Aprovado' },
    { value: 'refunded', label: 'Reembolsado' },
    { value: 'failed', label: 'Falhou' },
    { value: 'declined', label: 'Recusado' },
    { value: 'cancelled', label: 'Cancelado' },
    { value: 'expired', label: 'Expirado' },
    { value: 'chargeback', label: 'Chargeback' },
    { value: 'waiting_payment', label: 'Aguardando pagamento' },
    { value: 'blocked', label: 'Bloqueado' },
  ];

  const compactJson = (value: any) => {
    if (!value || (typeof value === 'object' && Object.keys(value).length === 0)) return null;
    return JSON.stringify(value, null, 2);
  };

  const copyText = async (value?: string | null) => {
    if (!value) return;
    await navigator.clipboard.writeText(value);
    toast.success('Copiado.');
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Concluido
          </span>
        );
      case 'approved':
      case 'processing':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
            <ArrowPathIcon className="h-3.5 w-3.5" />
            Processando
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
            <ClockIcon className="h-3.5 w-3.5" />
            Pendente
          </span>
        );
      case 'rejected':
      case 'cancelled':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">
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
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Aprovado
          </span>
        );
      case 'pending':
      case 'processing':
      case 'waiting_payment':
      case 'authorized':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
            <ClockIcon className="h-3.5 w-3.5" />
            {status === 'pending' ? 'Pendente' : status === 'authorized' ? 'Autorizado' : status === 'waiting_payment' ? 'Aguardando pagamento' : 'Processando'}
          </span>
        );
      case 'refunded':
      case 'partially_refunded':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
            <ArrowPathIcon className="h-3.5 w-3.5" />
            {status === 'refunded' ? 'Reembolsado' : 'Reembolso Parcial'}
          </span>
        );
      case 'chargeback':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">
            <XCircleIcon className="h-3.5 w-3.5" />
            Chargeback
          </span>
        );
      case 'declined':
      case 'failed':
      case 'cancelled':
      case 'expired':
      case 'blocked':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">
            <XCircleIcon className="h-3.5 w-3.5" />
            {status === 'declined' ? 'Recusado' : status === 'failed' ? 'Falhou' : status === 'cancelled' ? 'Cancelado' : status === 'blocked' ? 'Bloqueado' : 'Expirado'}
          </span>
        );
      default:
        return <span className="text-xs text-gray-500">{status}</span>;
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
        title={activeMainTab === 'withdrawals' ? 'Saques' : 'Transações'}
        subtitle={
          activeMainTab === 'withdrawals'
            ? 'Gerenciamento completo de saques do sistema'
            : 'Monitore e gerencie todas as transações da plataforma'
        }
        actions={
          activeMainTab === 'withdrawals' ? (
            <div className="flex items-center gap-2">
              <button
                onClick={() => setShowConfigModal(true)}
                className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Configurações
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
      {mode === 'all' && (
        <div className="flex w-full items-center gap-1 overflow-x-auto rounded-md bg-white p-1 shadow-sm">
          <button
            onClick={() => setActiveMainTab('transactions')}
            className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
              activeMainTab === 'transactions'
                ? 'bg-green-600 text-white shadow-sm'
                : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            <DocumentTextIcon className="h-4 w-4" />
            Transações
            {chargesPagination.total > 0 && (
              <span className={`rounded-full px-2 py-0.5 text-xs ${
                activeMainTab === 'transactions' ? 'bg-white/20' : 'bg-green-50 text-green-600'
              }`}>
                {chargesPagination.total}
              </span>
            )}
          </button>
          <button
            onClick={() => setActiveMainTab('withdrawals')}
            className={`flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-medium transition-colors ${
              activeMainTab === 'withdrawals'
                ? 'bg-green-600 text-white shadow-sm'
                : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            <BanknotesIcon className="h-4 w-4" />
            Saques
          </button>
        </div>
      )}

      {activeMainTab === 'withdrawals' && (
        <>
      {/* Stats Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div className="rounded-md bg-amber-50 p-4">
          <p className="text-xs text-amber-600 mb-1">Saques Pendentes</p>
          <p className="text-2xl font-bold text-amber-600">{pendingCount}</p>
        </div>
        <div className="rounded-md bg-orange-500/10 p-4">
          <p className="text-xs text-orange-600 mb-1">Total Pendente</p>
          <p className="text-2xl font-bold text-orange-600">{formatCurrency(pendingTotal)}</p>
        </div>
        <div className="rounded-md bg-emerald-50 p-4">
          <p className="text-xs text-emerald-600 mb-1">Taxa de Saque</p>
          <p className="text-2xl font-bold text-emerald-600">
            {config?.fee_percentage || 0}% + {formatCurrency(config?.fee_fixed || 0)}
          </p>
        </div>
        <div className="rounded-md bg-green-50 p-4">
          <p className="text-xs text-green-600 mb-1">Limite Auto-Aprovação</p>
          <p className="text-2xl font-bold text-green-600">
            {config?.auto_approve_limit ? formatCurrency(config.auto_approve_limit) : 'Manual'}
          </p>
        </div>
        <div className="rounded-md bg-blue-50 p-4">
          <p className="text-xs text-blue-600 mb-1">Saque Automático</p>
          <p className="text-2xl font-bold text-blue-600">
            {config?.auto_withdrawal_enabled ? (
              <span className="text-emerald-600">Ativo</span>
            ) : (
              <span className="text-gray-500">Desativado</span>
            )}
          </p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-md bg-emerald-50 p-4">
          <p className="text-xs text-emerald-600 mb-1">Taxa de Aprovação (transações filtradas)</p>
          <p className="text-2xl font-bold text-emerald-600">{successRate}%</p>
        </div>
        <div className="rounded-md bg-sky-500/10 p-4">
          <p className="text-xs text-sky-600 mb-1">Saques Concluídos (página atual)</p>
          <p className="text-2xl font-bold text-sky-600">{formatCurrency(completedWithdrawalsTotal)}</p>
        </div>
        <div className="rounded-md bg-red-50 p-4">
          <p className="text-xs text-red-600 mb-1">Alertas Operacionais</p>
          <p className="text-sm font-semibold text-red-600 mt-1">
            {pendingCount > 20 ? 'Fila de saques acima do ideal' : 'Sem alertas críticos'}
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div className="relative flex-1">
              <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-500" />
              <input
                type="text"
                placeholder="Buscar por nome, email ou ID..."
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setPage(1);
                }}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 pl-10 pr-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none focus:ring-0"
              />
            </div>

            <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:items-center">
              <FunnelIcon className="h-4 w-4 text-gray-500" />
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setPage(1);
                }}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
              >
                <option value="all">Todos os status</option>
                <option value="pending">Pendentes</option>
                <option value="processing">Processando</option>
                <option value="completed">Concluídos</option>
                <option value="cancelled">Cancelados</option>
              </select>

              <select
                value={methodFilter}
                onChange={(e) => {
                  setMethodFilter(e.target.value);
                  setPage(1);
                }}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
              >
                <option value="all">Todos os métodos</option>
                <option value="pix">PIX</option>
                <option value="bank_transfer">TED</option>
              </select>

              <button
                onClick={() => refetch()}
                className="flex w-full items-center justify-center gap-2 rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-border-DEFAULT transition-colors sm:w-auto"
              >
                <ArrowPathIcon className="h-4 w-4" />
                Atualizar
              </button>
            </div>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="grid grid-cols-[auto_1fr] items-center gap-2 sm:flex">
              <CalendarDaysIcon className="h-4 w-4 text-gray-500" />
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
              />
              <span className="text-sm text-gray-500">até</span>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
              />
            </div>
            {(search || statusFilter !== 'all' || methodFilter !== 'all' || dateFrom || dateTo) && (
              <button
                onClick={() => {
                  setSearch('');
                  setStatusFilter('pending');
                  setMethodFilter('all');
                  setDateFrom('');
                  setDateTo('');
                  setPage(1);
                }}
                className="flex items-center gap-1 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-500/20 transition-colors"
              >
                <XMarkIcon className="h-3.5 w-3.5" />
                Limpar filtros
              </button>
            )}
            <span className="text-xs text-gray-500 sm:ml-auto">
              {pagination.total} saques encontrados
            </span>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <div className="h-10 w-10 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : withdrawals.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20">
            <BanknotesIcon className="h-12 w-12 text-gray-500" />
            <p className="mt-4 text-sm text-gray-500">Nenhum saque encontrado</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-[860px] w-full">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50">
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Cliente
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Valor
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Método
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Status
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Data
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                      Ações
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {withdrawals.map((withdrawal: Withdrawal) => (
                    <tr
                      key={withdrawal.id}
                      className="hover:bg-gray-100 transition-colors"
                    >
                      <td className="px-6 py-4">
                        <div>
                          <p className="text-sm font-medium text-gray-900">{withdrawal.customer_name}</p>
                          <p className="text-xs text-gray-500">{withdrawal.customer_email}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <div>
                          <p className="text-sm font-semibold text-gray-900">
                            {formatCurrency(withdrawal.amount)}
                          </p>
                          {withdrawal.fee && withdrawal.fee > 0 && (
                            <p className="text-xs text-gray-500">
                              Taxa: {formatCurrency(withdrawal.fee)}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          {withdrawal.is_instant && (
                            <BoltIcon className="h-4 w-4 text-amber-700" title="Saque Instantâneo" />
                          )}
                          <span className="text-sm text-gray-900">
                            {withdrawal.method === 'pix' ? 'PIX' : 'TED'}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1">
                          {getStatusBadge(withdrawal.status)}
                          {(withdrawal.status === 'cancelled' || withdrawal.status === 'rejected') && (withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason) && (
                            <p className="max-w-[280px] truncate text-xs text-gray-500" title={withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason}>
                              Motivo: {withdrawal.failure_reason || withdrawal.notes || withdrawal.cancel_reason}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-gray-500">
                          {formatDate(withdrawal.requested_at)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => {
                              setSelectedWithdrawal(withdrawal);
                              setShowDetailModal(true);
                            }}
                            className="rounded-lg bg-gray-100 p-1.5 text-gray-500 hover:text-gray-900 transition-colors"
                            title="Ver detalhes"
                          >
                            <EyeIcon className="h-4 w-4" />
                          </button>
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
                                  setRejectReason('');
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
              <div className="flex flex-col gap-3 border-t border-gray-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p className="text-sm text-gray-500">
                  Página {pagination.current_page} de {pagination.last_page}
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                    className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-50"
                  >
                    <ChevronLeftIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => setPage(page + 1)}
                    disabled={page >= pagination.last_page}
                    className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-50"
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
          <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-col gap-4">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                <div className="relative flex-1">
                  <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-500" />
                  <input
                    type="text"
                    placeholder="Buscar por ID, cliente..."
                    value={txSearch}
                    onChange={(e) => { setTxSearch(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 pl-10 pr-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none focus:ring-0"
                  />
                </div>

                <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:items-center">
                  <select
                    value={txStatus}
                    onChange={(e) => { setTxStatus(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
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
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
                  >
                    <option value="all">Todos os métodos</option>
                    <option value="pix">PIX</option>
                    <option value="credit_card">Cartão</option>
                    <option value="boleto">Boleto</option>
                  </select>

                  <select
                    value={txAcquirer}
                    onChange={(e) => { setTxAcquirer(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
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
                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-border-DEFAULT transition-colors sm:w-auto"
                  >
                    <ArrowPathIcon className="h-4 w-4" />
                    Atualizar
                  </button>

                  <button
                    onClick={async () => {
                      if (!canBulkDeleteCharges) {
                        toast.error('Selecione explicitamente um status: expirado, falha ou recusado.');
                        return;
                      }

                      try {
                        const payload = await countDeletableChargesMutation.mutateAsync();
                        const count = Number(payload?.data?.count || 0);

                        if (count <= 0) {
                          toast('Nenhuma transação falhada, recusada ou expirada encontrada nos filtros atuais.', { icon: 'ℹ️' });
                          return;
                        }

                        const confirmed = window.confirm(`Deseja apagar definitivamente ${count} transa${count === 1 ? 'ção' : 'ções'} falhada${count === 1 ? '' : 's'}, recusada${count === 1 ? '' : 's'} e expirada${count === 1 ? '' : 's'} visíveis com os filtros atuais?`);
                        if (!confirmed) return;

                        deleteFailedExpiredChargesMutation.mutate();
                      } catch (error: any) {
                        toast.error(error?.response?.data?.message || 'Erro ao contar transações removíveis.');
                      }
                    }}
                    disabled={!canBulkDeleteCharges || deleteFailedExpiredChargesMutation.isPending || countDeletableChargesMutation.isPending}
                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50 transition-colors sm:w-auto"
                  >
                    <XMarkIcon className="h-4 w-4" />
                    {deleteFailedExpiredChargesMutation.isPending ? 'Apagando...' : countDeletableChargesMutation.isPending ? 'Contando...' : 'Apagar falhadas, recusadas e expiradas'}
                  </button>
                </div>
              </div>

              <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <div className="grid grid-cols-[auto_1fr] items-center gap-2 sm:flex">
                  <CalendarDaysIcon className="h-4 w-4 text-gray-500" />
                  <input
                    type="date"
                    value={txDateFrom}
                    onChange={(e) => { setTxDateFrom(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
                  />
                  <span className="text-sm text-gray-500">até</span>
                  <input
                    type="date"
                    value={txDateTo}
                    onChange={(e) => { setTxDateTo(e.target.value); setTxPage(1); }}
                    className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none sm:w-auto"
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
                    className="flex items-center gap-1 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-500/20 transition-colors"
                  >
                    <XMarkIcon className="h-3.5 w-3.5" />
                    Limpar filtros
                  </button>
                )}
              </div>
            </div>
          </div>

          {/* Transactions Table */}
          <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            {isLoadingCharges ? (
              <div className="flex items-center justify-center py-20">
                <div className="h-10 w-10 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
              </div>
            ) : charges.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-20">
                <DocumentTextIcon className="h-12 w-12 text-gray-500" />
                <p className="mt-4 text-sm text-gray-500">Nenhuma transação encontrada</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="min-w-[1120px] w-full">
                    <thead>
                      <tr className="border-b border-gray-200 bg-gray-50">
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Cliente
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Vendedor
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Produto
                        </th>
                        <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Valor
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Método
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Gateway
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Status
                        </th>
                        <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Data
                        </th>
                        <th className="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">
                          Ações
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border-DEFAULT">
                      {charges.map((charge: any) => (
                        <tr key={charge.id} className="hover:bg-gray-100 transition-colors cursor-pointer" onClick={() => openChargeDetail(charge)}>
                          <td className="px-6 py-4">
                            <div>
                              <p className="text-sm font-medium text-gray-900">{charge.customer_name || '—'}</p>
                              <p className="text-xs text-gray-500">{charge.customer_email || ''}</p>
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-gray-900">{charge.seller_name || charge.account_name}</span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-gray-900 truncate max-w-[150px] block">{charge.product_name || '—'}</span>
                          </td>
                          <td className="px-6 py-4 text-right">
                            <div>
                              <p className="text-sm font-semibold text-gray-900">{formatCurrency(charge.amount)}</p>
                              {charge.fee_amount > 0 && (
                                <p className="text-xs text-gray-500">
                                  Taxa: {formatCurrency(charge.fee_amount)}
                                </p>
                              )}
                            </div>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-gray-900">
                              {getPaymentMethodLabel(charge.payment_method)}
                              {charge.installments > 1 && ` ${charge.installments}x`}
                            </span>
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-xs text-gray-500">{charge.acquirer || '—'}</span>
                          </td>
                          <td className="px-6 py-4">
                            {getChargeStatusBadge(charge.status)}
                          </td>
                          <td className="px-6 py-4">
                            <span className="text-sm text-gray-500">{formatDate(charge.created_at)}</span>
                          </td>
                          <td className="px-6 py-4 text-center">
                            <button
                              onClick={(event) => {
                                event.stopPropagation();
                                openChargeDetail(charge);
                              }}
                              className="rounded-lg p-2 text-green-700 hover:bg-green-50 transition-colors"
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
                  <div className="flex flex-col gap-3 border-t border-gray-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p className="text-sm text-gray-500">
                      Página {chargesPagination.current_page} de {chargesPagination.last_page} ({chargesPagination.total} transações)
                    </p>
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setTxPage(txPage - 1)}
                        disabled={txPage === 1}
                        className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-50"
                      >
                        <ChevronLeftIcon className="h-5 w-5" />
                      </button>
                      <button
                        onClick={() => setTxPage(txPage + 1)}
                        disabled={txPage >= chargesPagination.last_page}
                        className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 disabled:opacity-50"
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
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 sm:p-4" onClick={() => setShowChargeDetailModal(false)}>
          <div className="w-full max-w-5xl max-h-[94vh] overflow-y-auto rounded-lg bg-white p-4 shadow-xl sm:p-6" onClick={(e) => e.stopPropagation()}>
            <div className="mb-5 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-lg font-bold text-gray-900">Detalhes da Transacao</h2>
                <p className="mt-1 text-sm text-gray-500">Dados completos da cobranca e controles financeiros.</p>
              </div>
              <button
                onClick={() => setShowChargeDetailModal(false)}
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
              <div className="space-y-4">
                <div className="flex items-center justify-between rounded-md bg-gray-50 p-4">
                  <div>
                    <p className="text-xs text-gray-500">Valor</p>
                    <p className="text-2xl font-bold text-gray-900">{formatCurrency(selectedCharge.amount)}</p>
                    <p className="mt-1 text-xs text-gray-500">
                      Taxa: {formatCurrency(selectedCharge.fee_amount || 0)} | Liquido: {formatCurrency(selectedCharge.net_amount || (selectedCharge.amount - (selectedCharge.fee_amount || 0)))}
                    </p>
                  </div>
                  {getChargeStatusBadge(selectedCharge.status)}
                </div>

                <div className="grid gap-3 md:grid-cols-2">
                  {[
                    ['ID da Transacao', selectedCharge.id],
                    ['ID Externo', selectedCharge.external_id],
                    ['ID na Adquirente', selectedCharge.acquirer_transaction_id],
                    ['Conta / Seller Account', selectedCharge.account_id],
                    ['Produto ID', selectedCharge.product_id],
                    ['Checkout ID', selectedCharge.checkout_id],
                    ['Afiliado ID', selectedCharge.affiliate_id],
                    ['URL de Pagamento', selectedCharge.payment_url],
                  ].filter(([, value]) => value).map(([label, value]) => (
                    <div key={label as string} className="rounded-md bg-gray-50 p-4">
                      <div className="mb-1 flex items-center justify-between gap-2">
                        <p className="text-xs text-gray-500">{label}</p>
                        <button type="button" onClick={() => copyText(String(value))} className="text-gray-400 hover:text-gray-700">
                          <ClipboardDocumentIcon className="h-4 w-4" />
                        </button>
                      </div>
                      <p className="break-all font-mono text-sm text-gray-900">{String(value)}</p>
                    </div>
                  ))}
                </div>

                <div className="rounded-md bg-gray-50 p-4">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Cliente</p>
                  <div className="grid gap-3 md:grid-cols-2">
                    <div>
                      <p className="text-xs text-gray-500">Nome</p>
                      <p className="text-sm font-medium text-gray-900">{selectedCharge.customer_name || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Email</p>
                      <p className="text-sm text-gray-900">{selectedCharge.customer_email || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Documento</p>
                      <p className="text-sm text-gray-900">{selectedCharge.customer_document || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Telefone</p>
                      <p className="text-sm text-gray-900">{selectedCharge.customer_phone || '-'}</p>
                    </div>
                  </div>
                </div>

                <div className="rounded-md bg-gray-50 p-4">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Seller & Produto</p>
                  <div className="grid gap-3 md:grid-cols-2">
                    <div>
                      <p className="text-xs text-gray-500">Seller</p>
                      {selectedCharge.seller_id ? (
                        <button
                          type="button"
                          onClick={() => {
                            setShowChargeDetailModal(false);
                            navigate(`/admin/sellers/${selectedCharge.seller_id}`);
                          }}
                          className="text-left text-sm font-semibold text-green-700 hover:text-green-800 hover:underline"
                        >
                          {selectedCharge.seller_name || selectedCharge.account_name || '-'}
                        </button>
                      ) : (
                        <p className="text-sm font-medium text-gray-900">{selectedCharge.seller_name || selectedCharge.account_name || '-'}</p>
                      )}
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Email do seller</p>
                      <p className="text-sm text-gray-900">{selectedCharge.seller_email || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Documento do seller</p>
                      <p className="text-sm text-gray-900">{selectedCharge.seller_document || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Telefone do seller</p>
                      <p className="text-sm text-gray-900">{selectedCharge.seller_phone || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Produto</p>
                      <p className="text-sm text-gray-900">{selectedCharge.product_name || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Checkout</p>
                      <p className="text-sm text-gray-900">{selectedCharge.checkout_name || '-'}</p>
                    </div>
                  </div>
                </div>

                <div className="rounded-md bg-gray-50 p-4">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Pagamento</p>
                  <div className="grid gap-3 md:grid-cols-2">
                    <div>
                      <p className="text-xs text-gray-500">Metodo</p>
                      <p className="text-sm font-medium text-gray-900">
                        {getPaymentMethodLabel(selectedCharge.payment_method)}
                        {selectedCharge.installments > 1 && ` ${selectedCharge.installments}x`}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Gateway</p>
                      <p className="text-sm text-gray-900">{selectedCharge.acquirer || selectedCharge.acquirer_name || '-'}</p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">Cartao</p>
                      <p className="text-sm text-gray-900">
                        {selectedCharge.card_brand || '-'} {selectedCharge.card_last4 || selectedCharge.card_last_digits ? `**** ${selectedCharge.card_last4 || selectedCharge.card_last_digits}` : ''}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs text-gray-500">PIX EndToEnd</p>
                      <p className="break-all font-mono text-sm text-gray-900">{selectedCharge.pix_end2end_id || '-'}</p>
                    </div>
                    {selectedCharge.pix_emv && (
                      <div className="md:col-span-2">
                        <div className="mb-1 flex items-center justify-between">
                          <p className="text-xs text-gray-500">PIX Copia e Cola</p>
                          <button type="button" onClick={() => copyText(selectedCharge.pix_emv)} className="text-gray-400 hover:text-gray-700">
                            <ClipboardDocumentIcon className="h-4 w-4" />
                          </button>
                        </div>
                        <p className="break-all font-mono text-xs text-gray-900">{selectedCharge.pix_emv}</p>
                      </div>
                    )}
                    {(selectedCharge.boleto_barcode || selectedCharge.boleto_digitable_line || selectedCharge.boleto_url) && (
                      <div className="md:col-span-2 grid gap-2 rounded-md bg-white p-3">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Boleto</p>
                        <p className="break-all text-xs text-gray-700">Codigo: {selectedCharge.boleto_barcode || '-'}</p>
                        <p className="break-all text-xs text-gray-700">Linha: {selectedCharge.boleto_digitable_line || '-'}</p>
                        <p className="break-all text-xs text-gray-700">URL: {selectedCharge.boleto_url || '-'}</p>
                      </div>
                    )}
                  </div>
                </div>

                <div className="rounded-md bg-gray-50 p-4">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Datas e motivos</p>
                  <div className="grid gap-3 md:grid-cols-2">
                    <div><p className="text-xs text-gray-500">Criado em</p><p className="text-sm text-gray-900">{formatDate(selectedCharge.created_at)}</p></div>
                    <div><p className="text-xs text-gray-500">Atualizado em</p><p className="text-sm text-gray-900">{selectedCharge.updated_at ? formatDate(selectedCharge.updated_at) : '-'}</p></div>
                    <div><p className="text-xs text-gray-500">Pago em</p><p className="text-sm text-gray-900">{selectedCharge.paid_at ? formatDate(selectedCharge.paid_at) : '-'}</p></div>
                    <div><p className="text-xs text-gray-500">Expira em</p><p className="text-sm text-gray-900">{selectedCharge.expires_at ? formatDate(selectedCharge.expires_at) : '-'}</p></div>
                    <div><p className="text-xs text-gray-500">Reembolsado em</p><p className="text-sm text-gray-900">{selectedCharge.refunded_at ? formatDate(selectedCharge.refunded_at) : '-'}</p></div>
                    <div><p className="text-xs text-gray-500">Cancelado em</p><p className="text-sm text-gray-900">{selectedCharge.cancelled_at ? formatDate(selectedCharge.cancelled_at) : '-'}</p></div>
                    <div className="md:col-span-2"><p className="text-xs text-gray-500">Motivo de falha/cancelamento</p><p className="text-sm text-gray-900">{selectedCharge.failure_reason || selectedCharge.cancellation_reason || '-'}</p></div>
                  </div>
                </div>

                {selectedCharge.transactions?.length > 0 && (
                  <div className="rounded-md bg-gray-50 p-4">
                    <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Transacoes internas</p>
                    <div className="overflow-x-auto">
                      <table className="min-w-full text-sm">
                        <thead>
                          <tr className="text-left text-xs uppercase tracking-wider text-gray-500">
                            <th className="py-2 pr-3">ID</th>
                            <th className="py-2 pr-3">Tipo</th>
                            <th className="py-2 pr-3">Status</th>
                            <th className="py-2 pr-3">Valor</th>
                            <th className="py-2 pr-3">TID</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                          {selectedCharge.transactions.map((transaction: any) => (
                            <tr key={transaction.id}>
                              <td className="break-all py-2 pr-3 font-mono text-xs">{transaction.id}</td>
                              <td className="py-2 pr-3">{transaction.type || '-'}</td>
                              <td className="py-2 pr-3">{transaction.status || '-'}</td>
                              <td className="py-2 pr-3">{formatCurrency(transaction.amount || 0)}</td>
                              <td className="break-all py-2 pr-3 font-mono text-xs">{transaction.acquirer_tid || '-'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}

                {(compactJson(selectedCharge.payment_data) || compactJson(selectedCharge.metadata)) && (
                  <div className="grid gap-3 md:grid-cols-2">
                    {compactJson(selectedCharge.payment_data) && (
                      <div className="rounded-md bg-gray-950 p-4 text-gray-100">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Payment data</p>
                        <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all text-xs">{compactJson(selectedCharge.payment_data)}</pre>
                      </div>
                    )}
                    {compactJson(selectedCharge.metadata) && (
                      <div className="rounded-md bg-gray-950 p-4 text-gray-100">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Metadata</p>
                        <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all text-xs">{compactJson(selectedCharge.metadata)}</pre>
                      </div>
                    )}
                  </div>
                )}
              </div>

              <aside className="space-y-4">
                <form
                  onSubmit={(event) => {
                    event.preventDefault();
                    updateChargeStatusMutation.mutate({
                      chargeId: selectedCharge.id,
                      status: chargeStatusForm.status,
                      reason: chargeStatusForm.reason,
                      reason_code: chargeStatusForm.status === 'chargeback' ? chargeStatusForm.reason_code : undefined,
                    });
                  }}
                  className="rounded-md border border-gray-200 bg-white p-4 shadow-sm"
                >
                  <div className="mb-4 flex items-center gap-2">
                    <ShieldCheckIcon className="h-5 w-5 text-green-700" />
                    <h3 className="text-sm font-semibold text-gray-900">Alterar status</h3>
                  </div>
                  <label className="mb-3 block">
                    <span className="mb-1 block text-xs font-medium text-gray-500">Novo status</span>
                    <select
                      value={chargeStatusForm.status}
                      onChange={(event) => setChargeStatusForm((current) => ({ ...current, status: event.target.value }))}
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
                    >
                      {chargeStatusOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </label>
                  {chargeStatusForm.status === 'chargeback' && (
                    <div className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3">
                      <p className="text-sm font-semibold text-red-800">Abrir disputa de chargeback</p>
                      <p className="mt-1 text-xs leading-5 text-red-700">
                        O valor será bloqueado no saldo do vendedor e a disputa ficará disponível para envio de evidências.
                      </p>
                      <label className="mt-3 block">
                        <span className="mb-1 block text-xs font-medium text-red-800">Origem da disputa</span>
                        <select
                          value={chargeStatusForm.reason_code}
                          onChange={(event) => setChargeStatusForm((current) => ({ ...current, reason_code: event.target.value }))}
                          className="w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-100"
                        >
                          <option value="customer_dispute">Contestação do cliente</option>
                          <option value="fraud">Compra não reconhecida</option>
                          <option value="product_not_received">Produto não recebido</option>
                          <option value="product_not_as_described">Produto diferente do anunciado</option>
                          <option value="duplicate_charge">Cobrança duplicada</option>
                          <option value="other">Outro motivo</option>
                        </select>
                      </label>
                    </div>
                  )}
                  <label className="mb-3 block">
                    <span className="mb-1 block text-xs font-medium text-gray-500">Motivo</span>
                    <textarea
                      value={chargeStatusForm.reason}
                      onChange={(event) => setChargeStatusForm((current) => ({ ...current, reason: event.target.value }))}
                      rows={3}
                      placeholder={chargeStatusForm.status === 'chargeback' ? 'Descreva a contestação do cliente' : 'Descreva o motivo da alteracao'}
                      className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
                    />
                  </label>
                  <button
                    type="submit"
                    disabled={
                      updateChargeStatusMutation.isPending ||
                      chargeStatusForm.status === selectedCharge.status ||
                      chargeStatusForm.reason.trim().length < 8
                    }
                    className="w-full rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {updateChargeStatusMutation.isPending
                      ? (chargeStatusForm.status === 'chargeback' ? 'Abrindo disputa...' : 'Atualizando...')
                      : (chargeStatusForm.status === 'chargeback' ? 'Abrir disputa' : 'Salvar status')}
                  </button>
                </form>

                <div className="rounded-md bg-gray-50 p-4">
                  <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Resumo financeiro</p>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between"><span className="text-gray-500">Bruto</span><span className="font-medium">{formatCurrency(selectedCharge.amount || 0)}</span></div>
                    <div className="flex justify-between"><span className="text-gray-500">Taxa</span><span className="font-medium">{formatCurrency(selectedCharge.fee_amount || 0)}</span></div>
                    <div className="flex justify-between"><span className="text-gray-500">Liquido</span><span className="font-medium">{formatCurrency(selectedCharge.net_amount || 0)}</span></div>
                    <div className="flex justify-between"><span className="text-gray-500">Reembolsado</span><span className="font-medium">{formatCurrency(selectedCharge.refunded_amount || 0)}</span></div>
                  </div>
                </div>
              </aside>
            </div>
          </div>
        </div>
      )}

      {/* Previous Charge Detail Modal kept disabled */}
      {false && showChargeDetailModal && selectedCharge && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowChargeDetailModal(false)}>
          <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-bold text-gray-900">Detalhes da Transação</h2>
              <button
                onClick={() => setShowChargeDetailModal(false)}
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              {/* Status & Amount */}
              <div className="flex items-center justify-between rounded-md bg-gray-50 p-4">
                <div>
                  <p className="text-xs text-gray-500 mb-1">Valor</p>
                  <p className="text-2xl font-bold text-gray-900">{formatCurrency(selectedCharge.amount)}</p>
                  {selectedCharge.fee_amount > 0 && (
                    <p className="text-xs text-gray-500 mt-1">
                      Taxa: {formatCurrency(selectedCharge.fee_amount)} | Líquido: {formatCurrency(selectedCharge.amount - selectedCharge.fee_amount)}
                    </p>
                  )}
                </div>
                <div>{getChargeStatusBadge(selectedCharge.status)}</div>
              </div>

              {/* IDs */}
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-md bg-gray-50 p-4">
                  <p className="text-xs text-gray-500 mb-1">ID da Transação</p>
                  <p className="text-sm font-mono text-gray-900 break-all">{selectedCharge.id}</p>
                </div>
                {selectedCharge.external_id && (
                  <div className="rounded-md bg-gray-50 p-4">
                    <p className="text-xs text-gray-500 mb-1">ID Externo</p>
                    <p className="text-sm font-mono text-gray-900 break-all">{selectedCharge.external_id}</p>
                  </div>
                )}
              </div>

              {/* Customer Info */}
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Cliente</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-gray-500">Nome</p>
                    <p className="text-sm font-medium text-gray-900">{selectedCharge.customer_name || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Email</p>
                    <p className="text-sm text-gray-900">{selectedCharge.customer_email || '—'}</p>
                  </div>
                  {selectedCharge.customer_document && (
                    <div>
                      <p className="text-xs text-gray-500">Documento</p>
                      <p className="text-sm text-gray-900">{selectedCharge.customer_document}</p>
                    </div>
                  )}
                  {selectedCharge.customer_phone && (
                    <div>
                      <p className="text-xs text-gray-500">Telefone</p>
                      <p className="text-sm text-gray-900">{selectedCharge.customer_phone}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Seller & Product */}
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Vendedor & Produto</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-gray-500">Vendedor</p>
                    <p className="text-sm font-medium text-gray-900">{selectedCharge.account_name || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Produto</p>
                    <p className="text-sm text-gray-900">{selectedCharge.product_name || '—'}</p>
                  </div>
                  {selectedCharge.checkout_name && (
                    <div>
                      <p className="text-xs text-gray-500">Checkout</p>
                      <p className="text-sm text-gray-900">{selectedCharge.checkout_name}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Payment Details */}
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Pagamento</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-gray-500">Método</p>
                    <p className="text-sm font-medium text-gray-900">
                      {getPaymentMethodLabel(selectedCharge.payment_method)}
                      {selectedCharge.installments > 1 && ` ${selectedCharge.installments}x`}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Gateway</p>
                    <p className="text-sm text-gray-900">{selectedCharge.acquirer || '—'}</p>
                  </div>
                  {selectedCharge.card_brand && (
                    <div>
                      <p className="text-xs text-gray-500">Bandeira</p>
                      <p className="text-sm text-gray-900">{selectedCharge.card_brand}</p>
                    </div>
                  )}
                  {selectedCharge.card_last4 && (
                    <div>
                      <p className="text-xs text-gray-500">Cartão</p>
                      <p className="text-sm text-gray-900">**** {selectedCharge.card_last4}</p>
                    </div>
                  )}
                  {selectedCharge.pix_txid && (
                    <div className="col-span-2">
                      <p className="text-xs text-gray-500">PIX TxID</p>
                      <p className="text-sm font-mono text-gray-900 break-all">{selectedCharge.pix_txid}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Dates */}
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Datas</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="text-xs text-gray-500">Criado em</p>
                    <p className="text-sm text-gray-900">{formatDate(selectedCharge.created_at)}</p>
                  </div>
                  {selectedCharge.paid_at && (
                    <div>
                      <p className="text-xs text-gray-500">Pago em</p>
                      <p className="text-sm text-gray-900">{formatDate(selectedCharge.paid_at)}</p>
                    </div>
                  )}
                  {selectedCharge.refunded_at && (
                    <div>
                      <p className="text-xs text-gray-500">Reembolsado em</p>
                      <p className="text-sm text-gray-900">{formatDate(selectedCharge.refunded_at)}</p>
                    </div>
                  )}
                  {selectedCharge.updated_at && selectedCharge.updated_at !== selectedCharge.created_at && (
                    <div>
                      <p className="text-xs text-gray-500">Atualizado em</p>
                      <p className="text-sm text-gray-900">{formatDate(selectedCharge.updated_at)}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Affiliate */}
              {selectedCharge.affiliate_name && (
                <div className="rounded-md bg-gray-50 p-4">
                  <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Afiliado</p>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <p className="text-xs text-gray-500">Nome</p>
                      <p className="text-sm font-medium text-gray-900">{selectedCharge.affiliate_name}</p>
                    </div>
                    {selectedCharge.affiliate_commission != null && (
                      <div>
                        <p className="text-xs text-gray-500">Comissão</p>
                        <p className="text-sm text-gray-900">{formatCurrency(selectedCharge.affiliate_commission)}</p>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Metadata / Notes */}
              {selectedCharge.notes && (
                <div className="rounded-md bg-gray-50 p-4">
                  <p className="text-xs text-gray-500 mb-1">Observações</p>
                  <p className="text-sm text-gray-900">{selectedCharge.notes}</p>
                </div>
              )}
            </div>

            <div className="mt-6 flex justify-end">
              <button
                onClick={() => setShowChargeDetailModal(false)}
                className="rounded-lg bg-gray-100 px-5 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
              >
                Fechar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showDetailModal && selectedWithdrawal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
          <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl my-8">
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-bold text-gray-900">Detalhes do Saque</h2>
              <button
                onClick={() => {
                  setShowDetailModal(false);
                  setSelectedWithdrawal(null);
                  setRejectReason('');
                }}
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Cliente</p>
                  <p className="text-sm font-medium text-gray-900">{selectedWithdrawal.customer_name}</p>
                  {selectedWithdrawal.customer_email && (
                    <p className="text-xs text-gray-500">{selectedWithdrawal.customer_email}</p>
                  )}
                </div>
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Status</p>
                  {getStatusBadge(selectedWithdrawal.status)}
                </div>
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Valor</p>
                  <p className="text-sm font-bold text-gray-900">{formatCurrency(selectedWithdrawal.amount)}</p>
                </div>
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Taxa</p>
                  <p className="text-sm font-medium text-gray-900">{selectedWithdrawal.fee ? formatCurrency(selectedWithdrawal.fee) : 'R$ 0'}</p>
                </div>
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Líquido</p>
                  <p className="text-sm font-bold text-emerald-600">{formatCurrency(selectedWithdrawal.net_amount || (selectedWithdrawal.amount - (selectedWithdrawal.fee || 0)))}</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Método</p>
                  <p className="text-sm font-medium text-gray-900">
                    {selectedWithdrawal.method === 'pix' ? 'PIX' : 'TED'}
                    {selectedWithdrawal.is_instant && ' (Instantâneo)'}
                  </p>
                </div>
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Data</p>
                  <p className="text-sm font-medium text-gray-900">{formatDate(selectedWithdrawal.requested_at)}</p>
                </div>
              </div>

              {selectedWithdrawal.pix_key && (
                <div className="rounded-md bg-gray-50 p-3">
                  <p className="text-xs text-gray-500 mb-0.5">Chave PIX</p>
                  <p className="text-sm font-mono text-gray-900">{selectedWithdrawal.pix_key}</p>
                </div>
              )}

              {selectedWithdrawal.bank_name && (
                <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-md bg-gray-50 p-3">
                    <p className="text-xs text-gray-500 mb-0.5">Banco</p>
                    <p className="text-sm font-medium text-gray-900">{selectedWithdrawal.bank_name}</p>
                  </div>
                  <div className="rounded-md bg-gray-50 p-3">
                    <p className="text-xs text-gray-500 mb-0.5">Conta</p>
                    <p className="text-sm font-mono text-gray-900">{selectedWithdrawal.bank_agency} / {selectedWithdrawal.bank_account}</p>
                  </div>
                </div>
              )}

              {(selectedWithdrawal.failure_reason || selectedWithdrawal.notes || selectedWithdrawal.cancel_reason) && (
                <div className="rounded-md bg-red-500/5 border border-red-500/20 p-3">
                  <p className="text-xs text-red-700 mb-0.5">Motivo</p>
                  <p className="text-sm text-gray-900">{selectedWithdrawal.failure_reason || selectedWithdrawal.notes || selectedWithdrawal.cancel_reason}</p>
                </div>
              )}

              <div className="rounded-md bg-gray-50 p-3">
                <p className="text-xs text-gray-500 mb-0.5">ID</p>
                <div className="flex items-center gap-2">
                  <p className="text-xs font-mono text-gray-500">{selectedWithdrawal.id}</p>
                  <button
                    onClick={() => { navigator.clipboard.writeText(selectedWithdrawal.id); toast.success('ID copiado'); }}
                    className="text-green-700 hover:text-green-400"
                  >
                    <ClipboardDocumentIcon className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>

              {selectedWithdrawal.status === 'pending' && (
                <>
                  <hr className="border-gray-200" />
                  <div>
                    <label className="block text-sm font-medium text-gray-600 mb-2">
                      Motivo da rejeição
                    </label>
                    <textarea
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                      placeholder="Informe o motivo..."
                      rows={3}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
                    />
                  </div>

                  <div className="flex gap-3 pt-2">
                    <button
                      onClick={() => processMutation.mutate({ withdrawalId: selectedWithdrawal.id, action: 'approve' })}
                      disabled={processMutation.isPending}
                      className="flex-1 rounded-lg bg-emerald-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-600 disabled:opacity-50 transition-colors"
                    >
                      {processMutation.isPending ? 'Processando...' : 'Aprovar'}
                    </button>
                    <button
                      onClick={() => processMutation.mutate({
                        withdrawalId: selectedWithdrawal.id,
                        action: 'reject',
                        notes: rejectReason,
                      })}
                      disabled={processMutation.isPending || !rejectReason.trim()}
                      className="flex-1 rounded-lg bg-red-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50 transition-colors"
                    >
                      {processMutation.isPending ? 'Rejeitando...' : 'Rejeitar'}
                    </button>
                  </div>
                </>
              )}

              {selectedWithdrawal.status !== 'pending' && (
                <div className="flex justify-end pt-2">
                  <button
                    onClick={() => { setShowDetailModal(false); setSelectedWithdrawal(null); }}
                    className="rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
                  >
                    Fechar
                  </button>
                </div>
              )}
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
      <div className="w-full max-w-3xl max-h-[90vh] flex flex-col rounded-lg bg-white shadow-xl">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div>
            <h2 className="text-lg font-bold text-gray-900">Configurações de Saque</h2>
            <p className="text-xs text-gray-500 mt-0.5">Configure taxas, limites, liquidação e automação</p>
          </div>
          <button onClick={onClose} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex border-b border-gray-200 px-6 overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                activeTab === tab.key
                  ? 'border-green-500 text-green-600'
                  : 'border-transparent text-gray-500 hover:text-gray-900'
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
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Taxa de Saque Padrão</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Taxa Percentual (%)</label>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      max="100"
                      value={localConfig.fee_percentage}
                      onChange={(e) => setLocalConfig({ ...localConfig, fee_percentage: parseFloat(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Aplicada sobre o valor do saque</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Taxa Fixa (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.fee_fixed}
                      onChange={(e) => setLocalConfig({ ...localConfig, fee_fixed: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Ex: 200 = R$ 2,00</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-6">
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Taxa por Método</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Taxa PIX (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.standard_withdrawal_fee_pix}
                      onChange={(e) => setLocalConfig({ ...localConfig, standard_withdrawal_fee_pix: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Taxa adicional para saque via PIX</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Taxa TED (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.standard_withdrawal_fee_bank}
                      onChange={(e) => setLocalConfig({ ...localConfig, standard_withdrawal_fee_bank: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Taxa adicional para saque via TED</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-6">
                <div className="flex items-center justify-between mb-3">
                  <h3 className="text-sm font-semibold text-gray-900">Saque Instantâneo</h3>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.instant_withdrawal_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                  </label>
                </div>
                {localConfig.instant_withdrawal_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Tipo da Taxa</label>
                      <select
                        value={localConfig.instant_withdrawal_fee_type}
                        onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_fee_type: e.target.value as 'percentage' | 'fixed' })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      >
                        <option value="percentage">Percentual (%)</option>
                        <option value="fixed">Valor Fixo (centavos)</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">
                        Valor da Taxa {localConfig.instant_withdrawal_fee_type === 'percentage' ? '(%)' : '(centavos)'}
                      </label>
                      <input
                        type="number"
                        step={localConfig.instant_withdrawal_fee_type === 'percentage' ? '0.01' : '1'}
                        min="0"
                        value={localConfig.instant_withdrawal_fee_value}
                        onChange={(e) => setLocalConfig({ ...localConfig, instant_withdrawal_fee_value: parseFloat(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
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
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Limites de Saque</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Mínimo por Saque (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.withdrawal_min_amount}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_min_amount: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Ex: 1000 = R$ 10,00</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Máximo por Saque (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.withdrawal_max_amount}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_max_amount: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">0 = sem limite</p>
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-6">
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Auto-Aprovação</h3>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Limite Auto-Aprovação (centavos)</label>
                  <input
                    type="number"
                    min="0"
                    value={localConfig.auto_approve_limit}
                    onChange={(e) => setLocalConfig({ ...localConfig, auto_approve_limit: parseInt(e.target.value) || 0 })}
                    className="w-full max-w-xs rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                  />
                  <p className="text-xs text-gray-500 mt-1">Saques abaixo desse valor são aprovados automaticamente. 0 = aprovação manual sempre</p>
                </div>
              </div>
            </div>
          )}

          {/* Controle */}
          {activeTab === 'controls' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Controles Operacionais</h3>
                <p className="text-xs text-gray-500 mb-4">Esses controles aplicam bloqueio e governança em tempo real para pagamentos e saques.</p>
                <div className="space-y-4">
                  <div className="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 p-4">
                    <div>
                      <p className="text-sm font-medium text-gray-900">Aceitar Novos Saques</p>
                      <p className="text-xs text-gray-500">Desative para congelar solicitações de saque no painel do seller/API.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.withdrawals_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, withdrawals_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                    </label>
                  </div>

                  <div className="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 p-4">
                    <div>
                      <p className="text-sm font-medium text-gray-900">Processar Saques Automaticamente</p>
                      <p className="text-xs text-gray-500">Desative para pausar envio PIX OUT e manter saques em fila de contingência.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.withdrawal_processing_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_processing_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                    </label>
                  </div>

                  <div className="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 p-4">
                    <div>
                      <p className="text-sm font-medium text-gray-900">Aceitar Novos Pagamentos</p>
                      <p className="text-xs text-gray-500">Kill switch global para checkout público e cobranças via API.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        checked={localConfig.payments_enabled}
                        onChange={(e) => setLocalConfig({ ...localConfig, payments_enabled: e.target.checked })}
                        className="sr-only peer"
                      />
                      <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                    </label>
                  </div>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-6">
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Proteções de Risco</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Limite Diário por Conta (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.daily_withdrawal_limit}
                      onChange={(e) => setLocalConfig({ ...localConfig, daily_withdrawal_limit: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">0 desabilita o limite diário.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Limiar de Aprovação Manual (centavos)</label>
                    <input
                      type="number"
                      min="0"
                      value={localConfig.large_withdrawal_threshold}
                      onChange={(e) => setLocalConfig({ ...localConfig, large_withdrawal_threshold: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Saques acima desse valor entram em pending_approval.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Cooldown Entre Saques (segundos)</label>
                    <input
                      type="number"
                      min="0"
                      max="3600"
                      value={localConfig.withdrawal_cooldown_seconds}
                      onChange={(e) => setLocalConfig({ ...localConfig, withdrawal_cooldown_seconds: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">0 desativa cooldown.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Máximo de Saques em Aberto por Conta</label>
                    <input
                      type="number"
                      min="0"
                      max="500"
                      value={localConfig.max_pending_withdrawals_per_account}
                      onChange={(e) => setLocalConfig({ ...localConfig, max_pending_withdrawals_per_account: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">0 remove o limite.</p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Liquidação */}
          {activeTab === 'settlement' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-sm font-semibold text-gray-900 mb-3">Dias para Liquidação</h3>
                <p className="text-xs text-gray-500 mb-4">Tempo que o saldo do vendedor fica retido após a venda antes de ficar disponível para saque</p>
                <div className="grid gap-4 sm:grid-cols-3">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">PIX (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.settlement_days_pix}
                      onChange={(e) => setLocalConfig({ ...localConfig, settlement_days_pix: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Vendas via PIX</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">TED/Boleto (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.settlement_days_ted}
                      onChange={(e) => setLocalConfig({ ...localConfig, settlement_days_ted: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Vendas via TED/Boleto</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Processamento (dias)</label>
                    <input
                      type="number"
                      min="0"
                      max="30"
                      value={localConfig.processing_days}
                      onChange={(e) => setLocalConfig({ ...localConfig, processing_days: parseInt(e.target.value) || 0 })}
                      className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    />
                    <p className="text-xs text-gray-500 mt-1">Dias para processar o saque</p>
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
                    <h3 className="text-sm font-semibold text-gray-900">Saque Automático</h3>
                    <p className="text-xs text-gray-500 mt-0.5">Transfere automaticamente o saldo disponível para o vendedor</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.auto_withdrawal_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                  </label>
                </div>

                {localConfig.auto_withdrawal_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2 mt-4">
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Frequência</label>
                      <select
                        value={localConfig.auto_withdrawal_schedule}
                        onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_schedule: e.target.value as WithdrawalConfig['auto_withdrawal_schedule'] })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      >
                        <option value="daily">Diário</option>
                        <option value="weekly">Semanal</option>
                        <option value="biweekly">Quinzenal</option>
                        <option value="monthly">Mensal</option>
                      </select>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Valor Mínimo (centavos)</label>
                      <input
                        type="number"
                        min="0"
                        value={localConfig.auto_withdrawal_min_amount}
                        onChange={(e) => setLocalConfig({ ...localConfig, auto_withdrawal_min_amount: parseInt(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      />
                      <p className="text-xs text-gray-500 mt-1">Saldo mínimo para disparar saque automático</p>
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
                    <h3 className="text-sm font-semibold text-gray-900">Holdback / Reserva</h3>
                    <p className="text-xs text-gray-500 mt-0.5">Retém uma porcentagem do saldo como reserva contra chargebacks</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input
                      type="checkbox"
                      checked={localConfig.holdback_enabled}
                      onChange={(e) => setLocalConfig({ ...localConfig, holdback_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600" />
                  </label>
                </div>

                {localConfig.holdback_enabled && (
                  <div className="grid gap-4 sm:grid-cols-2 mt-4">
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Porcentagem Retida (%)</label>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={localConfig.holdback_percentage}
                        onChange={(e) => setLocalConfig({ ...localConfig, holdback_percentage: parseFloat(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-600 mb-1">Dias de Retenção</label>
                      <input
                        type="number"
                        min="0"
                        max="365"
                        value={localConfig.holdback_days}
                        onChange={(e) => setLocalConfig({ ...localConfig, holdback_days: parseInt(e.target.value) || 0 })}
                        className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      />
                      <p className="text-xs text-gray-500 mt-1">Após esse período a reserva é liberada</p>
                    </div>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex gap-3 p-6 border-t border-gray-200">
          <button
            onClick={onClose}
            className="flex-1 rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
          >
            Cancelar
          </button>
          <button
            onClick={() => onSave(localConfig)}
            disabled={isSaving}
            className="flex-1 rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50 transition-colors"
          >
            {isSaving ? 'Salvando...' : 'Salvar Configurações'}
          </button>
        </div>
      </div>
    </div>
  );
}
