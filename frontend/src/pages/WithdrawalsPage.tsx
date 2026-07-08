import { useState, useRef, useCallback } from 'react';
import { useForm } from 'react-hook-form';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Dialog, Transition, Tab } from '@headlessui/react';
import { api } from '@/lib/api';
import { Fragment } from 'react';
import {
 BanknotesIcon,
 ArrowDownTrayIcon,
 ClockIcon,
 CheckCircleIcon,
 XCircleIcon,
 CurrencyDollarIcon,
 BuildingLibraryIcon,
 CalendarIcon,
 ArrowPathIcon,
 ExclamationTriangleIcon,
 BoltIcon,
 ShieldCheckIcon,
 DocumentArrowDownIcon,
 EyeIcon,
 MagnifyingGlassIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { formatCurrency } from '@/lib/api';
import PixIcon from '@/components/icons/PixIcon';
import { useAuthStore } from '@/stores/authStore';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';

interface WithdrawalSummary {
 available_balance: number;
 pending_balance: number;
 total_balance: number;
 total_withdrawn: number;
 pending_withdrawals: number;
 this_month_withdrawn: number;
 recent_withdrawals: Withdrawal[];
}

interface Withdrawal {
 id: string;
 amount: number;
 fee: number;
 net_amount: number;
 is_instant?: boolean;
 status: 'pending' | 'pending_approval' | 'processing' | 'approved' | 'completed' | 'failed' | 'cancelled';
  failure_reason?: string;
 method: 'pix' | 'bank_transfer' | 'manual';
  destination: {
  type: 'pix' | 'bank_account' | 'manual_adjustment';
  pix_key?: string;
  bank_name?: string;
  account_number?: string;
  };
  created_at: string;
  processed_at?: string;
  notes?: string;
  history_type?: 'withdrawal' | 'wallet_adjustment';
  direction?: 'credit' | 'debit';
  reason?: string;
}

interface WithdrawalSettings {
 instant_withdrawal_enabled: boolean;
 instant_withdrawal_fee_type: 'percentage' | 'fixed';
 instant_withdrawal_fee_value: number;
 standard_withdrawal_fee_pix: number;
 standard_withdrawal_fee_bank: number;
 withdrawal_min_amount: number;
 withdrawal_max_amount?: number;
 withdrawals_enabled?: boolean;
 withdrawal_processing_enabled?: boolean;
 daily_withdrawal_limit?: number;
 withdrawal_cooldown_seconds?: number;
 is_custom?: boolean;
 gateway_fee_percentage?: number;
 gateway_fee_fixed?: number;
 custom_pix_fee_percent?: number | null;
 custom_pix_fee_fixed?: number | null;
 custom_credit_card_fee_percent?: number | null;
 custom_credit_card_fee_fixed?: number | null;
 custom_boleto_fee_percent?: number | null;
 custom_boleto_fee_fixed?: number | null;
}

interface WithdrawalForm {
 amount: number;
 method: 'pix' | 'bank_transfer';
 pix_key?: string;
 pix_type?: 'cpf' | 'cnpj' | 'email' | 'phone' | 'random';
 bank_code?: string;
 bank_name?: string;
 agency?: string;
 account_number?: string;
 account_type?: 'checking' | 'savings';
 holder_name?: string;
 holder_document?: string;
}

const statusConfig = {
 pending: { label: 'Pendente', icon: ClockIcon, color: 'text-amber-700 bg-amber-500/10 border border-amber-500/20' },
 pending_approval: { label: 'Aguardando aprovação', icon: ShieldCheckIcon, color: 'text-orange-700 bg-orange-500/10 border border-orange-500/20' },
 processing: { label: 'Processando', icon: ArrowPathIcon, color: 'text-blue-700 bg-blue-500/10 border border-blue-500/20' },
 approved: { label: 'Aprovado', icon: CheckCircleIcon, color: 'text-emerald-700 bg-emerald-500/10 border border-emerald-500/20' },
 completed: { label: 'Concluído', icon: CheckCircleIcon, color: 'text-emerald-700 bg-emerald-500/10 border border-emerald-500/20' },
 failed: { label: 'Falhou', icon: XCircleIcon, color: 'text-red-700 bg-red-500/10 border border-red-500/20' },
 cancelled: { label: 'Cancelado', icon: XCircleIcon, color: 'text-tx-secondary bg-surface-hover border border-border-DEFAULT' },
};

const banks = [
 { code: '001', name: 'Banco do Brasil' },
 { code: '033', name: 'Santander' },
 { code: '104', name: 'Caixa Econômica' },
 { code: '237', name: 'Bradesco' },
 { code: '341', name: 'Itaú' },
 { code: '077', name: 'Banco Inter' },
 { code: '260', name: 'Nubank' },
 { code: '290', name: 'PagSeguro' },
 { code: '323', name: 'Mercado Pago' },
];

export default function WithdrawalsPage() {
 const [isWithdrawModalOpen, setIsWithdrawModalOpen] = useState(false);
 const [selectedStatus, setSelectedStatus] = useState<string>('all');
 const [mfaStep, setMfaStep] = useState(false);
 const [mfaCode, setMfaCode] = useState(['', '', '', '', '', '']);
 const [pendingFormData, setPendingFormData] = useState<WithdrawalForm | null>(null);
 const [withdrawalRequestKey, setWithdrawalRequestKey] = useState('');
 const [searchQuery, setSearchQuery] = useState('');
 const [methodFilter, setMethodFilter] = useState('all');
 const [dateFrom, setDateFrom] = useState('');
 const [dateTo, setDateTo] = useState('');
 const [selectedWithdrawalDetail, setSelectedWithdrawalDetail] = useState<Withdrawal | null>(null);
 const mfaInputRefs = useRef<(HTMLInputElement | null)[]>([]);
 const { user } = useAuthStore();
 const navigate = useNavigate();
 const queryClient = useQueryClient();

 // Buscar configurações de saque
 const { data: settingsData } = useQuery<{ data: WithdrawalSettings }>({
 queryKey: ['withdrawal-settings'],
 queryFn: async () => {
 const response = await api.get('/withdrawals/settings');
 return response.data;
 },
 });

 const settings = settingsData?.data;
 const minWithdrawal = settings?.withdrawal_min_amount ?? 1000;
 const maxWithdrawal = settings?.withdrawal_max_amount ?? 0;
 const withdrawalsEnabled = settings?.withdrawals_enabled ?? true;

 // Buscar resumo e saldo (polling a cada 5s para tempo real)
 const { data: summaryData, refetch: refetchSummary, isFetching: isFetchingSummary } = useQuery<{ data: WithdrawalSummary }>({
 queryKey: ['withdrawals-summary'],
 queryFn: async () => {
 const response = await api.get('/withdrawals/summary');
 return response.data;
 },
 refetchInterval: 5000,
 });

 // Buscar lista de saques (polling a cada 5s para tempo real)
 const { data: withdrawalsData, isLoading } = useQuery<{ data: Withdrawal[] }>({
 queryKey: ['withdrawals', selectedStatus],
 queryFn: async () => {
 const params = selectedStatus !== 'all' ? { status: selectedStatus } : {};
 const response = await api.get('/withdrawals', { params });
 return response.data;
 },
 refetchInterval: 5000,
 });

 const summary = summaryData?.data;
 const withdrawals = withdrawalsData?.data || [];
 const availableBalance = summary?.available_balance || 0;
 const pendingAmount = summary?.pending_withdrawals || 0;
 const pendingWithdrawals = withdrawals.filter(w => w.status === 'pending' || w.status === 'pending_approval' || w.status === 'processing');

 const { register, handleSubmit, watch, reset, setValue, formState: { errors } } = useForm<WithdrawalForm>({
 defaultValues: {
 method: 'pix',
 pix_type: 'email',
 account_type: 'checking',
 },
 });

 const withdrawMethod = watch('method');
 const withdrawAmount = watch('amount');
 const pixType = watch('pix_type');

 const calculateFees = (amountCents: number, method: string) => {
 // Base fee from settings
 let baseFee = 0;
 if (method === 'bank_transfer') {
 baseFee = settings?.standard_withdrawal_fee_bank ?? 200;
 } else {
 baseFee = settings?.standard_withdrawal_fee_pix ?? 0;
 }

 // Withdrawal fee (applied automatically when enabled)
 let withdrawalFee = 0;
 if (settings?.instant_withdrawal_enabled) {
 if (settings.instant_withdrawal_fee_type === 'percentage') {
 withdrawalFee = Math.round(amountCents * (settings.instant_withdrawal_fee_value / 100));
 } else {
 withdrawalFee = settings.instant_withdrawal_fee_value;
 }
 }

 return { baseFee, withdrawalFee, totalFee: baseFee + withdrawalFee };
 };

 // Keep backward-compat alias
 const calculateFee = (amountCents: number, method: string) =>
 calculateFees(amountCents, method).totalFee;

 const safeAmount = typeof withdrawAmount === 'number' && !isNaN(withdrawAmount) ? withdrawAmount : 0;
 const fees = safeAmount > 0 ? calculateFees(safeAmount * 100, withdrawMethod) : { baseFee: 0, withdrawalFee: 0, totalFee: 0 };
 const fee = fees.totalFee;
 const netAmount = safeAmount > 0 ? (safeAmount * 100) - fee : 0;

 // MFA digit input handlers
 const handleMfaDigitChange = useCallback((index: number, value: string) => {
 if (!/^\d?$/.test(value)) return;
 setMfaCode(prev => {
 const next = [...prev];
 next[index] = value;
 return next;
 });
 if (value && index < 5) {
 mfaInputRefs.current[index + 1]?.focus();
 }
 }, []);

 const handleMfaKeyDown = useCallback((index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
 if (e.key === 'Backspace' && !mfaCode[index] && index > 0) {
 mfaInputRefs.current[index - 1]?.focus();
 }
 }, [mfaCode]);

 const handleMfaPaste = useCallback((e: React.ClipboardEvent) => {
 e.preventDefault();
 const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
 if (pasted.length === 6) {
 setMfaCode(pasted.split(''));
 mfaInputRefs.current[5]?.focus();
 }
 }, []);

 const resetMfaState = useCallback(() => {
 setMfaStep(false);
 setMfaCode(['', '', '', '', '', '']);
 setPendingFormData(null);
 setWithdrawalRequestKey('');
 }, []);

 const generateIdempotencyKey = useCallback(() => {
 if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
 return crypto.randomUUID();
 }

 return `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`;
 }, []);

 // Mutation para criar saque
 const createWithdrawalMutation = useMutation({
 mutationFn: async ({ data, mfa_code, idempotency_key }: { data: WithdrawalForm; mfa_code: string; idempotency_key: string }) => {
 const payload: Record<string, any> = {
 amount: data.amount * 100,
 method: data.method,
 mfa_code,
 idempotency_key,
 destination: data.method === 'pix'
 ? { type: 'pix', pix_key: data.pix_key, pix_type: data.pix_type }
 : {
 type: 'bank_account',
 bank_code: data.bank_code,
 bank_name: data.bank_name,
 agency: data.agency,
 account_number: data.account_number,
 account_type: data.account_type,
 holder_name: data.holder_name,
 holder_document: data.holder_document,
 },
 };
 const response = await api.post('/withdrawals', payload);
 return response.data;
 },
 onSuccess: (result: any, variables) => {
 const createdWithdrawal = result?.data as Withdrawal | undefined;
 const lockedAmount = Math.max(0, Number(createdWithdrawal?.amount ?? Math.round(variables.data.amount * 100)));

 queryClient.setQueryData<{ data: WithdrawalSummary }>(['withdrawals-summary'], (current) => {
 if (!current?.data) return current;

 return {
 ...current,
 data: {
 ...current.data,
 available_balance: Math.max(0, (current.data.available_balance || 0) - lockedAmount),
 pending_withdrawals: (current.data.pending_withdrawals || 0) + lockedAmount,
 recent_withdrawals: createdWithdrawal
 ? [
 createdWithdrawal,
 ...(current.data.recent_withdrawals || [])
 .filter((w) => w.id !== createdWithdrawal.id)
 .slice(0, 4),
 ]
 : current.data.recent_withdrawals,
 },
 };
 });

 toast.success('Saque solicitado com sucesso!');
 queryClient.invalidateQueries({ queryKey: ['withdrawals'] });
 queryClient.invalidateQueries({ queryKey: ['withdrawals-summary'] });
 setIsWithdrawModalOpen(false);
 resetMfaState();
 reset();
 },
 onError: (error: any) => {
 const errorCode = error.response?.data?.error?.code;
 if (errorCode === 'mfa_invalid') {
 toast.error('Código 2FA inválido. Tente novamente.');
 setMfaCode(['', '', '', '', '', '']);
 mfaInputRefs.current[0]?.focus();
 } else if (errorCode === 'mfa_required') {
 toast.error('Ative a verificação 2FA nas configurações para realizar saques.');
 resetMfaState();
 } else {
 toast.error(error.response?.data?.message || error.response?.data?.error?.message || 'Erro ao criar saque');
 }
 },
 });

 const onSubmit = (data: WithdrawalForm) => {
 const amount = data.amount * 100;

 if (!withdrawalsEnabled) {
 toast.error('Saques temporariamente indisponíveis. Tente novamente mais tarde.');
 return;
 }
 
 if (amount < minWithdrawal) {
 toast.error(`Valor mínimo de saque é ${formatCurrency(minWithdrawal)}`);
 return;
 }

 if (maxWithdrawal > 0 && amount > maxWithdrawal) {
 toast.error(`Valor máximo de saque é ${formatCurrency(maxWithdrawal)}`);
 return;
 }

 if (amount > availableBalance) {
 toast.error('Saldo insuficiente');
 return;
 }

 // Validate net amount after all fees
 const totalFee = calculateFee(amount, data.method);
 if (amount - totalFee <= 0) {
 toast.error('O valor líquido após taxas deve ser maior que zero. Aumente o valor do saque.');
 return;
 }

 // Check if user has KYC approved
 if (user?.kyc_status !== 'approved') {
 toast.error('Você precisa completar a verificação KYC antes de realizar saques.');
 navigate('/kyc');
 return;
 }

 // Check if user has MFA enabled
 if (!user?.mfa_enabled) {
 toast.error('Você precisa ativar a verificação em dois fatores (2FA) nas configurações antes de realizar saques.');
 return;
 }

 // Move to MFA verification step
 setPendingFormData(data);
 setWithdrawalRequestKey(generateIdempotencyKey());
 setMfaStep(true);
 setMfaCode(['', '', '', '', '', '']);
 setTimeout(() => mfaInputRefs.current[0]?.focus(), 100);
 };

 const handleMfaSubmit = () => {
 const code = mfaCode.join('');
 if (code.length !== 6 || !pendingFormData) return;

 const idempotencyKey = withdrawalRequestKey || generateIdempotencyKey();
 if (!withdrawalRequestKey) {
 setWithdrawalRequestKey(idempotencyKey);
 }

 createWithdrawalMutation.mutate({ data: pendingFormData, mfa_code: code, idempotency_key: idempotencyKey });
 };

 // Mutation para cancelar saque
 const cancelWithdrawalMutation = useMutation({
 mutationFn: async (withdrawalId: string) => {
 const response = await api.post(`/withdrawals/${withdrawalId}/cancel`);
 return response.data;
 },
 onSuccess: (payload: any, withdrawalId: string) => {
 const cancelledWithdrawal = payload?.data as Withdrawal | undefined;

 queryClient.setQueryData<{ data: Withdrawal[] }>(['withdrawals', selectedStatus], (current) => {
 if (!current?.data) return current;

 if (selectedStatus === 'pending' || selectedStatus === 'processing') {
 return {
 ...current,
 data: current.data.filter((w) => w.id !== withdrawalId),
 };
 }

 return {
 ...current,
 data: current.data.map((w) =>
 w.id === withdrawalId
 ? {
 ...w,
 ...(cancelledWithdrawal ?? {}),
 status: 'cancelled',
 failure_reason: cancelledWithdrawal?.failure_reason || cancelledWithdrawal?.notes || payload?.cancel_reason || w.failure_reason,
 notes: cancelledWithdrawal?.notes || payload?.cancel_reason || w.notes,
 }
 : w
 ),
 };
 });

 if (cancelledWithdrawal?.amount) {
 queryClient.setQueryData<{ data: WithdrawalSummary }>(['withdrawals-summary'], (current) => {
 if (!current?.data) return current;

 const amount = Number(cancelledWithdrawal.amount) || 0;
 return {
 ...current,
 data: {
 ...current.data,
 available_balance: (current.data.available_balance || 0) + amount,
 pending_withdrawals: Math.max(0, (current.data.pending_withdrawals || 0) - amount),
 },
 };
 });
 }

 toast.success('Saque cancelado com sucesso!');
 queryClient.invalidateQueries({ queryKey: ['withdrawals'] });
 queryClient.invalidateQueries({ queryKey: ['withdrawals-summary'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao cancelar saque');
 },
 });

 const handleCancelWithdrawal = (withdrawalId: string) => {
 if (confirm('Tem certeza que deseja cancelar este saque?')) {
 cancelWithdrawalMutation.mutate(withdrawalId);
 }
 };

 const filteredWithdrawals = (selectedStatus === 'all'
 ? withdrawals
 : withdrawals.filter(w => w.status === selectedStatus))
 .filter((withdrawal) => {
 if (methodFilter !== 'all' && withdrawal.method !== methodFilter) return false;

 const createdAt = new Date(withdrawal.created_at);
 if (dateFrom && createdAt < new Date(`${dateFrom}T00:00:00`)) return false;
 if (dateTo && createdAt > new Date(`${dateTo}T23:59:59`)) return false;

 const query = searchQuery.trim().toLowerCase();
 if (!query) return true;

 const searchable = [
 withdrawal.id,
 withdrawal.method,
 withdrawal.status,
 withdrawal.destination.pix_key,
 withdrawal.destination.bank_name,
 withdrawal.destination.account_number,
 withdrawal.notes,
 withdrawal.failure_reason,
 ].filter(Boolean).join(' ').toLowerCase();

 return searchable.includes(query);
 });

 const statusTabs = [
 { key: 'all', label: 'Todos', count: withdrawals.length },
 { key: 'pending', label: 'Pendentes', count: pendingWithdrawals.length },
 { key: 'completed', label: 'Concluidos', count: withdrawals.filter(w => w.status === 'completed').length },
 { key: 'cancelled', label: 'Cancelados', count: withdrawals.filter(w => w.status === 'cancelled').length },
 ];

 const quickWithdrawalPercents = [25, 50, 100];

 const setQuickAmount = (percent: number) => {
 const amount = Math.floor((availableBalance * percent) / 100) / 100;
 setValue('amount', amount, { shouldDirty: true, shouldValidate: true });
 };

 const getWithdrawalTimeline = (withdrawal: Withdrawal) => {
 const base = [
 { label: 'Solicitado', date: withdrawal.created_at, done: true },
 {
 label: withdrawal.status === 'pending' ? 'Aguardando analise' : 'Analise concluida',
 date: withdrawal.status === 'pending' ? undefined : withdrawal.processed_at,
 done: withdrawal.status !== 'pending',
 },
 {
 label: ['processing', 'approved', 'completed'].includes(withdrawal.status) ? 'Processamento iniciado' : 'Processamento',
 date: ['processing', 'approved', 'completed'].includes(withdrawal.status) ? withdrawal.processed_at : undefined,
 done: ['processing', 'approved', 'completed'].includes(withdrawal.status),
 },
 {
 label: withdrawal.status === 'cancelled' ? 'Cancelado' : withdrawal.status === 'failed' ? 'Falhou' : 'Concluido',
 date: withdrawal.processed_at,
 done: ['completed', 'cancelled', 'failed'].includes(withdrawal.status),
 },
 ];

 return base;
 };

 const exportWithdrawalsCsv = () => {
 const headers = ['Data', 'Metodo', 'Destino', 'Valor', 'Taxa', 'Liquido', 'Status'];
 const rows = filteredWithdrawals.map((withdrawal) => [
 new Date(withdrawal.created_at).toLocaleString('pt-BR'),
 withdrawal.method,
 withdrawal.destination.pix_key || withdrawal.destination.bank_name || withdrawal.destination.account_number || 'Ajuste manual',
 (withdrawal.amount / 100).toFixed(2),
 (withdrawal.fee / 100).toFixed(2),
 (withdrawal.net_amount / 100).toFixed(2),
 statusConfig[withdrawal.status]?.label || withdrawal.status,
 ]);
 const csv = [headers, ...rows]
 .map((row) => row.map((value) => `"${String(value).replace(/"/g, '""')}"`).join(';'))
 .join('\n');
 const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
 const url = URL.createObjectURL(blob);
 const link = document.createElement('a');
 link.href = url;
 link.download = `saques-${new Date().toISOString().slice(0, 10)}.csv`;
 link.click();
 URL.revokeObjectURL(url);
 };

  return (
 <div className="space-y-5">
 <section className="rounded-xl border border-border-DEFAULT bg-surface-card p-5 shadow-sm sm:p-6">
 <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
 <div className="min-w-0">
 <h1 className="text-2xl font-bold tracking-tight text-tx-primary sm:text-3xl">Saques</h1>
 <p className="mt-1 text-sm text-tx-muted">
 Acompanhe saldo disponível, solicitações e histórico de transferências.
 </p>
 </div>
 <button
 onClick={() => {
 if (user?.kyc_status !== 'approved') {
 toast.error('Voce precisa completar a verificacao KYC antes de realizar saques.');
 navigate('/kyc');
 return;
 }
 setIsWithdrawModalOpen(true);
 }}
 disabled={createWithdrawalMutation.isPending || !withdrawalsEnabled || availableBalance < minWithdrawal}
 className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-accent-primary px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-accent-primary/90 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
 >
 <ArrowDownTrayIcon className="h-5 w-5" />
 Solicitar saque
 </button>
 </div>
 </section>

 {!withdrawalsEnabled && (
 <div className="rounded-2xl border border-status-warning bg-status-warning-subtle p-4">
 <div className="flex items-start gap-3">
 <ExclamationTriangleIcon className="h-5 w-5 text-status-warning mt-0.5" />
 <div>
 <p className="text-sm font-semibold text-status-warning">Saques temporariamente pausados</p>
 <p className="text-xs text-tx-muted mt-1">A administração bloqueou novas solicitações de saque no momento.</p>
 </div>
 </div>
 </div>
 )}



 <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
 <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
 <div className="mb-2 flex items-center justify-between">
 <span className="text-sm font-medium text-tx-secondary">Saldo disponível</span>
 <button
 onClick={() => refetchSummary()}
 disabled={isFetchingSummary}
 title="Atualizar saldo"
 className="rounded-md p-1 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
 >
 <ArrowPathIcon className={clsx('h-4 w-4', isFetchingSummary && 'animate-spin')} />
 </button>
 </div>
 <p className="mb-1 font-mono text-2xl font-bold tracking-tight text-tx-primary">{formatCurrency(availableBalance)}</p>
 <p className="text-xs text-tx-muted">Disponível para nova solicitação</p>
 </div>

 <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
 <div className="mb-2 flex items-center justify-between">
 <span className="text-sm font-medium text-tx-secondary">Em processamento</span>
 <ClockIcon className="h-5 w-5 text-tx-muted" />
 </div>
 <p className="mb-1 font-mono text-2xl font-bold tracking-tight text-tx-primary">{formatCurrency(pendingAmount)}</p>
 <p className="text-xs text-tx-muted">{pendingWithdrawals.length} solicitações ativas</p>
 </div>

 <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
 <div className="mb-2 flex items-center justify-between">
 <span className="text-sm font-medium text-tx-secondary">Sacado no mês</span>
 <CheckCircleIcon className="h-5 w-5 text-tx-muted" />
 </div>
 <p className="mb-1 font-mono text-2xl font-bold tracking-tight text-tx-primary">{formatCurrency(summary?.this_month_withdrawn || 0)}</p>
 <p className="text-xs text-tx-muted">Total de saques concluídos</p>
 </div>
 </div>

 <div className="rounded-xl border border-border-DEFAULT bg-surface-card px-4 py-3">
 <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-tx-muted">
 {settings?.is_custom && (
 <span className="inline-flex items-center gap-1 text-accent-primary font-medium">
 <span className="h-1.5 w-1.5 rounded-full bg-accent-primary" />
 Taxa personalizada
 </span>
 )}
 <span>Mín. saque: <strong className="text-tx-secondary">{formatCurrency(minWithdrawal)}</strong></span>
 <span className="hidden sm:inline text-tx-muted">|</span>
 <span>PIX: <strong className="text-tx-secondary">{(settings?.standard_withdrawal_fee_pix ?? 0) === 0 ? 'Grátis' : formatCurrency(settings?.standard_withdrawal_fee_pix ?? 0)}</strong></span>
 <span className="hidden sm:inline text-tx-muted">|</span>
 <span>Taxa Gateway: <strong className="text-tx-secondary">{settings?.gateway_fee_percentage ?? 5}%{(settings?.gateway_fee_fixed ?? 0) > 0 ? ` + ${formatCurrency((settings?.gateway_fee_fixed ?? 0) * 100)}` : ''}</strong></span>
 {settings?.instant_withdrawal_enabled && (
 <>
 <span className="hidden sm:inline text-tx-muted">|</span>
 <span>Taxa de Saque: <strong className="text-accent-primary">{settings.instant_withdrawal_fee_type === 'percentage' ? `${settings.instant_withdrawal_fee_value}%` : formatCurrency(settings.instant_withdrawal_fee_value)}</strong></span>
 </>
 )}
 </div>
 </div>

 <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
 <div className="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_160px_150px_150px_auto] lg:items-end">
 <div>
 <label className="mb-1 block text-xs font-semibold text-tx-muted">Buscar</label>
 <div className="relative">
 <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
 <input
 value={searchQuery}
 onChange={(event) => setSearchQuery(event.target.value)}
 className="input pl-9"
 placeholder="Chave PIX, banco, status ou ID"
 />
 </div>
 </div>
 <div>
 <label className="mb-1 block text-xs font-semibold text-tx-muted">Metodo</label>
 <select value={methodFilter} onChange={(event) => setMethodFilter(event.target.value)} className="input">
 <option value="all">Todos</option>
 <option value="pix">PIX</option>
 <option value="bank_transfer">TED</option>
 <option value="manual">Ajuste</option>
 </select>
 </div>
 <div>
 <label className="mb-1 block text-xs font-semibold text-tx-muted">De</label>
 <input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} className="input" />
 </div>
 <div>
 <label className="mb-1 block text-xs font-semibold text-tx-muted">Ate</label>
 <input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} className="input" />
 </div>
 <button
 type="button"
 onClick={exportWithdrawalsCsv}
 disabled={filteredWithdrawals.length === 0}
 className="inline-flex items-center justify-center gap-2 rounded-lg border border-border-DEFAULT px-4 py-2.5 text-sm font-semibold text-tx-secondary transition hover:bg-surface-hover disabled:opacity-50"
 >
 <DocumentArrowDownIcon className="h-4 w-4" />
 Exportar
 </button>
 </div>
 </div>

 <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
 <div className="flex gap-2 overflow-x-auto scrollbar-hide pb-1 sm:pb-0">
 <button
 onClick={() => setSelectedStatus('all')}
 className={clsx(
 'px-4 py-2 rounded-element text-sm font-medium transition-all whitespace-nowrap',
 selectedStatus === 'all'
 ? 'border border-accent-primary bg-accent-subtle text-accent-primary'
 : 'border border-border-DEFAULT bg-transparent text-tx-secondary hover:bg-surface-hover'
 )}
 >
 Todos ({statusTabs[0].count})
 </button>
 <button
 onClick={() => setSelectedStatus('pending')}
 className={clsx(
 'px-4 py-2 rounded-element text-sm font-medium transition-all whitespace-nowrap',
 selectedStatus === 'pending'
 ? 'border border-accent-primary bg-accent-subtle text-accent-primary'
 : 'border border-border-DEFAULT bg-transparent text-tx-secondary hover:bg-surface-hover'
 )}
 >
 Pendentes ({statusTabs[1].count})
 </button>
 <button
 onClick={() => setSelectedStatus('completed')}
 className={clsx(
 'px-4 py-2 rounded-element text-sm font-medium transition-all whitespace-nowrap',
 selectedStatus === 'completed'
 ? 'border border-accent-primary bg-accent-subtle text-accent-primary'
 : 'border border-border-DEFAULT bg-transparent text-tx-secondary hover:bg-surface-hover'
 )}
 >
 Concluídos
 </button>
 <button
 onClick={() => setSelectedStatus('cancelled')}
 className={clsx(
 'px-4 py-2 rounded-element text-sm font-medium transition-all whitespace-nowrap',
 selectedStatus === 'cancelled'
 ? 'border border-accent-primary bg-accent-subtle text-accent-primary'
 : 'border border-border-DEFAULT bg-transparent text-tx-secondary hover:bg-surface-hover'
 )}
 >
 Cancelados ({statusTabs[3].count})
 </button>
 </div>

 <button
 onClick={() => {
 if (user?.kyc_status !== 'approved') {
 toast.error('Você precisa completar a verificação KYC antes de realizar saques.');
 navigate('/kyc');
 return;
 }
 setIsWithdrawModalOpen(true);
 }}
 disabled={createWithdrawalMutation.isPending || !withdrawalsEnabled || availableBalance < minWithdrawal}
 className="hidden"
 >
 <ArrowDownTrayIcon className="h-5 w-5" />
 Solicitar Saque
 </button>
 </div>

 <div className="rounded-2xl bg-surface-card border border-border-DEFAULT overflow-hidden">
 <div className="overflow-x-auto">
 <table className="w-full">
 <thead>
 <tr className="border-b border-border-DEFAULT bg-surface-page/60">
 <th className="text-left py-3.5 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Data
 </th>
 <th className="text-left py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Método
 </th>
 <th className="text-left py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Destino
 </th>
 <th className="text-right py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Valor
 </th>
 <th className="text-right py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Taxa
 </th>
 <th className="text-right py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Líquido
 </th>
 <th className="text-center py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Status
 </th>
 <th className="text-center py-4 px-6 text-[11px] font-semibold text-tx-muted uppercase tracking-wider">
 Ações
 </th>
 </tr>
 </thead>
 <tbody className="divide-y divide-border-DEFAULT">
 {filteredWithdrawals.length === 0 ? (
 <tr>
 <td colSpan={8} className="py-12 text-center">
 <BanknotesIcon className="h-12 w-12 mx-auto text-tx-muted mb-3" />
 <p className="text-tx-muted">Nenhum saque encontrado</p>
 </td>
 </tr>
 ) : (
 filteredWithdrawals.map((withdrawal) => {
 const config = statusConfig[withdrawal.status];
 const StatusIcon = config.icon;
  return (
 <tr key={withdrawal.id} className="transition-colors hover:bg-surface-hover/40">
 <td className="py-4 px-6">
 <div className="flex items-center gap-2 text-sm">
 <CalendarIcon className="h-4 w-4 text-tx-muted" />
 <span className="text-tx-primary">
 {new Date(withdrawal.created_at).toLocaleDateString('pt-BR')}
 </span>
 </div>
 <span className="text-xs text-tx-muted">
 {new Date(withdrawal.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}
 </span>
 </td>
 <td className="py-4 px-6">
 <div className="flex items-center gap-2">
  {withdrawal.method === 'pix' ? (
  <>
  <PixIcon className="h-5 w-5 text-accent-primary/90" />
  <span className="text-sm font-medium text-tx-primary">PIX</span>
  </>
  ) : withdrawal.method === 'manual' ? (
  <>
  <CurrencyDollarIcon className="h-5 w-5 text-tx-muted" />
  <span className="text-sm font-medium text-tx-primary">AJUSTE</span>
  </>
  ) : (
  <>
  <BuildingLibraryIcon className="h-5 w-5 text-tx-muted" />
  <span className="text-sm font-medium text-tx-primary">TED</span>
  </>
 )}
 {withdrawal.is_instant && (
 <span className="inline-flex items-center gap-0.5 rounded-full border border-accent-primary/30 bg-accent-subtle px-1.5 py-0.5 text-[10px] font-semibold text-accent-primary">
 <BoltIcon className="h-2.5 w-2.5" />
 Instant
 </span>
 )}
 </div>
 </td>
 <td className="py-4 px-6">
  {withdrawal.destination.type === 'pix' ? (
  <div className="text-sm text-tx-secondary">
  {withdrawal.destination.pix_key}
  </div>
  ) : withdrawal.destination.type === 'manual_adjustment' ? (
  <div>
  <div className="text-sm font-medium text-tx-primary">Ajuste manual</div>
  <div className="text-xs text-tx-muted">
  {withdrawal.direction === 'debit' ? 'Debito de saldo' : 'Credito de saldo'}
  </div>
  </div>
  ) : (
  <div>
  <div className="text-sm font-medium text-tx-primary">
  {withdrawal.destination.bank_name}
 </div>
 <div className="text-xs text-tx-muted">
 {withdrawal.destination.account_number}
 </div>
 </div>
 )}
 </td>
 <td className="py-4 px-6 text-right">
 <div className="text-sm font-semibold font-mono text-tx-primary">
 {formatCurrency(withdrawal.amount)}
 </div>
 </td>
 <td className="py-4 px-6 text-right">
 <div className="text-sm font-mono text-tx-secondary">
 {withdrawal.fee > 0 ? formatCurrency(withdrawal.fee) : 'Grátis'}
 </div>
 </td>
 <td className="py-4 px-6 text-right">
 <div className="text-sm font-bold font-mono text-accent-primary">
 {formatCurrency(withdrawal.net_amount)}
 </div>
 </td>
 <td className="py-4 px-6">
 <div className="flex justify-center">
 <div className="max-w-[220px] text-center">
 <span className={clsx(
 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold',
 config.color
 )}>
 <StatusIcon className="h-3.5 w-3.5" />
 {config.label}
 </span>
  </div>
  </div>
  </td>
 <td className="py-4 px-6">
 <div className="flex justify-center gap-2">
  <button
  onClick={() => setSelectedWithdrawalDetail(withdrawal)}
  className="inline-flex items-center gap-1.5 rounded-element border border-border-DEFAULT px-3 py-1.5 text-xs font-semibold text-tx-secondary transition-colors hover:bg-surface-hover"
 >
 <EyeIcon className="h-4 w-4" />
 Detalhes
 </button>
  {withdrawal.status === 'pending' && withdrawal.history_type !== 'wallet_adjustment' && (
  <button
  onClick={() => handleCancelWithdrawal(withdrawal.id)}
  disabled={cancelWithdrawalMutation.isPending}
  className="inline-flex items-center gap-1.5 rounded-element border border-status-error/30 px-3 py-1.5 text-xs font-semibold text-status-error transition-colors hover:bg-status-error-subtle disabled:opacity-50"
 >
 <XCircleIcon className="h-4 w-4" />
 Cancelar
 </button>
 )}
 </div>
 </td>
 </tr>
 );
 })
 )}
 </tbody>
 </table>
 </div>
 </div>

 <Transition appear show={!!selectedWithdrawalDetail} as={Fragment}>
 <Dialog as="div" className="relative z-50" onClose={() => setSelectedWithdrawalDetail(null)}>
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-200"
 enterFrom="opacity-0"
 enterTo="opacity-100"
 leave="ease-in duration-150"
 leaveFrom="opacity-100"
 leaveTo="opacity-0"
 >
 <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" />
 </Transition.Child>
 <div className="fixed inset-0 overflow-y-auto">
 <div className="flex min-h-full items-center justify-center p-4">
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-200"
 enterFrom="opacity-0 scale-95"
 enterTo="opacity-100 scale-100"
 leave="ease-in duration-150"
 leaveFrom="opacity-100 scale-100"
 leaveTo="opacity-0 scale-95"
 >
 <Dialog.Panel className="w-full max-w-lg overflow-hidden rounded-2xl bg-surface-card shadow-xl">
 {selectedWithdrawalDetail && (
 <>
 <div className="border-b border-border-DEFAULT px-6 py-4">
 <Dialog.Title className="text-lg font-bold text-tx-primary">Detalhes do saque</Dialog.Title>
 <p className="mt-1 text-sm text-tx-muted">Acompanhamento financeiro da solicitacao.</p>
 </div>
 <div className="space-y-5 p-6">
 <div className="grid grid-cols-3 gap-3">
 <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-3">
 <p className="text-xs text-tx-muted">Valor</p>
 <p className="mt-1 font-mono text-sm font-bold text-tx-primary">{formatCurrency(selectedWithdrawalDetail.amount)}</p>
 </div>
 <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-3">
 <p className="text-xs text-tx-muted">Taxa</p>
 <p className="mt-1 font-mono text-sm font-bold text-tx-primary">{selectedWithdrawalDetail.fee > 0 ? formatCurrency(selectedWithdrawalDetail.fee) : 'Gratis'}</p>
 </div>
 <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-3">
 <p className="text-xs text-tx-muted">Liquido</p>
 <p className="mt-1 font-mono text-sm font-bold text-accent-primary">{formatCurrency(selectedWithdrawalDetail.net_amount)}</p>
 </div>
 </div>

 <div>
 <h3 className="mb-3 text-sm font-semibold text-tx-primary">Linha do tempo</h3>
 <div className="space-y-3">
 {getWithdrawalTimeline(selectedWithdrawalDetail).map((event, index) => (
 <div key={`${event.label}-${index}`} className="flex gap-3">
 <div className={clsx('mt-1 h-3 w-3 rounded-full border', event.done ? 'border-accent-primary bg-accent-primary' : 'border-border-DEFAULT bg-surface-hover')} />
 <div>
 <p className={clsx('text-sm font-semibold', event.done ? 'text-tx-primary' : 'text-tx-muted')}>{event.label}</p>
 <p className="text-xs text-tx-muted">
 {event.date ? new Date(event.date).toLocaleString('pt-BR') : 'Pendente'}
 </p>
 </div>
 </div>
 ))}
 </div>
 </div>

 {(selectedWithdrawalDetail.failure_reason || selectedWithdrawalDetail.notes) && (
 <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-3 text-sm text-tx-secondary">
 {selectedWithdrawalDetail.failure_reason || selectedWithdrawalDetail.notes}
 </div>
 )}
 </div>
 </>
 )}
 </Dialog.Panel>
 </Transition.Child>
 </div>
 </div>
 </Dialog>
 </Transition>

 {/* Withdraw Modal */}
 <Transition appear show={isWithdrawModalOpen} as={Fragment}>
 <Dialog as="div" className="relative z-50" onClose={() => { setIsWithdrawModalOpen(false); resetMfaState(); }}>
 <Transition.Child
 as={Fragment}
 enter="ease-out duration-300"
 enterFrom="opacity-0"
 enterTo="opacity-100"
 leave="ease-in duration-200"
 leaveFrom="opacity-100"
 leaveTo="opacity-0"
 >
 <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" />
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
 <Dialog.Panel className="w-full max-w-2xl transform overflow-hidden rounded-2xl bg-surface-card shadow-xl transition-all">
 {mfaStep ? (
 /* ── MFA Verification Step ───────────────────── */
 <div className="p-6">
 <div className="flex flex-col items-center text-center mb-8">
 <div className="w-16 h-16 rounded-full bg-accent-subtle flex items-center justify-center mb-4">
 <ShieldCheckIcon className="h-8 w-8 text-accent-primary" />
 </div>
 <h2 className="text-xl font-bold text-tx-primary">Verificação 2FA</h2>
 <p className="text-sm text-tx-muted mt-2">
 Digite o código de 6 dígitos do seu aplicativo autenticador para confirmar o saque
 </p>
 {pendingFormData && (
 <div className="mt-3 px-4 py-2 rounded-lg bg-surface-hover">
 <span className="text-sm text-tx-secondary">Valor: </span>
 <span className="text-sm font-bold text-accent-primary">{formatCurrency(pendingFormData.amount * 100)}</span>
 </div>
 )}
 </div>

 <div className="flex justify-center gap-3 mb-8" onPaste={handleMfaPaste}>
 {mfaCode.map((digit, i) => (
 <input
 key={i}
 ref={(el) => { mfaInputRefs.current[i] = el; }}
 type="text"
 inputMode="numeric"
 maxLength={1}
 value={digit}
 onChange={(e) => handleMfaDigitChange(i, e.target.value)}
 onKeyDown={(e) => handleMfaKeyDown(i, e)}
 className="w-12 h-14 text-center text-2xl font-bold rounded-xl border border-border-DEFAULT bg-surface-page text-tx-primary focus:border-accent-primary focus:ring-2 focus:ring-accent-primary/20 outline-none transition-all"
 />
 ))}
 </div>

 <div className="flex gap-3">
 <button
 type="button"
 onClick={() => resetMfaState()}
 className="flex-1 rounded-element border border-border-DEFAULT px-4 py-3 text-sm font-semibold text-tx-secondary hover:bg-surface-page transition-colors"
 >
 Voltar
 </button>
 <button
 type="button"
 onClick={handleMfaSubmit}
 disabled={mfaCode.join('').length !== 6 || createWithdrawalMutation.isPending}
 className="flex-1 btn-primary py-2.5 disabled:opacity-50"
 >
 {createWithdrawalMutation.isPending ? 'Verificando...' : 'Confirmar Saque'}
 </button>
 </div>
 </div>
 ) : (
 /* ── Withdrawal Form Step ────────────────────── */
 <>
 <div className="border-b border-border-DEFAULT px-6 py-4">
 <Dialog.Title className="text-xl font-bold text-tx-primary">
 Solicitar Saque
 </Dialog.Title>
 <p className="text-sm text-tx-muted mt-1">
 Saldo disponível: <span className="font-semibold text-accent-primary">{formatCurrency(availableBalance)}</span>
 </p>
 </div>

 <form onSubmit={handleSubmit(onSubmit)} className="p-6">
 {/* Hidden field for method */}
 <input type="hidden" {...register('method')} />

 
 <Tab.Group>
 <Tab.List className="flex gap-2 mb-6 p-1 bg-surface-hover rounded-xl">
 <Tab as={Fragment}>
 {({ selected }) => (
 <button
 type="button"
 onClick={() => setValue('method', 'pix')}
 className={clsx(
 'flex-1 py-3 px-4 rounded-lg font-medium text-sm transition-all flex items-center justify-center gap-2',
 selected
 ? 'bg-surface-card text-accent-primary'
 : 'text-tx-secondary hover:text-tx-primary'
 )}
 >
 <PixIcon className="h-5 w-5" />
 PIX {(settings?.standard_withdrawal_fee_pix ?? 0) === 0 ? '(Grátis)' : `(${formatCurrency(settings?.standard_withdrawal_fee_pix ?? 0)})`}
 </button>
 )}
 </Tab>
 <Tab as={Fragment}>
 {({ selected }) => (
 <button
 type="button"
 onClick={() => setValue('method', 'bank_transfer')}
 className={clsx(
 'flex-1 py-3 px-4 rounded-lg font-medium text-sm transition-all flex items-center justify-center gap-2',
 selected
 ? 'bg-surface-card text-accent-primary'
 : 'text-tx-secondary hover:text-tx-primary'
 )}
 >
 <BuildingLibraryIcon className="h-5 w-5" />
 Transferência ({settings?.gateway_fee_percentage ?? 5}%{(settings?.gateway_fee_fixed ?? 0) > 0 ? ` + ${formatCurrency((settings?.gateway_fee_fixed ?? 0) * 100)}` : ''})
 </button>
 )}
 </Tab>
 </Tab.List>

 <Tab.Panels>
 {/* PIX Panel */}
 <Tab.Panel className="space-y-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Valor do Saque (R$)
 </label>
 <input
 type="number"
 step="0.01"
 min={minWithdrawal / 100}
 max={availableBalance / 100}
 {...register('amount', { 
 required: 'Valor é obrigatório',
 valueAsNumber: true,
 min: { value: minWithdrawal / 100, message: `Valor mínimo é ${formatCurrency(minWithdrawal)}` },
 max: { value: availableBalance / 100, message: 'Saldo insuficiente' }
 })}
 className="input"
 placeholder="0,00"
 />
 {errors.amount && (
 <p className="text-status-error text-xs mt-1">{errors.amount.message}</p>
 )}
 <div className="mt-2 flex flex-wrap gap-2">
 {quickWithdrawalPercents.map((percent) => (
 <button
 key={`pix-${percent}`}
 type="button"
 onClick={() => setQuickAmount(percent)}
 className="rounded-md border border-border-DEFAULT px-2.5 py-1 text-xs font-semibold text-tx-secondary transition hover:bg-surface-hover"
 >
 {percent}% do saldo
 </button>
 ))}
 </div>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Tipo da Chave PIX
 </label>
 <select
 {...register('pix_type')}
 className="input"
 >
 <option value="cpf">CPF</option>
 <option value="cnpj">CNPJ</option>
 <option value="email">E-mail</option>
 <option value="phone">Telefone</option>
 <option value="random">Chave Aleatória</option>
 </select>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Chave PIX
 </label>
 <input
 type="text"
 {...register('pix_key', { required: withdrawMethod === 'pix' ? 'Chave PIX é obrigatória' : false })}
 className="input"
 placeholder="Digite sua chave PIX"
 />
 {errors.pix_key && (
 <p className="text-status-error text-xs mt-1">{errors.pix_key.message}</p>
 )}
 </div>

 {/* Summary */}
 {safeAmount > 0 && (
 <div className="rounded-lg bg-surface-page p-4 space-y-2">
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">Valor solicitado:</span>
 <span className="font-semibold text-tx-primary">
 {formatCurrency(safeAmount * 100)}
 </span>
 </div>
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">Taxa PIX:</span>
 <span className={clsx('font-semibold', fees.baseFee > 0 ? 'text-status-error' : 'text-status-success')}>
 {fees.baseFee > 0 ? `- ${formatCurrency(fees.baseFee)}` : 'Grátis'}
 </span>
 </div>
 {fees.withdrawalFee > 0 && (
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">
 Taxa de Saque ({settings?.instant_withdrawal_fee_type === 'percentage' ? `${settings?.instant_withdrawal_fee_value}%` : 'fixa'}):
 </span>
 <span className="font-semibold text-status-error">
 - {formatCurrency(fees.withdrawalFee)}
 </span>
 </div>
 )}
 <div className="border-t border-border-DEFAULT pt-2 flex justify-between">
 <span className="text-sm font-semibold text-tx-primary">Você receberá:</span>
 <span className={clsx('text-lg font-bold', netAmount > 0 ? 'text-accent-primary' : 'text-status-error')}>
 {formatCurrency(Math.max(0, netAmount))}
 </span>
 </div>
 <div className="flex justify-between text-xs text-tx-muted">
 <span>Previsao:</span>
 <span>Alguns minutos apos aprovacao</span>
 </div>
 </div>
 )}
 </Tab.Panel>

 {/* Bank Transfer Panel */}
 <Tab.Panel className="space-y-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Valor do Saque (R$)
 </label>
 <input
 type="number"
 step="0.01"
 min={minWithdrawal / 100}
 max={availableBalance / 100}
 {...register('amount', { 
 required: 'Valor é obrigatório',
 valueAsNumber: true,
 min: { value: minWithdrawal / 100, message: `Valor mínimo é ${formatCurrency(minWithdrawal)}` },
 max: { value: availableBalance / 100, message: 'Saldo insuficiente' }
 })}
 className="input"
 placeholder="0,00"
 />
 {errors.amount && (
 <p className="text-status-error text-xs mt-1">{errors.amount.message}</p>
 )}
 <div className="mt-2 flex flex-wrap gap-2">
 {quickWithdrawalPercents.map((percent) => (
 <button
 key={`bank-${percent}`}
 type="button"
 onClick={() => setQuickAmount(percent)}
 className="rounded-md border border-border-DEFAULT px-2.5 py-1 text-xs font-semibold text-tx-secondary transition hover:bg-surface-hover"
 >
 {percent}% do saldo
 </button>
 ))}
 </div>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Banco
 </label>
 <select {...register('bank_code', { required: withdrawMethod === 'bank_transfer' ? 'Selecione um banco' : false })} className="input">
 <option value="">Selecione...</option>
 {banks.map(bank => (
 <option key={bank.code} value={bank.code}>
 {bank.code} - {bank.name}
 </option>
 ))}
 </select>
 {errors.bank_code && (
 <p className="text-status-error text-xs mt-1">{errors.bank_code.message}</p>
 )}
 </div>

 <div className="grid grid-cols-2 gap-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Agência
 </label>
 <input
 type="text"
 {...register('agency', { required: withdrawMethod === 'bank_transfer' ? 'Agência é obrigatória' : false })}
 className="input"
 placeholder="0000"
 />
 {errors.agency && (
 <p className="text-status-error text-xs mt-1">{errors.agency.message}</p>
 )}
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Tipo de Conta
 </label>
 <select {...register('account_type')} className="input">
 <option value="checking">Corrente</option>
 <option value="savings">Poupança</option>
 </select>
 </div>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Número da Conta
 </label>
 <input
 type="text"
 {...register('account_number', { required: withdrawMethod === 'bank_transfer' ? 'Conta é obrigatória' : false })}
 className="input"
 placeholder="00000-0"
 />
 {errors.account_number && (
 <p className="text-status-error text-xs mt-1">{errors.account_number.message}</p>
 )}
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Nome do Titular
 </label>
 <input
 type="text"
 {...register('holder_name', { required: withdrawMethod === 'bank_transfer' ? 'Nome é obrigatório' : false })}
 className="input"
 placeholder="Nome completo"
 />
 {errors.holder_name && (
 <p className="text-status-error text-xs mt-1">{errors.holder_name.message}</p>
 )}
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 CPF/CNPJ do Titular
 </label>
 <input
 type="text"
 {...register('holder_document', { required: withdrawMethod === 'bank_transfer' ? 'CPF/CNPJ é obrigatório' : false })}
 className="input"
 placeholder="000.000.000-00"
 />
 {errors.holder_document && (
 <p className="text-status-error text-xs mt-1">{errors.holder_document.message}</p>
 )}
 </div>

 {/* Summary */}
 {safeAmount > 0 && (
 <div className="rounded-lg bg-surface-page p-4 space-y-2">
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">Valor solicitado:</span>
 <span className="font-semibold text-tx-primary">
 {formatCurrency(safeAmount * 100)}
 </span>
 </div>
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">Taxa TED:</span>
 <span className="font-semibold text-status-error">
 - {formatCurrency(fees.baseFee)}
 </span>
 </div>
 {fees.withdrawalFee > 0 && (
 <div className="flex justify-between text-sm">
 <span className="text-tx-secondary">
 Taxa de Saque ({settings?.instant_withdrawal_fee_type === 'percentage' ? `${settings?.instant_withdrawal_fee_value}%` : 'fixa'}):
 </span>
 <span className="font-semibold text-status-error">
 - {formatCurrency(fees.withdrawalFee)}
 </span>
 </div>
 )}
 <div className="border-t border-border-DEFAULT pt-2 flex justify-between">
 <span className="text-sm font-semibold text-tx-primary">Você receberá:</span>
 <span className={clsx('text-lg font-bold', netAmount > 0 ? 'text-accent-primary' : 'text-status-error')}>
 {formatCurrency(Math.max(0, netAmount))}
 </span>
 </div>
 <div className="flex justify-between text-xs text-tx-muted">
 <span>Previsao:</span>
 <span>Ate 1 dia util</span>
 </div>
 </div>
 )}

 <div className="rounded-lg bg-status-warning-subtle border border-yellow-200 p-3">
 <div className="flex gap-2">
 <ExclamationTriangleIcon className="h-5 w-5 text-status-warning flex-shrink-0" />
 <p className="text-xs text-status-warning">
 Saques via TED podem levar até 1 dia útil para serem processados.
 </p>
 </div>
 </div>
 </Tab.Panel>
 </Tab.Panels>
 </Tab.Group>

 {/* Actions */}
 <div className="flex gap-3 mt-6 pt-6 border-t border-border-DEFAULT">
 <button
 type="button"
 onClick={() => {
 setIsWithdrawModalOpen(false);
 resetMfaState();
 reset();
 }}
 className="flex-1 rounded-element border border-border-DEFAULT px-4 py-3 text-sm font-semibold text-tx-secondary hover:bg-surface-page transition-colors"
 >
 Cancelar
 </button>
 <button
 type="submit"
 disabled={createWithdrawalMutation.isPending}
 className="flex-1 btn-primary py-2.5"
 >
 Continuar
 </button>
 </div>
 </form>
 </>
 )}
 </Dialog.Panel>
 </Transition.Child>
 </div>
 </div>
 </Dialog>
 </Transition>
 </div>
 );
}
