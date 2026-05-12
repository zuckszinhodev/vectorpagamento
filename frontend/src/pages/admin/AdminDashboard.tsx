import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageShell, AdminPageHeader } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  ArrowTrendingDownIcon,
  ArrowTrendingUpIcon,
  BanknotesIcon,
  BoltIcon,
  BuildingLibraryIcon,
  CheckCircleIcon,
  ClockIcon,
  CreditCardIcon,
  DocumentTextIcon,
  ExclamationTriangleIcon,
  QrCodeIcon,
  ShoppingBagIcon,
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
  { key: 'month', label: 'Mês' },
  { key: 'year', label: 'Ano' },
];

const getPaymentMethodIcon = (method: string) => {
  const normalized = method.toLowerCase();
  if (normalized.includes('pix')) return QrCodeIcon;
  if (normalized.includes('cart') || normalized.includes('credit')) return CreditCardIcon;
  if (normalized.includes('boleto')) return DocumentTextIcon;
  return BanknotesIcon;
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

  const operationalHealth = useMemo(() => {
    if (pulseData) return pulseData.health_score;
    let penalty = 0;
    if (stats.success_rate < 92) penalty += (92 - stats.success_rate) * 1.2;
    if (stats.pending_kyc > 20) penalty += Math.min(20, (stats.pending_kyc - 20) * 1.5);
    if (pendingWithdrawalsCount > 15) penalty += Math.min(20, (pendingWithdrawalsCount - 15) * 1.2);
    if (controls.payments_enabled === false || controls.withdrawals_enabled === false) penalty += 25;
    return Math.max(0, Math.round(100 - penalty));
  }, [pulseData, stats.success_rate, stats.pending_kyc, pendingWithdrawalsCount, controls.payments_enabled, controls.withdrawals_enabled]);

  const alerts = useMemo(() => {
    if (pulseData?.critical_alerts?.length) return pulseData.critical_alerts;
    const list: string[] = [];
    if (controls.payments_enabled === false) list.push('Pagamentos desativados globalmente');
    if (controls.withdrawal_processing_enabled === false) list.push('Processamento de saques pausado');
    if (stats.success_rate < 90) list.push(`Taxa de sucesso abaixo da meta: ${stats.success_rate.toFixed(1)}%`);
    return list;
  }, [pulseData, controls.payments_enabled, controls.withdrawal_processing_enabled, stats.success_rate]);

  const refetchAll = () => {
    statsQuery.refetch();
    chartQuery.refetch();
    methodsQuery.refetch();
    activitiesQuery.refetch();
    withdrawalsQuery.refetch();
    controlsQuery.refetch();
    pulseQuery.refetch();
  };

  const chartOptions: ChartOptions<'line'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#111827',
        titleColor: '#fff',
        bodyColor: '#d1d5db',
        borderColor: '#e5e7eb',
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
        ticks: { color: '#9ca3af', font: { size: 11 } },
      },
      y: {
        grid: { color: '#f3f4f6' },
        ticks: {
          color: '#9ca3af',
          font: { size: 11 },
          callback: (value) => formatCurrency(Number(value) || 0),
        },
      },
    },
    elements: {
      line: { tension: 0.3 },
      point: { radius: 0, hoverRadius: 4 },
    },
  };

  const chartData = {
    labels: chart.labels,
    datasets: [
      {
        data: chart.values,
        borderColor: '#111827',
        borderWidth: 2,
        fill: true,
        backgroundColor: 'rgba(17, 24, 39, 0.04)',
      },
    ],
  };

  if (statsQuery.isLoading) {
    return (
      <div className="flex h-[60vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Dashboard"
        subtitle="Visão consolidada de indicadores financeiros e operacionais"
        actions={
          <div className="flex items-center gap-2">
            <div className="flex rounded-lg border border-gray-200 bg-white p-0.5">
              {PERIODS.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => setPeriod(item.key)}
                  className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                    period === item.key ? 'bg-gray-900 text-white' : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  {item.label}
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={refetchAll}
              className="rounded-lg border border-gray-200 p-2 text-gray-500 hover:bg-gray-50 hover:text-gray-700"
            >
              <ArrowPathIcon className={`h-4 w-4 ${statsQuery.isFetching ? 'animate-spin' : ''}`} />
            </button>
          </div>
        }
      />

      {/* Alerts */}
      {alerts.length > 0 && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3">
          <div className="flex items-start gap-2">
            <ExclamationTriangleIcon className="mt-0.5 h-4 w-4 text-red-600" />
            <div className="space-y-1">
              {alerts.map((alert, i) => (
                <p key={i} className="text-sm text-red-700">{alert}</p>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Key metrics */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard title="Faturamento" value={formatCurrency(stats.total_revenue)} change={stats.revenue_change} />
        <MetricCard title="Lucro Plataforma" value={formatCurrency(stats.gateway_profit)} change={stats.profit_change} />
        <MetricCard title="Repasse Sellers" value={formatCurrency(stats.seller_payout)} change={stats.payout_change} />
        <MetricCard title="Pendente" value={formatCurrency(stats.pending_amount)} change={stats.pending_change} />
      </div>

      {/* Secondary metrics */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <p className="text-xs font-medium text-gray-500">Taxa de Sucesso</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{stats.success_rate.toFixed(1)}%</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <p className="text-xs font-medium text-gray-500">Saúde Operacional</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{operationalHealth}%</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <p className="text-xs font-medium text-gray-500">Liquidez</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{formatCurrency(liquidityReserve)}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <p className="text-xs font-medium text-gray-500">Pressão de Saque</p>
          <p className={`mt-1 text-2xl font-semibold tabular-nums ${payoutPressure >= 20 ? 'text-amber-600' : 'text-gray-900'}`}>{payoutPressure}%</p>
        </div>
      </div>

      {/* Chart + Controls */}
      <div className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2 rounded-lg border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-medium text-gray-900">Receita</h3>
          <div className="mt-4 h-64">
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-medium text-gray-900">Controles</h3>
          <div className="mt-4 space-y-2">
            <ControlItem label="Pagamentos" enabled={controls.payments_enabled ?? true} />
            <ControlItem label="Novos Saques" enabled={controls.withdrawals_enabled ?? true} />
            <ControlItem label="Processamento" enabled={controls.withdrawal_processing_enabled ?? true} />
            <ControlItem label="Auto Processamento" enabled={controls.auto_withdrawal_enabled ?? false} />
          </div>
          <div className="mt-4 space-y-1.5 border-t border-gray-100 pt-4 text-xs text-gray-500">
            <p>Limite diário: <span className="font-medium text-gray-900">{formatCurrency(controls.daily_withdrawal_limit ?? 0)}</span></p>
            <p>Aprovação manual: <span className="font-medium text-gray-900">{formatCurrency(controls.large_withdrawal_threshold ?? 0)}</span></p>
            <p>Cooldown: <span className="font-medium text-gray-900">{controls.withdrawal_cooldown_seconds ?? 0}s</span></p>
          </div>
          <Link
            to="/admin/finance"
            className="mt-4 block w-full rounded-md bg-gray-900 px-3 py-2 text-center text-sm font-medium text-white hover:bg-gray-800"
          >
            Gerenciar financeiro
          </Link>
        </div>
      </div>

      {/* Withdrawals + Activity */}
      <div className="grid gap-6 xl:grid-cols-2">
        {/* Pending withdrawals */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-900">Saques Pendentes</h3>
            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{pendingWithdrawalsCount}</span>
          </div>

          {pendingWithdrawals.length === 0 ? (
            <p className="mt-4 text-sm text-gray-500">Nenhum saque pendente.</p>
          ) : (
            <div className="mt-4 divide-y divide-gray-100">
              {pendingWithdrawals.slice(0, 5).map((w) => (
                <div key={w.id} className="flex items-center justify-between py-2.5">
                  <div>
                    <p className="text-sm font-medium text-gray-900">{w.customer_name || 'Seller'}</p>
                    <p className="text-xs text-gray-500">{formatDate(w.requested_at)}</p>
                  </div>
                  <p className="text-sm font-medium tabular-nums text-gray-900">{formatCurrency(w.amount)}</p>
                </div>
              ))}
            </div>
          )}

          <div className="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-xs">
            <span className="text-gray-500">Total em fila</span>
            <span className="font-medium text-gray-900">{formatCurrency(pendingWithdrawalsAmount)}</span>
          </div>
        </div>

        {/* Recent activity */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-medium text-gray-900">Atividade Recente</h3>
            <Link to="/admin/monitoring" className="text-xs font-medium text-gray-500 hover:text-gray-700">Ver tudo</Link>
          </div>

          {activities.length === 0 ? (
            <p className="mt-4 text-sm text-gray-500">Sem eventos recentes.</p>
          ) : (
            <div className="mt-4 divide-y divide-gray-100">
              {activities.slice(0, 5).map((a) => (
                <div key={a.id} className="flex items-center justify-between py-2.5">
                  <div>
                    <p className="text-sm text-gray-900">{a.action}</p>
                    <p className="text-xs text-gray-500">{a.user || a.email || 'Sistema'}</p>
                  </div>
                  <div className="text-right">
                    {a.amount != null && <p className="text-sm font-medium tabular-nums text-gray-900">{formatCurrency(a.amount)}</p>}
                    <p className="text-xs text-gray-400">{a.time || formatDate(a.timestamp)}</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Payment methods */}
      <div className="rounded-lg border border-gray-200 bg-white p-5">
        <h3 className="text-sm font-medium text-gray-900">Métodos de Pagamento</h3>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {methods.map((method) => {
            const MethodIcon = getPaymentMethodIcon(method.method);
            return (
              <div key={method.method} className="flex items-center gap-3">
                <div className="rounded-md bg-gray-100 p-2">
                  <MethodIcon className="h-4 w-4 text-gray-600" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium text-gray-900">{method.method}</p>
                    <p className="text-xs text-gray-500">{method.percentage}%</p>
                  </div>
                  <div className="mt-1 h-1.5 rounded-full bg-gray-100">
                    <div className="h-1.5 rounded-full bg-gray-900" style={{ width: `${Math.min(method.percentage, 100)}%` }} />
                  </div>
                  <p className="mt-1 text-xs text-gray-500">{formatCurrency(method.amount)}</p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </AdminPageShell>
  );
}

function MetricCard({ title, value, change }: { title: string; value: string; change: number }) {
  const positive = change >= 0;
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <p className="text-xs font-medium text-gray-500">{title}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{value}</p>
      <div className="mt-2 flex items-center gap-1">
        {positive ? (
          <ArrowTrendingUpIcon className="h-3.5 w-3.5 text-emerald-600" />
        ) : (
          <ArrowTrendingDownIcon className="h-3.5 w-3.5 text-red-600" />
        )}
        <span className={`text-xs font-medium ${positive ? 'text-emerald-600' : 'text-red-600'}`}>
          {Math.abs(change).toFixed(1)}%
        </span>
      </div>
    </div>
  );
}

function ControlItem({ label, enabled }: { label: string; enabled: boolean }) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm text-gray-600">{label}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>
        {enabled ? 'Ativo' : 'Pausado'}
      </span>
    </div>
  );
}
