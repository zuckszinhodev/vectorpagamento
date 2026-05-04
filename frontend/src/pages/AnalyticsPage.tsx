import { useState, useEffect } from 'react';
import {
 TrendingUp,
 DollarSign,
 Users,
 CreditCard,
 Calendar,
 Download,
 Filter,
} from 'lucide-react';
import { GlowLineChart, GlowDoughnutChart, ChartCard } from '@/components/ui/GlowChart';
import { Spinner } from '@/components/ui/Spinner';
import { api } from '../lib/api';

interface AnalyticsData {
 revenue: {
 total: number;
 change: number;
 chart: number[];
 };
 transactions: {
 total: number;
 approved: number;
 declined: number;
 approval_rate: number;
 };
 customers: {
 total: number;
 new: number;
 returning: number;
 };
 average_ticket: {
 value: number;
 change: number;
 };
}

export default function AnalyticsPage() {
 const [loading, setLoading] = useState(true);
 const [data, setData] = useState<AnalyticsData | null>(null);
 const [dateRange, setDateRange] = useState('30d');
 const [compareWith, setCompareWith] = useState('previous');

 useEffect(() => {
 loadAnalytics();
 }, [dateRange]);

 const loadAnalytics = async () => {
 try {
 const response = await api.get(`/analytics?period=${dateRange}&compare=${compareWith}`);
 setData(response.data.data);
 } catch (error) {
   // Error handled silently
 } finally {
 setLoading(false);
 }
 };

 if (loading || !data) {
 return (
 <div className="flex items-center justify-center py-32">
 <Spinner size="lg" text="Carregando analytics..." />
 </div>
 );
 }

 // Revenue Chart labels
 const revenueChartLabels = Array.from({ length: 30 }, (_, i) => `Dia ${i + 1}`);

 // Approval Rate Chart
 const approvalLabels = ['Aprovadas', 'Recusadas'];
 const approvalData = [data.transactions.approved, data.transactions.declined];
 const approvalColors = ['rgb(34, 197, 94)', 'rgb(239, 68, 68)'];

 // Customer Type Chart
 const customerLabels = ['Novos', 'Recorrentes'];
 const customerData = [data.customers.new, data.customers.returning];
 const customerColors = ['rgb(22, 163, 74)', 'rgb(34, 197, 94)'];

 const formatCurrency = (value: number) => {
 return new Intl.NumberFormat('pt-BR', {
 style: 'currency',
 currency: 'BRL',
 }).format(value);
 };

 const formatPercent = (value: number) => {
 const sign = value >= 0 ? '+' : '';
 return `${sign}${value.toFixed(1)}%`;
 };

 return (
 <div className="p-3 sm:p-6 max-w-7xl mx-auto">
 {/* Header */}
 <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-0 mb-6 sm:mb-8">
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary mb-1 sm:mb-2">
 Analytics Dashboard
 </h1>
 <p className="text-xs sm:text-sm text-tx-muted mt-1">
 Análise detalhada de performance e métricas
 </p>
 </div>

 <div className="flex items-center gap-3">
 {/* Date Range Selector */}
 <select
 value={dateRange}
 onChange={(e) => setDateRange(e.target.value)}
 className="px-4 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary focus:ring-2 focus:ring-accent-primary transition-colors"
 >
 <option value="7d">Últimos 7 dias</option>
 <option value="30d">Últimos 30 dias</option>
 <option value="90d">Últimos 90 dias</option>
 <option value="1y">Último ano</option>
 </select>

 {/* Export Button */}
 <button className="flex items-center gap-2 px-4 py-2 bg-accent-primary text-white rounded-element hover:bg-accent-hover transition-colors hover:shadow-xl hover:shadow-green-500/30">
 <Download className="w-5 h-5" />
 Exportar
 </button>
 </div>
 </div>

 {/* KPI Cards */}
 <div className="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
 {/* Revenue */}
 <div className="group relative rounded-2xl bg-surface-card p-3 sm:p-6 shadow-slate-200/50 border border-border-DEFAULT/50/50 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/10 hover:-translate-y-1 overflow-hidden">
 <div className="absolute -top-20 -right-20 w-40 h-40 bg-gradient-to-br from-green-500 to-green-600 opacity-10 rounded-full blur-3xl group-hover:opacity-30 transition-opacity duration-500 pointer-events-none" />
 <div className="relative flex items-center justify-between mb-2">
 <p className="text-xs sm:text-sm text-tx-secondary">Receita Total</p>
 <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center">
 <DollarSign className="w-5 h-5 text-white" />
 </div>
 </div>
 <p className="relative text-lg sm:text-2xl font-bold text-tx-primary mb-1">
 {formatCurrency(data.revenue.total)}
 </p>
 <p className={`relative text-xs sm:text-sm ${data.revenue.change >= 0 ? 'text-status-success' : 'text-status-error'}`}>
 {formatPercent(data.revenue.change)} vs período anterior
 </p>
 </div>

 {/* Transactions */}
 <div className="group relative rounded-2xl bg-surface-card p-3 sm:p-6 shadow-slate-200/50 border border-border-DEFAULT/50/50 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/10 hover:-translate-y-1 overflow-hidden">
 <div className="absolute -top-20 -right-20 w-40 h-40 bg-gradient-to-br from-blue-500 to-indigo-600 opacity-10 rounded-full blur-3xl group-hover:opacity-30 transition-opacity duration-500 pointer-events-none" />
 <div className="relative flex items-center justify-between mb-2">
 <p className="text-xs sm:text-sm text-tx-secondary">Transações</p>
 <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center">
 <CreditCard className="w-5 h-5 text-white" />
 </div>
 </div>
 <p className="relative text-lg sm:text-2xl font-bold text-tx-primary mb-1">
 {data.transactions.total.toLocaleString()}
 </p>
 <p className="relative text-xs sm:text-sm text-status-success">
 {data.transactions.approval_rate.toFixed(1)}% de aprovação
 </p>
 </div>

 {/* Customers */}
 <div className="group relative rounded-2xl bg-surface-card p-3 sm:p-6 shadow-slate-200/50 border border-border-DEFAULT/50/50 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/10 hover:-translate-y-1 overflow-hidden">
 <div className="absolute -top-20 -right-20 w-40 h-40 bg-gradient-to-br from-fuchsia-500 to-green-600 opacity-10 rounded-full blur-3xl group-hover:opacity-30 transition-opacity duration-500 pointer-events-none" />
 <div className="relative flex items-center justify-between mb-2">
 <p className="text-xs sm:text-sm text-tx-secondary">Clientes</p>
 <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-fuchsia-500 to-green-600 flex items-center justify-center">
 <Users className="w-5 h-5 text-white" />
 </div>
 </div>
 <p className="relative text-lg sm:text-2xl font-bold text-tx-primary mb-1">
 {data.customers.total.toLocaleString()}
 </p>
 <p className="relative text-xs sm:text-sm text-accent-primary">
 {data.customers.new} novos clientes
 </p>
 </div>

 {/* Average Ticket */}
 <div className="group relative rounded-2xl bg-surface-card p-3 sm:p-6 shadow-slate-200/50 border border-border-DEFAULT/50/50 transition-all duration-300 hover:shadow-xl hover:shadow-green-500/10 hover:-translate-y-1 overflow-hidden">
 <div className="absolute -top-20 -right-20 w-40 h-40 bg-gradient-to-br from-orange-500 to-amber-600 opacity-10 rounded-full blur-3xl group-hover:opacity-30 transition-opacity duration-500 pointer-events-none" />
 <div className="relative flex items-center justify-between mb-2">
 <p className="text-xs sm:text-sm text-tx-secondary">Ticket Médio</p>
 <div className="h-10 w-10 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center">
 <TrendingUp className="w-5 h-5 text-white" />
 </div>
 </div>
 <p className="relative text-lg sm:text-2xl font-bold text-tx-primary mb-1">
 {formatCurrency(data.average_ticket.value)}
 </p>
 <p className={`relative text-xs sm:text-sm ${data.average_ticket.change >= 0 ? 'text-status-success' : 'text-status-error'}`}>
 {formatPercent(data.average_ticket.change)} vs anterior
 </p>
 </div>
 </div>

 {/* Charts Grid */}
 <div className="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-6 mb-6 sm:mb-8">
 {/* Revenue Trend */}
 <ChartCard title="Evolução da Receita" subtitle="Acompanhe o crescimento ao longo do tempo">
 <GlowLineChart
 labels={revenueChartLabels}
 data={data.revenue.chart}
 label="Receita"
 color="purple"
 height="h-64"
 tension={0.45}
 formatTooltip={(value) => formatCurrency(value)}
 formatYTick={(value) => formatCurrency(value)}
 />
 </ChartCard>

 {/* Approval Rate */}
 <ChartCard title="Taxa de Aprovação" subtitle="Distribuição de aprovações vs recusas">
 <GlowDoughnutChart
 labels={approvalLabels}
 data={approvalData}
 colors={approvalColors}
 height={250}
 />
 </ChartCard>

 {/* Customer Breakdown */}
 <ChartCard title="Perfil de Clientes" subtitle="Novos vs recorrentes">
 <GlowDoughnutChart
 labels={customerLabels}
 data={customerData}
 colors={customerColors}
 height={250}
 />
 </ChartCard>

 {/* Top Products */}
 <ChartCard title="Produtos Mais Vendidos" subtitle="Ranking por receita no período">
 <div className="space-y-4">
 {(data as any)?.top_products?.length > 0 ? (
 ((data as any).top_products as any[]).map((product: any, i: number) => (
 <div key={product.id || i} className="flex items-center justify-between py-3 border-b border-border-DEFAULT last:border-0 group/item hover:bg-accent-subtle0/5 rounded-element px-2 -mx-2 transition-colors">
 <div className="flex items-center gap-3">
 <div className="h-8 w-8 rounded-lg bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white font-bold text-xs">
 {i + 1}
 </div>
 <div>
 <p className="font-medium text-tx-primary">{product.name}</p>
 <p className="text-sm text-tx-muted">{product.sales_count || 0} vendas</p>
 </div>
 </div>
 <p className="font-semibold text-tx-primary">
 {formatCurrency(product.total_revenue || product.price || 0)}
 </p>
 </div>
 ))
 ) : (
 <p className="text-tx-muted text-sm text-center py-8">Nenhum produto vendido no período</p>
 )}
 </div>
 </ChartCard>
 </div>

 {/* Conversion Funnel */}
 <ChartCard title="Funil de Conversão" subtitle="Acompanhe as etapas do funil">
 <div className="space-y-5">
 {((data as any)?.funnel?.length > 0
 ? (data as any).funnel
 : [
 { stage: 'Checkout Iniciado', count: 0, rate: 0 },
 { stage: 'Pagamento Pendente', count: 0, rate: 0 },
 { stage: 'Pagamento Aprovado', count: 0, rate: 0 },
 ]
 ).map((stage: any, i: number) => (
 <div key={i}>
 <div className="flex items-center justify-between mb-2">
 <span className="text-sm font-medium text-tx-primary">
 {stage.stage}
 </span>
 <span className="text-sm text-tx-secondary">
 {stage.count} ({stage.rate}%)
 </span>
 </div>
 <div className="w-full bg-surface-hover rounded-full h-2.5 overflow-hidden">
 <div
 className="h-2.5 rounded-full bg-gradient-to-r from-green-600 to-fuchsia-500 transition-all duration-1000 ease-out"
 style={{ width: `${stage.rate}%` }}
 />
 </div>
 </div>
 ))}
 </div>
 </ChartCard>
 </div>
 );
}


