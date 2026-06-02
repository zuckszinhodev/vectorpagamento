import { useState, useEffect, useMemo } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowLeftIcon,
  ArrowPathIcon,
  AdjustmentsHorizontalIcon,
  BanknotesIcon,
  BoltIcon,
  CheckCircleIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClipboardDocumentIcon,
  ClockIcon,
  CreditCardIcon,
  DocumentTextIcon,
  EyeIcon,
  IdentificationIcon,
  KeyIcon,
  MagnifyingGlassIcon,
  ShieldCheckIcon,
  SignalIcon,
  UserCircleIcon,
  XCircleIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface CustomerDetail {
  id: string;
  account_id: string;
  name: string;
  email: string;
  phone?: string;
  role?: string;
  status: 'active' | 'suspended' | 'blocked';
  withdrawal_enabled?: boolean;
  block_reason?: string;
  blocked_at?: string;
  kyc_status: 'pending' | 'submitted' | 'under_review' | 'approved' | 'rejected';
  mfa_enabled: boolean;
  created_at: string;
  last_login_at?: string;
  last_login_ip?: string;
  account?: {
    name?: string;
    document?: string;
    document_type?: string;
    email?: string;
    phone?: string;
  };
  financials: {
    total_revenue: number;
    total_charges: number;
    approved_charges: number;
    available_balance: number;
    pending_charges: number;
    refunded_charges: number;
    total_withdrawn: number;
    pending_withdrawals: number;
  };
  bank_info?: {
    bank_code?: string;
    bank_name?: string;
    agency?: string;
    account_number?: string;
    account_type?: string;
    holder_name?: string;
    holder_document?: string;
  };
  pix_info?: {
    pix_key?: string;
    pix_type?: string;
    from_kyc?: boolean;
  };
  pix_keys?: Array<{ id?: string; pix_key: string; pix_type: string; label?: string }>;
  kyc?: {
    id: string;
    status: string;
    full_name?: string;
    document_number?: string;
    birth_date?: string;
    phone?: string;
    account_type?: string;
    company_name?: string;
    company_document?: string;
    address?: {
      street?: string;
      number?: string;
      complement?: string;
      neighborhood?: string;
      city?: string;
      state?: string;
      zip_code?: string;
    };
    reviewed_at?: string;
    rejection_reason?: string;
    submitted_at?: string;
  };
  kyc_documents?: Array<{ id: string; type: string; file_name: string; status: string; file_url?: string }>;
  recent_charges?: Array<{
    id: string;
    amount: number;
    fee_amount: number;
    net_amount: number;
    status: string;
    payment_method: string;
    product_name?: string;
    customer_name?: string;
    customer_email?: string;
    created_at: string;
  }>;
  logs?: Array<{ event: string; date?: string; ip?: string }>;
}

interface Charge {
  id: string;
  amount: number;
  fee_amount: number;
  net_amount: number;
  status: string;
  payment_method: string;
  product_name?: string;
  customer_name?: string;
  customer_email?: string;
  customer_document?: string;
  customer_phone?: string;
  acquirer_name?: string;
  acquirer_transaction_id?: string;
  pix_qr_code?: string;
  pix_qr_code_url?: string;
  pix_emv?: string;
  boleto_barcode?: string;
  boleto_url?: string;
  card_brand?: string;
  card_last_digits?: string;
  installments?: number;
  created_at: string;
  paid_at?: string;
  refunded_at?: string;
  expires_at?: string;
}

interface WithdrawalItem {
  id: string;
  amount: number;
  fee?: number;
  net_amount?: number;
  method?: string;
  is_instant: boolean;
  status: string;
  acquirer?: string;
  acquirer_transfer_id?: string;
  destination?: Record<string, string>;
  failure_reason?: string;
  notes?: string;
  created_at: string;
  processed_at?: string;
}

interface AuditLogItem {
  id: string;
  source?: string;
  action: string;
  entity_type?: string;
  entity_id?: string;
  ip_address?: string;
  user_agent?: string;
  endpoint?: string;
  method?: string;
  response_status?: number;
  correlation_id?: string;
  security_flags?: string[];
  request_body?: unknown;
  entered_at?: string;
  exited_at?: string;
  duration_ms?: number;
  risk_level?: 'low' | 'medium' | 'high' | string;
  old_values?: Record<string, unknown>;
  new_values?: Record<string, unknown>;
  metadata?: Record<string, unknown>;
  created_at: string;
}

type Tab = 'info' | 'charges' | 'withdrawals' | 'fees' | 'acquirers' | 'logs';

interface WalletAdjustmentFormState {
  direction: 'credit' | 'debit';
  amount: string;
  reason: string;
  notes: string;
}

export default function AdminCustomerDetail() {
  const { customerId } = useParams<{ customerId: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<Tab>('info');
  const [chargesPage, setChargesPage] = useState(1);
  const [chargesStatus, setChargesStatus] = useState('all');
  const [chargesSearch, setChargesSearch] = useState('');
  const [withdrawalsPage, setWithdrawalsPage] = useState(1);
  const [logsPage, setLogsPage] = useState(1);
  const [showWalletAdjustmentModal, setShowWalletAdjustmentModal] = useState(false);
  const [walletAdjustmentForm, setWalletAdjustmentForm] = useState<WalletAdjustmentFormState>({
    direction: 'debit',
    amount: '',
    reason: '',
    notes: '',
  });

  // Fetch customer detail
  const { data: customer, isLoading, refetch } = useQuery<CustomerDetail>({
    queryKey: ['admin-customer-detail', customerId],
    queryFn: async () => {
      const response = await api.get(`/admin/customers/${customerId}`);
      return response.data?.data;
    },
    enabled: !!customerId,
  });

  // Fetch charges
  const { data: chargesData, isLoading: chargesLoading } = useQuery({
    queryKey: ['admin-customer-charges', customerId, chargesPage, chargesStatus, chargesSearch],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', chargesPage.toString());
      params.append('per_page', '15');
      if (chargesStatus !== 'all') params.append('status', chargesStatus);
      if (chargesSearch) params.append('search', chargesSearch);
      const response = await api.get(`/admin/customers/${customerId}/charges?${params.toString()}`);
      return response.data;
    },
    enabled: !!customerId && activeTab === 'charges',
  });

  // Fetch withdrawals
  const { data: withdrawalsData, isLoading: withdrawalsLoading } = useQuery({
    queryKey: ['admin-customer-withdrawals', customerId, withdrawalsPage],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', withdrawalsPage.toString());
      params.append('per_page', '15');
      const response = await api.get(`/admin/customers/${customerId}/withdrawals?${params.toString()}`);
      return response.data;
    },
    enabled: !!customerId && activeTab === 'withdrawals',
  });

  // Fetch audit logs
  const { data: logsData, isLoading: logsLoading } = useQuery({
    queryKey: ['admin-customer-logs', customerId, logsPage],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', logsPage.toString());
      params.append('per_page', '20');
      const response = await api.get(`/admin/customers/${customerId}/audit-logs?${params.toString()}`);
      return response.data;
    },
    enabled: !!customerId && activeTab === 'logs',
  });

  // Mutations
  const updateStatusMutation = useMutation({
    mutationFn: async ({ status, block_reason }: { status: string; block_reason?: string }) => {
      await api.put(`/admin/customers/${customerId}/status`, { status, block_reason });
    },
    onSuccess: () => {
      toast.success('Status atualizado');
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
    },
    onError: () => toast.error('Erro ao atualizar status'),
  });

  const updateWithdrawalAccessMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      await api.put(`/admin/customers/${customerId}/withdrawal-access`, { enabled });
    },
    onSuccess: () => {
      toast.success('Controle de saque atualizado');
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
    },
    onError: () => toast.error('Erro ao atualizar controle de saque'),
  });

  const recalculateBalanceMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/admin/customers/${customerId}/recalculate-balance`);
      return response.data?.data;
    },
    onSuccess: (data) => {
      toast.success(`Saldo recalculado: ${formatCurrency(data?.new_balance || 0)}`);
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
    },
    onError: () => toast.error('Erro ao recalcular saldo'),
  });

  const adjustWalletMutation = useMutation({
    mutationFn: async (payload: { amount: number; direction: 'credit' | 'debit'; reason: string; notes?: string }) => {
      const response = await api.post(`/admin/customers/${customerId}/wallet-adjustments`, payload);
      return response.data?.data;
    },
    onSuccess: () => {
      toast.success('Ajuste de saldo aplicado');
      setShowWalletAdjustmentModal(false);
      setWalletAdjustmentForm({ direction: 'debit', amount: '', reason: '', notes: '' });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-logs', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-withdrawals', customerId] });
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || 'Erro ao aplicar ajuste de saldo';
      toast.error(message);
    },
  });

  const impersonateMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/admin/customers/${customerId}/impersonate`);
      return response.data;
    },
    onSuccess: (payload) => {
      if (payload?.success && payload?.data?.token) {
        const data = payload.data;
        window.open(`${window.location.origin}/dashboard?impersonate_token=${encodeURIComponent(data.token)}`, '_blank');
        toast.success(`Login como ${data.user?.name || 'vendedor'} aberto em nova aba`);
        return;
      }

      toast.error('Não foi possível gerar o token de impersonação');
    },
    onError: () => toast.error('Erro ao gerar token de impersonação'),
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active': return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"><CheckCircleIcon className="h-3.5 w-3.5" />Ativo</span>;
      case 'suspended': return <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700"><ClockIcon className="h-3.5 w-3.5" />Suspenso</span>;
      case 'blocked': return <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700"><XCircleIcon className="h-3.5 w-3.5" />Bloqueado</span>;
      default: return null;
    }
  };

  const getKycBadge = (status: string) => {
    switch (status) {
      case 'approved': return <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"><ShieldCheckIcon className="h-3.5 w-3.5" />Aprovado</span>;
      case 'submitted': return <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">Enviado</span>;
      case 'under_review': return <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">Em Análise</span>;
      case 'pending': return <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">Pendente</span>;
      case 'rejected': return <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700">Rejeitado</span>;
      default: return <span className="text-xs text-gray-500">-</span>;
    }
  };

  const getChargeStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      approved: 'bg-emerald-50 text-emerald-700',
      pending: 'bg-amber-50 text-amber-700',
      processing: 'bg-blue-50 text-blue-700',
      refunded: 'bg-green-50 text-green-700',
      failed: 'bg-red-50 text-red-700',
      cancelled: 'bg-gray-500/10 text-gray-500',
    };
    const labels: Record<string, string> = {
      approved: 'Aprovado', pending: 'Pendente', processing: 'Processando',
      refunded: 'Reembolsado', failed: 'Falhou', cancelled: 'Cancelado',
    };
    return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${styles[status] || 'bg-gray-500/10 text-gray-500'}`}>{labels[status] || status}</span>;
  };

  const getWithdrawalStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      completed: 'bg-emerald-50 text-emerald-700',
      approved: 'bg-blue-50 text-blue-700',
      processing: 'bg-blue-50 text-blue-700',
      pending: 'bg-amber-50 text-amber-700',
      rejected: 'bg-red-50 text-red-700',
      cancelled: 'bg-gray-500/10 text-gray-500',
      failed: 'bg-red-50 text-red-700',
    };
    const labels: Record<string, string> = {
      completed: 'Concluído', approved: 'Aprovado', processing: 'Processando',
      pending: 'Pendente', rejected: 'Rejeitado', cancelled: 'Cancelado', failed: 'Falhou',
    };
    return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${styles[status] || 'bg-gray-500/10 text-gray-500'}`}>{labels[status] || status}</span>;
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Copiado!');
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-10 w-10 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  if (!customer) {
    return (
      <div className="flex flex-col items-center justify-center py-20">
        <p className="text-gray-500">Cliente não encontrado</p>
        <button onClick={() => navigate(-1)} className="mt-4 text-green-700 hover:underline">Voltar</button>
      </div>
    );
  }

  const tabs = [
    { key: 'info' as const, label: 'Informações', icon: UserCircleIcon },
    { key: 'charges' as const, label: 'Transações', icon: BanknotesIcon },
    { key: 'withdrawals' as const, label: 'Saques', icon: BoltIcon },
    { key: 'fees' as const, label: 'Taxas', icon: AdjustmentsHorizontalIcon },
    { key: 'acquirers' as const, label: 'Adquirentes', icon: CreditCardIcon },
    { key: 'logs' as const, label: 'Logs / Auditoria', icon: DocumentTextIcon },
  ];

  const parsedAdjustmentAmount = Number(walletAdjustmentForm.amount.replace(/\D/g, ''));
  const currentAvailableBalance = customer.financials.available_balance || 0;
  const canZeroBalance = currentAvailableBalance > 0;
  const isWalletAdjustmentValid =
    parsedAdjustmentAmount > 0 &&
    walletAdjustmentForm.reason.trim().length >= 8 &&
    (walletAdjustmentForm.direction === 'credit' || parsedAdjustmentAmount <= currentAvailableBalance);

  const submitWalletAdjustment = () => {
    if (!isWalletAdjustmentValid) return;

    adjustWalletMutation.mutate({
      amount: parsedAdjustmentAmount,
      direction: walletAdjustmentForm.direction,
      reason: walletAdjustmentForm.reason.trim(),
      notes: walletAdjustmentForm.notes.trim() || undefined,
    });
  };

  return (
    <AdminPageShell>
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-start gap-4">
          <button
            onClick={() => navigate(-1)}
            className="mt-1 rounded-lg p-2 text-gray-500 hover:bg-gray-100 transition-colors"
          >
            <ArrowLeftIcon className="h-5 w-5" />
          </button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{customer.name || 'Sem nome'}</h1>
              {getStatusBadge(customer.status)}
              {getKycBadge(customer.kyc_status)}
            </div>
            <p className="text-sm text-gray-500 mt-1">{customer.email}</p>
            {customer.phone && <p className="text-xs text-gray-500">{customer.phone}</p>}
            <p className="text-xs text-gray-500 mt-1">
              ID: <span className="font-mono">{customer.id}</span>
              <button onClick={() => copyToClipboard(customer.id)} className="ml-1 text-green-700 hover:text-green-400">
                <ClipboardDocumentIcon className="inline h-3 w-3" />
              </button>
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => refetch()}
            className="flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-100 transition-colors"
          >
            <ArrowPathIcon className="h-3.5 w-3.5" />
            Atualizar
          </button>
          <button
            onClick={() => recalculateBalanceMutation.mutate()}
            disabled={recalculateBalanceMutation.isPending}
            className="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
          >
            <ArrowPathIcon className="h-3.5 w-3.5" />
            Recalcular Saldo
          </button>
          <button
            onClick={() => setShowWalletAdjustmentModal(true)}
            className="flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-2 text-xs font-medium text-white hover:bg-amber-700 transition-colors"
          >
            <AdjustmentsHorizontalIcon className="h-3.5 w-3.5" />
            Ajustar Saldo
          </button>
          <button
            onClick={() => {
              if (window.confirm(`Logar na conta de ${customer.name}? Isso será registrado no audit log.`)) {
                impersonateMutation.mutate();
              }
            }}
            disabled={impersonateMutation.isPending}
            className="flex items-center gap-1.5 rounded-md bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-800 disabled:opacity-50 transition-colors"
          >
            <KeyIcon className="h-3.5 w-3.5" />
            Login como Vendedor
          </button>
        </div>
      </div>

      {/* Financial Summary Cards */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-md bg-emerald-50 p-4">
          <p className="text-xs text-emerald-600 mb-1">Saldo Disponível</p>
          <p className="text-xl font-bold text-emerald-600">{formatCurrency(customer.financials.available_balance)}</p>
        </div>
        <div className="rounded-md bg-green-50 p-4">
          <p className="text-xs text-green-600 mb-1">Faturamento Total</p>
          <p className="text-xl font-bold text-green-600">{formatCurrency(customer.financials.total_revenue)}</p>
        </div>
        <div className="rounded-md bg-blue-50 p-4">
          <p className="text-xs text-blue-600 mb-1">Total Sacado</p>
          <p className="text-xl font-bold text-blue-600">{formatCurrency(customer.financials.total_withdrawn)}</p>
        </div>
        <div className="rounded-md bg-amber-50 p-4">
          <p className="text-xs text-amber-600 mb-1">Saques Pendentes</p>
          <p className="text-xl font-bold text-amber-600">{formatCurrency(customer.financials.pending_withdrawals)}</p>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="rounded-md bg-slate-500/10 p-4">
          <p className="text-xs text-slate-600 mb-1">Compliance KYC</p>
          <p className="text-sm font-bold text-slate-700">
            {customer.kyc_status === 'approved' ? 'Aprovado' : customer.kyc_status === 'rejected' ? 'Rejeitado' : 'Em análise'}
          </p>
        </div>
        <div className="rounded-md bg-cyan-500/10 p-4">
          <p className="text-xs text-cyan-600 mb-1">MFA</p>
          <p className="text-sm font-bold text-cyan-700">{customer.mfa_enabled ? 'Ativo' : 'Desativado'}</p>
        </div>
        <div className="rounded-md bg-green-50 p-4">
          <p className="text-xs text-green-600 mb-1">Último Login</p>
          <p className="text-sm font-bold text-green-700">{customer.last_login_at ? formatDate(customer.last_login_at) : 'Sem registro'}</p>
        </div>
        <div className="rounded-md bg-red-50 p-4">
          <p className="text-xs text-red-600 mb-1">Risco Operacional</p>
          <p className="text-sm font-bold text-red-700">
            {customer.status === 'blocked' || !customer.mfa_enabled || customer.kyc_status !== 'approved' ? 'Atenção' : 'Controlado'}
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <div className="flex overflow-x-auto">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`flex items-center gap-1.5 px-5 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
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
      </div>

      {/* Tab Content */}
      {activeTab === 'info' && (
        <InfoTab
          customer={customer}
          onStatusChange={(status, reason) => updateStatusMutation.mutate({ status, block_reason: reason })}
          statusLoading={updateStatusMutation.isPending}
          onWithdrawalAccessChange={(enabled) => updateWithdrawalAccessMutation.mutate(enabled)}
          withdrawalAccessLoading={updateWithdrawalAccessMutation.isPending}
        />
      )}
      {activeTab === 'charges' && (
        <ChargesTab
          customerId={customerId!}
          charges={chargesData?.data || []}
          pagination={chargesData?.meta}
          isLoading={chargesLoading}
          page={chargesPage}
          setPage={setChargesPage}
          statusFilter={chargesStatus}
          setStatusFilter={(s) => { setChargesStatus(s); setChargesPage(1); }}
          searchQuery={chargesSearch}
          setSearchQuery={(s) => { setChargesSearch(s); setChargesPage(1); }}
          getStatusBadge={getChargeStatusBadge}
          onChargeStatusChanged={() => {
            queryClient.invalidateQueries({ queryKey: ['admin-customer-charges', customerId] });
            queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
            queryClient.invalidateQueries({ queryKey: ['admin-customer-logs', customerId] });
          }}
        />
      )}
      {activeTab === 'withdrawals' && (
        <WithdrawalsTab
          customerId={customerId!}
          withdrawals={withdrawalsData?.data || []}
          pagination={withdrawalsData?.meta}
          isLoading={withdrawalsLoading}
          page={withdrawalsPage}
          setPage={setWithdrawalsPage}
          getStatusBadge={getWithdrawalStatusBadge}
        />
      )}
      {activeTab === 'logs' && (
        <LogsTab
          logs={logsData?.data || []}
          pagination={logsData?.meta}
          isLoading={logsLoading}
          page={logsPage}
          setPage={setLogsPage}
          activityLogs={customer.logs || []}
        />
      )}
      {activeTab === 'fees' && <FeesTab customerId={customerId!} />}
      {activeTab === 'acquirers' && <AcquirersTab customerId={customerId!} />}

      {showWalletAdjustmentModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setShowWalletAdjustmentModal(false)}>
          <div className="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
            <div className="mb-5 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-lg font-bold text-gray-900">Ajustar saldo do seller</h2>
                <p className="mt-1 text-sm text-gray-500">
                  O ajuste registra motivo e auditoria. Para zerar, use um debito no valor exato do saldo atual.
                </p>
              </div>
              <button
                onClick={() => setShowWalletAdjustmentModal(false)}
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="mb-4 rounded-lg bg-emerald-50 p-4">
              <p className="text-xs font-medium uppercase tracking-wide text-emerald-700">Saldo atual</p>
              <p className="mt-1 text-2xl font-bold text-emerald-700">{formatCurrency(currentAvailableBalance)}</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block">
                <span className="mb-1 block text-xs font-medium text-gray-500">Tipo de ajuste</span>
                <select
                  value={walletAdjustmentForm.direction}
                  onChange={(event) => setWalletAdjustmentForm((current) => ({
                    ...current,
                    direction: event.target.value as 'credit' | 'debit',
                  }))}
                  className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
                >
                  <option value="debit">Debitar saldo</option>
                  <option value="credit">Creditar saldo</option>
                </select>
              </label>

              <label className="block">
                <span className="mb-1 block text-xs font-medium text-gray-500">Valor em centavos</span>
                <input
                  value={walletAdjustmentForm.amount}
                  onChange={(event) => setWalletAdjustmentForm((current) => ({
                    ...current,
                    amount: event.target.value.replace(/\D/g, ''),
                  }))}
                  inputMode="numeric"
                  placeholder="Ex.: 110020"
                  className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
                />
                <p className="mt-1 text-xs text-gray-500">Equivale a {formatCurrency(parsedAdjustmentAmount || 0)}</p>
              </label>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => setWalletAdjustmentForm((current) => ({
                  ...current,
                  direction: 'debit',
                  amount: String(currentAvailableBalance),
                }))}
                disabled={!canZeroBalance}
                className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
              >
                Zerar saldo com debito total
              </button>
            </div>

            <label className="mt-4 block">
              <span className="mb-1 block text-xs font-medium text-gray-500">Motivo</span>
              <input
                value={walletAdjustmentForm.reason}
                onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, reason: event.target.value }))}
                placeholder="Motivo obrigatorio para auditoria"
                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
              />
            </label>

            <label className="mt-4 block">
              <span className="mb-1 block text-xs font-medium text-gray-500">Observacoes</span>
              <textarea
                value={walletAdjustmentForm.notes}
                onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, notes: event.target.value }))}
                rows={4}
                placeholder="Detalhes adicionais do ajuste"
                className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-100"
              />
            </label>

            {walletAdjustmentForm.direction === 'debit' && parsedAdjustmentAmount > currentAvailableBalance && (
              <div className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                O debito nao pode deixar o saldo negativo.
              </div>
            )}

            <div className="mt-6 flex justify-end gap-3">
              <button
                type="button"
                onClick={() => setShowWalletAdjustmentModal(false)}
                className="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={submitWalletAdjustment}
                disabled={!isWalletAdjustmentValid || adjustWalletMutation.isPending}
                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
              >
                {adjustWalletMutation.isPending ? 'Aplicando...' : 'Aplicar ajuste'}
              </button>
            </div>
          </div>
        </div>
      )}
    </AdminPageShell>
  );
}

// ─── Info Tab ────────────────────────────────────────────────────────────────

function InfoTab({
  customer,
  onStatusChange,
  statusLoading,
  onWithdrawalAccessChange,
  withdrawalAccessLoading,
}: {
  customer: CustomerDetail;
  onStatusChange: (status: string, reason?: string) => void;
  statusLoading: boolean;
  onWithdrawalAccessChange: (enabled: boolean) => void;
  withdrawalAccessLoading: boolean;
}) {
  const [blockReason, setBlockReason] = useState('');

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {/* Personal Info */}
      <div className="rounded-lg bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <UserCircleIcon className="h-4 w-4 text-green-700" />
          Dados Pessoais
        </h3>
        <div className="space-y-3">
          <InfoRow label="Nome" value={customer.name} />
          <InfoRow label="Email" value={customer.email} />
          <InfoRow label="Telefone" value={customer.phone || customer.account?.phone} />
          <InfoRow label="Documento" value={customer.account?.document} />
          <InfoRow label="Tipo Doc." value={customer.account?.document_type === 'cnpj' ? 'CNPJ' : 'CPF'} />
          <InfoRow label="Role" value={customer.role || 'user'} />
          <InfoRow label="MFA" value={customer.mfa_enabled ? '✅ Ativado' : '❌ Desativado'} />
          <InfoRow label="Cadastro" value={customer.created_at ? formatDate(customer.created_at) : '-'} />
          <InfoRow label="Último Login" value={customer.last_login_at ? formatDate(customer.last_login_at) : 'Nunca'} />
          <InfoRow label="IP Último Login" value={customer.last_login_ip} mono />
        </div>
      </div>

      {/* KYC Info */}
      <div className="rounded-lg bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <IdentificationIcon className="h-4 w-4 text-green-700" />
          KYC / Verificação
        </h3>
        {customer.kyc ? (
          <div className="space-y-3">
            <InfoRow label="Status" value={customer.kyc_status} badge />
            <InfoRow label="Nome Completo" value={customer.kyc.full_name} />
            <InfoRow label="Documento" value={customer.kyc.document_number} />
            <InfoRow label="Data Nasc." value={customer.kyc.birth_date} />
            <InfoRow label="Telefone" value={customer.kyc.phone} />
            <InfoRow label="Tipo Conta" value={customer.kyc.account_type === 'company' ? 'Empresa' : 'Pessoa Física'} />
            {customer.kyc.company_name && <InfoRow label="Empresa" value={customer.kyc.company_name} />}
            {customer.kyc.company_document && <InfoRow label="CNPJ" value={customer.kyc.company_document} />}
            {customer.kyc.address && (
              <>
                <div className="border-t border-gray-200 pt-2 mt-2">
                  <p className="text-xs font-medium text-gray-500 mb-2">Endereço</p>
                </div>
                <InfoRow label="Rua" value={customer.kyc.address.street} />
                <InfoRow label="Número" value={customer.kyc.address.number} />
                {customer.kyc.address.complement && <InfoRow label="Complemento" value={customer.kyc.address.complement} />}
                <InfoRow label="Bairro" value={customer.kyc.address.neighborhood} />
                <InfoRow label="Cidade" value={`${customer.kyc.address.city || ''} - ${customer.kyc.address.state || ''}`} />
                <InfoRow label="CEP" value={customer.kyc.address.zip_code} />
              </>
            )}
            {customer.kyc.rejection_reason && (
              <div className="mt-2 rounded-lg bg-red-50 p-3">
                <p className="text-xs font-medium text-red-700 mb-1">Motivo da Rejeição</p>
                <p className="text-xs text-red-400">{customer.kyc.rejection_reason}</p>
              </div>
            )}
            <InfoRow label="Enviado em" value={customer.kyc.submitted_at ? formatDate(customer.kyc.submitted_at) : '-'} />
          </div>
        ) : (
          <p className="text-sm text-gray-500">KYC não enviado</p>
        )}

        {customer.kyc_documents && customer.kyc_documents.length > 0 && (
          <div className="mt-4 border-t border-gray-200 pt-4">
            <p className="text-xs font-medium text-gray-500 mb-2">Documentos Enviados</p>
            <div className="space-y-2">
              {customer.kyc_documents.map((doc) => (
                <div key={doc.id} className="flex items-center justify-between rounded-lg bg-gray-50 p-2.5">
                  <div className="flex items-center gap-2">
                    <DocumentTextIcon className="h-4 w-4 text-gray-500" />
                    <div>
                      <p className="text-xs font-medium text-gray-900">{doc.type}</p>
                      <p className="text-xs text-gray-500">{doc.file_name}</p>
                    </div>
                  </div>
                  {doc.file_url && (
                    <a href={doc.file_url} target="_blank" rel="noopener noreferrer" className="text-xs text-green-700 hover:underline">
                      <EyeIcon className="h-4 w-4" />
                    </a>
                  )}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Payment Info */}
      <div className="rounded-lg bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <BanknotesIcon className="h-4 w-4 text-green-700" />
          Dados de Pagamento
        </h3>
        <div className="space-y-4">
          {/* PIX */}
          <div>
            <p className="text-xs font-medium text-gray-500 mb-2">Chaves PIX</p>
            {customer.pix_keys && customer.pix_keys.length > 0 ? (
              <div className="space-y-2">
                {customer.pix_keys.map((key, i) => (
                  <div key={key.id || i} className="flex items-center justify-between rounded-lg bg-gray-50 p-2.5">
                    <div>
                      <p className="text-xs text-gray-500">{key.pix_type?.toUpperCase()} {key.label ? `(${key.label})` : ''}</p>
                      <p className="text-sm font-mono text-gray-900">{key.pix_key}</p>
                    </div>
                    <button onClick={() => { navigator.clipboard.writeText(key.pix_key); toast.success('Copiado!'); }}>
                      <ClipboardDocumentIcon className="h-4 w-4 text-gray-500 hover:text-green-700" />
                    </button>
                  </div>
                ))}
              </div>
            ) : customer.pix_info ? (
              <div className="rounded-lg bg-gray-50 p-2.5">
                <p className="text-xs text-gray-500">{customer.pix_info.pix_type?.toUpperCase()} {customer.pix_info.from_kyc ? '(do KYC)' : ''}</p>
                <p className="text-sm font-mono text-gray-900">{customer.pix_info.pix_key}</p>
              </div>
            ) : (
              <p className="text-xs text-gray-500">Nenhuma chave PIX cadastrada</p>
            )}
          </div>

          {/* Bank */}
          <div>
            <p className="text-xs font-medium text-gray-500 mb-2">Conta Bancária</p>
            {customer.bank_info ? (
              <div className="rounded-lg bg-gray-50 p-3 space-y-1">
                <InfoRow label="Banco" value={`${customer.bank_info.bank_code || ''} - ${customer.bank_info.bank_name || ''}`} />
                <InfoRow label="Agência" value={customer.bank_info.agency} />
                <InfoRow label="Conta" value={customer.bank_info.account_number} />
                <InfoRow label="Tipo" value={customer.bank_info.account_type === 'savings' ? 'Poupança' : 'Corrente'} />
                <InfoRow label="Titular" value={customer.bank_info.holder_name} />
                <InfoRow label="CPF/CNPJ" value={customer.bank_info.holder_document} />
              </div>
            ) : (
              <p className="text-xs text-gray-500">Nenhuma conta bancária cadastrada</p>
            )}
          </div>
        </div>
      </div>

      {/* Financial Stats + Status Control */}
      <div className="space-y-6">
        <div className="rounded-lg bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-900 mb-4">Resumo Financeiro</h3>
          <div className="grid grid-cols-2 gap-3">
            <StatMini label="Total Cobranças" value={customer.financials.total_charges.toString()} />
            <StatMini label="Aprovadas" value={customer.financials.approved_charges.toString()} color="emerald" />
            <StatMini label="Pendentes" value={customer.financials.pending_charges.toString()} color="amber" />
            <StatMini label="Reembolsadas" value={customer.financials.refunded_charges.toString()} color="purple" />
          </div>
        </div>

        {/* Status Control */}
        <div className="rounded-lg bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <ShieldCheckIcon className="h-4 w-4 text-green-700" />
            Controle de Conta
          </h3>

          {customer.block_reason && (
            <div className="mb-4 rounded-lg bg-red-50 p-3">
              <p className="text-xs font-medium text-red-700 mb-1">Motivo do Bloqueio</p>
              <p className="text-xs text-red-400">{customer.block_reason}</p>
              {customer.blocked_at && <p className="text-xs text-red-400 mt-1">Em: {formatDate(customer.blocked_at)}</p>}
            </div>
          )}

          <div className="space-y-3">
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Motivo (opcional)</label>
              <input
                type="text"
                value={blockReason}
                onChange={(e) => setBlockReason(e.target.value)}
                placeholder="Motivo para suspensão/bloqueio..."
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
              />
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => onStatusChange('active')}
                disabled={customer.status === 'active' || statusLoading}
                className="flex-1 rounded-lg bg-emerald-500 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-600 disabled:opacity-40 transition-colors"
              >
                Ativar
              </button>
              <button
                onClick={() => onStatusChange('suspended', blockReason || undefined)}
                disabled={customer.status === 'suspended' || statusLoading}
                className="flex-1 rounded-lg bg-amber-500 px-3 py-2 text-xs font-medium text-white hover:bg-amber-600 disabled:opacity-40 transition-colors"
              >
                Suspender
              </button>
              <button
                onClick={() => {
                  if (window.confirm('Tem certeza que deseja bloquear esta conta?')) {
                    onStatusChange('blocked', blockReason || undefined);
                  }
                }}
                disabled={customer.status === 'blocked' || statusLoading}
                className="flex-1 rounded-lg bg-red-500 px-3 py-2 text-xs font-medium text-white hover:bg-red-600 disabled:opacity-40 transition-colors"
              >
                Bloquear
              </button>
            </div>

            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
              <div className="mb-2 flex items-center justify-between">
                <p className="text-xs font-medium text-gray-700">Saques da Empresa</p>
                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${customer.withdrawal_enabled !== false ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>
                  {customer.withdrawal_enabled !== false ? 'Habilitado' : 'Desabilitado'}
                </span>
              </div>
              <button
                onClick={() => onWithdrawalAccessChange(customer.withdrawal_enabled === false)}
                disabled={withdrawalAccessLoading}
                className={`w-full rounded-lg px-3 py-2 text-xs font-medium text-white transition-colors disabled:opacity-40 ${customer.withdrawal_enabled !== false ? 'bg-red-500 hover:bg-red-600' : 'bg-emerald-500 hover:bg-emerald-600'}`}
              >
                {customer.withdrawal_enabled !== false ? 'Desabilitar Saques' : 'Habilitar Saques'}
              </button>
            </div>
          </div>
        </div>

        {/* Activity Timeline */}
        {customer.logs && customer.logs.length > 0 && (
          <div className="rounded-lg bg-white p-5 shadow-sm">
            <h3 className="text-sm font-semibold text-gray-900 mb-4">Histórico Rápido</h3>
            <div className="space-y-3">
              {customer.logs.map((log, i) => (
                <div key={i} className="flex items-start gap-3">
                  <div className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-green-500" />
                  <div>
                    <p className="text-xs font-medium text-gray-900">{log.event}</p>
                    <p className="text-xs text-gray-500">{log.date ? formatDate(log.date) : '-'}{log.ip ? ` · IP: ${log.ip}` : ''}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Charges Tab ─────────────────────────────────────────────────────────────

function ChargesTab({
  customerId,
  charges,
  pagination,
  isLoading,
  page,
  setPage,
  statusFilter,
  setStatusFilter,
  searchQuery,
  setSearchQuery,
  getStatusBadge,
  onChargeStatusChanged,
}: {
  customerId: string;
  charges: Charge[];
  pagination?: { current_page: number; last_page: number; total: number };
  isLoading: boolean;
  page: number;
  setPage: (p: number) => void;
  statusFilter: string;
  setStatusFilter: (s: string) => void;
  searchQuery: string;
  setSearchQuery: (s: string) => void;
  getStatusBadge: (status: string) => JSX.Element;
  onChargeStatusChanged: () => void;
}) {
  const [selectedCharge, setSelectedCharge] = useState<Charge | null>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [targetStatus, setTargetStatus] = useState('');
  const [changeReason, setChangeReason] = useState('');
  const [mfaCode, setMfaCode] = useState('');

  const manualStatusOptions = [
    { value: 'processing', label: 'Processando' },
    { value: 'approved', label: 'Aprovado' },
    { value: 'refunded', label: 'Estornado' },
  ];

  const resolveInitialTargetStatus = (status: string) => {
    return manualStatusOptions.some((option) => option.value === status) ? status : 'processing';
  };

  const openDetailModal = (charge: Charge) => {
    setSelectedCharge(charge);
    setTargetStatus(resolveInitialTargetStatus(charge.status || 'processing'));
    setChangeReason('');
    setMfaCode('');
    setShowDetailModal(true);
  };

  const changeStatusMutation = useMutation({
    mutationFn: async () => {
      if (!selectedCharge) return;

      const response = await api.post(`/admin/customers/${customerId}/charges/${selectedCharge.id}/status`, {
        status: targetStatus,
        reason: changeReason,
        mfa_code: mfaCode,
      });

      return response.data;
    },
    onSuccess: () => {
      toast.success('Status alterado com sucesso e registrado em auditoria.');
      setSelectedCharge((prev) => {
        if (!prev) return prev;
        const next: Charge = { ...prev, status: targetStatus };
        if (targetStatus === 'approved' && !prev.paid_at) {
          next.paid_at = new Date().toISOString();
        }
        if (targetStatus === 'refunded' && !prev.refunded_at) {
          next.refunded_at = new Date().toISOString();
        }
        return next;
      });
      setChangeReason('');
      setMfaCode('');
      onChargeStatusChanged();
    },
    onError: (error: any) => {
      const message =
        error?.response?.data?.message ||
        error?.response?.data?.error?.message ||
        'Erro ao alterar status da transação.';
      toast.error(message);
    },
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
          <input
            type="text"
            placeholder="Buscar por ID, nome, email..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full rounded-lg border border-gray-200 bg-gray-50 pl-9 pr-4 py-2 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
        >
          <option value="all">Todos os status</option>
          <option value="approved">Aprovados</option>
          <option value="pending">Pendentes</option>
          <option value="refunded">Reembolsados</option>
          <option value="failed">Falhos</option>
        </select>
        {pagination && <p className="text-xs text-gray-500">{pagination.total} transações</p>}
      </div>

      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : charges.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <BanknotesIcon className="h-10 w-10 text-gray-500" />
            <p className="mt-3 text-sm text-gray-500">Nenhuma transação encontrada</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50">
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Produto</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Cliente</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Valor</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Taxa</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Líquido</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Método</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Data</th>
                    <th className="px-4 py-3 text-center text-xs font-semibold uppercase text-gray-500">Ações</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {charges.map((charge) => (
                    <tr key={charge.id} className="hover:bg-gray-100 transition-colors">
                      <td className="px-4 py-3 text-sm text-gray-900 max-w-[180px] truncate">{charge.product_name || '-'}</td>
                      <td className="px-4 py-3">
                        <p className="text-sm text-gray-900 truncate max-w-[140px]">{charge.customer_name || '-'}</p>
                        <p className="text-xs text-gray-500 truncate max-w-[140px]">{charge.customer_email || ''}</p>
                      </td>
                      <td className="px-4 py-3 text-right text-sm font-medium text-gray-900">{formatCurrency(charge.amount)}</td>
                      <td className="px-4 py-3 text-right text-sm text-gray-500">{formatCurrency(charge.fee_amount)}</td>
                      <td className="px-4 py-3 text-right text-sm font-medium text-emerald-700">{formatCurrency(charge.net_amount)}</td>
                      <td className="px-4 py-3 text-sm text-gray-900 uppercase">{charge.payment_method || '-'}</td>
                      <td className="px-4 py-3">{getStatusBadge(charge.status)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{formatDate(charge.created_at)}</td>
                      <td className="px-4 py-3 text-center">
                        <button
                          onClick={() => openDetailModal(charge)}
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
            <Pagination pagination={pagination} page={page} setPage={setPage} />
          </>
        )}
      </div>

      {/* Modal de Detalhes da Transação */}
      {showDetailModal && selectedCharge && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="relative w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-lg bg-white shadow-xl">
            <div className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
              <h3 className="text-lg font-semibold text-gray-900">Detalhes da Transação</h3>
              <button
                onClick={() => setShowDetailModal(false)}
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 transition-colors"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="p-6 space-y-6">
              {/* Informações Gerais */}
              <div>
                <h4 className="text-sm font-semibold text-gray-900 mb-3">Informações Gerais</h4>
                <div className="grid grid-cols-2 gap-4">
                  <InfoRow label="ID da Transação" value={selectedCharge.id} mono />
                  <InfoRow label="Status" value={selectedCharge.status} badge />
                  <InfoRow label="Método de Pagamento" value={selectedCharge.payment_method?.toUpperCase()} />
                  <InfoRow label="Produto" value={selectedCharge.product_name} />
                  <InfoRow label="Criado em" value={formatDate(selectedCharge.created_at)} />
                  {selectedCharge.paid_at && <InfoRow label="Pago em" value={formatDate(selectedCharge.paid_at)} />}
                  {selectedCharge.expires_at && <InfoRow label="Expira em" value={formatDate(selectedCharge.expires_at)} />}
                </div>
              </div>

              {/* Valores */}
              <div>
                <h4 className="text-sm font-semibold text-gray-900 mb-3">Valores</h4>
                <div className="grid grid-cols-3 gap-4">
                  <div className="rounded-lg bg-gray-50 p-4">
                    <p className="text-xs text-gray-500 mb-1">Valor Total</p>
                    <p className="text-lg font-semibold text-gray-900">{formatCurrency(selectedCharge.amount)}</p>
                  </div>
                  <div className="rounded-lg bg-gray-50 p-4">
                    <p className="text-xs text-gray-500 mb-1">Taxa</p>
                    <p className="text-lg font-semibold text-orange-500">{formatCurrency(selectedCharge.fee_amount)}</p>
                  </div>
                  <div className="rounded-lg bg-gray-50 p-4">
                    <p className="text-xs text-gray-500 mb-1">Valor Líquido</p>
                    <p className="text-lg font-semibold text-emerald-700">{formatCurrency(selectedCharge.net_amount)}</p>
                  </div>
                </div>
              </div>

              {/* Informações do Cliente */}
              <div>
                <h4 className="text-sm font-semibold text-gray-900 mb-3">Informações do Cliente</h4>
                <div className="grid grid-cols-2 gap-4">
                  <InfoRow label="Nome" value={selectedCharge.customer_name} />
                  <InfoRow label="Email" value={selectedCharge.customer_email} />
                  {selectedCharge.customer_document && <InfoRow label="Documento" value={selectedCharge.customer_document} />}
                  {selectedCharge.customer_phone && <InfoRow label="Telefone" value={selectedCharge.customer_phone} />}
                </div>
              </div>

              {/* Informações do Adquirente */}
              {selectedCharge.acquirer_name && (
                <div>
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">Adquirente</h4>
                  <div className="grid grid-cols-2 gap-4">
                    <InfoRow label="Nome do Adquirente" value={selectedCharge.acquirer_name} />
                    {selectedCharge.acquirer_transaction_id && (
                      <InfoRow label="ID da Transação no Adquirente" value={selectedCharge.acquirer_transaction_id} mono />
                    )}
                  </div>
                </div>
              )}

              {/* Detalhes do Cartão */}
              {selectedCharge.payment_method === 'credit_card' && (
                <div>
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">Detalhes do Cartão</h4>
                  <div className="grid grid-cols-2 gap-4">
                    {selectedCharge.card_brand && <InfoRow label="Bandeira" value={selectedCharge.card_brand.toUpperCase()} />}
                    {selectedCharge.card_last_digits && <InfoRow label="Últimos Dígitos" value={`**** ${selectedCharge.card_last_digits}`} />}
                    {selectedCharge.installments && <InfoRow label="Parcelas" value={`${selectedCharge.installments}x`} />}
                  </div>
                </div>
              )}

              {/* QR Code PIX */}
              {selectedCharge.payment_method === 'pix' && (selectedCharge.pix_qr_code_url || selectedCharge.pix_emv) && (
                <div>
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">QR Code PIX</h4>
                  <div className="space-y-4">
                    {selectedCharge.pix_qr_code_url && (
                      <div className="flex justify-center">
                        <img 
                          src={selectedCharge.pix_qr_code_url} 
                          alt="QR Code PIX" 
                          className="w-64 h-64 rounded-lg border border-gray-200"
                        />
                      </div>
                    )}
                    {selectedCharge.pix_emv && (
                      <div>
                        <label className="text-xs font-medium text-gray-500 mb-2 block">Código PIX Copia e Cola</label>
                        <div className="flex items-center gap-2">
                          <input
                            type="text"
                            value={selectedCharge.pix_emv}
                            readOnly
                            className="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-mono text-gray-900"
                          />
                          <button
                            onClick={() => {
                              navigator.clipboard.writeText(selectedCharge.pix_emv!);
                              toast.success('Código PIX copiado!');
                            }}
                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition-colors"
                          >
                            Copiar
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Boleto */}
              {selectedCharge.payment_method === 'boleto' && (selectedCharge.boleto_barcode || selectedCharge.boleto_url) && (
                <div>
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">Boleto</h4>
                  <div className="space-y-4">
                    {selectedCharge.boleto_barcode && (
                      <div>
                        <label className="text-xs font-medium text-gray-500 mb-2 block">Código de Barras</label>
                        <div className="flex items-center gap-2">
                          <input
                            type="text"
                            value={selectedCharge.boleto_barcode}
                            readOnly
                            className="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-mono text-gray-900"
                          />
                          <button
                            onClick={() => {
                              navigator.clipboard.writeText(selectedCharge.boleto_barcode!);
                              toast.success('Código de barras copiado!');
                            }}
                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition-colors"
                          >
                            Copiar
                          </button>
                        </div>
                      </div>
                    )}
                    {selectedCharge.boleto_url && (
                      <a
                        href={selectedCharge.boleto_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 transition-colors"
                      >
                        <DocumentTextIcon className="h-4 w-4" />
                        Visualizar Boleto
                      </a>
                    )}
                  </div>
                </div>
              )}

              <div className="rounded-lg border border-red-200 bg-red-50 p-4">
                <h4 className="text-sm font-semibold text-red-700">Alterar Status Manualmente</h4>
                <p className="mt-1 text-xs text-red-700">
                  Ação crítica: exige código 2FA e motivo obrigatório. Aprovado liquida saldo, Estornado reverte saldo e toda alteração gera alerta e log de auditoria.
                </p>

                <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-500">Novo status</label>
                    <select
                      value={targetStatus}
                      onChange={(e) => setTargetStatus(e.target.value)}
                      className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                    >
                      {manualStatusOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="mb-1 block text-xs font-medium text-gray-500">Código 2FA</label>
                    <input
                      type="text"
                      inputMode="numeric"
                      maxLength={6}
                      value={mfaCode}
                      onChange={(e) => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                      placeholder="000000"
                      className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
                    />
                  </div>
                </div>

                <div className="mt-3">
                  <label className="mb-1 block text-xs font-medium text-gray-500">Motivo da alteração</label>
                  <textarea
                    rows={3}
                    value={changeReason}
                    onChange={(e) => setChangeReason(e.target.value)}
                    placeholder="Descreva o motivo operacional/compliance para a troca de status..."
                    className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
                  />
                </div>

                <button
                  onClick={() => changeStatusMutation.mutate()}
                  disabled={
                    changeStatusMutation.isPending ||
                    !selectedCharge ||
                    targetStatus === selectedCharge.status ||
                    changeReason.trim().length < 8 ||
                    mfaCode.length !== 6
                  }
                  className="mt-4 inline-flex items-center justify-center rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {changeStatusMutation.isPending ? 'Alterando...' : 'Confirmar alteração de status'}
                </button>
              </div>
            </div>

            <div className="sticky bottom-0 border-t border-gray-200 bg-white px-6 py-4">
              <button
                onClick={() => setShowDetailModal(false)}
                className="w-full rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50 transition-colors"
              >
                Fechar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Withdrawals Tab ─────────────────────────────────────────────────────────

function WithdrawalsTab({
  customerId, withdrawals, pagination, isLoading, page, setPage, getStatusBadge,
}: {
  customerId: string;
  withdrawals: WithdrawalItem[];
  pagination?: { current_page: number; last_page: number; total: number };
  isLoading: boolean;
  page: number;
  setPage: (p: number) => void;
  getStatusBadge: (status: string) => JSX.Element;
}) {
  const queryClient = useQueryClient();
  const [detailWithdrawal, setDetailWithdrawal] = useState<WithdrawalItem | null>(null);
  const [withdrawalStatusDraft, setWithdrawalStatusDraft] = useState('');
  const [withdrawalStatusNote, setWithdrawalStatusNote] = useState('');
  const [selectedWithdrawal, setSelectedWithdrawal] = useState<WithdrawalItem | null>(null);
  const [cancelReason, setCancelReason] = useState('');

  const copyValue = (text?: string | null) => {
    if (!text) return;
    navigator.clipboard.writeText(text);
    toast.success('Copiado!');
  };

  const destinationFieldLabels: Record<string, string> = {
    type: 'Tipo de destino',
    pix_key: 'Chave PIX',
    pix_type: 'Tipo da chave PIX',
    holder_name: 'Titular',
    holder_document: 'Documento do titular',
    bank_name: 'Banco',
    bank_code: 'Código do banco',
    agency: 'Agência',
    account_number: 'Conta',
    account_type: 'Tipo de conta',
  };

  const getDestinationSummary = (withdrawal: WithdrawalItem) =>
    withdrawal.destination?.pix_key || withdrawal.destination?.bank_name || '-';

  const getDestinationEntries = (withdrawal: WithdrawalItem) =>
    Object.entries(withdrawal.destination ?? {}).filter(([, value]) => Boolean(value));

  const formatDestinationValue = (key: string, value: string) => {
    if (['pix_type', 'account_type', 'type'].includes(key)) {
      return String(value).toUpperCase();
    }

    return value;
  };

  const cancelWithdrawalMutation = useMutation({
    mutationFn: async ({ withdrawalId, reason }: { withdrawalId: string; reason: string }) => {
      const response = await api.post(`/admin/withdrawals/${withdrawalId}/cancel`, {
        reason,
      });
      return response.data;
    },
    onSuccess: (payload) => {
      toast.success(payload?.message || 'Saque cancelado com sucesso.');
      queryClient.invalidateQueries({ queryKey: ['admin-customer-withdrawals', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-logs', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-withdrawals'] });
      setSelectedWithdrawal(null);
      setCancelReason('');
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || error?.response?.data?.error?.message || 'Erro ao cancelar saque';
      toast.error(message);
    },
  });

  const updateWithdrawalStatusMutation = useMutation({
    mutationFn: async ({
      withdrawalId,
      status,
      notes,
    }: {
      withdrawalId: string;
      status: string;
      notes: string;
    }) => {
      const response = await api.put(`/admin/withdrawals/${withdrawalId}`, {
        status,
        notes: notes.trim() || undefined,
      });
      return response.data;
    },
    onSuccess: (payload) => {
      toast.success(payload?.message || 'Status do saque atualizado.');
      const updated = payload?.data as WithdrawalItem | undefined;
      if (updated) {
        setDetailWithdrawal(updated);
        setWithdrawalStatusDraft(updated.status);
        setWithdrawalStatusNote('');
      }
      queryClient.invalidateQueries({ queryKey: ['admin-customer-withdrawals', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-detail', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-customer-logs', customerId] });
      queryClient.invalidateQueries({ queryKey: ['admin-withdrawals'] });
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || error?.response?.data?.error?.message || 'Erro ao alterar status do saque';
      toast.error(message);
    },
  });

  const closeCancelModal = () => {
    if (cancelWithdrawalMutation.isPending) return;
    setSelectedWithdrawal(null);
    setCancelReason('');
  };

  const closeDetailModal = () => {
    if (updateWithdrawalStatusMutation.isPending) return;
    setDetailWithdrawal(null);
    setWithdrawalStatusDraft('');
    setWithdrawalStatusNote('');
  };

  const getWithdrawalReason = (withdrawal: WithdrawalItem) => withdrawal.failure_reason || withdrawal.notes;

  return (
    <>
    <div className="space-y-4">
      {pagination && <p className="text-xs text-gray-500">{pagination.total} saques</p>}

      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : withdrawals.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <BoltIcon className="h-10 w-10 text-gray-500" />
            <p className="mt-3 text-sm text-gray-500">Nenhum saque encontrado</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50">
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Valor</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Taxa</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Líquido</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Método</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Destino</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Data</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Processado</th>
                    <th className="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">Ações</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {withdrawals.map((w) => (
                    <tr key={w.id} className="hover:bg-gray-100 transition-colors">
                      <td className="px-4 py-3 text-right text-sm font-medium text-gray-900">{formatCurrency(w.amount)}</td>
                      <td className="px-4 py-3 text-right text-sm text-gray-500">{w.fee ? formatCurrency(w.fee) : '-'}</td>
                      <td className="px-4 py-3 text-right text-sm font-medium text-emerald-700">{w.net_amount ? formatCurrency(w.net_amount) : '-'}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-1">
                          {w.is_instant && <BoltIcon className="h-3.5 w-3.5 text-amber-700" />}
                          <span className="text-sm text-gray-900 uppercase">{w.method || 'pix'}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="space-y-1.5">
                          {getStatusBadge(w.status)}
                          {getWithdrawalReason(w) && (
                            <p className="max-w-[240px] text-xs leading-5 text-gray-500 whitespace-pre-line break-words">
                              Motivo: {getWithdrawalReason(w)}
                            </p>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 max-w-[200px]">
                        <div className="flex items-start justify-between gap-2">
                          <p className="truncate text-gray-900">{getDestinationSummary(w)}</p>
                          {getDestinationSummary(w) !== '-' && (
                            <button
                              type="button"
                              onClick={() => copyValue(getDestinationSummary(w))}
                              className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                              title="Copiar destino"
                            >
                              <ClipboardDocumentIcon className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{formatDate(w.created_at)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{w.processed_at ? formatDate(w.processed_at) : '-'}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => {
                              setDetailWithdrawal(w);
                              setWithdrawalStatusDraft(w.status);
                              setWithdrawalStatusNote('');
                            }}
                            className="rounded-lg border border-gray-200 p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                            title="Ver detalhes do saque"
                          >
                            <EyeIcon className="h-4 w-4" />
                          </button>

                          {['pending', 'processing'].includes(w.status) ? (
                            <button
                              onClick={() => {
                                setSelectedWithdrawal(w);
                                setCancelReason(getWithdrawalReason(w) || '');
                              }}
                              className="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-600 transition-colors"
                              title={w.status === 'processing' ? 'Tentar cancelar antes da confirmação final do gateway' : 'Cancelar saque pendente'}
                            >
                              Cancelar
                            </button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination pagination={pagination} page={page} setPage={setPage} />
          </>
        )}
      </div>
    </div>

    {detailWithdrawal && (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={closeDetailModal}>
        <div className="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
          <div className="mb-6 flex items-start justify-between gap-4">
            <div>
              <h3 className="text-lg font-bold text-gray-900">Detalhes do saque</h3>
              <p className="mt-1 text-sm text-gray-500">Visualize todas as informações disponíveis deste saque.</p>
            </div>
            <button
              onClick={closeDetailModal}
              className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
            >
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>

          <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs uppercase tracking-wide text-gray-500">Resumo financeiro</p>
                <div className="mt-3 space-y-2 text-sm text-gray-900">
                  <p><span className="text-gray-500">Valor:</span> {formatCurrency(detailWithdrawal.amount)}</p>
                  <p><span className="text-gray-500">Taxa:</span> {detailWithdrawal.fee ? formatCurrency(detailWithdrawal.fee) : '-'}</p>
                  <p><span className="text-gray-500">Líquido:</span> {detailWithdrawal.net_amount ? formatCurrency(detailWithdrawal.net_amount) : '-'}</p>
                  <p><span className="text-gray-500">Método:</span> {(detailWithdrawal.method || 'pix').toUpperCase()}</p>
                  <p><span className="text-gray-500">Status:</span> {getStatusBadge(detailWithdrawal.status)}</p>
                  <p><span className="text-gray-500">Instantâneo:</span> {detailWithdrawal.is_instant ? 'Sim' : 'Não'}</p>
                </div>
              </div>

              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs uppercase tracking-wide text-gray-500">Datas</p>
                <div className="mt-3 space-y-2 text-sm text-gray-900">
                  <p><span className="text-gray-500">Solicitado em:</span> {formatDate(detailWithdrawal.created_at)}</p>
                  <p><span className="text-gray-500">Processado em:</span> {detailWithdrawal.processed_at ? formatDate(detailWithdrawal.processed_at) : '-'}</p>
                </div>
              </div>
            </div>

            <div className="rounded-md bg-gray-50 p-4">
              <div className="mb-3 flex items-center justify-between gap-2">
                <p className="text-xs uppercase tracking-wide text-gray-500">Identificadores</p>
              </div>
              <div className="space-y-3 text-sm text-gray-900">
                <div>
                  <div className="mb-1 flex items-center justify-between gap-2">
                    <span className="text-gray-500">ID do saque</span>
                    <button
                      type="button"
                      onClick={() => copyValue(detailWithdrawal.id)}
                      className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                      title="Copiar ID do saque"
                    >
                      <ClipboardDocumentIcon className="h-4 w-4" />
                    </button>
                  </div>
                  <p className="break-all font-mono">{detailWithdrawal.id}</p>
                </div>

                {detailWithdrawal.acquirer && (
                  <p><span className="text-gray-500">Adquirente:</span> <span className="uppercase">{detailWithdrawal.acquirer}</span></p>
                )}

                {detailWithdrawal.acquirer_transfer_id && (
                  <div>
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <span className="text-gray-500">ID da transferência</span>
                      <button
                        type="button"
                        onClick={() => copyValue(detailWithdrawal.acquirer_transfer_id)}
                        className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                        title="Copiar ID da transferência"
                      >
                        <ClipboardDocumentIcon className="h-4 w-4" />
                      </button>
                    </div>
                    <p className="break-all font-mono">{detailWithdrawal.acquirer_transfer_id}</p>
                  </div>
                )}
              </div>
            </div>

            <div className="rounded-md bg-gray-50 p-4">
              <div className="mb-3 flex items-center justify-between gap-2">
                <p className="text-xs uppercase tracking-wide text-gray-500">Destino</p>
                {getDestinationSummary(detailWithdrawal) !== '-' && (
                  <button
                    type="button"
                    onClick={() => copyValue(getDestinationSummary(detailWithdrawal))}
                    className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                    title="Copiar destino"
                  >
                    <ClipboardDocumentIcon className="h-4 w-4" />
                  </button>
                )}
              </div>

              {getDestinationEntries(detailWithdrawal).length > 0 ? (
                <div className="grid gap-3 md:grid-cols-2">
                  {getDestinationEntries(detailWithdrawal).map(([key, value]) => (
                    <div key={key} className="rounded-lg border border-gray-200 bg-white p-3">
                      <p className="text-[11px] uppercase tracking-wide text-gray-500">{destinationFieldLabels[key] || key.replace(/_/g, ' ')}</p>
                      <p className="mt-1 break-all text-sm text-gray-900">{formatDestinationValue(key, value)}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-gray-500">Nenhuma informação de destino disponível.</p>
              )}
            </div>

            {getWithdrawalReason(detailWithdrawal) && (
              <div className="rounded-md bg-gray-50 p-4">
                <p className="text-xs uppercase tracking-wide text-gray-500">Motivo / observações</p>
                <p className="mt-2 whitespace-pre-line break-words text-sm text-gray-900">{getWithdrawalReason(detailWithdrawal)}</p>
              </div>
            )}

            {['pending_approval', 'pending', 'processing'].includes(detailWithdrawal.status) && (
              <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                <p className="text-xs uppercase tracking-wide text-amber-700">Alterar status do saque</p>
                <div className="mt-3 grid gap-3 md:grid-cols-[220px_1fr]">
                  <div>
                    <label className="mb-1 block text-xs font-medium text-amber-800">Novo status</label>
                    <select
                      value={withdrawalStatusDraft || detailWithdrawal.status}
                      onChange={(event) => setWithdrawalStatusDraft(event.target.value)}
                      className="w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-amber-400 focus:outline-none"
                    >
                      <option value="processing">Processando</option>
                      <option value="completed">Pago / concluido</option>
                      <option value="failed">Falhou</option>
                      <option value="cancelled">Cancelado</option>
                    </select>
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-medium text-amber-800">Motivo / observacao</label>
                    <input
                      type="text"
                      value={withdrawalStatusNote}
                      onChange={(event) => setWithdrawalStatusNote(event.target.value)}
                      placeholder="Ex: pago manualmente na adquirente"
                      className="w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-500 focus:border-amber-400 focus:outline-none"
                    />
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => updateWithdrawalStatusMutation.mutate({
                    withdrawalId: detailWithdrawal.id,
                    status: withdrawalStatusDraft || detailWithdrawal.status,
                    notes: withdrawalStatusNote,
                  })}
                  disabled={
                    updateWithdrawalStatusMutation.isPending ||
                    !withdrawalStatusDraft ||
                    withdrawalStatusDraft === detailWithdrawal.status
                  }
                  className="mt-3 inline-flex items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {updateWithdrawalStatusMutation.isPending ? 'Alterando...' : 'Salvar novo status'}
                </button>
              </div>
            )}

            <div className="flex justify-end pt-2">
              <button
                onClick={closeDetailModal}
                className="rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
              >
                Fechar
              </button>
            </div>
          </div>
        </div>
      </div>
    )}

    {selectedWithdrawal && (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={closeCancelModal}>
        <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()}>
          <div className="mb-6 flex items-center justify-between">
            <div>
              <h3 className="text-lg font-bold text-gray-900">{selectedWithdrawal.status === 'processing' ? 'Cancelar saque em processamento' : 'Cancelar saque'}</h3>
              <p className="mt-1 text-sm text-gray-500">Informe o motivo que ficará visível para o vendedor na área de saques.</p>
              {selectedWithdrawal.status === 'processing' && (
                <div className="mt-2 space-y-1 text-xs text-amber-600">
                  <p>
                    {selectedWithdrawal.acquirer === 'payfort'
                      ? 'O PayFort não expõe cancelamento de PIX OUT via API pública. Se a transferência já saiu para o gateway, será necessário cancelar no painel do adquirente.'
                      : 'Se o saque já tiver sido consolidado no gateway, o cancelamento automático será bloqueado.'}
                  </p>
                  {selectedWithdrawal.acquirer && (
                    <p>Adquirente: <span className="font-semibold uppercase">{selectedWithdrawal.acquirer}</span></p>
                  )}
                  {selectedWithdrawal.acquirer_transfer_id && (
                    <p className="break-all">Transferência no adquirente: {selectedWithdrawal.acquirer_transfer_id}</p>
                  )}
                </div>
              )}
            </div>
            <button
              onClick={closeCancelModal}
              className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
            >
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>

          <div className="space-y-4">
            <div className="rounded-md bg-gray-50 p-4">
              <p className="text-xs text-gray-500">Saque selecionado</p>
              <p className="mt-1 text-base font-semibold text-gray-900">{formatCurrency(selectedWithdrawal.amount)}</p>
              <p className="mt-1 text-xs text-gray-500">Solicitado em {formatDate(selectedWithdrawal.created_at)}</p>
              <div className="mt-4 space-y-3 text-xs text-gray-500">
                <div>
                  <div className="mb-1 flex items-center justify-between gap-2">
                    <span className="uppercase tracking-wide">ID do saque</span>
                    <button
                      type="button"
                      onClick={() => copyValue(selectedWithdrawal.id)}
                      className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                      title="Copiar ID do saque"
                    >
                      <ClipboardDocumentIcon className="h-4 w-4" />
                    </button>
                  </div>
                  <p className="break-all font-mono text-gray-900">{selectedWithdrawal.id}</p>
                </div>

                {selectedWithdrawal.acquirer && (
                  <div>
                    <p className="uppercase tracking-wide">Adquirente</p>
                    <p className="mt-1 text-gray-900 uppercase">{selectedWithdrawal.acquirer}</p>
                  </div>
                )}

                {selectedWithdrawal.acquirer_transfer_id && (
                  <div>
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <span className="uppercase tracking-wide">ID da transferência</span>
                      <button
                        type="button"
                        onClick={() => copyValue(selectedWithdrawal.acquirer_transfer_id)}
                        className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                        title="Copiar ID da transferência"
                      >
                        <ClipboardDocumentIcon className="h-4 w-4" />
                      </button>
                    </div>
                    <p className="break-all font-mono text-gray-900">{selectedWithdrawal.acquirer_transfer_id}</p>
                  </div>
                )}

                {(selectedWithdrawal.destination?.pix_key || selectedWithdrawal.destination?.bank_name) && (
                  <div>
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <span className="uppercase tracking-wide">Destino</span>
                      <button
                        type="button"
                        onClick={() => copyValue(selectedWithdrawal.destination?.pix_key || selectedWithdrawal.destination?.bank_name)}
                        className="rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900"
                        title="Copiar destino"
                      >
                        <ClipboardDocumentIcon className="h-4 w-4" />
                      </button>
                    </div>
                    <p className="break-all text-gray-900">{selectedWithdrawal.destination?.pix_key || selectedWithdrawal.destination?.bank_name}</p>
                    {selectedWithdrawal.destination?.pix_type && <p className="mt-1">Tipo PIX: {String(selectedWithdrawal.destination.pix_type).toUpperCase()}</p>}
                    {selectedWithdrawal.destination?.holder_name && <p className="mt-1">Titular: {selectedWithdrawal.destination.holder_name}</p>}
                    {selectedWithdrawal.destination?.holder_document && <p className="mt-1">Documento: {selectedWithdrawal.destination.holder_document}</p>}
                  </div>
                )}
              </div>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-gray-600">Motivo do cancelamento</label>
              <textarea
                value={cancelReason}
                onChange={(event) => setCancelReason(event.target.value)}
                rows={4}
                placeholder="Explique ao vendedor por que o saque foi cancelado"
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-900 placeholder:text-gray-500 focus:border-gray-400 focus:outline-none"
              />
              <p className="mt-2 text-xs text-gray-500">Mínimo de 5 caracteres.</p>
            </div>

            <div className="flex gap-3 pt-2">
              <button
                onClick={closeCancelModal}
                className="flex-1 rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
              >
                Fechar
              </button>
              <button
                onClick={() => cancelWithdrawalMutation.mutate({
                  withdrawalId: selectedWithdrawal.id,
                  reason: cancelReason.trim(),
                })}
                disabled={cancelWithdrawalMutation.isPending || cancelReason.trim().length < 5}
                className="flex-1 rounded-lg bg-red-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50 transition-colors"
              >
                {cancelWithdrawalMutation.isPending ? 'Cancelando...' : 'Confirmar cancelamento'}
              </button>
            </div>
          </div>
        </div>
      </div>
    )}
    </>
  );
}

// ─── Logs Tab ────────────────────────────────────────────────────────────────

function LogsTab({
  logs, pagination, isLoading, page, setPage, activityLogs,
}: {
  logs: AuditLogItem[];
  pagination?: { current_page: number; last_page: number; total: number };
  isLoading: boolean;
  page: number;
  setPage: (p: number) => void;
  activityLogs: Array<{ event: string; date?: string; ip?: string }>;
}) {
  const [selectedLog, setSelectedLog] = useState<AuditLogItem | null>(null);
  const [riskFilter, setRiskFilter] = useState<'all' | 'low' | 'medium' | 'high'>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | '2xx' | '4xx' | '5xx'>('all');
  const [endpointFilter, setEndpointFilter] = useState('');
  const [searchFilter, setSearchFilter] = useState('');

  const formatDuration = (durationMs?: number) => {
    if (durationMs === null || durationMs === undefined) return '-';
    if (durationMs < 1000) return `${durationMs.toFixed(0)} ms`;
    return `${(durationMs / 1000).toFixed(2)} s`;
  };

  const getRiskBadge = (risk?: string) => {
    if (risk === 'high') {
      return <span className="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Alto</span>;
    }

    if (risk === 'medium') {
      return <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Médio</span>;
    }

    return <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Baixo</span>;
  };

  const filteredLogs = useMemo(() => {
    const endpointNeedle = endpointFilter.trim().toLowerCase();
    const searchNeedle = searchFilter.trim().toLowerCase();

    return logs.filter((log) => {
      if (riskFilter !== 'all' && (log.risk_level || 'low') !== riskFilter) {
        return false;
      }

      if (statusFilter !== 'all') {
        const statusCode = log.response_status;
        if (!statusCode) return false;
        if (statusFilter === '2xx' && (statusCode < 200 || statusCode >= 300)) return false;
        if (statusFilter === '4xx' && (statusCode < 400 || statusCode >= 500)) return false;
        if (statusFilter === '5xx' && (statusCode < 500 || statusCode >= 600)) return false;
      }

      if (endpointNeedle && !(log.endpoint || '').toLowerCase().includes(endpointNeedle)) {
        return false;
      }

      if (searchNeedle) {
        const securityFlagsText = Array.isArray(log.security_flags) ? log.security_flags.join(' ') : '';
        const haystack = [
          log.action,
          log.entity_type,
          log.entity_id,
          log.source,
          log.ip_address,
          log.endpoint,
          log.method,
          log.correlation_id,
          securityFlagsText,
        ]
          .filter(Boolean)
          .join(' ')
          .toLowerCase();

        if (!haystack.includes(searchNeedle)) {
          return false;
        }
      }

      return true;
    });
  }, [logs, riskFilter, statusFilter, endpointFilter, searchFilter]);

  const exportRows = useMemo(() => {
    return filteredLogs.map((log) => ({
      id: log.id,
      source: log.source || 'audit_log',
      action: log.action,
      entity_type: log.entity_type || '',
      entity_id: log.entity_id || '',
      method: log.method || '',
      endpoint: log.endpoint || '',
      response_status: log.response_status ?? '',
      risk_level: log.risk_level || 'low',
      ip_address: log.ip_address || '',
      entered_at: log.entered_at || log.created_at,
      exited_at: log.exited_at || log.created_at,
      duration_ms: log.duration_ms ?? '',
      correlation_id: log.correlation_id || '',
      security_flags: JSON.stringify(log.security_flags || []),
      metadata: JSON.stringify(log.metadata || {}),
      request_body: JSON.stringify(log.request_body ?? null),
      old_values: JSON.stringify(log.old_values || {}),
      new_values: JSON.stringify(log.new_values || {}),
    }));
  }, [filteredLogs]);

  const downloadFile = (filename: string, content: string, contentType: string) => {
    const blob = new Blob([content], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.URL.revokeObjectURL(url);
  };

  const exportJson = () => {
    const payload = JSON.stringify(exportRows, null, 2);
    downloadFile(`customer-audit-logs-page-${page}.json`, payload, 'application/json;charset=utf-8');
  };

  const exportCsv = () => {
    if (exportRows.length === 0) return;
    const headers = Object.keys(exportRows[0]);
    const csvEscape = (value: unknown) => {
      const text = String(value ?? '');
      const escaped = text.replace(/"/g, '""');
      return `"${escaped}"`;
    };
    const lines = [
      headers.join(','),
      ...exportRows.map((row) => headers.map((key) => csvEscape((row as Record<string, unknown>)[key])).join(',')),
    ];
    downloadFile(`customer-audit-logs-page-${page}.csv`, lines.join('\n'), 'text/csv;charset=utf-8');
  };

  const clearFilters = () => {
    setRiskFilter('all');
    setStatusFilter('all');
    setEndpointFilter('');
    setSearchFilter('');
  };

  return (
    <div className="space-y-6">
      {/* Activity timeline */}
      {activityLogs.length > 0 && (
        <div className="rounded-lg bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-gray-900 mb-4">Timeline de Eventos</h3>
          <div className="space-y-3">
            {activityLogs.map((log, i) => (
              <div key={i} className="flex items-start gap-3">
                <div className="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-green-500" />
                <div>
                  <p className="text-xs font-medium text-gray-900">{log.event}</p>
                  <p className="text-xs text-gray-500">{log.date ? formatDate(log.date) : '-'}{log.ip ? ` · IP: ${log.ip}` : ''}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Audit Logs Table */}
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <div className="px-5 py-4 border-b border-gray-200">
          <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 className="text-sm font-semibold text-gray-900">Audit Logs</h3>
                {pagination && <p className="text-xs text-gray-500 mt-0.5">{filteredLogs.length} visíveis nesta página ({pagination.total} no total)</p>}
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <button
                  onClick={exportCsv}
                  disabled={filteredLogs.length === 0}
                  className="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-900 hover:bg-border-DEFAULT disabled:opacity-50"
                >
                  Exportar CSV
                </button>
                <button
                  onClick={exportJson}
                  disabled={filteredLogs.length === 0}
                  className="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-900 hover:bg-border-DEFAULT disabled:opacity-50"
                >
                  Exportar JSON
                </button>
              </div>
            </div>

            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
              <input
                value={searchFilter}
                onChange={(e) => setSearchFilter(e.target.value)}
                placeholder="Buscar ação, IP, correlation..."
                className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
              />
              <input
                value={endpointFilter}
                onChange={(e) => setEndpointFilter(e.target.value)}
                placeholder="Filtrar endpoint"
                className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
              />
              <select
                value={riskFilter}
                onChange={(e) => setRiskFilter(e.target.value as 'all' | 'low' | 'medium' | 'high')}
                className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
              >
                <option value="all">Risco: Todos</option>
                <option value="low">Risco: Baixo</option>
                <option value="medium">Risco: Médio</option>
                <option value="high">Risco: Alto</option>
              </select>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value as 'all' | '2xx' | '4xx' | '5xx')}
                className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
              >
                <option value="all">Status HTTP: Todos</option>
                <option value="2xx">Status HTTP: 2xx</option>
                <option value="4xx">Status HTTP: 4xx</option>
                <option value="5xx">Status HTTP: 5xx</option>
              </select>
              <button
                onClick={clearFilters}
                className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-medium text-gray-900 hover:bg-gray-100"
              >
                Limpar filtros
              </button>
            </div>
          </div>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : logs.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <DocumentTextIcon className="h-10 w-10 text-gray-500" />
            <p className="mt-3 text-sm text-gray-500">Nenhum log encontrado</p>
          </div>
        ) : filteredLogs.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16">
            <DocumentTextIcon className="h-10 w-10 text-gray-500" />
            <p className="mt-3 text-sm text-gray-500">Nenhum log encontrado para os filtros aplicados</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-gray-200 bg-gray-50">
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Ação</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Origem</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Endpoint</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">IP</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Entrada</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Saída</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Duração</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Risco</th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">Detalhes</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {filteredLogs.map((log) => (
                    <tr key={log.id} className="hover:bg-gray-100 transition-colors">
                      <td className="px-4 py-3">
                        <span className="inline-flex rounded bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">
                          {log.action}
                        </span>
                        <p className="mt-1 text-[11px] text-gray-500">
                          {log.entity_type}{log.entity_id ? ` #${log.entity_id.slice(0, 8)}` : ''}
                        </p>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500">{log.source || 'audit_log'}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 max-w-[220px] truncate" title={log.endpoint || '-'}>
                        {log.method ? `${log.method} ` : ''}{log.endpoint || '-'}
                      </td>
                      <td className="px-4 py-3 text-xs font-mono text-gray-500">{log.ip_address || '-'}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{formatDate(log.entered_at || log.created_at)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{formatDate(log.exited_at || log.created_at)}</td>
                      <td className="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{formatDuration(log.duration_ms)}</td>
                      <td className="px-4 py-3">{getRiskBadge(log.risk_level)}</td>
                      <td className="px-4 py-3">
                        <button
                          onClick={() => setSelectedLog(log)}
                          className="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-900 hover:bg-border-DEFAULT"
                        >
                          Ver completo
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination pagination={pagination} page={page} setPage={setPage} />
          </>
        )}
      </div>

      {selectedLog && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setSelectedLog(null)}>
          <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-lg font-semibold text-gray-900">Detalhes completos do log</h3>
              <button onClick={() => setSelectedLog(null)} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100">
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <InfoRow label="Ação" value={selectedLog.action} />
              <InfoRow label="Origem" value={selectedLog.source || 'audit_log'} />
              <InfoRow label="Endpoint" value={selectedLog.endpoint || '-'} mono />
              <InfoRow label="Método" value={selectedLog.method || '-'} />
              <InfoRow label="Status HTTP" value={selectedLog.response_status ? String(selectedLog.response_status) : '-'} />
              <InfoRow label="Risco" value={selectedLog.risk_level || 'low'} />
              <InfoRow label="Entrada" value={selectedLog.entered_at ? formatDate(selectedLog.entered_at) : formatDate(selectedLog.created_at)} />
              <InfoRow label="Saída" value={selectedLog.exited_at ? formatDate(selectedLog.exited_at) : formatDate(selectedLog.created_at)} />
              <InfoRow label="Duração" value={formatDuration(selectedLog.duration_ms)} />
              <InfoRow label="IP" value={selectedLog.ip_address || '-'} mono />
              <InfoRow label="Correlation ID" value={selectedLog.correlation_id || '-'} mono />
              <InfoRow label="User Agent" value={selectedLog.user_agent || '-'} />
            </div>

            <div className="mt-4 space-y-3">
              <div className="rounded-lg bg-gray-50 p-3">
                <p className="mb-1 text-xs font-semibold text-gray-500">Security Flags</p>
                <pre className="overflow-x-auto text-xs text-gray-900">{JSON.stringify(selectedLog.security_flags || [], null, 2)}</pre>
              </div>

              <div className="rounded-lg bg-gray-50 p-3">
                <p className="mb-1 text-xs font-semibold text-gray-500">Metadata</p>
                <pre className="overflow-x-auto text-xs text-gray-900">{JSON.stringify(selectedLog.metadata || {}, null, 2)}</pre>
              </div>

              <div className="rounded-lg bg-gray-50 p-3">
                <p className="mb-1 text-xs font-semibold text-gray-500">New Values</p>
                <pre className="overflow-x-auto text-xs text-gray-900">{JSON.stringify(selectedLog.new_values || {}, null, 2)}</pre>
              </div>

              <div className="rounded-lg bg-gray-50 p-3">
                <p className="mb-1 text-xs font-semibold text-gray-500">Old Values</p>
                <pre className="overflow-x-auto text-xs text-gray-900">{JSON.stringify(selectedLog.old_values || {}, null, 2)}</pre>
              </div>

              <div className="rounded-lg bg-gray-50 p-3">
                <p className="mb-1 text-xs font-semibold text-gray-500">Request Body</p>
                <pre className="overflow-x-auto text-xs text-gray-900">{JSON.stringify(selectedLog.request_body || null, null, 2)}</pre>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Shared Components ───────────────────────────────────────────────────────

function InfoRow({ label, value, mono, badge }: { label: string; value?: string | null; mono?: boolean; badge?: boolean }) {
  if (!value) return null;
  return (
    <div className="flex items-center justify-between">
      <span className="text-xs text-gray-500">{label}</span>
      {badge ? (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
          value === 'approved' ? 'bg-emerald-50 text-emerald-700' :
          value === 'submitted' || value === 'under_review' ? 'bg-blue-50 text-blue-700' :
          value === 'pending' ? 'bg-amber-50 text-amber-700' :
          'bg-red-50 text-red-700'
        }`}>
          {value === 'approved' ? 'Aprovado' : value === 'submitted' ? 'Enviado' : value === 'under_review' ? 'Em Análise' : value === 'pending' ? 'Pendente' : 'Rejeitado'}
        </span>
      ) : (
        <span className={`text-xs font-medium text-gray-900 ${mono ? 'font-mono' : ''}`}>{value}</span>
      )}
    </div>
  );
}

function StatMini({ label, value, color }: { label: string; value: string; color?: string }) {
  const colorClass = color === 'emerald' ? 'text-emerald-700' : color === 'amber' ? 'text-amber-700' : color === 'purple' ? 'text-green-700' : 'text-gray-900';
  return (
    <div className="rounded-lg bg-gray-50 p-3">
      <p className="text-xs text-gray-500 mb-0.5">{label}</p>
      <p className={`text-lg font-bold ${colorClass}`}>{value}</p>
    </div>
  );
}

function Pagination({ pagination, page, setPage }: { pagination?: { current_page: number; last_page: number; total: number }; page: number; setPage: (p: number) => void }) {
  if (!pagination || pagination.last_page <= 1) return null;
  return (
    <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3">
      <p className="text-xs text-gray-500">
        Página {pagination.current_page} de {pagination.last_page} ({pagination.total})
      </p>
      <div className="flex items-center gap-1">
        <button onClick={() => setPage(page - 1)} disabled={page === 1} className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 disabled:opacity-40">
          <ChevronLeftIcon className="h-4 w-4" />
        </button>
        <button onClick={() => setPage(page + 1)} disabled={page >= pagination.last_page} className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 disabled:opacity-40">
          <ChevronRightIcon className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}

// ─── Fees Tab ────────────────────────────────────────────────────────────────

interface CustomFees {
  id?: string;
  account_id?: string;
  pix_fee_percent: number | null;
  pix_fee_fixed: number | null;
  credit_card_fee_percent: number | null;
  credit_card_fee_fixed: number | null;
  boleto_fee_percent: number | null;
  boleto_fee_fixed: number | null;
  withdrawal_pix_fee: number | null;
  withdrawal_ted_fee: number | null;
  instant_withdrawal_fee_percent: number | null;
  antifraude_fee_percent: number | null;
  antifraude_fee_fixed: number | null;
  installment_fee_percent: number | null;
  min_withdrawal: number | null;
  holdback_percent: number | null;
  holdback_days: number | null;
  notes: string | null;
}

const defaultFees: CustomFees = {
  pix_fee_percent: null,
  pix_fee_fixed: null,
  credit_card_fee_percent: null,
  credit_card_fee_fixed: null,
  boleto_fee_percent: null,
  boleto_fee_fixed: null,
  withdrawal_pix_fee: null,
  withdrawal_ted_fee: null,
  instant_withdrawal_fee_percent: null,
  antifraude_fee_percent: null,
  antifraude_fee_fixed: null,
  installment_fee_percent: null,
  min_withdrawal: null,
  holdback_percent: null,
  holdback_days: null,
  notes: null,
};

function FeesTab({ customerId }: { customerId: string }) {
  const queryClient = useQueryClient();
  const [fees, setFees] = useState<CustomFees>(defaultFees);

  const { data: feesData, isLoading } = useQuery({
    queryKey: ['admin-customer-fees', customerId],
    queryFn: async () => {
      const res = await api.get(`/admin/customers/${customerId}/fees`);
      return res.data;
    },
    enabled: !!customerId,
  });

  useEffect(() => {
    if (feesData?.data) setFees(feesData.data);
  }, [feesData]);

  const saveMutation = useMutation({
    mutationFn: async (payload: Partial<CustomFees>) => {
      const res = await api.put(`/admin/customers/${customerId}/fees`, payload);
      return res.data;
    },
    onSuccess: () => {
      toast.success('Taxas atualizadas com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-customer-fees', customerId] });
    },
    onError: () => toast.error('Erro ao salvar taxas'),
  });

  const handleSave = () => {
    const { id, account_id, ...payload } = fees;
    saveMutation.mutate(payload);
  };

  const updateField = (field: keyof CustomFees, value: string) => {
    setFees(prev => ({ ...prev, [field]: value === '' ? null : Number(value) }));
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-white p-5">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h3 className="text-sm font-semibold text-gray-900">Taxas Personalizadas</h3>
            <p className="text-xs text-gray-500 mt-0.5">Deixe em branco para usar as taxas padrão do sistema</p>
          </div>
          <button
            onClick={handleSave}
            disabled={saveMutation.isPending}
            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Salvando...' : 'Salvar Taxas'}
          </button>
        </div>

        {/* PIX */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">PIX</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa (%)" value={fees.pix_fee_percent} onChange={(v) => updateField('pix_fee_percent', v)} suffix="%" />
            <FeeInput label="Taxa Fixa (R$)" value={fees.pix_fee_fixed} onChange={(v) => updateField('pix_fee_fixed', v)} prefix="R$" />
          </div>
        </div>

        {/* Cartão de Crédito */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Cartão de Crédito</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa (%)" value={fees.credit_card_fee_percent} onChange={(v) => updateField('credit_card_fee_percent', v)} suffix="%" />
            <FeeInput label="Taxa Fixa (R$)" value={fees.credit_card_fee_fixed} onChange={(v) => updateField('credit_card_fee_fixed', v)} prefix="R$" />
          </div>
        </div>

        {/* Boleto */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Boleto</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa (%)" value={fees.boleto_fee_percent} onChange={(v) => updateField('boleto_fee_percent', v)} suffix="%" />
            <FeeInput label="Taxa Fixa (R$)" value={fees.boleto_fee_fixed} onChange={(v) => updateField('boleto_fee_fixed', v)} prefix="R$" />
          </div>
        </div>

        {/* Parcelamento */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Parcelamento</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa Parcela (%)" value={fees.installment_fee_percent} onChange={(v) => updateField('installment_fee_percent', v)} suffix="%" />
          </div>
        </div>

        {/* Saques */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Saques</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa PIX (R$)" value={fees.withdrawal_pix_fee} onChange={(v) => updateField('withdrawal_pix_fee', v)} prefix="R$" />
            <FeeInput label="Taxa TED (R$)" value={fees.withdrawal_ted_fee} onChange={(v) => updateField('withdrawal_ted_fee', v)} prefix="R$" />
            <FeeInput label="Saque Instantâneo (%)" value={fees.instant_withdrawal_fee_percent} onChange={(v) => updateField('instant_withdrawal_fee_percent', v)} suffix="%" />
            <FeeInput label="Saque Mínimo (R$)" value={fees.min_withdrawal} onChange={(v) => updateField('min_withdrawal', v)} prefix="R$" />
          </div>
        </div>

        {/* Antifraude */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Antifraude</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Taxa (%)" value={fees.antifraude_fee_percent} onChange={(v) => updateField('antifraude_fee_percent', v)} suffix="%" />
            <FeeInput label="Taxa Fixa (R$)" value={fees.antifraude_fee_fixed} onChange={(v) => updateField('antifraude_fee_fixed', v)} prefix="R$" />
          </div>
        </div>

        {/* Holdback */}
        <div className="mb-6">
          <h4 className="text-xs font-semibold uppercase text-gray-500 mb-3">Holdback / Reserva</h4>
          <div className="grid grid-cols-2 gap-4">
            <FeeInput label="Holdback (%)" value={fees.holdback_percent} onChange={(v) => updateField('holdback_percent', v)} suffix="%" />
            <FeeInput label="Dias de Holdback" value={fees.holdback_days} onChange={(v) => updateField('holdback_days', v)} suffix="dias" />
          </div>
        </div>

        {/* Notas */}
        <div>
          <label className="block text-xs font-medium text-gray-500 mb-1">Observações</label>
          <textarea
            value={fees.notes || ''}
            onChange={(e) => setFees(prev => ({ ...prev, notes: e.target.value || null }))}
            rows={3}
            maxLength={1000}
            className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none resize-none"
            placeholder="Notas sobre taxas específicas deste cliente..."
          />
        </div>
      </div>
    </div>
  );
}

function FeeInput({ label, value, onChange, prefix, suffix }: {
  label: string;
  value: number | null;
  onChange: (value: string) => void;
  prefix?: string;
  suffix?: string;
}) {
  return (
    <div>
      <label className="block text-xs font-medium text-gray-500 mb-1">{label}</label>
      <div className="relative">
        {prefix && <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-500">{prefix}</span>}
        <input
          type="number"
          step="0.01"
          min="0"
          value={value ?? ''}
          onChange={(e) => onChange(e.target.value)}
          className={`w-full rounded-lg border border-gray-200 bg-gray-50 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none ${prefix ? 'pl-8 pr-3' : suffix ? 'pl-3 pr-12' : 'px-3'}`}
          placeholder="Padrão"
        />
        {suffix && <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500">{suffix}</span>}
      </div>
    </div>
  );
}

// ─── Acquirers Tab ───────────────────────────────────────────────────────────

interface AcquirerItem {
  acquirer_id: string;
  slug: string;
  name: string;
  is_active_global: boolean;
  supported_methods: string[];
  assigned: boolean;
  is_active: boolean;
  priority: number;
  account_acquirer_id: string | null;
}

function AcquirersTab({ customerId }: { customerId: string }) {
  const queryClient = useQueryClient();
  const [acquirers, setAcquirers] = useState<AcquirerItem[]>([]);

  const { data: acquirersData, isLoading } = useQuery({
    queryKey: ['admin-customer-acquirers', customerId],
    queryFn: async () => {
      const res = await api.get(`/admin/customers/${customerId}/acquirers`);
      return res.data;
    },
    enabled: !!customerId,
  });

  useEffect(() => {
    if (acquirersData?.data) setAcquirers(acquirersData.data);
  }, [acquirersData]);

  const saveMutation = useMutation({
    mutationFn: async (payload: { acquirers: { acquirer_id: string; is_active: boolean; priority: number }[] }) => {
      const res = await api.put(`/admin/customers/${customerId}/acquirers`, payload);
      return res.data;
    },
    onSuccess: () => {
      toast.success('Adquirentes atualizados com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-customer-acquirers', customerId] });
    },
    onError: () => toast.error('Erro ao salvar adquirentes'),
  });

  const handleSave = () => {
    const payload = acquirers
      .filter(a => a.is_active)
      .map(a => ({ acquirer_id: a.acquirer_id, is_active: true, priority: a.priority }));
    // Also send deactivated ones that were previously assigned
    const deactivated = acquirers
      .filter(a => !a.is_active && a.assigned)
      .map(a => ({ acquirer_id: a.acquirer_id, is_active: false, priority: a.priority }));
    saveMutation.mutate({ acquirers: [...payload, ...deactivated] });
  };

  const toggleAcquirer = (acquirerId: string) => {
    setAcquirers(prev => prev.map(a =>
      a.acquirer_id === acquirerId ? { ...a, is_active: !a.is_active, assigned: true } : a
    ));
  };

  const updatePriority = (acquirerId: string, priority: number) => {
    setAcquirers(prev => prev.map(a =>
      a.acquirer_id === acquirerId ? { ...a, priority } : a
    ));
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-white p-5">
        <div className="flex items-center justify-between mb-6">
          <div>
            <h3 className="text-sm font-semibold text-gray-900">Adquirentes do Cliente</h3>
            <p className="text-xs text-gray-500 mt-0.5">Ative/desative adquirentes e defina a prioridade (menor = maior prioridade)</p>
          </div>
          <button
            onClick={handleSave}
            disabled={saveMutation.isPending}
            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Salvando...' : 'Salvar Adquirentes'}
          </button>
        </div>

        {acquirers.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12">
            <CreditCardIcon className="h-10 w-10 text-gray-500" />
            <p className="mt-3 text-sm text-gray-500">Nenhum adquirente disponível</p>
          </div>
        ) : (
          <div className="space-y-3">
            {acquirers.map((acq) => (
              <div
                key={acq.acquirer_id}
                className={`flex items-center justify-between rounded-md border p-4 transition-colors ${
                  acq.is_active
                    ? 'border-green-500/30 bg-green-500/5'
                    : 'border-gray-200 bg-gray-50'
                }`}
              >
                <div className="flex items-center gap-4">
                  <button
                    onClick={() => toggleAcquirer(acq.acquirer_id)}
                    className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${
                      acq.is_active ? 'bg-green-600' : 'bg-gray-300 dark:bg-gray-600'
                    }`}
                  >
                    <span
                      className={`absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform ${
                        acq.is_active ? 'translate-x-5' : 'translate-x-0'
                      }`}
                    />
                  </button>
                  <div>
                    <p className="text-sm font-medium text-gray-900">{acq.name}</p>
                    <div className="flex items-center gap-2 mt-0.5">
                      <span className="text-xs text-gray-500 font-mono">{acq.slug}</span>
                      {acq.supported_methods.length > 0 && (
                        <span className="text-xs text-gray-500">
                          · {acq.supported_methods.join(', ')}
                        </span>
                      )}
                      {!acq.is_active_global && (
                        <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                          Desativado Globalmente
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-3">
                  <div className="flex items-center gap-2">
                    <label className="text-xs text-gray-500">Prioridade:</label>
                    <input
                      type="number"
                      min="0"
                      max="99"
                      value={acq.priority}
                      onChange={(e) => updatePriority(acq.acquirer_id, parseInt(e.target.value) || 0)}
                      className="w-16 rounded-lg border border-gray-200 bg-gray-50 px-2 py-1 text-center text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                      disabled={!acq.is_active}
                    />
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

