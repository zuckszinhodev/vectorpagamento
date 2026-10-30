import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import { AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  CheckCircleIcon,
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
  type: string;
  severity: 'low' | 'medium' | 'high' | 'critical';
  message: string;
  ip?: string;
  timestamp: string;
  acknowledged: boolean;
}

export default function AdminMonitoring(_props: { embedded?: boolean }) {
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [acknowledgingAlertId, setAcknowledgingAlertId] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const { data: stats, refetch: refetchStats } = useQuery<SecurityStats>({
    queryKey: ['security-dashboard'],
    queryFn: async () => {
      const response = await api.get('/admin/security/dashboard');
      return response.data?.data;
    },
    refetchInterval: autoRefresh ? 10000 : false,
  });

  const { data: liveActivityData } = useQuery({
    queryKey: ['security-live'],
    queryFn: async () => {
      const response = await api.get('/admin/security/live');
      return response.data?.data || [];
    },
    refetchInterval: autoRefresh ? 5000 : false,
    retry: false,
  });

  const liveActivity: LiveActivity[] = Array.isArray(liveActivityData) ? liveActivityData : [];

  const { data: alertsData } = useQuery({
    queryKey: ['security-alerts'],
    queryFn: async () => {
      const response = await api.get('/admin/security/alerts');
      return response.data?.data || [];
    },
    retry: false,
  });

  const alerts: SecurityAlert[] = Array.isArray(alertsData) ? alertsData : [];

  const acknowledgeAlertMutation = useMutation<void, unknown, string, { previousAlerts: SecurityAlert[] }>({
    onMutate: async (alertId: string) => {
      setAcknowledgingAlertId(alertId);

      await queryClient.cancelQueries({ queryKey: ['security-alerts'] });

      const previousAlerts = queryClient.getQueryData<SecurityAlert[]>(['security-alerts']);
      const safePreviousAlerts = Array.isArray(previousAlerts) ? previousAlerts : [];

      queryClient.setQueryData<SecurityAlert[]>(
        ['security-alerts'],
        safePreviousAlerts.filter((alert) => alert.id !== alertId)
      );

      return { previousAlerts: safePreviousAlerts };
    },
    mutationFn: async (alertId: string) => {
      await api.post(`/admin/security/alerts/${alertId}/acknowledge`);
    },
    onError: (_error, _alertId, context) => {
      if (context?.previousAlerts) {
        queryClient.setQueryData(['security-alerts'], context.previousAlerts);
      }

      toast.error('Falha ao reconhecer alerta. Tente novamente.');
    },
    onSuccess: async () => {
      toast.success('Alerta reconhecido com sucesso!');

      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['security-alerts'] }),
        queryClient.invalidateQueries({ queryKey: ['security-dashboard'] }),
      ]);
    },
    onSettled: () => {
      setAcknowledgingAlertId(null);
    },
  });

  const { data: blockedIps = [] } = useQuery({
    queryKey: ['blocked-ips'],
    queryFn: async () => {
      const response = await api.get('/admin/security/blocked-ips');
      return response.data?.data || [];
    },
  });

  const severityCls: Record<string, string> = {
    critical: 'bg-red-50 text-red-700 border-red-200',
    high: 'bg-orange-50 text-orange-700 border-orange-200',
    medium: 'bg-amber-50 text-amber-700 border-amber-200',
    low: 'bg-blue-50 text-blue-700 border-blue-200',
  };

  const statusColor = (s: number) => s >= 500 ? 'text-red-600' : s >= 400 ? 'text-amber-600' : 'text-emerald-600';

  const methodCls: Record<string, string> = {
    GET: 'bg-emerald-50 text-emerald-700',
    POST: 'bg-blue-50 text-blue-700',
    PUT: 'bg-amber-50 text-amber-700',
    DELETE: 'bg-red-50 text-red-700',
  };

  return (
    <AdminPageShell>
      <div className="flex items-center justify-end gap-2">
        <label className="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer">
          <input type="checkbox" checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} className="h-3.5 w-3.5 rounded border-gray-300 text-gray-900" />
          Atualização automática
        </label>
        <button onClick={() => refetchStats()} aria-label="Atualizar" className="rounded-md border border-gray-200 p-1.5 text-gray-500 hover:bg-gray-50">
          <ArrowPathIcon className="h-4 w-4" />
        </button>
      </div>

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Requisições (24h)" value={stats?.total_requests_24h?.toLocaleString() || '0'} />
        <Stat label="Bloqueadas" value={stats?.blocked_requests_24h?.toLocaleString() || '0'} />
        <Stat label="Logins Falhos" value={stats?.failed_logins_24h?.toLocaleString() || '0'} />
        <Stat label="Uptime" value={stats?.uptime_percentage != null ? `${stats.uptime_percentage}%` : '—'} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Live Activity */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-sm font-medium text-gray-900">Atividade em Tempo Real</h3>
            {autoRefresh && <span className="flex items-center gap-1 text-xs text-gray-500"><span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />Live</span>}
          </div>
          {liveActivity.length === 0 ? (
            <p className="text-sm text-gray-500 py-6 text-center">Nenhuma atividade recente</p>
          ) : (
            <div className="space-y-1.5 max-h-[360px] overflow-y-auto">
              {liveActivity.slice(0, 15).map((a) => (
                <div key={a.id} className="flex items-center justify-between rounded-md bg-gray-50 px-3 py-2">
                  <div className="flex items-center gap-2">
                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-mono font-medium ${methodCls[a.method] || 'bg-gray-100 text-gray-600'}`}>{a.method}</span>
                    <span className="text-xs font-mono text-gray-700 truncate max-w-[160px]">{a.path}</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] font-mono text-gray-400">{a.ip}</span>
                    <span className={`text-xs font-medium ${statusColor(a.status)}`}>{a.status}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Alerts */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-medium text-gray-900 mb-4">Alertas de Segurança</h3>
          {alerts.length === 0 ? (
            <div className="py-6 text-center">
              <CheckCircleIcon className="h-8 w-8 text-emerald-500 mx-auto mb-2" />
              <p className="text-sm text-gray-500">Nenhum alerta</p>
            </div>
          ) : (
            <div className="space-y-2 max-h-[360px] overflow-y-auto">
              {alerts.slice(0, 10).map((alert) => (
                <div key={alert.id} className={`rounded-md border px-3 py-2 ${severityCls[alert.severity] || 'bg-gray-50 text-gray-700 border-gray-200'}`}>
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-medium uppercase">{alert.severity}</span>
                    <span className="opacity-70">{new Date(alert.timestamp).toLocaleTimeString('pt-BR')}</span>
                  </div>
                  <p className="text-sm mt-0.5">{alert.message}</p>
                  {alert.ip && <p className="text-xs opacity-70 font-mono mt-0.5">IP: {alert.ip}</p>}
                  <div className="mt-2 flex justify-end">
                    <button
                      type="button"
                      onClick={() => acknowledgeAlertMutation.mutate(alert.id)}
                      disabled={acknowledgingAlertId === alert.id}
                      className="rounded-md border border-current px-2.5 py-1 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {acknowledgingAlertId === alert.id ? 'Reconhecendo...' : 'Reconhecer'}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Summary */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-medium text-gray-900 mb-4">Resumo</h3>
          <div className="space-y-2">
            <Row label="Sessões Ativas" value={stats?.active_sessions || 0} />
            <Row label="IPs Bloqueados" value={stats?.blocked_ips || blockedIps.length} />
            <Row label="Rate Limit Hits" value={stats?.rate_limit_hits || 0} />
            <Row label="Alertas" value={stats?.security_alerts || alerts.length} />
          </div>
        </div>

        {/* Blocked IPs */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-medium text-gray-900 mb-4">IPs Bloqueados</h3>
          {blockedIps.length === 0 ? (
            <p className="text-sm text-gray-500 py-6 text-center">Nenhum IP bloqueado</p>
          ) : (
            <div className="space-y-1.5 max-h-[240px] overflow-y-auto">
              {blockedIps.slice(0, 10).map((ip: any) => (
                <div key={ip.id || ip.ip} className="flex items-center justify-between rounded-md bg-gray-50 px-3 py-2">
                  <div>
                    <p className="text-xs font-mono text-gray-900">{ip.ip || ip.ip_address}</p>
                    <p className="text-[10px] text-gray-500">{ip.reason || 'Bloqueado'}</p>
                  </div>
                  <span className="text-[10px] text-gray-400">{ip.blocked_at ? new Date(ip.blocked_at).toLocaleDateString('pt-BR') : '-'}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </AdminPageShell>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <p className="text-xs font-medium text-gray-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{value}</p>
    </div>
  );
}

function Row({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between rounded-md bg-gray-50 px-3 py-2">
      <span className="text-sm text-gray-600">{label}</span>
      <span className="text-sm font-semibold text-gray-900">{value}</span>
    </div>
  );
}
