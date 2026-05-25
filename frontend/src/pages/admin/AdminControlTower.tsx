import { type ComponentType, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  BanknotesIcon,
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

type MonitoringPeriod = '1h' | '6h' | '24h' | '7d';
type MonitoringLogsStatus = 'all' | 'success' | 'error' | 'blocked';

interface MonitoringStoredFilters {
  period: MonitoringPeriod;
  search: string;
  logsStatus: MonitoringLogsStatus;
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

interface MonitoringPaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

interface MonitoringRequestRow {
  id: string;
  user_id: string | null;
  user_name: string | null;
  user_email: string | null;
  account_id: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  method: string;
  path: string;
  status_code: number | null;
  duration_ms: number | null;
  ip_address: string | null;
  created_at: string | null;
}

interface MonitoringLogRow {
  id: string;
  user_id: string | null;
  user_name: string | null;
  user_email: string | null;
  account_id: string | null;
  actor_role: string | null;
  endpoint: string;
  method: string;
  response_status: number | null;
  response_time_ms: number | null;
  ip_address: string | null;
  user_agent: string | null;
  correlation_id: string | null;
  security_flags: string[];
  accessed_at: string | null;
}

interface ControlTowerMonitoringResponse {
  period: MonitoringPeriod;
  window_start: string;
  generated_at: string;
  summary: {
    requests_total: number;
    requests_unique_users: number;
    requests_create_total: number;
    requests_update_total: number;
    requests_delete_total: number;
    logs_total: number;
    logs_errors: number;
    logs_blocked: number;
    logs_unique_users: number;
    logs_unique_ips: number;
    logs_avg_response_time_ms: number | null;
  };
  requests: {
    data: MonitoringRequestRow[];
    meta: MonitoringPaginationMeta;
  };
  logs: {
    data: MonitoringLogRow[];
    meta: MonitoringPaginationMeta;
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

const DEFAULT_MONITORING_META: MonitoringPaginationMeta = {
  current_page: 1,
  per_page: 12,
  total: 0,
  last_page: 1,
};

const MONITORING_FILTERS_STORAGE_KEY = 'admin-control-tower-monitoring-filters-v1';

function readMonitoringFiltersFromStorage(): MonitoringStoredFilters {
  const fallback: MonitoringStoredFilters = {
    period: '24h',
    search: '',
    logsStatus: 'all',
  };

  if (typeof window === 'undefined') {
    return fallback;
  }

  try {
    const rawValue = window.localStorage.getItem(MONITORING_FILTERS_STORAGE_KEY);
    if (!rawValue) {
      return fallback;
    }

    const parsedValue = JSON.parse(rawValue) as Partial<MonitoringStoredFilters>;

    const allowedPeriods: MonitoringPeriod[] = ['1h', '6h', '24h', '7d'];
    const allowedStatuses: MonitoringLogsStatus[] = ['all', 'success', 'error', 'blocked'];

    const period = allowedPeriods.includes(parsedValue.period as MonitoringPeriod)
      ? (parsedValue.period as MonitoringPeriod)
      : fallback.period;
    const logsStatus = allowedStatuses.includes(parsedValue.logsStatus as MonitoringLogsStatus)
      ? (parsedValue.logsStatus as MonitoringLogsStatus)
      : fallback.logsStatus;

    return {
      period,
      search: typeof parsedValue.search === 'string' ? parsedValue.search : fallback.search,
      logsStatus,
    };
  } catch {
    return fallback;
  }
}

function persistMonitoringFiltersToStorage(filters: MonitoringStoredFilters): void {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(MONITORING_FILTERS_STORAGE_KEY, JSON.stringify(filters));
  } catch {
    // Silent fallback: localStorage may be blocked by browser privacy mode.
  }
}

function csvEscape(value: unknown): string {
  if (value === null || value === undefined) {
    return '""';
  }

  const stringValue = Array.isArray(value)
    ? value.map((entry) => String(entry)).join(' | ')
    : String(value);

  return `"${stringValue.replace(/"/g, '""')}"`;
}

function downloadCsvFile(fileName: string, headers: string[], rows: unknown[][]): void {
  if (typeof window === 'undefined') {
    return;
  }

  const csvBody = [
    headers.map((header) => csvEscape(header)).join(';'),
    ...rows.map((row) => row.map((cell) => csvEscape(cell)).join(';')),
  ].join('\n');

  const csvWithBom = `\uFEFF${csvBody}`;
  const blob = new Blob([csvWithBom], { type: 'text/csv;charset=utf-8;' });
  const downloadUrl = window.URL.createObjectURL(blob);
  const link = window.document.createElement('a');

  link.href = downloadUrl;
  link.download = fileName;
  link.style.display = 'none';

  window.document.body.appendChild(link);
  link.click();
  window.document.body.removeChild(link);
  window.URL.revokeObjectURL(downloadUrl);
}

function buildExportTimestamp(): string {
  const now = new Date();
  const yyyy = now.getFullYear();
  const mm = String(now.getMonth() + 1).padStart(2, '0');
  const dd = String(now.getDate()).padStart(2, '0');
  const hh = String(now.getHours()).padStart(2, '0');
  const mi = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  return `${yyyy}${mm}${dd}-${hh}${mi}${ss}`;
}

export default function AdminControlTower() {
  const queryClient = useQueryClient();
  const [draftControls, setDraftControls] = useState<WithdrawalConfig>(DEFAULT_CONTROLS);
  const [slaPeriod, setSlaPeriod] = useState<'24h' | '7d' | '30d' | '90d'>('30d');
  const [riskLevelFilter, setRiskLevelFilter] = useState<'all' | 'high' | 'medium' | 'low'>('all');
  const [selectedRiskUsers, setSelectedRiskUsers] = useState<string[]>([]);
  const [monitoringPeriod, setMonitoringPeriod] = useState<MonitoringPeriod>(() => readMonitoringFiltersFromStorage().period);
  const [monitoringSearch, setMonitoringSearch] = useState(() => readMonitoringFiltersFromStorage().search);
  const [monitoringLogsStatus, setMonitoringLogsStatus] = useState<MonitoringLogsStatus>(
    () => readMonitoringFiltersFromStorage().logsStatus,
  );
  const [monitoringRequestsPage, setMonitoringRequestsPage] = useState(1);
  const [monitoringLogsPage, setMonitoringLogsPage] = useState(1);
  const [isExportingRequestsCsv, setIsExportingRequestsCsv] = useState(false);
  const [isExportingLogsCsv, setIsExportingLogsCsv] = useState(false);

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

  const monitoringSearchValue = monitoringSearch.trim();

  const monitoringQuery = useQuery<ControlTowerMonitoringResponse>({
    queryKey: [
      'admin-control-tower-monitoring-feed',
      monitoringPeriod,
      monitoringSearchValue,
      monitoringLogsStatus,
      monitoringRequestsPage,
      monitoringLogsPage,
    ],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('period', monitoringPeriod);
      params.append('requests_page', String(monitoringRequestsPage));
      params.append('logs_page', String(monitoringLogsPage));
      params.append('requests_per_page', '8');
      params.append('logs_per_page', '8');

      if (monitoringLogsStatus !== 'all') {
        params.append('logs_status', monitoringLogsStatus);
      }

      if (monitoringSearchValue) {
        params.append('search', monitoringSearchValue);
      }

      const response = await api.get<WrappedResponse<ControlTowerMonitoringResponse>>(
        `/admin/control-tower/monitoring-feed?${params.toString()}`,
      );
      return response.data?.data;
    },
    refetchInterval: 30000,
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
  const monitoring = monitoringQuery.data;
  const monitoringSummary = monitoring?.summary;
  const monitoringRequests = monitoring?.requests.data ?? [];
  const monitoringRequestsMeta = monitoring?.requests.meta ?? DEFAULT_MONITORING_META;
  const monitoringLogs = monitoring?.logs.data ?? [];
  const monitoringLogsMeta = monitoring?.logs.meta ?? DEFAULT_MONITORING_META;

  useEffect(() => {
    if (!riskMatrixQuery.data?.data) {
      return;
    }

    const visibleIds = new Set(riskMatrixQuery.data.data.map((row) => row.user_id));
    setSelectedRiskUsers((previous) => previous.filter((userId) => visibleIds.has(userId)));
  }, [riskMatrixQuery.data?.data]);

  useEffect(() => {
    setMonitoringRequestsPage(1);
    setMonitoringLogsPage(1);
  }, [monitoringPeriod, monitoringSearchValue, monitoringLogsStatus]);

  useEffect(() => {
    persistMonitoringFiltersToStorage({
      period: monitoringPeriod,
      search: monitoringSearch,
      logsStatus: monitoringLogsStatus,
    });
  }, [monitoringLogsStatus, monitoringPeriod, monitoringSearch]);

  const healthTone = useMemo(() => {
    if (pulse?.state === 'critical') return 'border-red-400/30 bg-red-50 text-red-300';
    if (pulse?.state === 'attention') return 'border-amber-400/30 bg-amber-50 text-amber-300';
    return 'border-emerald-400/30 bg-emerald-50 text-emerald-300';
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
    monitoringQuery.refetch();
  };

  const exportRequestsCsv = () => {
    if (!monitoringRequests.length) {
      toast.error('Nao ha requisicoes para exportar neste filtro');
      return;
    }

    setIsExportingRequestsCsv(true);

    try {
      const rows = monitoringRequests.map((requestRow) => [
        requestRow.created_at ?? '',
        requestRow.user_name ?? '',
        requestRow.user_email ?? '',
        requestRow.user_id ?? '',
        requestRow.account_id ?? '',
        requestRow.method ?? '',
        requestRow.path ?? '',
        requestRow.action ?? '',
        requestRow.entity_type ?? '',
        requestRow.entity_id ?? '',
        requestRow.status_code ?? '',
        requestRow.duration_ms ?? '',
        requestRow.ip_address ?? '',
      ]);

      downloadCsvFile(
        `control-tower-requisicoes-${monitoringPeriod}-${buildExportTimestamp()}.csv`,
        [
          'created_at',
          'user_name',
          'user_email',
          'user_id',
          'account_id',
          'method',
          'path',
          'action',
          'entity_type',
          'entity_id',
          'status_code',
          'duration_ms',
          'ip_address',
        ],
        rows,
      );

      toast.success('CSV de requisicoes exportado');
    } finally {
      setIsExportingRequestsCsv(false);
    }
  };

  const exportLogsCsv = () => {
    if (!monitoringLogs.length) {
      toast.error('Nao ha logs para exportar neste filtro');
      return;
    }

    setIsExportingLogsCsv(true);

    try {
      const rows = monitoringLogs.map((logRow) => [
        logRow.accessed_at ?? '',
        logRow.user_name ?? '',
        logRow.user_email ?? '',
        logRow.user_id ?? '',
        logRow.account_id ?? '',
        logRow.actor_role ?? '',
        logRow.method ?? '',
        logRow.endpoint ?? '',
        logRow.response_status ?? '',
        logRow.response_time_ms ?? '',
        logRow.ip_address ?? '',
        logRow.security_flags,
        logRow.correlation_id ?? '',
      ]);

      downloadCsvFile(
        `control-tower-logs-${monitoringPeriod}-${buildExportTimestamp()}.csv`,
        [
          'accessed_at',
          'user_name',
          'user_email',
          'user_id',
          'account_id',
          'actor_role',
          'method',
          'endpoint',
          'response_status',
          'response_time_ms',
          'ip_address',
          'security_flags',
          'correlation_id',
        ],
        rows,
      );

      toast.success('CSV de logs exportado');
    } finally {
      setIsExportingLogsCsv(false);
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        
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
          icon={ScaleIcon}
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
        <div className="xl:col-span-2 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-gray-900">Kill Switches e Guardrails</h3>
              <p className="text-xs text-gray-500">Controle direto dos mecanismos criticos de operacao</p>
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
              className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {saveControlsMutation.isPending ? 'Salvando...' : 'Salvar controles'}
            </button>
            <button
              type="button"
              onClick={() => setDraftControls(controlsQuery.data ?? DEFAULT_CONTROLS)}
              className="inline-flex items-center rounded-md border border-gray-100 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-900 hover:bg-gray-100"
            >
              Reverter
            </button>
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
          <h3 className="text-lg font-semibold text-gray-900">Alertas criticos</h3>
          <p className="mt-1 text-xs text-gray-500">Sinalizacao imediata para resposta operacional</p>

          <div className="mt-4 space-y-3">
            {pulse?.critical_alerts?.length ? (
              pulse.critical_alerts.map((alert, index) => (
                <div key={`${alert}-${index}`} className="rounded-md border border-red-400/20 bg-red-500/5 p-3 text-sm text-red-300">
                  <div className="flex items-start gap-2">
                    <ShieldExclamationIcon className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{alert}</span>
                  </div>
                </div>
              ))
            ) : (
              <div className="rounded-md border border-emerald-400/20 bg-emerald-500/5 p-3 text-sm text-emerald-300">
                Nenhum alerta critico ativo.
              </div>
            )}
          </div>

          <div className="mt-4 space-y-2 text-xs text-gray-600">
            <div className="flex items-center justify-between">
              <span>Ultima atualizacao</span>
              <span className="font-semibold text-gray-900">{pulse?.updated_at ? formatDate(pulse.updated_at) : '-'}</span>
            </div>
            <div className="flex items-center justify-between">
              <span>Saques pendentes</span>
              <span className="font-semibold text-gray-900">{pulse?.queues.pending_withdrawals ?? 0}</span>
            </div>
            <div className="flex items-center justify-between">
              <span>KYC pendente</span>
              <span className="font-semibold text-gray-900">{pulse?.queues.pending_kyc ?? 0}</span>
            </div>
          </div>
        </div>
      </section>

      <section className="grid gap-6 xl:grid-cols-3">
        <InfoCard
          title="Risco e fraude"
          icon={ShieldExclamationIcon}
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
          icon={CpuChipIcon}
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

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-start gap-2">
            <FunnelIcon className="mt-0.5 h-5 w-5 text-blue-700" />
            <div>
              <h3 className="text-lg font-semibold text-gray-900">Logs e requisicoes de usuarios</h3>
              <p className="text-xs text-gray-500">Monitoramento centralizado de mutacoes auditadas e acessos administrativos</p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <select
              value={monitoringPeriod}
              onChange={(event) => setMonitoringPeriod(event.target.value as '1h' | '6h' | '24h' | '7d')}
              className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 focus:border-blue-500 focus:outline-none"
            >
              <option value="1h">Ultima 1h</option>
              <option value="6h">Ultimas 6h</option>
              <option value="24h">Ultimas 24h</option>
              <option value="7d">Ultimos 7 dias</option>
            </select>

            <select
              value={monitoringLogsStatus}
              onChange={(event) => setMonitoringLogsStatus(event.target.value as 'all' | 'success' | 'error' | 'blocked')}
              className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 focus:border-blue-500 focus:outline-none"
            >
              <option value="all">Logs: todos os status</option>
              <option value="success">Logs: sucesso</option>
              <option value="error">Logs: erro</option>
              <option value="blocked">Logs: bloqueadas</option>
            </select>

            <input
              value={monitoringSearch}
              onChange={(event) => setMonitoringSearch(event.target.value)}
              placeholder="Buscar por usuario, endpoint, acao ou IP"
              className="w-64 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-900 placeholder:text-gray-500 focus:border-blue-500 focus:outline-none"
            />

            <button
              type="button"
              onClick={() => monitoringQuery.refetch()}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100"
            >
              <ArrowPathIcon className={`h-3.5 w-3.5 ${monitoringQuery.isFetching ? 'animate-spin' : ''}`} />
              Atualizar feed
            </button>

            <button
              type="button"
              onClick={exportRequestsCsv}
              disabled={!monitoringRequests.length || isExportingRequestsCsv}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isExportingRequestsCsv ? 'Exportando...' : 'CSV requisicoes'}
            </button>

            <button
              type="button"
              onClick={exportLogsCsv}
              disabled={!monitoringLogs.length || isExportingLogsCsv}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isExportingLogsCsv ? 'Exportando...' : 'CSV logs'}
            </button>
          </div>
        </div>

        <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <MiniStatCard
            label="Requisicoes mutaveis"
            value={(monitoringSummary?.requests_total ?? 0).toLocaleString('pt-BR')}
            tone="blue"
          />
          <MiniStatCard
            label="Usuarios unicos"
            value={(monitoringSummary?.requests_unique_users ?? 0).toLocaleString('pt-BR')}
            tone="emerald"
          />
          <MiniStatCard
            label="Logs com erro"
            value={(monitoringSummary?.logs_errors ?? 0).toLocaleString('pt-BR')}
            tone={(monitoringSummary?.logs_errors ?? 0) > 0 ? 'red' : 'blue'}
          />
          <MiniStatCard
            label="Latencia media logs"
            value={formatLatency(monitoringSummary?.logs_avg_response_time_ms ?? null)}
            tone="amber"
          />
        </div>

        <div className="mt-5 grid gap-6 xl:grid-cols-2">
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div className="flex items-center justify-between gap-3">
              <h4 className="text-sm font-semibold text-gray-900">Requisicoes de usuarios (audit)</h4>
              <span className="text-xs font-semibold text-gray-500">
                {monitoringRequestsMeta.total.toLocaleString('pt-BR')} registros
              </span>
            </div>

            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[640px]">
                <thead>
                  <tr className="border-b border-gray-200 text-left text-[11px] uppercase tracking-wide text-gray-500">
                    <th className="px-2 py-2">Horario</th>
                    <th className="px-2 py-2">Usuario</th>
                    <th className="px-2 py-2">Metodo / rota</th>
                    <th className="px-2 py-2">Acao</th>
                    <th className="px-2 py-2">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 text-xs text-gray-700">
                  {monitoringRequests.length ? (
                    monitoringRequests.map((item) => (
                      <tr key={item.id} className="hover:bg-white">
                        <td className="px-2 py-2 text-gray-600">{item.created_at ? formatDate(item.created_at) : '-'}</td>
                        <td className="px-2 py-2">
                          <p className="font-semibold text-gray-900">{item.user_name ?? item.user_email ?? shortId(item.user_id) ?? 'Sistema'}</p>
                          <p className="text-[11px] text-gray-500">{item.user_email ?? item.ip_address ?? '-'}</p>
                        </td>
                        <td className="px-2 py-2 text-gray-600">
                          <p className="font-semibold text-gray-900">{item.method || '-'}</p>
                          <p className="truncate text-[11px] text-gray-500">{item.path || '-'}</p>
                        </td>
                        <td className="px-2 py-2 text-gray-600">
                          <p className="font-semibold text-gray-900">{item.action || '-'}</p>
                          <p className="text-[11px] text-gray-500">
                            {item.entity_type ?? '-'} {item.entity_id ? `#${item.entity_id.slice(0, 8)}` : ''}
                          </p>
                        </td>
                        <td className="px-2 py-2">
                          <StatusPill statusCode={item.status_code} />
                          <p className="mt-1 text-[11px] text-gray-500">{formatLatency(item.duration_ms)}</p>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={5} className="px-2 py-6 text-center text-xs text-gray-500">
                        Nenhuma requisicao auditada no periodo atual.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <PaginationControls
              meta={monitoringRequestsMeta}
              isFetching={monitoringQuery.isFetching}
              onPrevious={() => setMonitoringRequestsPage((current) => Math.max(1, current - 1))}
              onNext={() => setMonitoringRequestsPage((current) => Math.min(monitoringRequestsMeta.last_page, current + 1))}
            />
          </div>

          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div className="flex items-center justify-between gap-3">
              <h4 className="text-sm font-semibold text-gray-900">Logs operacionais (admin access)</h4>
              <span className="text-xs font-semibold text-gray-500">
                {monitoringLogsMeta.total.toLocaleString('pt-BR')} registros
              </span>
            </div>

            <div className="mt-3 overflow-x-auto">
              <table className="w-full min-w-[680px]">
                <thead>
                  <tr className="border-b border-gray-200 text-left text-[11px] uppercase tracking-wide text-gray-500">
                    <th className="px-2 py-2">Horario</th>
                    <th className="px-2 py-2">Operador</th>
                    <th className="px-2 py-2">Endpoint</th>
                    <th className="px-2 py-2">Status</th>
                    <th className="px-2 py-2">IP</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 text-xs text-gray-700">
                  {monitoringLogs.length ? (
                    monitoringLogs.map((logRow) => (
                      <tr key={logRow.id} className="hover:bg-white">
                        <td className="px-2 py-2 text-gray-600">{logRow.accessed_at ? formatDate(logRow.accessed_at) : '-'}</td>
                        <td className="px-2 py-2">
                          <p className="font-semibold text-gray-900">
                            {logRow.user_name ?? logRow.user_email ?? shortId(logRow.user_id) ?? 'Operacao automatica'}
                          </p>
                          <p className="text-[11px] text-gray-500">{logRow.actor_role ?? '-'}</p>
                        </td>
                        <td className="px-2 py-2 text-gray-600">
                          <p className="font-semibold text-gray-900">{logRow.method || '-'}</p>
                          <p className="truncate text-[11px] text-gray-500">{logRow.endpoint || '-'}</p>
                        </td>
                        <td className="px-2 py-2">
                          <StatusPill statusCode={logRow.response_status} />
                          <p className="mt-1 text-[11px] text-gray-500">{formatLatency(logRow.response_time_ms)}</p>
                        </td>
                        <td className="px-2 py-2 text-gray-600">
                          <p>{logRow.ip_address ?? '-'}</p>
                          <p className="truncate text-[11px] text-gray-500">{logRow.security_flags.join(', ') || '-'}</p>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={5} className="px-2 py-6 text-center text-xs text-gray-500">
                        Nenhum log operacional no periodo atual.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <PaginationControls
              meta={monitoringLogsMeta}
              isFetching={monitoringQuery.isFetching}
              onPrevious={() => setMonitoringLogsPage((current) => Math.max(1, current - 1))}
              onNext={() => setMonitoringLogsPage((current) => Math.min(monitoringLogsMeta.last_page, current + 1))}
            />
          </div>
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">SLA por adquirente e rota</h3>
            <p className="mt-1 text-xs text-gray-500">Latencia, aprovacao por rota e sinais de fallback automatico</p>
          </div>

          <div className="flex items-center gap-2">
            <select
              value={slaPeriod}
              onChange={(event) => setSlaPeriod(event.target.value as '24h' | '7d' | '30d' | '90d')}
              className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 focus:border-blue-500 focus:outline-none"
            >
              <option value="24h">Ultimas 24h</option>
              <option value="7d">Ultimos 7 dias</option>
              <option value="30d">Ultimos 30 dias</option>
              <option value="90d">Ultimos 90 dias</option>
            </select>

            <button
              type="button"
              onClick={() => slaQuery.refetch()}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100"
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
              <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
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
                  <tr key={`${route.acquirer}-${route.payment_method ?? 'all'}`} className="hover:bg-gray-100">
                    <td className="px-2 py-3 font-semibold text-gray-900">{route.acquirer.toUpperCase()}</td>
                    <td className="px-2 py-3 text-gray-600">{(route.payment_method ?? 'all').toUpperCase()}</td>
                    <td className="px-2 py-3 text-gray-900">{route.approval_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-gray-600">{formatLatency(route.p95_latency_ms)}</td>
                    <td className="px-2 py-3 text-gray-600">{route.fallback_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-gray-900">{route.total_transactions.toLocaleString('pt-BR')}</td>
                    <td className="px-2 py-3">
                      <SlaStatePill state={route.sla_state} />
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={7} className="px-2 py-6 text-center text-sm text-gray-500">
                    Sem dados de SLA para o periodo selecionado.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-start gap-2">
            <UserGroupIcon className="mt-0.5 h-5 w-5 text-blue-700" />
            <div>
              <h3 className="text-lg font-semibold text-gray-900">Matriz de risco por seller</h3>
              <p className="text-xs text-gray-500">Score consolidado com acoes em massa para operacao e antifraude</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <select
              value={riskLevelFilter}
              onChange={(event) => setRiskLevelFilter(event.target.value as 'all' | 'high' | 'medium' | 'low')}
              className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 focus:border-blue-500 focus:outline-none"
            >
              <option value="all">Todos os riscos</option>
              <option value="high">Alto risco</option>
              <option value="medium">Risco medio</option>
              <option value="low">Risco baixo</option>
            </select>

            <button
              type="button"
              onClick={() => riskMatrixQuery.refetch()}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100"
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
            className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100"
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
            className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Recalcular saldo
          </button>
          <button
            type="button"
            onClick={() => runRiskBulkAction('request_kyc_review')}
            disabled={!selectedRiskUsers.length || bulkRiskActionMutation.isPending}
            className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
          >
            Enviar para KYC review
          </button>

          <span className="ml-auto rounded-full bg-gray-50 px-3 py-1 text-xs font-semibold text-gray-600">
            {selectedRiskUsers.length.toLocaleString('pt-BR')} selecionados
          </span>
        </div>

        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[980px]">
            <thead>
              <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                <th className="px-2 py-3">
                  <input
                    type="checkbox"
                    checked={isAllRiskRowsSelected}
                    onChange={toggleSelectAllRiskRows}
                    className="h-4 w-4 rounded border-gray-200 bg-gray-50 text-blue-600"
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
                  <tr key={seller.user_id} className="hover:bg-gray-100">
                    <td className="px-2 py-3">
                      <input
                        type="checkbox"
                        checked={selectedRiskUsers.includes(seller.user_id)}
                        onChange={() => toggleRiskUser(seller.user_id)}
                        className="h-4 w-4 rounded border-gray-200 bg-gray-50 text-blue-600"
                      />
                    </td>
                    <td className="px-2 py-3">
                      <p className="font-semibold text-gray-900">{seller.seller_name}</p>
                      <p className="text-xs text-gray-500">{seller.seller_email}</p>
                    </td>
                    <td className="px-2 py-3">
                      <div className="flex items-center gap-2">
                        <RiskLevelPill level={seller.risk_level} />
                        <span className="text-xs font-semibold text-gray-900">{seller.risk_score}</span>
                      </div>
                    </td>
                    <td className="px-2 py-3 text-xs text-gray-600">
                      {seller.risk_signals.slice(0, 2).join(' • ') || 'Sem sinal forte'}
                    </td>
                    <td className="px-2 py-3 text-gray-900">{seller.metrics.approval_rate.toFixed(1)}%</td>
                    <td className="px-2 py-3 text-gray-600">{seller.metrics.chargeback_rate.toFixed(2)}%</td>
                    <td className="px-2 py-3 text-gray-600">
                      {seller.metrics.pending_withdrawals.toLocaleString('pt-BR')} ({formatCurrency(seller.metrics.pending_withdrawals_amount)})
                    </td>
                    <td className="px-2 py-3">
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/admin/sellers/${seller.user_id}`}
                          className="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-900 hover:bg-gray-100"
                        >
                          Abrir
                        </Link>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={8} className="px-2 py-6 text-center text-sm text-gray-500">
                    Nenhum seller encontrado para o filtro de risco atual.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">Modo incidente e playbooks executaveis</h3>
            <p className="mt-1 text-xs text-gray-500">Execute respostas operacionais sem sair da Control Tower</p>
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
            href="/admin/withdrawals"
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
  icon?: ComponentType<{ className?: string }>;
  tone: 'blue' | 'emerald' | 'violet' | 'amber' | 'red';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    violet: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  };
  const CardIcon = Icon ?? ShieldCheckIcon;

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
      <div className="flex items-center gap-3">
        <div className={`rounded-md p-2.5 ${toneClasses[tone]}`}>
          <CardIcon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-gray-500">{title}</p>
          <p className="text-xl font-semibold text-gray-900">{value}</p>
          <p className="text-xs text-gray-500">{subtitle}</p>
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
      className="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2.5 text-left hover:bg-gray-100"
    >
      <span className="text-sm text-gray-600">{label}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>
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
    <label className="space-y-1 rounded-md border border-gray-100 bg-gray-50 p-3">
      <span className="block text-xs text-gray-500">{label}</span>
      <input
        type="number"
        value={Number.isFinite(value) ? value : 0}
        onChange={(event) => onChange(event.target.value)}
        className="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none"
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
    blue: 'border-blue-400/20 bg-blue-500/5 text-blue-700',
    emerald: 'border-emerald-400/20 bg-emerald-500/5 text-emerald-700',
    amber: 'border-amber-400/20 bg-amber-500/5 text-amber-700',
    red: 'border-red-400/20 bg-red-500/5 text-red-700',
    violet: 'border-green-400/20 bg-green-500/5 text-green-700',
  };

  return (
    <div className={`rounded-md border p-3 ${toneClasses[tone]}`}>
      <p className="text-[11px] font-medium uppercase tracking-wide opacity-80">{label}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  );
}

function SlaStatePill({ state }: { state: 'healthy' | 'attention' | 'critical' }) {
  const toneClasses: Record<string, string> = {
    healthy: 'bg-emerald-50 text-emerald-700',
    attention: 'bg-amber-50 text-amber-700',
    critical: 'bg-red-50 text-red-700',
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
    low: 'bg-emerald-50 text-emerald-700',
    medium: 'bg-amber-50 text-amber-700',
    high: 'bg-red-50 text-red-700',
  };

  const labels: Record<string, string> = {
    low: 'Baixo',
    medium: 'Medio',
    high: 'Alto',
  };

  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${toneClasses[level]}`}>{labels[level]}</span>;
}

function StatusPill({ statusCode }: { statusCode: number | null }) {
  if (statusCode === null || !Number.isFinite(statusCode)) {
    return <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">N/A</span>;
  }

  const isSuccess = statusCode >= 200 && statusCode < 400;
  const isBlocked = statusCode === 429;
  const tone = isBlocked
    ? 'bg-amber-50 text-amber-700'
    : isSuccess
      ? 'bg-emerald-50 text-emerald-700'
      : 'bg-red-50 text-red-700';

  return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>{statusCode}</span>;
}

function PaginationControls({
  meta,
  isFetching,
  onPrevious,
  onNext,
}: {
  meta: MonitoringPaginationMeta;
  isFetching: boolean;
  onPrevious: () => void;
  onNext: () => void;
}) {
  return (
    <div className="mt-3 flex items-center justify-between text-xs text-gray-500">
      <span>
        Pagina {meta.current_page.toLocaleString('pt-BR')} de {Math.max(1, meta.last_page).toLocaleString('pt-BR')}
      </span>
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={onPrevious}
          disabled={meta.current_page <= 1 || isFetching}
          className="rounded-md border border-gray-200 bg-white px-2 py-1 font-semibold text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Anterior
        </button>
        <button
          type="button"
          onClick={onNext}
          disabled={meta.current_page >= meta.last_page || isFetching}
          className="rounded-md border border-gray-200 bg-white px-2 py-1 font-semibold text-gray-600 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Proxima
        </button>
      </div>
    </div>
  );
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
    blue: 'bg-blue-50 text-blue-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    violet: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  };

  return (
    <div className="rounded-md border border-gray-100 bg-gray-50 p-3">
      <div className={`inline-flex rounded-md p-1.5 ${toneClasses[tone]}`}>
        <PlayIcon className="h-4 w-4" />
      </div>
      <p className="mt-2 text-sm font-semibold text-gray-900">{title}</p>
      <p className="text-xs text-gray-500">{subtitle}</p>

      <button
        type="button"
        onClick={onRun}
        disabled={running}
        className="mt-3 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-900 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
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

function shortId(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }

  return value.length > 8 ? `${value.slice(0, 8)}...` : value;
}

function InfoCard({
  title,
  icon: Icon,
  lines,
  footerLink,
}: {
  title: string;
  icon?: ComponentType<{ className?: string }>;
  lines: string[];
  footerLink: { href: string; label: string };
}) {
  const CardIcon = Icon ?? ClockIcon;

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
      <div className="flex items-center gap-2">
        <div className="rounded-lg bg-blue-50 p-2">
          <CardIcon className="h-4 w-4 text-blue-700" />
        </div>
        <h4 className="text-sm font-semibold text-gray-900">{title}</h4>
      </div>

      <div className="mt-3 space-y-1.5 text-xs text-gray-600">
        {lines.map((line, index) => (
          <p key={`${title}-${index}`}>{line}</p>
        ))}
      </div>

      <Link to={footerLink.href} className="mt-4 inline-flex text-xs font-semibold text-blue-700 hover:text-blue-400">
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
    blue: 'bg-blue-50 text-blue-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    violet: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
  };

  return (
    <Link to={href} className="rounded-md border border-gray-100 bg-gray-50 p-3 transition-colors hover:bg-gray-100">
      <div className={`inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold ${toneClasses[tone]}`}>Runbook</div>
      <p className="mt-2 text-sm font-semibold text-gray-900">{title}</p>
      <p className="text-xs text-gray-500">{subtitle}</p>
    </Link>
  );
}


