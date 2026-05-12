import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  BanknotesIcon,
  BoltIcon,
  CheckCircleIcon,
  ShieldCheckIcon,
  ShieldExclamationIcon,
  ClockIcon,
  CpuChipIcon,
  ScaleIcon,
  FunnelIcon,
  NoSymbolIcon,
  PlayIcon,
  UserGroupIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface WrappedResponse<T> {
  success: boolean;
  data: T;
}

interface AdminPulse {
  health_score: number;
  state: 'healthy' | 'attention' | 'critical';
  queues: {
    pending_withdrawals: number;
    pending_withdrawals_amount: number;
    pending_kyc: number;
  };
  switches: {
    payments_enabled: boolean;
    withdrawals_enabled: boolean;
    withdrawal_processing_enabled: boolean;
    auto_withdrawal_enabled: boolean;
  };
  critical_alerts: string[];
  updated_at: string;
}

interface AdminStats {
  total_revenue: number;
  gateway_profit: number;
  seller_payout: number;
  pending_amount: number;
  transactions_today: number;
  volume_today: number;
  success_rate: number;
}

interface WithdrawalConfig {
  payments_enabled: boolean;
  withdrawals_enabled: boolean;
  withdrawal_processing_enabled: boolean;
  auto_withdrawal_enabled: boolean;
  daily_withdrawal_limit: number;
  large_withdrawal_threshold: number;
  withdrawal_cooldown_seconds: number;
  max_pending_withdrawals_per_account: number;
  auto_approve_limit: number;
  holdback_percentage: number;
}

interface FraudDashboard {
  total_analyzed: number;
  high_risk_count: number;
  blocked_count: number;
  average_score: number;
  active_blacklist_entries: number;
}

interface SecurityStats {
  threats_today: number;
  threats_week: number;
  blocked_ips: number;
  blocked_emails: number;
  fraud_prevented_amount: number;
}

interface ReconciliationDashboard {
  total_reconciliations: number;
  with_discrepancies: number;
  total_revenue: number;
  total_fees: number;
  total_refunds: number;
  total_chargebacks: number;
  latest?: {
    reconciled_at?: string;
  };
}

interface ControlTowerSlaBucket {
  acquirer: string;
  route: string;
  payment_method?: string;
  total_transactions: number;
  approved_transactions: number;
  approval_rate: number;
  fallback_count: number;
  fallback_rate: number;
  avg_latency_ms: number | null;
  p95_latency_ms: number | null;
  sla_state: 'healthy' | 'attention' | 'critical';
}

interface ControlTowerSlaResponse {
  period: '24h' | '7d' | '30d' | '90d';
  window_start: string;
  generated_at: string;
  overall: {
    total_transactions: number;
    approval_rate: number;
    fallback_rate: number;
  };
  acquirers: ControlTowerSlaBucket[];
  routes: ControlTowerSlaBucket[];
}

interface RiskMatrixRow {
  user_id: string;
  account_id: string | null;
  seller_name: string;
  seller_email: string;
  status: 'active' | 'suspended' | 'blocked';
  kyc_status: string;
  account_name: string | null;
  account_status: string | null;
  risk_score: number;
  risk_level: 'low' | 'medium' | 'high';
  risk_signals: string[];
  recommended_actions: string[];
  metrics: {
    total_charges: number;
    approved_charges: number;
    failed_charges: number;
    approved_volume: number;
    approval_rate: number;
    chargebacks: number;
    active_chargebacks: number;
    chargeback_rate: number;
    pending_withdrawals: number;
    pending_withdrawals_amount: number;
    available_balance: number;
  };
  created_at?: string;
}

interface RiskMatrixResponse {
  success: boolean;
  data: RiskMatrixRow[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
  summary: {
    high: number;
    medium: number;
    low: number;
  };
}

const DEFAULT_CONTROLS: WithdrawalConfig = {
  payments_enabled: true,
  withdrawals_enabled: true,
  withdrawal_processing_enabled: true,
  auto_withdrawal_enabled: false,
  daily_withdrawal_limit: 5000000,
  large_withdrawal_threshold: 1000000,
  withdrawal_cooldown_seconds: 60,
  max_pending_withdrawals_per_account: 10,
  auto_approve_limit: 0,
  holdback_percentage: 0,
};

export default function AdminControlTower() {
  const queryClient = useQueryClient();
  const [draftControls, setDraftControls] = useState<WithdrawalConfig>(DEFAULT_CONTROLS);
  const [slaPeriod, setSlaPeriod] = useState<'24h' | '7d' | '30d' | '90d'>('30d');
  const [riskLevelFilter, setRiskLevelFilter] = useState<'all' | 'high' | 'medium' | 'low'>('all');
  const [selectedRiskUsers, setSelectedRiskUsers] = useState<string[]>([]);

  const pulseQuery = useQuery<AdminPulse>({
    queryKey: ['admin-control-tower-pulse'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<AdminPulse>>('/admin/pulse');
      return response.data?.data;
    },
    refetchInterval: 20000,
  });

  const statsQuery = useQuery<AdminStats>({
    queryKey: ['admin-control-tower-stats'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<AdminStats>>('/admin/stats?period=month');
      return response.data?.data;
    },
    refetchInterval: 45000,
  });

  const controlsQuery = useQuery<WithdrawalConfig>({
    queryKey: ['admin-control-tower-controls'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<WithdrawalConfig>>('/admin/withdrawals/config');
      return response.data?.data;
    },
    refetchInterval: 45000,
  });

  const fraudQuery = useQuery<FraudDashboard>({
    queryKey: ['admin-control-tower-fraud'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<FraudDashboard>>('/admin/fraud/dashboard?period=30d');
      return response.data?.data;
    },
    refetchInterval: 60000,
  });

  const securityQuery = useQuery<SecurityStats>({
    queryKey: ['admin-control-tower-security'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<SecurityStats>>('/admin/security/stats');
      return response.data?.data;
    },
    refetchInterval: 60000,
  });

  const reconciliationQuery = useQuery<ReconciliationDashboard>({
    queryKey: ['admin-control-tower-reconciliation'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<ReconciliationDashboard>>('/admin/reconciliation/dashboard?period=30d');
      return response.data?.data;
    },
    refetchInterval: 90000,
  });

  const slaQuery = useQuery<ControlTowerSlaResponse>({
    queryKey: ['admin-control-tower-sla', slaPeriod],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<ControlTowerSlaResponse>>(`/admin/control-tower/sla?period=${slaPeriod}`);
      return response.data?.data;
    },
    refetchInterval: 45000,
  });

  const riskMatrixQuery = useQuery<RiskMatrixResponse>({
    queryKey: ['admin-control-tower-risk-matrix', riskLevelFilter],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('period', '30d');
      params.append('per_page', '12');
      if (riskLevelFilter !== 'all') {
        params.append('risk_level', riskLevelFilter);
      }

      const response = await api.get<RiskMatrixResponse>(`/admin/control-tower/risk-matrix?${params.toString()}`);
      return response.data;
    },
    refetchInterval: 60000,
  });

  useEffect(() => {
    if (controlsQuery.data) {
      setDraftControls((prev) => ({ ...prev, ...controlsQuery.data }));
    }
  }, [controlsQuery.data]);

  const saveControlsMutation = useMutation({
    mutationFn: async (payload: Partial<WithdrawalConfig>) => {
      const response = await api.put('/admin/withdrawals/config', payload);
      return response.data;
    },
    onSuccess: () => {
      toast.success('Controles operacionais atualizados');
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-controls'] });
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-pulse'] });
      queryClient.invalidateQueries({ queryKey: ['admin-pulse'] });
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard-pulse-v2'] });
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard-controls-v2'] });
    },
    onError: () => {
      toast.error('Falha ao atualizar controles');
    },
  });

  const bulkRiskActionMutation = useMutation({
    mutationFn: async (payload: {
      action: 'suspend' | 'activate' | 'recalculate_balance' | 'request_kyc_review';
      user_ids: string[];
      reason?: string;
    }) => {
      const response = await api.post('/admin/control-tower/risk-actions/bulk', payload);
      return response.data;
    },
    onSuccess: (response) => {
      const processed = Number(response?.data?.processed ?? 0);
      toast.success(`${processed.toLocaleString('pt-BR')} sellers atualizados`);
      setSelectedRiskUsers([]);
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-risk-matrix'] });
      queryClient.invalidateQueries({ queryKey: ['admin-customers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-pulse'] });
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-controls'] });
    },
    onError: () => {
      toast.error('Falha ao executar acao em massa');
    },
  });

  const playbookMutation = useMutation({
    mutationFn: async (payload: {
      playbook: 'freeze_withdrawals' | 'freeze_all_money_flows' | 'manual_fallback' | 'recovery_safe' | 'recovery_full';
      note?: string;
    }) => {
      const response = await api.post('/admin/control-tower/playbooks/execute', payload);
      return response.data;
    },
    onSuccess: (response) => {
      toast.success(response?.message ?? 'Playbook executado com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-pulse'] });
      queryClient.invalidateQueries({ queryKey: ['admin-control-tower-controls'] });
      queryClient.invalidateQueries({ queryKey: ['admin-pulse'] });
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard-pulse-v2'] });
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard-controls-v2'] });
    },
    onError: () => {
      toast.error('Falha ao executar playbook');
    },
  });

  const pulse = pulseQuery.data;
  const stats = statsQuery.data;
  const fraud = fraudQuery.data;
  const security = securityQuery.data;
  const reconciliation = reconciliationQuery.data;
  const sla = slaQuery.data;
  const riskRows = riskMatrixQuery.data?.data ?? [];
  const riskSummary = riskMatrixQuery.data?.summary ?? { high: 0, medium: 0, low: 0 };

  useEffect(() => {
    if (!riskMatrixQuery.data?.data) {
      return;
    }

    const visibleIds = new Set(riskMatrixQuery.data.data.map((row) => row.user_id));
    setSelectedRiskUsers((previous) => previous.filter((userId) => visibleIds.has(userId)));
  }, [riskMatrixQuery.data?.data]);

  const healthTone = useMemo(() => {
    if (pulse?.state === 'critical') return 'border-red-400/30 bg-red-500/10 text-red-300';
    if (pulse?.state === 'attention') return 'border-amber-400/30 bg-amber-500/10 text-amber-300';
    return 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300';
  }, [pulse?.state]);

  const liquidityBuffer = useMemo(() => {
    if (!stats) return 0;
    return Math.max(0, stats.total_revenue - stats.seller_payout - (pulse?.queues.pending_withdrawals_amount ?? 0));
  }, [pulse?.queues.pending_withdrawals_amount, stats]);

  const setBooleanControl = (key: keyof WithdrawalConfig) => {
    setDraftControls((prev) => ({
      ...prev,
      [key]: !Boolean(prev[key]),
    }));
  };

  const setNumericControl = (key: keyof WithdrawalConfig, value: string) => {
    const parsed = Number(value);
    setDraftControls((prev) => ({
      ...prev,
      [key]: Number.isFinite(parsed) ? parsed : 0,
    }));
  };

  const isAllRiskRowsSelected = riskRows.length > 0 && riskRows.every((row) => selectedRiskUsers.includes(row.user_id));

  const toggleRiskUser = (userId: string) => {
    setSelectedRiskUsers((previous) => {
      if (previous.includes(userId)) {
        return previous.filter((id) => id !== userId);
      }
      return [...previous, userId];
    });
  };

  const toggleSelectAllRiskRows = () => {
    if (isAllRiskRowsSelected) {
      setSelectedRiskUsers([]);
      return;
    }

    setSelectedRiskUsers(riskRows.map((row) => row.user_id));
  };

  const runRiskBulkAction = (action: 'suspend' | 'activate' | 'recalculate_balance' | 'request_kyc_review') => {
    if (!selectedRiskUsers.length) {
      toast.error('Selecione pelo menos um seller na matriz de risco');
      return;
    }

    let reason: string | undefined;
    if (action === 'suspend') {
      const typedReason = window.prompt('Motivo da suspensao em lote (opcional):', 'Revisao operacional preventiva');
      if (typedReason === null) {
        return;
      }
      reason = typedReason;
    }

    bulkRiskActionMutation.mutate({
      action,
      user_ids: selectedRiskUsers,
      reason,
    });
  };

  const runPlaybook = (
    playbook: 'freeze_withdrawals' | 'freeze_all_money_flows' | 'manual_fallback' | 'recovery_safe' | 'recovery_full',
  ) => {
    playbookMutation.mutate({ playbook });
  };

  const runRefresh = () => {
    pulseQuery.refetch();
    statsQuery.refetch();
    controlsQuery.refetch();
    fraudQuery.refetch();
    securityQuery.refetch();
    reconciliationQuery.refetch();
    slaQuery.refetch();
    riskMatrixQuery.refetch();
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={CpuChipIcon}
        title="Control Tower Fintech"
        subtitle="Centro de comando para operacao financeira, risco, compliance e resposta a incidentes"
        actions={
          <button
            type="button"
            onClick={runRefresh}
            className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20"
          >
            <ArrowPathIcon className={`h-4 w-4 ${pulseQuery.isFetching ? 'animate-spin' : ''}`} />
            Atualizar
          </button>
        }
      />

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          title="Saude operacional"
          value={`${pulse?.health_score ?? 0}%`}
          subtitle={pulse?.state === 'critical' ? 'Estado critico' : pulse?.state === 'attention' ? 'Em atencao' : 'Estavel'}
          icon={ShieldCheckIcon}
          tone={pulse?.state === 'critical' ? 'red' : pulse?.state === 'attention' ? 'amber' : 'emerald'}
        />
        <MetricCard
          title="Volume hoje"
          value={formatCurrency(stats?.volume_today ?? 0)}
          subtitle={`${(stats?.transactions_today ?? 0).toLocaleString('pt-BR')} transacoes`}
          icon={BanknotesIcon}
          tone="blue"
        />
        <MetricCard
          title="Buffer de liquidez"
          value={formatCurrency(liquidityBuffer)}
          subtitle="Receita - repasse - fila de saques"
          icon={BoltIcon}
          tone={liquidityBuffer <= 0 ? 'amber' : 'violet'}
        />
        <MetricCard
          title="Sucesso (30d)"
          value={`${(stats?.success_rate ?? 0).toFixed(1)}%`}
          subtitle="Taxa de aprovacao"
          icon={CheckCircleIcon}
          tone={(stats?.success_rate ?? 0) < 90 ? 'amber' : 'emerald'}
        />
      </section>

      <section className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2 rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Kill Switches e Guardrails</h3>
              <p className="text-xs text-tx-muted">Controle direto dos mecanismos criticos de operacao</p>
            </div>
            <span className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${healthTone}`}>
              Estado: {pulse?.state ?? 'unknown'}
            </span>
          </div>

          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            <ToggleControl
              label="Pagamentos ativos"
              enabled={Boolean(draftControls.payments_enabled)}
              onToggle={() => setBooleanControl('payments_enabled')}
            />
            <ToggleControl
              label="Novos saques ativos"
              enabled={Boolean(draftControls.withdrawals_enabled)}
              onToggle={() => setBooleanControl('withdrawals_enabled')}
            />
            <ToggleControl
              label="Processamento de saques"
              enabled={Boolean(draftControls.withdrawal_processing_enabled)}
              onToggle={() => setBooleanControl('withdrawal_processing_enabled')}
            />
            <ToggleControl
              label="Auto saque"
              enabled={Boolean(draftControls.auto_withdrawal_enabled)}
              onToggle={() => setBooleanControl('auto_withdrawal_enabled')}
            />
          </div>

          <div className="mt-5 grid gap-3 md:grid-cols-2">
            <NumberControl
              label="Limite diario por conta (centavos)"
              value={draftControls.daily_withdrawal_limit}
              onChange={(value) => setNumericControl('daily_withdrawal_limit', value)}
            />
            <NumberControl
              label="Limiar de saque grande (centavos)"
              value={draftControls.large_withdrawal_threshold}
              onChange={(value) => setNumericControl('large_withdrawal_threshold', value)}
            />
            <NumberControl
              label="Cooldown entre saques (segundos)"
              value={draftControls.withdrawal_cooldown_seconds}
              onChange={(value) => setNumericControl('withdrawal_cooldown_seconds', value)}
            />
            <NumberControl
              label="Maximo saques pendentes por conta"
              value={draftControls.max_pending_withdrawals_per_account}
              onChange={(value) => setNumericControl('max_pending_withdrawals_per_account', value)}
            />
            <NumberControl
              label="Auto aprovacao (centavos)"
              value={draftControls.auto_approve_limit}
              onChange={(value) => setNumericControl('auto_approve_limit', value)}
            />
            <NumberControl
              label="Holdback (%)"
              value={draftControls.holdback_percentage}
              onChange={(value) => setNumericControl('holdback_percentage', value)}
            />
          </div>

          <div className="mt-5 flex flex-wrap items-center gap-3">
            <button
              type="button"
              onClick={() => saveControlsMutation.mutate(draftControls)}
              disabled={saveControlsMutation.isPending}
              className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {saveControlsMutation.isPending ? 'Salvando...' : 'Salvar controles'}
            </button>
            <button
              type="button"
              onClick={() => setDraftControls(controlsQuery.data ?? DEFAULT_CONTROLS)}
              className="inline-flex items-center rounded-xl border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm font-semibold text-tx-primary hover:bg-surface-hover"
            >
              Reverter
            </button>
          </div>
        </div>

        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <h3 className="text-lg font-semibold text-tx-primary">Alertas criticos</h3>
          <p className="mt-1 text-xs text-tx-muted">Sinalizacao imediata para resposta operacional</p>

          <div className="mt-4 space-y-3">
            {pulse?.critical_alerts?.length ? (
              pulse.critical_alerts.map((alert, index) => (
                <div key={`${alert}-${index}`} className="rounded-xl border border-red-400/20 bg-red-500/5 p-3 text-sm text-red-300">
                  <div className="flex items-start gap-2">
                    <ShieldExclamationIcon className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{alert}</span>
                  </div>
                </div>
              ))
            ) : (
              <div className="rounded-xl border border-emerald-400/20 bg-emerald-500/5 p-3 text-sm text-emerald-300">
                Nenhum alerta critico ativo.
              </div>
            )}
          </div>

          <div className="mt-4 space-y-2 text-xs text-tx-secondary">
            <div className="flex items-center justify-between">
              <span>Ultima atualizacao</span>
              <span className="font-semibold text-tx-primary">{pulse?.updated_at ? formatDate(pulse.updated_at) : '-'}</span>
            </div>
            <div className="flex items-center justify-between">
              <span>Saques pendentes</span>
              <span className="font-semibold text-tx-primary">{pulse?.queues.pending_withdrawals ?? 0}</span>
            </div>
            <div className="flex items-center justify-between">
              <span>KYC pendente</span>
              <span className="font-semibold text-tx-primary">{pulse?.queues.pending_kyc ?? 0}</span>
            </div>
          </div>
        </div>
      </section>

      <section className="grid gap-6 xl:grid-cols-3">
        <InfoCard
          title="Risco e fraude"
          icon={FunnelIcon}
          lines={[
            `Analisadas: ${(fraud?.total_analyzed ?? 0).toLocaleString('pt-BR')}`,
            `Alto risco: ${(fraud?.high_risk_count ?? 0).toLocaleString('pt-BR')}`,
            `Declinadas: ${(fraud?.blocked_count ?? 0).toLocaleString('pt-BR')}`,
            `Score medio: ${(fraud?.average_score ?? 0).toFixed(1)}`,
            `Blacklist ativa: ${(fraud?.active_blacklist_entries ?? 0).toLocaleString('pt-BR')}`,
          ]}
          footerLink={{ href: '/admin/monitoring', label: 'Abrir monitoramento' }}
        />

        <InfoCard
          title="Reconciliacao"
          icon={ScaleIcon}
          lines={[
            `Execucoes: ${(reconciliation?.total_reconciliations ?? 0).toLocaleString('pt-BR')}`,
            `Com divergencia: ${(reconciliation?.with_discrepancies ?? 0).toLocaleString('pt-BR')}`,
            `Receita conciliada: ${formatCurrency(reconciliation?.total_revenue ?? 0)}`,
            `Taxas conciliadas: ${formatCurrency(reconciliation?.total_fees ?? 0)}`,
            `Chargebacks: ${formatCurrency(reconciliation?.total_chargebacks ?? 0)}`,
          ]}
          footerLink={{ href: '/admin/monitoring', label: 'Abrir monitoramento' }}
        />

        <InfoCard
          title="Seguranca operacional"
          icon={ShieldCheckIcon}
          lines={[
            `Ameacas hoje: ${(security?.threats_today ?? 0).toLocaleString('pt-BR')}`,
            `Ameacas 7d: ${(security?.threats_week ?? 0).toLocaleString('pt-BR')}`,
            `IPs bloqueados: ${(security?.blocked_ips ?? 0).toLocaleString('pt-BR')}`,
            `Emails bloqueados: ${(security?.blocked_emails ?? 0).toLocaleString('pt-BR')}`,
            `Fraude evitada: ${formatCurrency(security?.fraud_prevented_amount ?? 0)}`,
          ]}
          footerLink={{ href: '/admin/monitoring', label: 'Abrir monitoramento' }}
        />
      </section>

      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold text-tx-primary">SLA por adquirente e rota</h3>
            <p className="mt-1 text-xs text-tx-muted">Latencia, aprovacao por rota e sinais de fallback automatico</p>
          </div>

          <div className="flex items-center gap-2">
            <select
              value={slaPeriod}
              onChange={(event) => setSlaPeriod(event.target.value as '24h' | '7d' | '30d' | '90d')}
              className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary focus:border-blue-500 focus:outline-none"
            >
              <option value="24h">Ultimas 24h</option>
              <option value="7d">Ultimos 7 dias</option>
              <option value="30d">Ultimos 30 dias</option>
              <option value="90d">Ultimos 90 dias</option>
            </select>

            <button
              type="button"
              onClick={() => slaQuery.refetch()}
              className="inline-flex items-center gap-2 rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover"
            >
              <ArrowPathIcon className={`h-3.5 w-3.5 ${slaQuery.isFetching ? 'animate-spin' : ''}`} />
              Atualizar SLA
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <MiniStatCard
            label="Transacoes no periodo"
            value={(sla?.overall.total_transactions ?? 0).toLocaleString('pt-BR')}
            tone="blue"
          />
          <MiniStatCard
            label="Aprovacao global"
            value={`${(sla?.overall.approval_rate ?? 0).toFixed(1)}%`}
            tone={(sla?.overall.approval_rate ?? 0) < 90 ? 'amber' : 'emerald'}
          />
          <MiniStatCard
            label="Fallback global"
            value={`${(sla?.overall.fallback_rate ?? 0).toFixed(1)}%`}
            tone={(sla?.overall.fallback_rate ?? 0) > 10 ? 'red' : 'blue'}
          />
        </div>

        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[860px]">
            <thead>
              <tr className="border-b border-border-DEFAULT text-left text-xs uppercase tracking-wide text-tx-muted">
                <th className="px-2 py-3">Adquirente</th>
                <th className="px-2 py-3">Rota</th>
                <th className="px-2 py-3">Aprovacao</th>
                <th className="px-2 py-3">P95</th>
                <th className="px-2 py-3">Fallback</th>
                <th className="px-2 py-3">Volume</th>
                <th className="px-2 py-3">SLA</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT text-sm">
              {sla?.routes?.length ? (
                sla.routes.slice(0, 12).map((route) => (
                  <tr key={`${route.acquirer}-${route.payment_method ?? 'all'}`} className="hover:bg-surface-hover">
                    <td className="px-2 py-3 font-semibold text-tx-primary">{route.acquirer.toUpperCase()}</td>
                    <td className="px-2 py-3 text-tx-secondary">{(route.payment_method ?? 'all').toUpperCase()}</td>
                    <td className="px-2 py-3 text-tx-primary">{route.approval_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-tx-secondary">{formatLatency(route.p95_latency_ms)}</td>
                    <td className="px-2 py-3 text-tx-secondary">{route.fallback_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-tx-primary">{route.total_transactions.toLocaleString('pt-BR')}</td>
                    <td className="px-2 py-3">
                      <SlaStatePill state={route.sla_state} />
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={7} className="px-2 py-6 text-center text-sm text-tx-muted">
                    Sem dados de SLA para o periodo selecionado.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-start gap-2">
            <UserGroupIcon className="mt-0.5 h-5 w-5 text-blue-500" />
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Matriz de risco por seller</h3>
              <p className="text-xs text-tx-muted">Score consolidado com acoes em massa para operacao e antifraude</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <select
              value={riskLevelFilter}
              onChange={(event) => setRiskLevelFilter(event.target.value as 'all' | 'high' | 'medium' | 'low')}
              className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary focus:border-blue-500 focus:outline-none"
            >
              <option value="all">Todos os riscos</option>
              <option value="high">Alto risco</option>
              <option value="medium">Risco medio</option>
              <option value="low">Risco baixo</option>
            </select>

            <button
              type="button"
              onClick={() => riskMatrixQuery.refetch()}
              className="inline-flex items-center gap-2 rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover"
            >
              <ArrowPathIcon className={`h-3.5 w-3.5 ${riskMatrixQuery.isFetching ? 'animate-spin' : ''}`} />
              Atualizar matriz
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <MiniStatCard label="Risco alto" value={riskSummary.high.toLocaleString('pt-BR')} tone="red" />
          <MiniStatCard label="Risco medio" value={riskSummary.medium.toLocaleString('pt-BR')} tone="amber" />
          <MiniStatCard label="Risco baixo" value={riskSummary.low.toLocaleString('pt-BR')} tone="emerald" />
        </div>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={toggleSelectAllRiskRows}
            className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover"
          >
            {isAllRiskRowsSelected ? 'Limpar selecao' : 'Selecionar pagina'}
          </button>
          <button
            type="button"
            onClick={() => runRiskBulkAction('suspend')}
            disabled={!selectedRiskUsers.length || bulkRiskActionMutation.isPending}
            className="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Suspender
          </button>
          <button
            type="button"
            onClick={() => runRiskBulkAction('activate')}
            disabled={!selectedRiskUsers.length || bulkRiskActionMutation.isPending}
            className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Reativar
          </button>
          <button
            type="button"
            onClick={() => runRiskBulkAction('recalculate_balance')}
            disabled={!selectedRiskUsers.length || bulkRiskActionMutation.isPending}
            className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
          >
            Recalcular saldo
          </button>
          <button
            type="button"
            onClick={() => runRiskBulkAction('request_kyc_review')}
            disabled={!selectedRiskUsers.length || bulkRiskActionMutation.isPending}
            className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
          >
            Enviar para KYC review
          </button>

          <span className="ml-auto rounded-full bg-surface-page px-3 py-1 text-xs font-semibold text-tx-secondary">
            {selectedRiskUsers.length.toLocaleString('pt-BR')} selecionados
          </span>
        </div>

        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[980px]">
            <thead>
              <tr className="border-b border-border-DEFAULT text-left text-xs uppercase tracking-wide text-tx-muted">
                <th className="px-2 py-3">
                  <input
                    type="checkbox"
                    checked={isAllRiskRowsSelected}
                    onChange={toggleSelectAllRiskRows}
                    className="h-4 w-4 rounded border-border-DEFAULT bg-surface-page text-blue-600"
                  />
                </th>
                <th className="px-2 py-3">Seller</th>
                <th className="px-2 py-3">Risco</th>
                <th className="px-2 py-3">Sinais</th>
                <th className="px-2 py-3">Aprovacao</th>
                <th className="px-2 py-3">Chargeback</th>
                <th className="px-2 py-3">Saques pendentes</th>
                <th className="px-2 py-3">Acoes</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT text-sm">
              {riskRows.length ? (
                riskRows.map((seller) => (
                  <tr key={seller.user_id} className="hover:bg-surface-hover">
                    <td className="px-2 py-3">
                      <input
                        type="checkbox"
                        checked={selectedRiskUsers.includes(seller.user_id)}
                        onChange={() => toggleRiskUser(seller.user_id)}
                        className="h-4 w-4 rounded border-border-DEFAULT bg-surface-page text-blue-600"
                      />
                    </td>
                    <td className="px-2 py-3">
                      <p className="font-semibold text-tx-primary">{seller.seller_name}</p>
                      <p className="text-xs text-tx-muted">{seller.seller_email}</p>
                    </td>
                    <td className="px-2 py-3">
                      <div className="flex items-center gap-2">
                        <RiskLevelPill level={seller.risk_level} />
                        <span className="text-xs font-semibold text-tx-primary">{seller.risk_score}</span>
                      </div>
                    </td>
                    <td className="px-2 py-3 text-xs text-tx-secondary">
                      {seller.risk_signals.slice(0, 2).join(' • ') || 'Sem sinal forte'}
                    </td>
                    <td className="px-2 py-3 text-tx-primary">{seller.metrics.approval_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-tx-secondary">{seller.metrics.chargeback_rate.toFixed(2)}%</td>
                    <td className="px-2 py-3 text-tx-secondary">
                      {seller.metrics.pending_withdrawals.toLocaleString('pt-BR')} ({formatCurrency(seller.metrics.pending_withdrawals_amount)})
                    </td>
                    <td className="px-2 py-3">
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/admin/sellers/${seller.user_id}`}
                          className="rounded-md border border-border-DEFAULT bg-surface-page px-2.5 py-1 text-xs font-semibold text-tx-primary hover:bg-surface-hover"
                        >
                          Abrir
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={8} className="px-2 py-6 text-center text-sm text-tx-muted">
                    Nenhum seller encontrado para o filtro de risco atual.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold text-tx-primary">Modo incidente e playbooks executaveis</h3>
            <p className="mt-1 text-xs text-tx-muted">Execute respostas operacionais sem sair da Control Tower</p>
          </div>

          <span className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold ${healthTone}`}>
            <NoSymbolIcon className="h-3.5 w-3.5" />
            {pulse?.state === 'critical' ? 'Modo incidente recomendado' : 'Modo monitoramento'}
          </span>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <PlaybookCard
            title="Conter saques"
            subtitle="Pausa novos saques e processamento"
            tone="amber"
            running={playbookMutation.isPending}
            onRun={() => runPlaybook('freeze_withdrawals')}
          />
          <PlaybookCard
            title="Congelar fluxos"
            subtitle="Pausa pagamentos e saques"
            tone="red"
            running={playbookMutation.isPending}
            onRun={() => runPlaybook('freeze_all_money_flows')}
          />
          <PlaybookCard
            title="Fallback manual"
            subtitle="Mantem sistema ativo com aprovacao manual"
            tone="blue"
            running={playbookMutation.isPending}
            onRun={() => runPlaybook('manual_fallback')}
          />
          <PlaybookCard
            title="Recovery safe"
            subtitle="Retoma operacao com auto saque desligado"
            tone="violet"
            running={playbookMutation.isPending}
            onRun={() => runPlaybook('recovery_safe')}
          />
          <PlaybookCard
            title="Recovery full"
            subtitle="Reativa todos os switches principais"
            tone="emerald"
            running={playbookMutation.isPending}
            onRun={() => runPlaybook('recovery_full')}
          />
        </div>

        <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <QuickLink
            href="/admin/finance"
            title="Fila de saques"
            subtitle={`${pulse?.queues.pending_withdrawals ?? 0} pendentes`}
            tone={(pulse?.queues.pending_withdrawals ?? 0) > 10 ? 'amber' : 'blue'}
          />
          <QuickLink
            href="/admin/kyc"
            title="Compliance KYC"
            subtitle={`${pulse?.queues.pending_kyc ?? 0} em revisao`}
            tone={(pulse?.queues.pending_kyc ?? 0) > 20 ? 'amber' : 'emerald'}
          />
          <QuickLink
            href="/admin/acquirers"
            title="Roteamento adquirentes"
            subtitle={`${(stats?.success_rate ?? 0).toFixed(1)}% sucesso`}
            tone={(stats?.success_rate ?? 0) < 90 ? 'amber' : 'blue'}
          />
        </div>
      </section>
    </AdminPageShell>
  );
}

function MetricCard({
  title,
  value,
  subtitle,
  icon: Icon,
  tone,
}: {
  title: string;
  value: string;
  subtitle: string;
  icon: typeof ShieldCheckIcon;
  tone: 'blue' | 'emerald' | 'violet' | 'amber' | 'red';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    violet: 'bg-green-500/10 text-green-500',
    amber: 'bg-amber-500/10 text-amber-500',
    red: 'bg-red-500/10 text-red-500',
  };

  return (
    <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-5 shadow-sm">
      <div className="flex items-center gap-3">
        <div className={`rounded-xl p-2.5 ${toneClasses[tone]}`}>
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-tx-muted">{title}</p>
          <p className="text-xl font-semibold text-tx-primary">{value}</p>
          <p className="text-xs text-tx-muted">{subtitle}</p>
        </div>
      </div>
    </div>
  );
}

function ToggleControl({
  label,
  enabled,
  onToggle,
}: {
  label: string;
  enabled: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      className="flex items-center justify-between rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-left hover:bg-surface-hover"
    >
      <span className="text-sm text-tx-secondary">{label}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${enabled ? 'bg-emerald-500/10 text-emerald-500' : 'bg-red-500/10 text-red-500'}`}>
        {enabled ? 'Ativo' : 'Pausado'}
      </span>
    </button>
  );
}

function NumberControl({
  label,
  value,
  onChange,
}: {
  label: string;
  value: number;
  onChange: (value: string) => void;
}) {
  return (
    <label className="space-y-1 rounded-xl border border-border-DEFAULT bg-surface-page p-3">
      <span className="block text-xs text-tx-muted">{label}</span>
      <input
        type="number"
        value={Number.isFinite(value) ? value : 0}
        onChange={(event) => onChange(event.target.value)}
        className="w-full rounded-lg border border-border-DEFAULT bg-surface-card px-3 py-2 text-sm text-tx-primary focus:border-blue-500 focus:outline-none"
      />
    </label>
  );
}

function MiniStatCard({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone: 'blue' | 'emerald' | 'amber' | 'red' | 'violet';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'border-blue-400/20 bg-blue-500/5 text-blue-500',
    emerald: 'border-emerald-400/20 bg-emerald-500/5 text-emerald-500',
    amber: 'border-amber-400/20 bg-amber-500/5 text-amber-500',
    red: 'border-red-400/20 bg-red-500/5 text-red-500',
    violet: 'border-green-400/20 bg-green-500/5 text-green-500',
  };

  return (
    <div className={`rounded-xl border p-3 ${toneClasses[tone]}`}>
      <p className="text-[11px] font-medium uppercase tracking-wide opacity-80">{label}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  );
}

function SlaStatePill({ state }: { state: 'healthy' | 'attention' | 'critical' }) {
  const toneClasses: Record<string, string> = {
    healthy: 'bg-emerald-500/10 text-emerald-500',
    attention: 'bg-amber-500/10 text-amber-500',
    critical: 'bg-red-500/10 text-red-500',
  };

  const labels: Record<string, string> = {
    healthy: 'Saudavel',
    attention: 'Atencao',
    critical: 'Critico',
  };

  return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${toneClasses[state]}`}>{labels[state]}</span>;
}

function RiskLevelPill({ level }: { level: 'low' | 'medium' | 'high' }) {
  const toneClasses: Record<string, string> = {
    low: 'bg-emerald-500/10 text-emerald-500',
    medium: 'bg-amber-500/10 text-amber-500',
    high: 'bg-red-500/10 text-red-500',
  };

  const labels: Record<string, string> = {
    low: 'Baixo',
    medium: 'Medio',
    high: 'Alto',
  };

  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${toneClasses[level]}`}>{labels[level]}</span>;
}

function PlaybookCard({
  title,
  subtitle,
  tone,
  running,
  onRun,
}: {
  title: string;
  subtitle: string;
  tone: 'blue' | 'emerald' | 'violet' | 'amber' | 'red';
  running: boolean;
  onRun: () => void;
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    violet: 'bg-green-500/10 text-green-500',
    amber: 'bg-amber-500/10 text-amber-500',
    red: 'bg-red-500/10 text-red-500',
  };

  return (
    <div className="rounded-xl border border-border-DEFAULT bg-surface-page p-3">
      <div className={`inline-flex rounded-md p-1.5 ${toneClasses[tone]}`}>
        <PlayIcon className="h-4 w-4" />
      </div>
      <p className="mt-2 text-sm font-semibold text-tx-primary">{title}</p>
      <p className="text-xs text-tx-muted">{subtitle}</p>

      <button
        type="button"
        onClick={onRun}
        disabled={running}
        className="mt-3 w-full rounded-lg border border-border-DEFAULT bg-surface-card px-3 py-2 text-xs font-semibold text-tx-primary hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
      >
        {running ? 'Executando...' : 'Executar playbook'}
      </button>
    </div>
  );
}

function formatLatency(latencyMs: number | null): string {
  if (latencyMs === null || !Number.isFinite(latencyMs)) {
    return '-';
  }

  if (latencyMs < 1000) {
    return `${Math.max(0, Math.round(latencyMs))} ms`;
  }

  const seconds = latencyMs / 1000;
  if (seconds < 60) {
    return `${seconds.toFixed(1)} s`;
  }

  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = Math.round(seconds % 60);
  return `${minutes}m ${remainingSeconds}s`;
}

function InfoCard({
  title,
  icon: Icon,
  lines,
  footerLink,
}: {
  title: string;
  icon: typeof ClockIcon;
  lines: string[];
  footerLink: { href: string; label: string };
}) {
  return (
    <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-5 shadow-sm">
      <div className="flex items-center gap-2">
        <div className="rounded-lg bg-blue-500/10 p-2">
          <Icon className="h-4 w-4 text-blue-500" />
        </div>
        <h4 className="text-sm font-semibold text-tx-primary">{title}</h4>
      </div>

      <div className="mt-3 space-y-1.5 text-xs text-tx-secondary">
        {lines.map((line, index) => (
          <p key={`${title}-${index}`}>{line}</p>
        ))}
      </div>

      <Link to={footerLink.href} className="mt-4 inline-flex text-xs font-semibold text-blue-500 hover:text-blue-400">
        {footerLink.label}
      </Link>
    </div>
  );
}

function QuickLink({
  href,
  title,
  subtitle,
  tone,
}: {
  href: string;
  title: string;
  subtitle: string;
  tone: 'blue' | 'emerald' | 'violet' | 'amber';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    violet: 'bg-green-500/10 text-green-500',
    amber: 'bg-amber-500/10 text-amber-500',
  };

  return (
    <Link to={href} className="rounded-xl border border-border-DEFAULT bg-surface-page p-3 transition-colors hover:bg-surface-hover">
      <div className={`inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold ${toneClasses[tone]}`}>Runbook</div>
      <p className="mt-2 text-sm font-semibold text-tx-primary">{title}</p>
      <p className="text-xs text-tx-muted">{subtitle}</p>
    </Link>
  );
}


