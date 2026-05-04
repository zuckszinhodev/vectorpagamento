import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ShieldExclamationIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  ServerIcon,
  GlobeAltIcon,
  UserGroupIcon,
  ArrowPathIcon,
  ChartBarIcon,
  EyeIcon,
  NoSymbolIcon,
  SignalIcon,
} from '@heroicons/react/24/outline';

interface SecurityStats {
  total_requests_24h: number;
  blocked_requests_24h: number;
  failed_logins_24h: number;
  active_sessions: number;
  blocked_ips: number;
  rate_limit_hits: number;
  security_alerts: number;
  uptime_percentage: number;
}

interface LiveActivity {
  id: string;
  type: 'request' | 'login' | 'error' | 'block';
  ip: string;
  path: string;
  status: number;
  method: string;
  timestamp: string;
  user_agent?: string;
  country?: string;
}

interface SecurityAlert {
  id: string;
  type: 'brute_force' | 'suspicious_activity' | 'rate_limit' | 'blocked_ip';
  severity: 'low' | 'medium' | 'high' | 'critical';
  message: string;
  ip?: string;
  timestamp: string;
  acknowledged: boolean;
}

export default function AdminMonitoring() {
  const [autoRefresh, setAutoRefresh] = useState(true);

  // Fetch Security Stats
  const { data: stats, isLoading: loadingStats, refetch: refetchStats } = useQuery<SecurityStats>({
    queryKey: ['security-dashboard'],
    queryFn: async () => {
      const response = await api.get('/admin/security/dashboard');
      return response.data?.data || {
        total_requests_24h: 0,
        blocked_requests_24h: 0,
        failed_logins_24h: 0,
        active_sessions: 0,
        blocked_ips: 0,
        rate_limit_hits: 0,
        security_alerts: 0,
        uptime_percentage: 99.9,
      };
    },
    refetchInterval: autoRefresh ? 10000 : false,
  });

  // Fetch Live Activity
  const { data: liveActivityData, isLoading: loadingActivity } = useQuery({
    queryKey: ['security-live'],
    queryFn: async () => {
      const response = await api.get('/admin/security/live');
      return response.data?.data || [];
    },
    refetchInterval: autoRefresh ? 5000 : false,
    retry: false,
  });

  const liveActivity = Array.isArray(liveActivityData) ? liveActivityData : [];

  // Fetch Security Alerts
  const { data: alertsData, isLoading: loadingAlerts } = useQuery({
    queryKey: ['security-alerts'],
    queryFn: async () => {
      const response = await api.get('/admin/security/alerts');
      return response.data?.data || [];
    },
    retry: false,
  });

  const alerts = Array.isArray(alertsData) ? alertsData : [];

  // Fetch Blocked IPs
  const { data: blockedIps = [] } = useQuery({
    queryKey: ['blocked-ips'],
    queryFn: async () => {
      const response = await api.get('/admin/security/blocked-ips');
      return response.data?.data || [];
    },
  });

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical': return 'bg-red-500/10 text-red-500 border-red-500/30';
      case 'high': return 'bg-orange-500/10 text-orange-500 border-orange-500/30';
      case 'medium': return 'bg-amber-500/10 text-amber-500 border-amber-500/30';
      default: return 'bg-blue-500/10 text-blue-500 border-blue-500/30';
    }
  };

  const getStatusColor = (status: number) => {
    if (status >= 500) return 'text-red-500';
    if (status >= 400) return 'text-amber-500';
    if (status >= 300) return 'text-blue-500';
    return 'text-emerald-500';
  };

  const getMethodColor = (method: string) => {
    switch (method) {
      case 'GET': return 'bg-emerald-500/10 text-emerald-500';
      case 'POST': return 'bg-blue-500/10 text-blue-500';
      case 'PUT': return 'bg-amber-500/10 text-amber-500';
      case 'DELETE': return 'bg-red-500/10 text-red-500';
      default: return 'bg-gray-500/10 text-gray-500';
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={ChartBarIcon}
        title="Monitoramento de Seguranca"
        subtitle="Visao em tempo real da seguranca da plataforma"
        actions={
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2 cursor-pointer rounded-lg border border-white/10 bg-white/5 px-3 py-2">
              <input
                type="checkbox"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
              />
              <span className="text-sm text-slate-100">Auto-refresh</span>
            </label>
            <button
              onClick={() => refetchStats()}
              className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
            >
              <ArrowPathIcon className="h-4 w-4" />
              Atualizar
            </button>
          </div>
        }
      />

      {/* Stats Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Requisições (24h)"
          value={stats?.total_requests_24h?.toLocaleString() || '0'}
          icon={GlobeAltIcon}
          color="purple"
          loading={loadingStats}
        />
        <StatCard
          title="Bloqueadas (24h)"
          value={stats?.blocked_requests_24h?.toLocaleString() || '0'}
          icon={NoSymbolIcon}
          color="red"
          loading={loadingStats}
        />
        <StatCard
          title="Logins Falhos"
          value={stats?.failed_logins_24h?.toLocaleString() || '0'}
          icon={ExclamationTriangleIcon}
          color="amber"
          loading={loadingStats}
        />
        <StatCard
          title="Uptime"
          value={`${stats?.uptime_percentage || 99.9}%`}
          icon={SignalIcon}
          color="emerald"
          loading={loadingStats}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Live Activity */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-3">
              <div className="rounded-xl bg-green-500/10 p-2.5">
                <EyeIcon className="h-6 w-6 text-green-500" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-tx-primary">Atividade em Tempo Real</h2>
                <p className="text-sm text-tx-muted">Últimas requisições</p>
              </div>
            </div>
            {autoRefresh && (
              <div className="flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                <span className="text-xs text-tx-muted">Live</span>
              </div>
            )}
          </div>

          {loadingActivity ? (
            <div className="flex items-center justify-center py-8">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
            </div>
          ) : liveActivity.length === 0 ? (
            <div className="text-center py-8">
              <EyeIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
              <p className="text-sm text-tx-muted">Nenhuma atividade recente</p>
            </div>
          ) : (
            <div className="space-y-2 max-h-[400px] overflow-y-auto">
              {liveActivity.slice(0, 15).map((activity) => (
                <div key={activity.id} className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
                  <div className="flex items-center gap-3">
                    <span className={`rounded px-1.5 py-0.5 text-xs font-mono font-medium ${getMethodColor(activity.method)}`}>
                      {activity.method}
                    </span>
                    <span className="text-sm text-tx-primary font-mono truncate max-w-[180px]">{activity.path}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="text-xs text-tx-muted font-mono">{activity.ip}</span>
                    <span className={`text-sm font-medium ${getStatusColor(activity.status)}`}>
                      {activity.status}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Security Alerts */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-4">
            <div className="rounded-xl bg-red-500/10 p-2.5">
              <ShieldExclamationIcon className="h-6 w-6 text-red-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Alertas de Segurança</h2>
              <p className="text-sm text-tx-muted">{alerts.filter(a => !a.acknowledged).length} não reconhecidos</p>
            </div>
          </div>

          {loadingAlerts ? (
            <div className="flex items-center justify-center py-8">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-red-500 border-t-transparent" />
            </div>
          ) : alerts.length === 0 ? (
            <div className="text-center py-8">
              <CheckCircleIcon className="h-12 w-12 text-emerald-500 mx-auto mb-3" />
              <p className="text-sm text-tx-muted">Nenhum alerta de segurança</p>
            </div>
          ) : (
            <div className="space-y-2 max-h-[400px] overflow-y-auto">
              {alerts.slice(0, 10).map((alert) => (
                <div 
                  key={alert.id} 
                  className={`p-3 rounded-lg border ${getSeverityColor(alert.severity)}`}
                >
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-medium uppercase">{alert.severity}</span>
                    <span className="text-xs opacity-70">
                      {new Date(alert.timestamp).toLocaleTimeString('pt-BR')}
                    </span>
                  </div>
                  <p className="text-sm">{alert.message}</p>
                  {alert.ip && (
                    <p className="text-xs opacity-70 mt-1 font-mono">IP: {alert.ip}</p>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Quick Stats */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-blue-500/10 p-2.5">
              <ChartBarIcon className="h-6 w-6 text-blue-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Resumo de Segurança</h2>
              <p className="text-sm text-tx-muted">Métricas das últimas 24 horas</p>
            </div>
          </div>

          <div className="space-y-4">
            <div className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
              <div className="flex items-center gap-3">
                <UserGroupIcon className="h-5 w-5 text-tx-muted" />
                <span className="text-sm text-tx-secondary">Sessões Ativas</span>
              </div>
              <span className="text-lg font-semibold text-tx-primary">{stats?.active_sessions || 0}</span>
            </div>
            <div className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
              <div className="flex items-center gap-3">
                <NoSymbolIcon className="h-5 w-5 text-tx-muted" />
                <span className="text-sm text-tx-secondary">IPs Bloqueados</span>
              </div>
              <span className="text-lg font-semibold text-tx-primary">{stats?.blocked_ips || blockedIps.length}</span>
            </div>
            <div className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
              <div className="flex items-center gap-3">
                <ClockIcon className="h-5 w-5 text-tx-muted" />
                <span className="text-sm text-tx-secondary">Rate Limit Hits</span>
              </div>
              <span className="text-lg font-semibold text-tx-primary">{stats?.rate_limit_hits || 0}</span>
            </div>
            <div className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
              <div className="flex items-center gap-3">
                <ShieldExclamationIcon className="h-5 w-5 text-tx-muted" />
                <span className="text-sm text-tx-secondary">Alertas</span>
              </div>
              <span className="text-lg font-semibold text-tx-primary">{stats?.security_alerts || alerts.length}</span>
            </div>
          </div>
        </div>

        {/* Blocked IPs */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-red-500/10 p-2.5">
              <NoSymbolIcon className="h-6 w-6 text-red-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">IPs Bloqueados</h2>
              <p className="text-sm text-tx-muted">{blockedIps.length} endereço(s)</p>
            </div>
          </div>

          {blockedIps.length === 0 ? (
            <div className="text-center py-8">
              <CheckCircleIcon className="h-12 w-12 text-emerald-500 mx-auto mb-3" />
              <p className="text-sm text-tx-muted">Nenhum IP bloqueado</p>
            </div>
          ) : (
            <div className="space-y-2 max-h-[250px] overflow-y-auto">
              {blockedIps.slice(0, 10).map((ip: any) => (
                <div key={ip.id || ip.ip} className="flex items-center justify-between p-3 rounded-lg bg-surface-page">
                  <div>
                    <p className="text-sm font-mono text-tx-primary">{ip.ip || ip.ip_address}</p>
                    <p className="text-xs text-tx-muted">{ip.reason || 'Bloqueado manualmente'}</p>
                  </div>
                  <span className="text-xs text-tx-muted">
                    {ip.blocked_at ? new Date(ip.blocked_at).toLocaleDateString('pt-BR') : '-'}
                  </span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </AdminPageShell>
  );
}

function StatCard({ 
  title, 
  value, 
  icon: Icon, 
  color, 
  loading 
}: { 
  title: string; 
  value: string; 
  icon: typeof ShieldExclamationIcon; 
  color: 'purple' | 'red' | 'amber' | 'emerald' | 'blue';
  loading?: boolean;
}) {
  const colors = {
    purple: 'bg-green-500/10 text-green-500',
    red: 'bg-red-500/10 text-red-500',
    amber: 'bg-amber-500/10 text-amber-500',
    emerald: 'bg-emerald-500/10 text-emerald-500',
    blue: 'bg-blue-500/10 text-blue-500',
  };

  return (
    <div className="rounded-2xl bg-surface-card p-5 shadow-sm">
      <div className="flex items-center gap-3 mb-3">
        <div className={`rounded-xl p-2.5 ${colors[color]}`}>
          <Icon className="h-5 w-5" />
        </div>
        <span className="text-sm text-tx-muted">{title}</span>
      </div>
      {loading ? (
        <div className="h-8 w-24 bg-surface-hover animate-pulse rounded" />
      ) : (
        <p className="text-2xl font-bold text-tx-primary">{value}</p>
      )}
    </div>
  );
}


