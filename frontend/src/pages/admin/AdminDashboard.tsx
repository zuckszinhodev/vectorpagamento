import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  ArrowTrendingDownIcon,
  ArrowTrendingUpIcon,
  BanknotesIcon,
  BoltIcon,
  BuildingLibraryIcon,
  CheckCircleIcon,
  ClockIcon,
  CpuChipIcon,
  CreditCardIcon,
  DocumentCheckIcon,
  DocumentTextIcon,
  ExclamationTriangleIcon,
  QrCodeIcon,
  ShieldCheckIcon,
  ShoppingBagIcon,
  UsersIcon,
} from '@heroicons/react/24/outline';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
  type ChartOptions,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

type DashboardPeriod = 'today' | 'week' | 'month' | 'year';

interface AdminStats {
  total_revenue: number;
  gateway_profit: number;
  gateway_fees: number;
  seller_payout: number;
  pending_amount: number;
  paid_transactions: number;
  pending_transactions: number;
  active_sellers: number;
  total_customers: number;
  pending_kyc: number;
  active_acquirers: number;
  transactions_today: number;
  volume_today: number;
  success_rate: number;
  revenue_change: number;
  profit_change: number;
  payout_change: number;
  pending_change: number;
}

interface RevenueChartData {
  labels: string[];
  values: number[];
}

interface PaymentMethodStats {
  method: string;
  count: number;
  amount: number;
  percentage: number;
}

interface AdminWithdrawal {
  id: string;
  customer_name: string;
  customer_email?: string;
  amount: number;
  net_amount?: number;
  method?: string;
  status: string;
  requested_at: string;
}

interface AdminApproval {
  id: string;
  action_key: string;
  resource_type: string;
  request_reason: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'expired' | 'consumed';
  requested_at: string;
}

interface AdminActivity {
  id: string;
  type: string;
  action: string;
  user?: string;
  email?: string;
  amount?: number;
  status?: 'success' | 'error' | 'info';
  time?: string;
  timestamp: string;
}

interface WithdrawalConfigSnapshot {
  payments_enabled?: boolean;
  withdrawals_enabled?: boolean;
  withdrawal_processing_enabled?: boolean;
  auto_withdrawal_enabled?: boolean;
  daily_withdrawal_limit?: number;
  large_withdrawal_threshold?: number;
  withdrawal_cooldown_seconds?: number;
  max_pending_withdrawals_per_account?: number;
}

interface AdminPulse {
  health_score: number;
  state: 'healthy' | 'attention' | 'critical';
  queues: {
    pending_withdrawals: number;
    pending_withdrawals_amount: number;
    pending_approvals: number;
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

interface WrappedResponse<T> {
  success: boolean;
  data: T;
}

interface PaginatedResponse<T> {
  success: boolean;
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

const DEFAULT_STATS: AdminStats = {
  total_revenue: 0,
  gateway_profit: 0,
  gateway_fees: 0,
  seller_payout: 0,
  pending_amount: 0,
  paid_transactions: 0,
  pending_transactions: 0,
  active_sellers: 0,
  total_customers: 0,
  pending_kyc: 0,
  active_acquirers: 0,
  transactions_today: 0,
  volume_today: 0,
  success_rate: 0,
  revenue_change: 0,
  profit_change: 0,
  payout_change: 0,
  pending_change: 0,
};

const PERIODS: Array<{ key: DashboardPeriod; label: string }> = [
  { key: 'today', label: 'Hoje' },
  { key: 'week', label: 'Semana' },
  { key: 'month', label: 'Mes' },
  { key: 'year', label: 'Ano' },
];

const getPaymentMethodIcon = (method: string) => {
  const normalized = method.toLowerCase();
  if (normalized.includes('pix')) return QrCodeIcon;
  if (normalized.includes('cart') || normalized.includes('credit')) return CreditCardIcon;
  if (normalized.includes('boleto')) return DocumentTextIcon;
  return BanknotesIcon;
};

const getActivityTone = (status?: string) => {
  if (status === 'success') return 'bg-emerald-500/10 text-emerald-500';
  if (status === 'error') return 'bg-red-500/10 text-red-500';
  return 'bg-blue-500/10 text-blue-500';
};

const getApprovalStatusTone = (status: AdminApproval['status']) => {
  if (status === 'pending') return 'bg-amber-500/10 text-amber-500';
  if (status === 'approved') return 'bg-emerald-500/10 text-emerald-500';
  if (status === 'rejected') return 'bg-red-500/10 text-red-500';
  return 'bg-slate-500/10 text-slate-500';
};

export default function AdminDashboard() {
  const [period, setPeriod] = useState<DashboardPeriod>('month');

  const statsQuery = useQuery<AdminStats>({
    queryKey: ['admin-dashboard-stats-v2', period],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<AdminStats>>(`/admin/stats?period=${period}`);
      return response.data?.data ?? DEFAULT_STATS;
    },
    refetchInterval: 60000,
  });

  const chartQuery = useQuery<RevenueChartData>({
    queryKey: ['admin-dashboard-chart-v2', period],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<RevenueChartData>>(`/admin/chart/revenue?period=${period}`);
      return response.data?.data ?? { labels: [], values: [] };
    },
    refetchInterval: 60000,
  });

  const methodsQuery = useQuery<PaymentMethodStats[]>({
    queryKey: ['admin-dashboard-methods-v2', period],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<PaymentMethodStats[]>>(`/admin/payment-methods?period=${period}`);
      return response.data?.data ?? [];
    },
    refetchInterval: 60000,
  });

  const activitiesQuery = useQuery<AdminActivity[]>({
    queryKey: ['admin-dashboard-activities-v2'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<AdminActivity[]>>('/admin/activities?limit=5');
      return response.data?.data ?? [];
    },
    refetchInterval: 30000,
  });

  const approvalsQuery = useQuery<PaginatedResponse<AdminApproval>>({
    queryKey: ['admin-dashboard-approvals-v2'],
    queryFn: async () => {
      const response = await api.get<PaginatedResponse<AdminApproval>>('/admin/approvals?status=pending&per_page=8');
      return response.data;
    },
    refetchInterval: 30000,
  });

  const withdrawalsQuery = useQuery<PaginatedResponse<AdminWithdrawal>>({
    queryKey: ['admin-dashboard-withdrawals-v2'],
    queryFn: async () => {
      const response = await api.get<PaginatedResponse<AdminWithdrawal>>('/admin/withdrawals?status=pending&per_page=8');
      return response.data;
    },
    refetchInterval: 30000,
  });

  const controlsQuery = useQuery<WithdrawalConfigSnapshot>({
    queryKey: ['admin-dashboard-controls-v2'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<WithdrawalConfigSnapshot>>('/admin/withdrawals/config');
      return response.data?.data ?? {};
    },
    refetchInterval: 45000,
  });

  const pulseQuery = useQuery<AdminPulse>({
    queryKey: ['admin-dashboard-pulse-v2'],
    queryFn: async () => {
      const response = await api.get<WrappedResponse<AdminPulse>>('/admin/pulse');
      return response.data?.data;
    },
    refetchInterval: 20000,
  });

  const stats = statsQuery.data ?? DEFAULT_STATS;
  const chart = chartQuery.data ?? { labels: [], values: [] };
  const methods = methodsQuery.data ?? [];
  const activities = activitiesQuery.data ?? [];
  const recentActivities = activities.slice(0, 5);
  const pendingApprovals = approvalsQuery.data?.data ?? [];
  const pendingApprovalsCount = approvalsQuery.data?.meta?.total ?? 0;
  const pendingWithdrawals = withdrawalsQuery.data?.data ?? [];
  const pendingWithdrawalsCount = withdrawalsQuery.data?.meta?.total ?? 0;
  const controls = controlsQuery.data ?? {};
  const pulseData = pulseQuery.data;

  const pendingWithdrawalsAmount = useMemo(
    () => pendingWithdrawals.reduce((sum, item) => sum + (item.amount || 0), 0),
    [pendingWithdrawals]
  );

  const liquidityReserve = Math.max(0, stats.total_revenue - stats.seller_payout - pendingWithdrawalsAmount);
  const payoutPressure = stats.total_revenue > 0 ? Math.round((pendingWithdrawalsAmount / stats.total_revenue) * 100) : 0;

  const fallbackOperationalHealth = useMemo(() => {
    let penalty = 0;
    if (stats.success_rate < 92) penalty += (92 - stats.success_rate) * 1.2;
    if (stats.pending_kyc > 20) penalty += Math.min(20, (stats.pending_kyc - 20) * 1.5);
    if (pendingApprovalsCount > 10) penalty += Math.min(20, (pendingApprovalsCount - 10) * 1.8);
    if (pendingWithdrawalsCount > 15) penalty += Math.min(20, (pendingWithdrawalsCount - 15) * 1.2);
    if (controls.payments_enabled === false || controls.withdrawals_enabled === false) penalty += 25;

    return Math.max(0, Math.round(100 - penalty));
  }, [
    controls.payments_enabled,
    controls.withdrawals_enabled,
    pendingApprovalsCount,
    pendingWithdrawalsCount,
    stats.pending_kyc,
    stats.success_rate,
  ]);

  const operationalHealth = pulseData?.health_score ?? fallbackOperationalHealth;

  const incidentState = useMemo(() => {
    if (pulseData) {
      if (pulseData.state === 'critical') {
        return {
          title: 'Incident Mode',
          detail: pulseData.critical_alerts[0] ?? 'Operacao em contingencia.',
          tone: 'bg-red-500/15 text-red-300 border-red-400/30',
        };
      }

      if (pulseData.state === 'attention') {
        return {
          title: 'Atencao Operacional',
          detail: pulseData.critical_alerts[0] ?? 'Fila elevada ou indicadores abaixo da meta. Requer acao do time.',
          tone: 'bg-amber-500/20 text-amber-200 border-amber-400/40',
        };
      }

      return {
        title: 'Sistema Saudavel',
        detail: 'Roteamento, compliance e liquidez dentro do esperado.',
        tone: 'bg-emerald-500/15 text-emerald-200 border-emerald-400/40',
      };
    }

    if (controls.payments_enabled === false) {
      return {
        title: 'Incident Mode',
        detail: 'Pagamentos desativados globalmente. Operacao em contingencia.',
        tone: 'bg-red-500/15 text-red-300 border-red-400/30',
      };
    }

    if (operationalHealth < 70 || pendingApprovalsCount > 20 || pendingWithdrawalsCount > 25) {
      return {
        title: 'Atencao Operacional',
        detail: 'Fila elevada ou indicadores abaixo da meta. Requer acao do time.',
        tone: 'bg-amber-500/20 text-amber-200 border-amber-400/40',
      };
    }

    return {
      title: 'Sistema Saudavel',
      detail: 'Roteamento, compliance e liquidez dentro do esperado.',
      tone: 'bg-emerald-500/15 text-emerald-200 border-emerald-400/40',
    };
  }, [controls.payments_enabled, fallbackOperationalHealth, pendingApprovalsCount, pendingWithdrawalsCount, pulseData]);

  const operationalAlerts = useMemo(() => {
    if (pulseData) {
      if (pulseData.critical_alerts.length > 0) {
        const tone = pulseData.state === 'critical'
          ? 'border-red-400/30 bg-red-500/10 text-red-300'
          : 'border-amber-400/30 bg-amber-500/10 text-amber-300';

        return pulseData.critical_alerts.map((alert) => ({
          title: pulseData.state === 'critical' ? 'Alerta Critico' : 'Atencao Operacional',
          description: alert,
          tone,
        }));
      }

      return [{
        title: 'Sem alertas criticos',
        description: 'Todos os indicadores de risco estao operando em faixa segura.',
        tone: 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300',
      }];
    }

    const alerts: Array<{ title: string; description: string; tone: string }> = [];

    if (controls.withdrawals_enabled === false) {
      alerts.push({
        title: 'Saques bloqueados',
        description: 'Novas solicitacoes de saque estao pausadas para o ecossistema.',
        tone: 'border-red-400/30 bg-red-500/10 text-red-300',
      });
    }

    if (controls.withdrawal_processing_enabled === false) {
      alerts.push({
        title: 'Processamento de saque pausado',
        description: 'Pedidos estao em fila de contingencia sem envio de PIX OUT.',
        tone: 'border-amber-400/30 bg-amber-500/10 text-amber-300',
      });
    }

    if (stats.success_rate < 90) {
      alerts.push({
        title: 'Taxa de sucesso abaixo da meta',
        description: `Taxa atual: ${stats.success_rate.toFixed(1)}%. Investigue adquirentes e antifraude.`,
        tone: 'border-red-400/30 bg-red-500/10 text-red-300',
      });
    }

    if (pendingApprovalsCount > 15) {
      alerts.push({
        title: 'Fila de dupla aprovacao elevada',
        description: `${pendingApprovalsCount} solicitacoes aguardando revisao.`,
        tone: 'border-amber-400/30 bg-amber-500/10 text-amber-300',
      });
    }

    if (alerts.length === 0) {
      alerts.push({
        title: 'Sem alertas criticos',
        description: 'Todos os indicadores de risco estao operando em faixa segura.',
        tone: 'border-emerald-400/30 bg-emerald-500/10 text-emerald-300',
      });
    }

    return alerts;
  }, [
    controls.withdrawals_enabled,
    controls.withdrawal_processing_enabled,
    pendingApprovalsCount,
    stats.success_rate,
    pulseData,
  ]);

  const criticalAlertsCount = useMemo(() => {
    if (pulseData) {
      return pulseData.critical_alerts.length;
    }

    if (operationalAlerts.length === 1 && operationalAlerts[0]?.title === 'Sem alertas criticos') {
      return 0;
    }

    return operationalAlerts.length;
  }, [operationalAlerts, pulseData]);

  const chartOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15, 23, 42, 0.95)',
        titleColor: '#ffffff',
        bodyColor: '#cbd5e1',
        borderColor: 'rgba(148, 163, 184, 0.25)',
        borderWidth: 1,
        displayColors: false,
        callbacks: {
          label: (context) => formatCurrency(Number(context.parsed.y) || 0),
        },
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { color: '#94a3b8' },
      },
      y: {
        grid: { color: 'rgba(148, 163, 184, 0.15)' },
        ticks: {
          color: '#94a3b8',
          callback: (value) => formatCurrency(Number(value) || 0),
        },
      },
    },
    elements: {
      line: { tension: 0.35 },
      point: { radius: 0, hoverRadius: 4 },
    },
  };

  const chartData = {
    labels: chart.labels,
    datasets: [
      {
        data: chart.values,
        borderColor: '#3b82f6',
        borderWidth: 2,
        fill: true,
        backgroundColor: (context: { chart: { ctx: CanvasRenderingContext2D } }) => {
          const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 300);
          gradient.addColorStop(0, 'rgba(59, 130, 246, 0.35)');
          gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');
          return gradient;
        },
      },
    ],
  };

  if (statsQuery.isLoading) {
    return (
      <div className="flex h-[60vh] items-center justify-center">
        <div className="h-12 w-12 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
      </div>
    );
  }

  return (
    <AdminPageShell>
      <section className="relative overflow-hidden rounded-3xl border border-border-DEFAULT bg-slate-950 p-6 text-white shadow-2xl">
        <div className="pointer-events-none absolute -top-28 right-[-60px] h-72 w-72 rounded-full bg-blue-500/20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-20 left-[-80px] h-72 w-72 rounded-full bg-emerald-500/10 blur-3xl" />

        <div className="relative grid gap-6 lg:grid-cols-3">
          <div className="lg:col-span-2">
            <h1 className="text-3xl font-bold tracking-tight">Painel Administrativo</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-300">
              Acompanhamento consolidado de indicadores financeiros, risco operacional, liquidez e compliance para suporte a decisoes de gestao.
            </p>

            <div className={`mt-5 rounded-2xl border px-4 py-3 text-sm ${incidentState.tone}`}>
              <p className="font-semibold">{incidentState.title}</p>
              <p className="mt-1 text-xs opacity-90">{incidentState.detail}</p>
            </div>

            <div className="mt-5 grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl border border-white/10 bg-white/5 p-3">
                <p className="text-xs text-slate-300">Transacoes hoje</p>
                <p className="mt-1 text-xl font-semibold">{stats.transactions_today.toLocaleString('pt-BR')}</p>
              </div>
              <div className="rounded-xl border border-white/10 bg-white/5 p-3">
                <p className="text-xs text-slate-300">Volume hoje</p>
                <p className="mt-1 text-xl font-semibold">{formatCurrency(stats.volume_today)}</p>
              </div>
              <div className="rounded-xl border border-white/10 bg-white/5 p-3">
                <p className="text-xs text-slate-300">Saude operacional</p>
                <p className="mt-1 text-xl font-semibold">{operationalHealth}%</p>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <div className="flex items-center justify-between">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-300">Periodo analitico</p>
              <button
                type="button"
                onClick={() => {
                  statsQuery.refetch();
                  chartQuery.refetch();
                  methodsQuery.refetch();
                  activitiesQuery.refetch();
                  approvalsQuery.refetch();
                  withdrawalsQuery.refetch();
                  controlsQuery.refetch();
                  pulseQuery.refetch();
                }}
                className="rounded-lg border border-white/10 p-1.5 text-slate-200 hover:bg-white/10"
                title="Atualizar painel"
              >
                <ArrowPathIcon className={`h-4 w-4 ${(statsQuery.isFetching || chartQuery.isFetching) ? 'animate-spin' : ''}`} />
              </button>
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2">
              {PERIODS.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => setPeriod(item.key)}
                  className={`rounded-xl px-3 py-2 text-sm font-medium transition-colors ${
                    period === item.key ? 'bg-blue-500 text-white' : 'bg-white/5 text-slate-300 hover:bg-white/10'
                  }`}
                >
                  {item.label}
                </button>
              ))}
            </div>

            <div className="mt-4 rounded-xl border border-white/10 bg-slate-900/40 p-3">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-slate-400">Snapshot operacional</p>
                  <p className="mt-1 text-xs text-slate-300">Saude do ecossistema no periodo selecionado</p>
                </div>
                <span
                  className={`rounded-full px-2 py-1 text-[11px] font-semibold ${
                    operationalHealth < 70
                      ? 'bg-red-500/20 text-red-200'
                      : operationalHealth < 85
                        ? 'bg-amber-500/20 text-amber-200'
                        : 'bg-emerald-500/20 text-emerald-200'
                  }`}
                >
                  {operationalHealth < 70 ? 'Critico' : operationalHealth < 85 ? 'Atencao' : 'Estavel'}
                </span>
              </div>

              <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div className="rounded-lg bg-white/5 px-2.5 py-2">
                  <p className="text-slate-400">Saques pendentes</p>
                  <p className="mt-0.5 text-sm font-semibold text-white">{pendingWithdrawalsCount}</p>
                </div>
                <div className="rounded-lg bg-white/5 px-2.5 py-2">
                  <p className="text-slate-400">Aprovacoes</p>
                  <p className="mt-0.5 text-sm font-semibold text-white">{pendingApprovalsCount}</p>
                </div>
                <div className="rounded-lg bg-white/5 px-2.5 py-2">
                  <p className="text-slate-400">Valor em fila</p>
                  <p className="mt-0.5 text-sm font-semibold text-white">{formatCurrency(pendingWithdrawalsAmount)}</p>
                </div>
                <div className="rounded-lg bg-white/5 px-2.5 py-2">
                  <p className="text-slate-400">Alertas ativos</p>
                  <p className="mt-0.5 text-sm font-semibold text-white">{criticalAlertsCount}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          title="Faturamento Total"
          value={formatCurrency(stats.total_revenue)}
          change={stats.revenue_change}
          icon={BanknotesIcon}
          tone="blue"
        />
        <MetricCard
          title="Lucro da Plataforma"
          value={formatCurrency(stats.gateway_profit)}
          change={stats.profit_change}
          icon={BuildingLibraryIcon}
          tone="emerald"
        />
        <MetricCard
          title="Repasse Sellers"
          value={formatCurrency(stats.seller_payout)}
          change={stats.payout_change}
          icon={ShoppingBagIcon}
          tone="emerald"
        />
        <MetricCard
          title="Saldo em Risco/Pendente"
          value={formatCurrency(stats.pending_amount)}
          change={stats.pending_change}
          icon={ClockIcon}
          tone="amber"
        />
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SimpleMetric
          title="Taxa de Sucesso"
          value={`${stats.success_rate.toFixed(1)}%`}
          subtitle="Ultimos 30 dias"
          icon={CheckCircleIcon}
          tone="emerald"
        />
        <SimpleMetric
          title="Liquidez Disponivel"
          value={formatCurrency(liquidityReserve)}
          subtitle="Receita - repasse - fila"
          icon={BoltIcon}
          tone="blue"
        />
        <SimpleMetric
          title="Pressao de Saque"
          value={`${payoutPressure}%`}
          subtitle="Fila de saque / receita"
          icon={ExclamationTriangleIcon}
          tone={payoutPressure >= 20 ? 'amber' : 'emerald'}
        />
        <SimpleMetric
          title="Fila Dual Control"
          value={pendingApprovalsCount.toString()}
          subtitle="Aguardando revisao"
          icon={DocumentCheckIcon}
          tone={pendingApprovalsCount > 10 ? 'amber' : 'emerald'}
        />
      </section>

      <section className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2 rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Curva de Receita</h3>
              <p className="text-xs text-tx-muted">Monitoramento temporal por periodo selecionado</p>
            </div>
            <div className="rounded-lg bg-surface-page px-3 py-1.5 text-xs text-tx-secondary">
              Atualizando a cada 60s
            </div>
          </div>
          <div className="h-72">
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>

        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <h3 className="text-lg font-semibold text-tx-primary">Controles Operacionais</h3>
          <p className="mt-1 text-xs text-tx-muted">Snapshot de kill switches e protecoes fintech</p>

          <div className="mt-4 space-y-3">
            <ControlItem label="Pagamentos" enabled={controls.payments_enabled ?? true} />
            <ControlItem label="Novos Saques" enabled={controls.withdrawals_enabled ?? true} />
            <ControlItem
              label="Processamento de Saques"
              enabled={controls.withdrawal_processing_enabled ?? true}
            />
            <ControlItem label="Auto Processamento" enabled={controls.auto_withdrawal_enabled ?? false} />
          </div>

          <div className="mt-4 rounded-xl bg-surface-page p-3 text-xs text-tx-secondary">
            <p>Limite diario por conta: <span className="font-semibold text-tx-primary">{formatCurrency(controls.daily_withdrawal_limit ?? 0)}</span></p>
            <p className="mt-1">Limiar aprovacao manual: <span className="font-semibold text-tx-primary">{formatCurrency(controls.large_withdrawal_threshold ?? 0)}</span></p>
            <p className="mt-1">Cooldown entre saques: <span className="font-semibold text-tx-primary">{controls.withdrawal_cooldown_seconds ?? 0}s</span></p>
          </div>

          <Link
            to="/admin/finance"
            className="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"
          >
            Gerenciar configuracoes financeiras
          </Link>
        </div>
      </section>

      <section className="grid gap-6 xl:grid-cols-2">
        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Fila de Saques</h3>
              <p className="text-xs text-tx-muted">Solicitacoes pendentes aguardando processamento</p>
            </div>
            <span className="rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-600">
              {pendingWithdrawalsCount} pendentes
            </span>
          </div>

          {pendingWithdrawals.length === 0 ? (
            <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-sm text-emerald-600">
              Nenhum saque pendente no momento.
            </div>
          ) : (
            <div className="space-y-3">
              {pendingWithdrawals.slice(0, 6).map((withdrawal) => (
                <div key={withdrawal.id} className="rounded-xl border border-border-DEFAULT bg-surface-page p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-tx-primary">{withdrawal.customer_name || 'Seller'}</p>
                      <p className="text-xs text-tx-muted">{withdrawal.customer_email || 'Sem email'}</p>
                    </div>
                    <p className="text-sm font-semibold text-tx-primary">{formatCurrency(withdrawal.amount)}</p>
                  </div>
                  <div className="mt-2 flex items-center justify-between text-xs text-tx-muted">
                    <span>Metodo: {withdrawal.method === 'pix' ? 'PIX' : withdrawal.method || 'N/A'}</span>
                    <span>{formatDate(withdrawal.requested_at)}</span>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="mt-4 flex items-center justify-between rounded-xl bg-surface-page px-3 py-2 text-xs text-tx-secondary">
            <span>Total em fila</span>
            <span className="font-semibold text-tx-primary">{formatCurrency(pendingWithdrawalsAmount)}</span>
          </div>

          <Link
            to="/admin/finance"
            className="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm font-semibold text-tx-primary hover:bg-surface-hover"
          >
            Abrir operacao de saques
          </Link>
        </div>

        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Fila de Aprovacoes</h3>
              <p className="text-xs text-tx-muted">Acoes criticas sob politica de dual control</p>
            </div>
            <span className="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600">
              {pendingApprovalsCount} pendentes
            </span>
          </div>

          {pendingApprovals.length === 0 ? (
            <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-sm text-emerald-600">
              Nenhuma aprovacao pendente.
            </div>
          ) : (
            <div className="space-y-3">
              {pendingApprovals.slice(0, 6).map((approval) => (
                <div key={approval.id} className="rounded-xl border border-border-DEFAULT bg-surface-page p-3">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-semibold text-tx-primary">{approval.action_key}</p>
                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${getApprovalStatusTone(approval.status)}`}>
                      {approval.status}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-tx-muted">
                    {approval.request_reason || 'Sem motivo informado'}
                  </p>
                  <div className="mt-2 text-xs text-tx-muted">{formatDate(approval.requested_at)}</div>
                </div>
              ))}
            </div>
          )}

          <Link
            to="/admin/approvals"
            className="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm font-semibold text-tx-primary hover:bg-surface-hover"
          >
            Revisar aprovacoes
          </Link>
        </div>
      </section>

      <section className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2 rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h3 className="text-lg font-semibold text-tx-primary">Atividade Recente</h3>
              <p className="mt-1 text-xs text-tx-muted">Eventos operacionais, KYC e transacoes</p>
            </div>
            <Link
              to="/admin/monitoring"
              className="inline-flex items-center justify-center rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-1.5 text-xs font-semibold text-tx-primary hover:bg-surface-hover"
            >
              Ver todas as atividades
            </Link>
          </div>

          {recentActivities.length === 0 ? (
            <div className="mt-4 rounded-xl border border-border-DEFAULT bg-surface-page p-4 text-sm text-tx-muted">
              Sem eventos recentes para exibir.
            </div>
          ) : (
            <div className="mt-4 space-y-3">
              {recentActivities.map((activity) => (
                <div key={activity.id} className="rounded-xl border border-border-DEFAULT bg-surface-page p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-tx-primary">{activity.action}</p>
                      <p className="text-xs text-tx-muted">{activity.user || activity.email || 'Sistema'}</p>
                    </div>
                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${getActivityTone(activity.status)}`}>
                      {activity.status || 'info'}
                    </span>
                  </div>
                  <div className="mt-2 flex items-center justify-between text-xs text-tx-muted">
                    <span>{activity.time || formatDate(activity.timestamp)}</span>
                    {typeof activity.amount === 'number' ? <span>{formatCurrency(activity.amount)}</span> : null}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
          <h3 className="text-lg font-semibold text-tx-primary">Trilhos de Pagamento</h3>
          <p className="mt-1 text-xs text-tx-muted">Mix de metodos no periodo selecionado</p>

          <div className="mt-4 space-y-3">
            {methods.map((method) => {
              const MethodIcon = getPaymentMethodIcon(method.method);
              return (
                <div key={method.method} className="rounded-xl border border-border-DEFAULT bg-surface-page p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                      <div className="rounded-lg bg-blue-500/10 p-2">
                        <MethodIcon className="h-4 w-4 text-blue-500" />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-tx-primary">{method.method}</p>
                        <p className="text-xs text-tx-muted">{method.count.toLocaleString('pt-BR')} transacoes</p>
                      </div>
                    </div>
                    <p className="text-xs font-semibold text-tx-secondary">{method.percentage}%</p>
                  </div>
                  <div className="mt-2 h-2 rounded-full bg-surface-hover">
                    <div className="h-2 rounded-full bg-gradient-to-r from-blue-500 to-cyan-500" style={{ width: `${Math.min(method.percentage, 100)}%` }} />
                  </div>
                  <p className="mt-2 text-xs text-tx-muted">{formatCurrency(method.amount)}</p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm">
        <h3 className="text-lg font-semibold text-tx-primary">Alertas e Compliance</h3>
        <p className="mt-1 text-xs text-tx-muted">Monitor de risco operacional para atuacao imediata</p>

        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {operationalAlerts.map((alert, index) => (
            <div key={`${alert.title}-${index}`} className={`rounded-xl border p-4 ${alert.tone}`}>
              <p className="text-sm font-semibold">{alert.title}</p>
              <p className="mt-1 text-xs opacity-90">{alert.description}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <QuickActionCard
          title="Control Tower"
          subtitle="Comando operacional, risco e contingencia"
          icon={CpuChipIcon}
          href="/admin/control-tower"
        />
        <QuickActionCard
          title="Operacao Financeira"
          subtitle="Ajustar limites, filas e processamento"
          icon={BanknotesIcon}
          href="/admin/finance"
        />
        <QuickActionCard
          title="Fila de Aprovacoes"
          subtitle="Revisar acoes criticas com dual control"
          icon={DocumentCheckIcon}
          href="/admin/approvals"
        />
        <QuickActionCard
          title="KYC e Compliance"
          subtitle="Priorizar clientes em revisao"
          icon={ShieldCheckIcon}
          href="/admin/kyc"
        />
        <QuickActionCard
          title="Gestao de Sellers"
          subtitle="Suporte operacional e risco por conta"
          icon={UsersIcon}
          href="/admin/sellers"
        />
      </section>
    </AdminPageShell>
  );
}

function MetricCard({
  title,
  value,
  change,
  icon: Icon,
  tone,
}: {
  title: string;
  value: string;
  change: number;
  icon: typeof BanknotesIcon;
  tone: 'blue' | 'emerald' | 'amber';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    amber: 'bg-amber-500/10 text-amber-500',
  };

  const positive = change >= 0;

  return (
    <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-5 shadow-sm">
      <div className="flex items-start justify-between">
        <div className={`rounded-xl p-2.5 ${toneClasses[tone]}`}>
          <Icon className="h-5 w-5" />
        </div>
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${positive ? 'bg-emerald-500/10 text-emerald-500' : 'bg-red-500/10 text-red-500'}`}>
          {positive ? <ArrowTrendingUpIcon className="h-3.5 w-3.5" /> : <ArrowTrendingDownIcon className="h-3.5 w-3.5" />}
          {Math.abs(change).toFixed(1)}%
        </span>
      </div>
      <p className="mt-3 text-xs text-tx-muted">{title}</p>
      <p className="mt-1 text-xl font-bold text-tx-primary">{value}</p>
    </div>
  );
}

function SimpleMetric({
  title,
  value,
  subtitle,
  icon: Icon,
  tone,
}: {
  title: string;
  value: string;
  subtitle: string;
  icon: typeof BanknotesIcon;
  tone: 'blue' | 'emerald' | 'amber';
}) {
  const toneClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    amber: 'bg-amber-500/10 text-amber-500',
  };

  return (
    <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
      <div className="flex items-center gap-3">
        <div className={`rounded-lg p-2 ${toneClasses[tone]}`}>
          <Icon className="h-4 w-4" />
        </div>
        <div>
          <p className="text-xs text-tx-muted">{title}</p>
          <p className="text-lg font-semibold text-tx-primary">{value}</p>
          <p className="text-xs text-tx-muted">{subtitle}</p>
        </div>
      </div>
    </div>
  );
}

function ControlItem({ label, enabled }: { label: string; enabled: boolean }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-surface-page px-3 py-2 text-sm">
      <span className="text-tx-secondary">{label}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${enabled ? 'bg-emerald-500/10 text-emerald-500' : 'bg-red-500/10 text-red-500'}`}>
        {enabled ? 'Ativo' : 'Pausado'}
      </span>
    </div>
  );
}

function QuickActionCard({
  title,
  subtitle,
  icon: Icon,
  href,
}: {
  title: string;
  subtitle: string;
  icon: typeof BanknotesIcon;
  href: string;
}) {
  return (
    <Link to={href} className="rounded-2xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm transition-colors hover:bg-surface-hover">
      <div className="flex items-start gap-3">
        <div className="rounded-lg bg-blue-500/10 p-2.5">
          <Icon className="h-5 w-5 text-blue-500" />
        </div>
        <div>
          <p className="text-sm font-semibold text-tx-primary">{title}</p>
          <p className="mt-1 text-xs text-tx-muted">{subtitle}</p>
        </div>
      </div>
    </Link>
  );
}
