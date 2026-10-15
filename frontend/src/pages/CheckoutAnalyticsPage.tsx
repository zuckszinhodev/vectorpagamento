import { useEffect, useState, useMemo } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArrowLeftIcon,
  EyeIcon,
  CurrencyDollarIcon,
  ArrowTrendingUpIcon,
  ShoppingCartIcon,
  ArrowPathIcon,
  ExclamationTriangleIcon,
  BanknotesIcon,
  ReceiptPercentIcon,
  ClockIcon,
  UserGroupIcon,
  GlobeAltIcon,
} from '@heroicons/react/24/outline';
import { api, formatCurrency } from '@/lib/api';
import { domainsService, CustomDomain } from '@/services/api/domains.service';
import toast from 'react-hot-toast';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { Line, Doughnut, Bar } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Title, Tooltip, Legend, Filler);

interface OverviewData {
  views: number;
  conversions: number;
  conversion_rate: number;
  total_revenue: number;
  period_revenue: number;
  all_time_revenue: number;
  avg_ticket: number;
  total_charges: number;
  approved_charges: number;
  pending_charges: number;
  failed_charges: number;
  refunded_count: number;
  refunded_amount: number;
  chargeback_count: number;
  total_fees: number;
  net_revenue: number;
}

interface RecentCharge {
  id: string;
  amount: number;
  status: string;
  payment_method: string;
  customer_name: string;
  customer_email: string | null;
  created_at: string;
  installments: number;
}

interface AnalyticsData {
  overview: OverviewData;
  by_payment_method: Record<string, { count: number; total: number; approved: number }>;
  by_status: Record<string, number>;
  by_installments: Record<string, number>;
  daily_chart: Array<{ date: string; revenue: number; sales: number }>;
  recent_charges: RecentCharge[];
  period: string;
  period_days: number;
}

interface CheckoutData {
  id: string;
  name: string;
  slug: string;
  status: string;
}

const STATUS_LABELS: Record<string, string> = {
  pending: 'Pendente', processing: 'Processando', authorized: 'Autorizado',
  approved: 'Aprovado', declined: 'Recusado', cancelled: 'Cancelado',
  refunded: 'Reembolsado', partially_refunded: 'Reembolso Parcial',
  chargeback: 'Chargeback', expired: 'Expirado', failed: 'Falhou',
};

const STATUS_COLORS: Record<string, string> = {
  approved: '#22c55e', authorized: '#3b82f6', pending: '#eab308',
  processing: '#f97316', declined: '#ef4444', failed: '#ef4444',
  cancelled: '#6b7280', refunded: '#a855f7', chargeback: '#dc2626', expired: '#9ca3af',
};

const METHOD_LABELS: Record<string, string> = {
  credit_card: 'Cartão de Crédito', pix: 'PIX', boleto: 'Boleto', debit_card: 'Cartão de Débito',
};

const METHOD_COLORS: Record<string, string> = {
  credit_card: '#10b981', pix: '#22c55e', boleto: '#f97316', debit_card: '#3b82f6',
};

const STATUS_BADGE: Record<string, string> = {
  approved: 'bg-green-500/10 text-green-500', authorized: 'bg-blue-500/10 text-blue-500',
  pending: 'bg-yellow-500/10 text-yellow-500', processing: 'bg-orange-500/10 text-orange-500',
  declined: 'bg-red-500/10 text-red-500', failed: 'bg-red-500/10 text-red-500',
  cancelled: 'bg-gray-500/10 text-gray-400', refunded: 'bg-emerald-500/10 text-emerald-500',
  chargeback: 'bg-red-600/10 text-red-400', expired: 'bg-gray-500/10 text-gray-400',
};

export default function CheckoutAnalyticsPage() {
  const { id } = useParams<{ id: string }>();
  const [checkout, setCheckout] = useState<CheckoutData | null>(null);
  const [analytics, setAnalytics] = useState<AnalyticsData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [period, setPeriod] = useState('30d');

  const { data: domainsData } = useQuery({
    queryKey: ['domains'],
    queryFn: () => domainsService.list(),
  });

  const checkoutBaseUrl = useMemo(() => {
    const verified = (domainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');
    const primary = verified.find((d: CustomDomain) => d.is_primary) || verified[0];
    return primary ? `https://${primary.domain}` : window.location.origin;
  }, [domainsData]);

  const fetchData = async (selectedPeriod: string) => {
    if (!id) return;
    setIsLoading(true);
    try {
      const [checkoutRes, analyticsRes] = await Promise.all([
        api.get(`/checkouts/${id}`),
        api.get(`/checkouts/${id}/analytics?period=${selectedPeriod}`),
      ]);
      if (checkoutRes.data.success) setCheckout(checkoutRes.data.data);
      if (analyticsRes.data.success) setAnalytics(analyticsRes.data.data);
    } catch {
      toast.error('Erro ao carregar analytics');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => { fetchData(period); }, [id, period]);

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
      </div>
    );
  }

  if (!checkout || !analytics) {
    return (
      <div className="text-center py-12">
        <p className="text-tx-muted">Checkout não encontrado</p>
        <Link to="/products" className="mt-4 text-accent-primary hover:underline">Voltar para produtos</Link>
      </div>
    );
  }

  const { overview } = analytics;

  const revenueChartData = {
    labels: analytics.daily_chart.map((d) => {
      const date = new Date(d.date + 'T12:00:00');
      return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
    }),
    datasets: [
      {
        label: 'Receita (R$)',
        data: analytics.daily_chart.map((d) => d.revenue / 100),
        borderColor: '#10b981',
        backgroundColor: 'rgba(139, 92, 246, 0.1)',
        fill: true,
        tension: 0.4,
        pointRadius: 2,
        pointHoverRadius: 5,
      },
      {
        label: 'Vendas',
        data: analytics.daily_chart.map((d) => d.sales),
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34, 197, 94, 0.1)',
        fill: false,
        tension: 0.4,
        pointRadius: 2,
        pointHoverRadius: 5,
        yAxisID: 'y1',
      },
    ],
  };

  const revenueChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index' as const, intersect: false },
    plugins: {
      legend: { display: true, position: 'top' as const, labels: { color: '#94a3b8', font: { size: 11 } } },
      tooltip: {
        backgroundColor: '#1e293b',
        titleColor: '#f1f5f9',
        bodyColor: '#cbd5e1',
        callbacks: {
          label: (ctx: any) => ctx.datasetIndex === 0 ? `Receita: R$ ${ctx.parsed.y.toFixed(2)}` : `Vendas: ${ctx.parsed.y}`,
        },
      },
    },
    scales: {
      x: { ticks: { color: '#64748b', maxRotation: 0, maxTicksLimit: 15, font: { size: 10 } }, grid: { display: false } },
      y: { position: 'left' as const, ticks: { color: '#10b981', callback: (v: any) => `R$ ${v}` }, grid: { color: 'rgba(148,163,184,0.1)' } },
      y1: { position: 'right' as const, ticks: { color: '#22c55e', stepSize: 1 }, grid: { display: false } },
    },
  };

  const methodEntries = Object.entries(analytics.by_payment_method);
  const methodChartData = {
    labels: methodEntries.map(([k]) => METHOD_LABELS[k] || k),
    datasets: [{ data: methodEntries.map(([, v]) => v.approved), backgroundColor: methodEntries.map(([k]) => METHOD_COLORS[k] || '#6b7280'), borderWidth: 0 }],
  };

  const statusEntries = Object.entries(analytics.by_status).filter(([, v]) => v > 0);
  const statusChartData = {
    labels: statusEntries.map(([k]) => STATUS_LABELS[k] || k),
    datasets: [{ data: statusEntries.map(([, v]) => v), backgroundColor: statusEntries.map(([k]) => STATUS_COLORS[k] || '#6b7280'), borderWidth: 0 }],
  };

  const doughnutOptions = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' as const, labels: { color: '#94a3b8', font: { size: 11 }, padding: 12 } } },
    cutout: '65%',
  };

  const installmentEntries = Object.entries(analytics.by_installments);
  const installmentChartData = {
    labels: installmentEntries.map(([k]) => `${k}x`),
    datasets: [{ label: 'Vendas', data: installmentEntries.map(([, v]) => v), backgroundColor: '#10b981', borderRadius: 6 }],
  };

  const barOptions = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#64748b' }, grid: { display: false } },
      y: { ticks: { color: '#64748b', stepSize: 1 }, grid: { color: 'rgba(148,163,184,0.1)' } },
    },
  };

  const statCards = [
    { label: 'Visualizações', value: overview.views.toLocaleString('pt-BR'), icon: EyeIcon, color: 'text-blue-500', bg: 'bg-blue-500/10' },
    { label: 'Vendas Aprovadas', value: overview.approved_charges.toLocaleString('pt-BR'), icon: ShoppingCartIcon, color: 'text-green-500', bg: 'bg-green-500/10' },
    { label: 'Taxa de Conversão', value: `${overview.conversion_rate.toFixed(2)}%`, icon: ArrowTrendingUpIcon, color: 'text-yellow-500', bg: 'bg-yellow-500/10' },
    { label: 'Receita no Período', value: formatCurrency(overview.period_revenue), icon: CurrencyDollarIcon, color: 'text-emerald-500', bg: 'bg-emerald-500/10' },
    { label: 'Ticket Médio', value: formatCurrency(overview.avg_ticket), icon: ReceiptPercentIcon, color: 'text-cyan-500', bg: 'bg-cyan-500/10' },
    { label: 'Receita Líquida', value: formatCurrency(overview.net_revenue), icon: BanknotesIcon, color: 'text-emerald-500', bg: 'bg-emerald-500/10' },
  ];

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="flex items-center gap-3">
          <Link to="/products" className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <ArrowLeftIcon className="h-5 w-5" />
          </Link>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Analytics — {checkout.name}</h1>
            <p className="text-sm text-tx-muted mt-0.5 flex items-center gap-2">
              <span className={`inline-block h-2 w-2 rounded-full ${checkout.status === 'active' ? 'bg-green-500' : 'bg-gray-400'}`} />
              {checkout.status === 'active' ? 'Ativo' : 'Inativo'}
              <span className="text-border-DEFAULT">·</span>
              /{checkout.slug}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/*
            O mapa ao vivo é um widget do DashboardLayout. Um evento de janela
            evita passar props por toda a árvore só para abri-lo daqui.
          */}
          <button
            onClick={() =>
              window.dispatchEvent(
                new CustomEvent('live-map:open', {
                  detail: { checkoutId: id, label: checkout.name },
                }),
              )
            }
            title="Ver acessos deste checkout no mapa ao vivo"
            className="inline-flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/20"
          >
            <GlobeAltIcon className="h-4 w-4" />
            Mapa ao vivo
          </button>

          <div className="flex rounded-lg bg-surface-card border border-border-DEFAULT overflow-hidden">
            {[{ value: '7d', label: '7D' }, { value: '15d', label: '15D' }, { value: '30d', label: '30D' }, { value: '90d', label: '90D' }, { value: '365d', label: '1A' }].map((p) => (
              <button key={p.value} onClick={() => setPeriod(p.value)}
                className={`px-3 py-1.5 text-xs font-medium transition-colors ${period === p.value ? 'bg-accent-primary text-white' : 'text-tx-muted hover:bg-surface-hover'}`}>
                {p.label}
              </button>
            ))}
          </div>
          <Link to={`/checkout-builder/${checkout.id}`} className="rounded-lg bg-accent-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-accent-hover">Editar</Link>
          <a href={`${checkoutBaseUrl}/checkout/${checkout.slug}`} target="_blank" rel="noopener noreferrer"
            className="rounded-lg border border-border-DEFAULT px-3 py-1.5 text-xs font-medium text-tx-secondary hover:bg-surface-hover">Visualizar</a>
        </div>
      </div>

      {/* Stat Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {statCards.map((stat) => (
          <div key={stat.label} className="rounded-xl bg-surface-card p-3 sm:p-4">
            <div className="flex items-center gap-2 mb-2">
              <div className={`rounded-lg p-1.5 ${stat.bg}`}><stat.icon className={`h-4 w-4 ${stat.color}`} /></div>
            </div>
            <p className="text-lg sm:text-xl font-bold text-tx-primary">{stat.value}</p>
            <p className="text-[10px] sm:text-xs text-tx-muted mt-0.5">{stat.label}</p>
          </div>
        ))}
      </div>

      {/* Secondary stats */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div className="rounded-xl bg-surface-card p-3 flex items-center gap-3">
          <div className="rounded-lg p-2 bg-orange-500/10"><ClockIcon className="h-4 w-4 text-orange-500" /></div>
          <div><p className="text-sm font-semibold text-tx-primary">{overview.pending_charges}</p><p className="text-[10px] text-tx-muted">Pendentes</p></div>
        </div>
        <div className="rounded-xl bg-surface-card p-3 flex items-center gap-3">
          <div className="rounded-lg p-2 bg-red-500/10"><ExclamationTriangleIcon className="h-4 w-4 text-red-500" /></div>
          <div><p className="text-sm font-semibold text-tx-primary">{overview.failed_charges}</p><p className="text-[10px] text-tx-muted">Falhas</p></div>
        </div>
        <div className="rounded-xl bg-surface-card p-3 flex items-center gap-3">
          <div className="rounded-lg p-2 bg-emerald-500/10"><ArrowPathIcon className="h-4 w-4 text-emerald-500" /></div>
          <div><p className="text-sm font-semibold text-tx-primary">{overview.refunded_count}</p><p className="text-[10px] text-tx-muted">Reembolsos ({formatCurrency(overview.refunded_amount)})</p></div>
        </div>
        <div className="rounded-xl bg-surface-card p-3 flex items-center gap-3">
          <div className="rounded-lg p-2 bg-red-600/10"><ExclamationTriangleIcon className="h-4 w-4 text-red-600" /></div>
          <div><p className="text-sm font-semibold text-tx-primary">{overview.chargeback_count}</p><p className="text-[10px] text-tx-muted">Chargebacks</p></div>
        </div>
      </div>

      {/* Revenue Chart */}
      <div className="rounded-xl bg-surface-card p-4 sm:p-6">
        <h2 className="text-sm font-semibold text-tx-primary mb-4">Receita & Vendas — Últimos {analytics.period_days} dias</h2>
        <div className="h-64 sm:h-80">
          <Line data={revenueChartData} options={revenueChartOptions} />
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div className="rounded-xl bg-surface-card p-4">
          <h3 className="text-sm font-semibold text-tx-primary mb-3">Métodos de Pagamento</h3>
          {methodEntries.length > 0 ? (
            <>
              <div className="h-48"><Doughnut data={methodChartData} options={doughnutOptions} /></div>
              <div className="mt-3 space-y-1.5">
                {methodEntries.map(([method, data]) => (
                  <div key={method} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: METHOD_COLORS[method] || '#6b7280' }} />
                      <span className="text-tx-muted">{METHOD_LABELS[method] || method}</span>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-tx-secondary font-medium">{data.approved}</span>
                      <span className="text-tx-muted">{formatCurrency(data.total)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </>
          ) : <p className="text-xs text-tx-muted text-center py-8">Sem dados no período</p>}
        </div>

        <div className="rounded-xl bg-surface-card p-4">
          <h3 className="text-sm font-semibold text-tx-primary mb-3">Status das Cobranças</h3>
          {statusEntries.length > 0 ? (
            <>
              <div className="h-48"><Doughnut data={statusChartData} options={doughnutOptions} /></div>
              <div className="mt-3 space-y-1.5">
                {statusEntries.map(([status, count]) => (
                  <div key={status} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: STATUS_COLORS[status] || '#6b7280' }} />
                      <span className="text-tx-muted">{STATUS_LABELS[status] || status}</span>
                    </div>
                    <span className="text-tx-secondary font-medium">{count}</span>
                  </div>
                ))}
              </div>
            </>
          ) : <p className="text-xs text-tx-muted text-center py-8">Sem dados no período</p>}
        </div>

        <div className="rounded-xl bg-surface-card p-4">
          <h3 className="text-sm font-semibold text-tx-primary mb-3">Parcelas (Cartão)</h3>
          {installmentEntries.length > 0 ? (
            <div className="h-48"><Bar data={installmentChartData} options={barOptions} /></div>
          ) : <p className="text-xs text-tx-muted text-center py-8">Sem vendas no cartão no período</p>}
        </div>
      </div>

      {/* Funnel */}
      <div className="rounded-xl bg-surface-card p-4 sm:p-6">
        <h2 className="text-sm font-semibold text-tx-primary mb-4">Funil de Conversão</h2>
        <div className="flex flex-col sm:flex-row items-stretch">
          {[
            { label: 'Visualizações', value: overview.views, color: 'bg-blue-500' },
            { label: 'Cobranças Criadas', value: overview.total_charges, color: 'bg-orange-500' },
            { label: 'Aprovadas', value: overview.approved_charges, color: 'bg-green-500' },
          ].map((step, i, arr) => {
            const pct = arr[0].value > 0 ? ((step.value / arr[0].value) * 100).toFixed(1) : '0.0';
            const dropoff = i > 0 && arr[i - 1].value > 0 ? (((arr[i - 1].value - step.value) / arr[i - 1].value) * 100).toFixed(1) : null;
            return (
              <div key={step.label} className="flex-1 relative">
                {i > 0 && <div className="hidden sm:flex absolute -left-3 top-1/2 -translate-y-1/2 z-10 text-tx-muted text-lg">→</div>}
                <div className="p-4 text-center">
                  <div className={`h-1.5 rounded-full ${step.color} mb-3`} style={{ width: `${Math.max(Number(pct), 5)}%`, margin: '0 auto' }} />
                  <p className="text-2xl font-bold text-tx-primary">{step.value.toLocaleString('pt-BR')}</p>
                  <p className="text-xs text-tx-muted mt-1">{step.label}</p>
                  <p className="text-[10px] text-tx-muted">{pct}% do total</p>
                  {dropoff && Number(dropoff) > 0 && <p className="text-[10px] text-red-400 mt-0.5">↓ {dropoff}% perda</p>}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Recent Charges */}
      <div className="rounded-xl bg-surface-card p-4 sm:p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold text-tx-primary">Últimas Cobranças</h2>
          <Link to="/charges" className="text-xs text-accent-primary hover:underline">Ver todas</Link>
        </div>
        {analytics.recent_charges.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-border-DEFAULT">
                  <th className="pb-2 text-left text-tx-muted font-medium">Cliente</th>
                  <th className="pb-2 text-left text-tx-muted font-medium">Valor</th>
                  <th className="pb-2 text-left text-tx-muted font-medium">Método</th>
                  <th className="pb-2 text-left text-tx-muted font-medium">Parcelas</th>
                  <th className="pb-2 text-left text-tx-muted font-medium">Status</th>
                  <th className="pb-2 text-right text-tx-muted font-medium">Data</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-DEFAULT">
                {analytics.recent_charges.map((charge) => (
                  <tr key={charge.id} className="hover:bg-surface-hover/50">
                    <td className="py-2.5">
                      <p className="font-medium text-tx-primary">{charge.customer_name}</p>
                      {charge.customer_email && <p className="text-tx-muted text-[10px]">{charge.customer_email}</p>}
                    </td>
                    <td className="py-2.5 font-medium text-tx-primary">{formatCurrency(charge.amount)}</td>
                    <td className="py-2.5 text-tx-muted">{METHOD_LABELS[charge.payment_method] || charge.payment_method}</td>
                    <td className="py-2.5 text-tx-muted">{charge.installments}x</td>
                    <td className="py-2.5">
                      <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_BADGE[charge.status] || 'bg-gray-500/10 text-gray-400'}`}>
                        {STATUS_LABELS[charge.status] || charge.status}
                      </span>
                    </td>
                    <td className="py-2.5 text-right text-tx-muted">
                      {new Date(charge.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="text-center py-8">
            <UserGroupIcon className="h-8 w-8 text-tx-muted mx-auto mb-2" />
            <p className="text-sm text-tx-muted">Nenhuma cobrança no período selecionado</p>
          </div>
        )}
      </div>

      {/* Revenue summary */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="rounded-xl bg-surface-card p-4">
          <p className="text-xs text-tx-muted mb-1">Receita Total (All Time)</p>
          <p className="text-xl font-bold text-tx-primary">{formatCurrency(overview.all_time_revenue)}</p>
        </div>
        <div className="rounded-xl bg-surface-card p-4">
          <p className="text-xs text-tx-muted mb-1">Total em Taxas</p>
          <p className="text-xl font-bold text-red-400">{formatCurrency(overview.total_fees)}</p>
        </div>
        <div className="rounded-xl bg-surface-card p-4">
          <p className="text-xs text-tx-muted mb-1">Total de Cobranças no Período</p>
          <p className="text-xl font-bold text-tx-primary">{overview.total_charges}</p>
        </div>
      </div>
    </div>
  );
}
