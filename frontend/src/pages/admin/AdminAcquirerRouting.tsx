import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  ArrowPathIcon,
  BoltIcon,
  ChartBarIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  PencilSquareIcon,
  PlusIcon,
  ServerStackIcon,
  SignalIcon,
  SignalSlashIcon,
  TrashIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

/* ── Tipos ──────────────────────────────────────────────────── */

type HealthStateValue = 'online' | 'degraded' | 'offline' | 'unknown';
type CircuitStateValue = 'closed' | 'open' | 'half_open';

interface CredentialField {
  field_key: string;
  label: string;
  type: string;
  options?: string[] | null;
  masked: string;
  length: number;
  configured: boolean;
  rotated_at: string | null;
  deprecated?: boolean;
}

interface RoutingProfile {
  routing_enabled: boolean;
  priority: number;
  weight: number;
  max_tps: number | null;
  max_concurrent: number | null;
  connect_timeout_ms: number;
  timeout_ms: number;
  max_retries: number;
  retry_backoff_ms: number;
  retry_backoff_max_ms: number;
  cb_failure_threshold: number;
  cb_cooldown_seconds: number;
  cb_half_open_max_calls: number;
  cb_success_threshold: number;
  health_check_enabled: boolean;
  health_check_url: string | null;
  health_check_interval_seconds: number;
  min_amount_cents: number | null;
  max_amount_cents: number | null;
  rules: Record<string, unknown>;
}

interface HealthSnapshot {
  acquirer_key: string;
  state: HealthStateValue;
  state_label: string;
  circuit_state: CircuitStateValue;
  circuit_state_label: string;
  consecutive_failures: number;
  circuit_trip_count: number;
  last_latency_ms: number | null;
  avg_latency_ms: number | null;
  availability_percent: number;
  last_error: string | null;
  last_error_at: string | null;
  last_check_at: string | null;
}

interface MetricsSnapshot {
  attempts: number;
  successes: number;
  failures: number;
  approval_rate: number;
  error_rate: number;
  avg_latency_ms: number;
  errors_per_minute: number;
  observed_tps: number;
  fallback_ins: number;
  volume_cents: number;
}

interface AcquirerRow {
  id: string;
  acquirer_key: string;
  display_name: string;
  nominal_name: string | null;
  type: string;
  enabled: boolean;
  supported_methods: string[];
  module_available: boolean;
  credentials: Record<string, CredentialField>;
  credentials_complete: boolean;
  routing: RoutingProfile;
  health: HealthSnapshot;
  metrics: MetricsSnapshot | null;
}

interface DashboardSummary {
  total: number;
  online: number;
  degraded: number;
  offline: number;
  attempts: number;
  approval_rate: number | null;
  errors_per_minute: number;
  tps: number;
  avg_latency_ms: number | null;
  fallbacks: number;
  volume_cents: number;
}

interface Policy {
  scope: string;
  account_id: string | null;
  payment_method: string;
  payment_method_label: string;
  strategy: string;
  strategy_label: string;
  failover_enabled: boolean;
  max_attempts: number;
  total_budget_ms: number;
  strict_health_check: boolean;
}

interface StrategyOption {
  value: string;
  label: string;
}

/* ── Utilitários ────────────────────────────────────────────── */

const currency = (cents: number) =>
  (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

const percent = (ratio: number | null | undefined) =>
  ratio === null || ratio === undefined ? '—' : `${(ratio * 100).toFixed(1)}%`;

const ms = (value: number | null | undefined) =>
  value === null || value === undefined ? '—' : `${Math.round(value)} ms`;

const HEALTH_STYLES: Record<HealthStateValue, string> = {
  online: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
  degraded: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
  offline: 'bg-red-500/15 text-red-400 border-red-500/30',
  unknown: 'bg-slate-500/15 text-slate-400 border-slate-500/30',
};

const CIRCUIT_STYLES: Record<CircuitStateValue, string> = {
  closed: 'bg-emerald-500/10 text-emerald-400',
  half_open: 'bg-amber-500/10 text-amber-400',
  open: 'bg-red-500/10 text-red-400',
};

/* ── Página ─────────────────────────────────────────────────── */

export default function AdminAcquirerRouting() {
  const queryClient = useQueryClient();
  const [windowMinutes, setWindowMinutes] = useState(60);
  const [editing, setEditing] = useState<AcquirerRow | null>(null);
  const [creating, setCreating] = useState(false);

  const acquirersQuery = useQuery({
    queryKey: ['routing', 'acquirers', windowMinutes],
    queryFn: async () => {
      const { data } = await api.get('/admin/routing/acquirers', { params: { window_minutes: windowMinutes } });
      return data as { data: AcquirerRow[]; meta: { strategies: StrategyOption[]; available_modules: string[] } };
    },
    // Monitoramento em tempo real: o painel reflete o estado atual do roteamento.
    refetchInterval: 15000,
  });

  const dashboardQuery = useQuery({
    queryKey: ['routing', 'dashboard', windowMinutes],
    queryFn: async () => {
      const { data } = await api.get('/admin/routing/dashboard', { params: { window_minutes: windowMinutes } });
      return data.data as { summary: DashboardSummary };
    },
    refetchInterval: 15000,
  });

  const policiesQuery = useQuery({
    queryKey: ['routing', 'policies'],
    queryFn: async () => {
      const { data } = await api.get('/admin/routing/policies');
      return data.data as { global: Policy[]; strategies: StrategyOption[] };
    },
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['routing'] });
  };

  const toggleMutation = useMutation({
    mutationFn: async ({ id, enabled }: { id: string; enabled: boolean }) =>
      api.post(`/admin/routing/acquirers/${id}/toggle`, { enabled }),
    onSuccess: (res) => {
      toast.success(res.data?.message ?? 'Status atualizado.');
      invalidate();
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message ?? 'Falha ao alterar status.');
    },
  });

  const testMutation = useMutation({
    mutationFn: async (id: string) => api.post(`/admin/routing/acquirers/${id}/test-connection`),
    onSuccess: (res) => {
      const payload = res.data?.data;
      if (payload?.skipped) toast(res.data.message, { icon: 'ℹ️' });
      else if (payload?.healthy) toast.success(res.data.message);
      else toast.error(res.data.message);
      invalidate();
    },
    onError: () => toast.error('Falha ao testar conexão.'),
  });

  const resetCircuitMutation = useMutation({
    mutationFn: async (id: string) => api.post(`/admin/routing/acquirers/${id}/reset-circuit`),
    onSuccess: () => {
      toast.success('Circuit breaker resetado.');
      invalidate();
    },
    onError: () => toast.error('Falha ao resetar circuit breaker.'),
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => api.delete(`/admin/routing/acquirers/${id}`),
    onSuccess: (res) => {
      toast.success(res.data?.message ?? 'Adquirente excluída.');
      invalidate();
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message ?? 'Falha ao excluir adquirente.');
    },
  });

  const acquirers = acquirersQuery.data?.data ?? [];
  const summary = dashboardQuery.data?.summary;
  const strategies = policiesQuery.data?.strategies ?? acquirersQuery.data?.meta.strategies ?? [];

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Roteamento de Adquirentes"
        subtitle="Cadastro, balanceamento inteligente, fallback automático e monitoramento em tempo real."
        icon={ServerStackIcon}
        actions={
          <>
            <select
              value={windowMinutes}
              onChange={(e) => setWindowMinutes(Number(e.target.value))}
              className="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white"
            >
              <option value={15}>Últimos 15 min</option>
              <option value={60}>Última hora</option>
              <option value={360}>Últimas 6 horas</option>
              <option value={1440}>Últimas 24 horas</option>
            </select>
            <button
              onClick={() => setCreating(true)}
              className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500"
            >
              <PlusIcon className="h-4 w-4" />
              Nova adquirente
            </button>
          </>
        }
      />

      <SummaryCards summary={summary} windowMinutes={windowMinutes} />

      <PoliciesPanel policies={policiesQuery.data?.global ?? []} strategies={strategies} onSaved={invalidate} />

      <section className="rounded-3xl border border-border-DEFAULT bg-surface-DEFAULT p-5">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold">Adquirentes cadastradas</h2>
          {acquirersQuery.isFetching && <ArrowPathIcon className="h-4 w-4 animate-spin text-slate-400" />}
        </div>

        {acquirers.length === 0 ? (
          <p className="py-8 text-center text-sm text-slate-400">
            Nenhuma adquirente cadastrada. Clique em "Nova adquirente" para começar.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1100px] text-sm">
              <thead>
                <tr className="border-b border-border-DEFAULT text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="py-3 pr-4">Adquirente</th>
                  <th className="py-3 pr-4">Status</th>
                  <th className="py-3 pr-4">Circuit</th>
                  <th className="py-3 pr-4">Prio / Peso</th>
                  <th className="py-3 pr-4">Latência</th>
                  <th className="py-3 pr-4">Aprovação</th>
                  <th className="py-3 pr-4">TPS</th>
                  <th className="py-3 pr-4">Erros/min</th>
                  <th className="py-3 pr-4">Disponib.</th>
                  <th className="py-3 pr-4">Volume</th>
                  <th className="py-3 text-right">Ações</th>
                </tr>
              </thead>
              <tbody>
                {acquirers.map((row) => (
                  <AcquirerTableRow
                    key={row.id}
                    row={row}
                    onEdit={() => setEditing(row)}
                    onToggle={() => toggleMutation.mutate({ id: row.id, enabled: !row.enabled })}
                    onTest={() => testMutation.mutate(row.id)}
                    onResetCircuit={() => resetCircuitMutation.mutate(row.id)}
                    onDelete={() => {
                      if (window.confirm(`Excluir "${row.display_name}"? As credenciais serão removidas permanentemente.`)) {
                        deleteMutation.mutate(row.id);
                      }
                    }}
                    busy={toggleMutation.isPending || testMutation.isPending}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {(editing || creating) && (
        <AcquirerFormModal
          acquirer={editing}
          availableModules={acquirersQuery.data?.meta.available_modules ?? []}
          onClose={() => {
            setEditing(null);
            setCreating(false);
          }}
          onSaved={() => {
            setEditing(null);
            setCreating(false);
            invalidate();
          }}
        />
      )}
    </AdminPageShell>
  );
}

/* ── Cards de resumo ────────────────────────────────────────── */

function SummaryCards({ summary, windowMinutes }: { summary?: DashboardSummary; windowMinutes: number }) {
  const cards = [
    { label: 'Online', value: summary ? String(summary.online) : '—', icon: SignalIcon, tone: 'text-emerald-400' },
    { label: 'Degradadas', value: summary ? String(summary.degraded) : '—', icon: ExclamationTriangleIcon, tone: 'text-amber-400' },
    { label: 'Offline', value: summary ? String(summary.offline) : '—', icon: SignalSlashIcon, tone: 'text-red-400' },
    { label: 'TPS', value: summary ? summary.tps.toFixed(2) : '—', icon: BoltIcon, tone: 'text-blue-400' },
    { label: 'Latência média', value: ms(summary?.avg_latency_ms), icon: ChartBarIcon, tone: 'text-cyan-400' },
    { label: 'Taxa de aprovação', value: percent(summary?.approval_rate), icon: CheckCircleIcon, tone: 'text-emerald-400' },
    { label: 'Erros/min', value: summary ? summary.errors_per_minute.toFixed(2) : '—', icon: ExclamationTriangleIcon, tone: 'text-red-400' },
    { label: 'Fallbacks', value: summary ? String(summary.fallbacks) : '—', icon: ArrowPathIcon, tone: 'text-amber-400' },
    { label: 'Volume processado', value: summary ? currency(summary.volume_cents) : '—', icon: ChartBarIcon, tone: 'text-blue-400' },
  ];

  return (
    <section>
      <p className="mb-2 text-xs uppercase tracking-wide text-slate-400">
        Janela: últimos {windowMinutes} minutos
      </p>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        {cards.map((card) => (
          <div key={card.label} className="rounded-2xl border border-border-DEFAULT bg-surface-DEFAULT p-4">
            <div className="flex items-center gap-2">
              <card.icon className={`h-4 w-4 ${card.tone}`} />
              <span className="text-xs text-slate-400">{card.label}</span>
            </div>
            <p className="mt-2 text-xl font-bold">{card.value}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ── Linha da tabela ────────────────────────────────────────── */

function AcquirerTableRow({
  row,
  onEdit,
  onToggle,
  onTest,
  onResetCircuit,
  onDelete,
  busy,
}: {
  row: AcquirerRow;
  onEdit: () => void;
  onToggle: () => void;
  onTest: () => void;
  onResetCircuit: () => void;
  onDelete: () => void;
  busy: boolean;
}) {
  const metrics = row.metrics;

  return (
    <tr className="border-b border-border-DEFAULT/50 last:border-0">
      <td className="py-3 pr-4">
        <div className="font-medium">{row.display_name}</div>
        <div className="text-xs text-slate-400">
          {row.acquirer_key} · {row.supported_methods.join(', ') || 'sem métodos'}
        </div>
        {!row.module_available && (
          <div className="mt-1 text-xs text-red-400">Módulo não implementado</div>
        )}
        {row.module_available && !row.credentials_complete && (
          <div className="mt-1 text-xs text-amber-400">Credenciais incompletas</div>
        )}
      </td>

      <td className="py-3 pr-4">
        <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${HEALTH_STYLES[row.health.state]}`}>
          {row.health.state_label}
        </span>
        {!row.enabled && <div className="mt-1 text-xs text-slate-500">desativada</div>}
      </td>

      <td className="py-3 pr-4">
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs ${CIRCUIT_STYLES[row.health.circuit_state]}`}>
          {row.health.circuit_state_label}
        </span>
        {row.health.circuit_trip_count > 0 && (
          <div className="mt-1 text-xs text-slate-500">{row.health.circuit_trip_count} aberturas</div>
        )}
      </td>

      <td className="py-3 pr-4 text-slate-300">
        {row.routing.priority} / {row.routing.weight}
        {row.routing.max_tps ? <div className="text-xs text-slate-500">máx {row.routing.max_tps} TPS</div> : null}
      </td>

      <td className="py-3 pr-4 text-slate-300">{ms(row.health.avg_latency_ms ?? metrics?.avg_latency_ms)}</td>
      <td className="py-3 pr-4 text-slate-300">{metrics ? percent(metrics.approval_rate) : '—'}</td>
      <td className="py-3 pr-4 text-slate-300">{metrics ? metrics.observed_tps.toFixed(3) : '—'}</td>
      <td className="py-3 pr-4 text-slate-300">{metrics ? metrics.errors_per_minute.toFixed(2) : '—'}</td>
      <td className="py-3 pr-4 text-slate-300">{row.health.availability_percent.toFixed(1)}%</td>
      <td className="py-3 pr-4 text-slate-300">{metrics ? currency(metrics.volume_cents) : '—'}</td>

      <td className="py-3">
        <div className="flex items-center justify-end gap-1">
          <IconButton title="Testar conexão" onClick={onTest} disabled={busy}>
            <SignalIcon className="h-4 w-4" />
          </IconButton>
          {row.health.circuit_state !== 'closed' && (
            <IconButton title="Resetar circuit breaker" onClick={onResetCircuit} tone="amber">
              <ArrowPathIcon className="h-4 w-4" />
            </IconButton>
          )}
          <IconButton title="Editar" onClick={onEdit}>
            <PencilSquareIcon className="h-4 w-4" />
          </IconButton>
          <IconButton
            title={row.enabled ? 'Desativar' : 'Ativar'}
            onClick={onToggle}
            disabled={busy}
            tone={row.enabled ? 'amber' : 'emerald'}
          >
            {row.enabled ? <SignalSlashIcon className="h-4 w-4" /> : <CheckCircleIcon className="h-4 w-4" />}
          </IconButton>
          <IconButton title="Excluir" onClick={onDelete} tone="red">
            <TrashIcon className="h-4 w-4" />
          </IconButton>
        </div>
      </td>
    </tr>
  );
}

function IconButton({
  children,
  title,
  onClick,
  disabled,
  tone = 'slate',
}: {
  children: React.ReactNode;
  title: string;
  onClick: () => void;
  disabled?: boolean;
  tone?: 'slate' | 'red' | 'amber' | 'emerald';
}) {
  const tones = {
    slate: 'hover:bg-slate-500/15 text-slate-300',
    red: 'hover:bg-red-500/15 text-red-400',
    amber: 'hover:bg-amber-500/15 text-amber-400',
    emerald: 'hover:bg-emerald-500/15 text-emerald-400',
  };

  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      disabled={disabled}
      className={`rounded-lg p-2 transition disabled:opacity-40 ${tones[tone]}`}
    >
      {children}
    </button>
  );
}

/* ── Políticas de balanceamento ─────────────────────────────── */

function PoliciesPanel({
  policies,
  strategies,
  onSaved,
}: {
  policies: Policy[];
  strategies: StrategyOption[];
  onSaved: () => void;
}) {
  const saveMutation = useMutation({
    mutationFn: async (policy: Partial<Policy> & { payment_method: string }) =>
      api.put('/admin/routing/policies', policy),
    onSuccess: () => {
      toast.success('Política salva.');
      onSaved();
    },
    onError: () => toast.error('Falha ao salvar política.'),
  });

  if (policies.length === 0) return null;

  return (
    <section className="rounded-3xl border border-border-DEFAULT bg-surface-DEFAULT p-5">
      <h2 className="mb-1 text-lg font-semibold">Estratégias de balanceamento</h2>
      <p className="mb-4 text-sm text-slate-400">
        Define como o roteador escolhe a adquirente primária de cada método. Estratégias por
        estabelecimento sobrescrevem estas.
      </p>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {policies.map((policy) => (
          <div key={policy.payment_method} className="rounded-2xl border border-border-DEFAULT p-4">
            <div className="mb-3 font-medium">{policy.payment_method_label}</div>

            <label className="mb-1 block text-xs text-slate-400">Estratégia</label>
            <select
              defaultValue={policy.strategy}
              onChange={(e) =>
                saveMutation.mutate({ payment_method: policy.payment_method, strategy: e.target.value })
              }
              className="mb-3 w-full rounded-lg border border-border-DEFAULT bg-transparent px-3 py-2 text-sm"
            >
              {strategies.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>

            <div className="grid grid-cols-2 gap-2 text-xs">
              <NumberField
                label="Máx. tentativas"
                value={policy.max_attempts}
                min={1}
                max={10}
                onCommit={(v) =>
                  saveMutation.mutate({
                    payment_method: policy.payment_method,
                    strategy: policy.strategy,
                    max_attempts: v,
                  })
                }
              />
              <NumberField
                label="Orçamento (ms)"
                value={policy.total_budget_ms}
                min={1000}
                max={120000}
                step={1000}
                onCommit={(v) =>
                  saveMutation.mutate({
                    payment_method: policy.payment_method,
                    strategy: policy.strategy,
                    total_budget_ms: v,
                  })
                }
              />
            </div>

            <label className="mt-3 flex items-center gap-2 text-xs text-slate-300">
              <input
                type="checkbox"
                defaultChecked={policy.failover_enabled}
                onChange={(e) =>
                  saveMutation.mutate({
                    payment_method: policy.payment_method,
                    strategy: policy.strategy,
                    failover_enabled: e.target.checked,
                  })
                }
              />
              Fallback automático
            </label>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ── Modal de cadastro/edição ───────────────────────────────── */

function AcquirerFormModal({
  acquirer,
  availableModules,
  onClose,
  onSaved,
}: {
  acquirer: AcquirerRow | null;
  availableModules: string[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = acquirer !== null;

  const [form, setForm] = useState({
    acquirer_key: acquirer?.acquirer_key ?? '',
    display_name: acquirer?.display_name ?? '',
    nominal_name: acquirer?.nominal_name ?? '',
    type: acquirer?.type ?? 'pix',
    supported_methods: acquirer?.supported_methods ?? ['pix'],
  });

  const [routing, setRouting] = useState<RoutingProfile>(
    acquirer?.routing ?? {
      routing_enabled: true,
      priority: 100,
      weight: 1,
      max_tps: null,
      max_concurrent: null,
      connect_timeout_ms: 3000,
      timeout_ms: 15000,
      max_retries: 1,
      retry_backoff_ms: 200,
      retry_backoff_max_ms: 2000,
      cb_failure_threshold: 5,
      cb_cooldown_seconds: 60,
      cb_half_open_max_calls: 1,
      cb_success_threshold: 2,
      health_check_enabled: true,
      health_check_url: null,
      health_check_interval_seconds: 60,
      min_amount_cents: null,
      max_amount_cents: null,
      rules: {},
    },
  );

  // Só campos digitados são enviados. O backend ignora vazios e mascarados,
  // então salvar sem redigitar um segredo não o apaga.
  const [credentials, setCredentials] = useState<Record<string, string>>({});

  const credentialFields = useMemo(
    () => Object.values(acquirer?.credentials ?? {}),
    [acquirer],
  );

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = { ...form, credentials, routing };
      return isEdit
        ? api.put(`/admin/routing/acquirers/${acquirer!.id}`, payload)
        : api.post('/admin/routing/acquirers', payload);
    },
    onSuccess: (res) => {
      toast.success(res.data?.message ?? 'Adquirente salva.');
      onSaved();
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message ?? 'Falha ao salvar adquirente.');
    },
  });

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4">
      <div className="my-8 w-full max-w-3xl rounded-3xl border border-border-DEFAULT bg-surface-DEFAULT p-6">
        <div className="mb-5 flex items-center justify-between">
          <h2 className="text-lg font-semibold">
            {isEdit ? `Editar ${acquirer!.display_name}` : 'Nova adquirente'}
          </h2>
          <button onClick={onClose} className="rounded-lg p-2 hover:bg-slate-500/15">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-6">
          <FieldGroup title="Identificação">
            <div className="grid gap-3 md:grid-cols-2">
              <TextField
                label="Chave (acquirer_key)"
                value={form.acquirer_key}
                disabled={isEdit}
                placeholder="ex.: minha_adquirente"
                onChange={(v) => setForm({ ...form, acquirer_key: v.toLowerCase().replace(/[^a-z0-9_]/g, '') })}
                hint={
                  isEdit
                    ? 'A chave não pode ser alterada após o cadastro.'
                    : `Módulos disponíveis: ${availableModules.join(', ')}`
                }
              />
              <TextField label="Nome" value={form.display_name} onChange={(v) => setForm({ ...form, display_name: v })} />
              <TextField label="Tipo" value={form.type} onChange={(v) => setForm({ ...form, type: v })} />
              <TextField
                label="Nome nominal (extrato)"
                value={form.nominal_name}
                onChange={(v) => setForm({ ...form, nominal_name: v })}
              />
            </div>

            <div className="mt-3">
              <label className="mb-2 block text-xs text-slate-400">Métodos suportados</label>
              <div className="flex flex-wrap gap-3">
                {['pix', 'pix_out', 'credit_card', 'debit_card', 'boleto'].map((method) => (
                  <label key={method} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={form.supported_methods.includes(method)}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          supported_methods: e.target.checked
                            ? [...form.supported_methods, method]
                            : form.supported_methods.filter((m) => m !== method),
                        })
                      }
                    />
                    {method}
                  </label>
                ))}
              </div>
            </div>
          </FieldGroup>

          <FieldGroup
            title="Credenciais"
            subtitle="Criptografadas com AES-256-GCM antes de gravar. Valores existentes aparecem apenas mascarados; deixe em branco para manter."
          >
            {credentialFields.length === 0 ? (
              <p className="text-sm text-slate-400">
                {isEdit
                  ? 'Nenhum campo de credencial definido para esta adquirente.'
                  : 'Salve a adquirente para que os campos de credencial sejam exibidos.'}
              </p>
            ) : (
              <div className="grid gap-3 md:grid-cols-2">
                {credentialFields.map((field) => (
                  <div key={field.field_key}>
                    <label className="mb-1 block text-xs text-slate-400">
                      {field.label}
                      {field.configured && (
                        <span className="ml-2 font-mono text-slate-500">{field.masked}</span>
                      )}
                      {field.deprecated && <span className="ml-2 text-amber-400">(obsoleto)</span>}
                    </label>
                    <input
                      type={field.type === 'password' ? 'password' : 'text'}
                      autoComplete="new-password"
                      placeholder={field.configured ? 'Manter valor atual' : 'Não configurado'}
                      value={credentials[field.field_key] ?? ''}
                      onChange={(e) =>
                        setCredentials({ ...credentials, [field.field_key]: e.target.value })
                      }
                      className="w-full rounded-lg border border-border-DEFAULT bg-transparent px-3 py-2 text-sm"
                    />
                  </div>
                ))}
              </div>
            )}
          </FieldGroup>

          <FieldGroup title="Roteamento e balanceamento">
            <div className="grid gap-3 md:grid-cols-3">
              <NumberField label="Prioridade" value={routing.priority} min={0} max={10000} onCommit={(v) => setRouting({ ...routing, priority: v })} />
              <NumberField label="Peso" value={routing.weight} min={1} max={100} onCommit={(v) => setRouting({ ...routing, weight: v })} />
              <NumberField label="Máx. TPS (vazio = sem limite)" value={routing.max_tps} min={1} max={100000} nullable onCommit={(v) => setRouting({ ...routing, max_tps: v })} />
              <NumberField label="Máx. concorrência" value={routing.max_concurrent} min={1} max={10000} nullable onCommit={(v) => setRouting({ ...routing, max_concurrent: v })} />
              <NumberField label="Valor mín. (centavos)" value={routing.min_amount_cents} min={0} nullable onCommit={(v) => setRouting({ ...routing, min_amount_cents: v })} />
              <NumberField label="Valor máx. (centavos)" value={routing.max_amount_cents} min={0} nullable onCommit={(v) => setRouting({ ...routing, max_amount_cents: v })} />
            </div>
          </FieldGroup>

          <FieldGroup title="Timeouts e retry">
            <div className="grid gap-3 md:grid-cols-3">
              <NumberField label="Timeout total (ms)" value={routing.timeout_ms} min={1000} max={120000} step={500} onCommit={(v) => setRouting({ ...routing, timeout_ms: v })} />
              <NumberField label="Timeout conexão (ms)" value={routing.connect_timeout_ms} min={500} max={60000} step={500} onCommit={(v) => setRouting({ ...routing, connect_timeout_ms: v })} />
              <NumberField label="Máx. retries" value={routing.max_retries} min={0} max={5} onCommit={(v) => setRouting({ ...routing, max_retries: v })} />
              <NumberField label="Backoff inicial (ms)" value={routing.retry_backoff_ms} min={0} max={10000} step={50} onCommit={(v) => setRouting({ ...routing, retry_backoff_ms: v })} />
              <NumberField label="Backoff máximo (ms)" value={routing.retry_backoff_max_ms} min={0} max={60000} step={100} onCommit={(v) => setRouting({ ...routing, retry_backoff_max_ms: v })} />
            </div>
          </FieldGroup>

          <FieldGroup title="Circuit breaker e health check">
            <div className="grid gap-3 md:grid-cols-3">
              <NumberField label="Falhas p/ abrir" value={routing.cb_failure_threshold} min={1} max={100} onCommit={(v) => setRouting({ ...routing, cb_failure_threshold: v })} />
              <NumberField label="Cooldown (s)" value={routing.cb_cooldown_seconds} min={5} max={3600} onCommit={(v) => setRouting({ ...routing, cb_cooldown_seconds: v })} />
              <NumberField label="Sucessos p/ fechar" value={routing.cb_success_threshold} min={1} max={20} onCommit={(v) => setRouting({ ...routing, cb_success_threshold: v })} />
              <NumberField label="Probes em meio-aberto" value={routing.cb_half_open_max_calls} min={1} max={20} onCommit={(v) => setRouting({ ...routing, cb_half_open_max_calls: v })} />
              <NumberField label="Intervalo health check (s)" value={routing.health_check_interval_seconds} min={15} max={3600} onCommit={(v) => setRouting({ ...routing, health_check_interval_seconds: v })} />
            </div>

            <label className="mt-3 flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={routing.health_check_enabled}
                onChange={(e) => setRouting({ ...routing, health_check_enabled: e.target.checked })}
              />
              Health check automático
            </label>
          </FieldGroup>
        </div>

        <div className="mt-6 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-xl border border-border-DEFAULT px-4 py-2 text-sm">
            Cancelar
          </button>
          <button
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending || !form.acquirer_key || !form.display_name}
            className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-500 disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}

/* ── Campos ─────────────────────────────────────────────────── */

function FieldGroup({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-border-DEFAULT p-4">
      <h3 className="text-sm font-semibold">{title}</h3>
      {subtitle && <p className="mt-1 text-xs text-slate-400">{subtitle}</p>}
      <div className="mt-3">{children}</div>
    </div>
  );
}

function TextField({
  label,
  value,
  onChange,
  disabled,
  placeholder,
  hint,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  placeholder?: string;
  hint?: string;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs text-slate-400">{label}</label>
      <input
        type="text"
        value={value}
        disabled={disabled}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-DEFAULT bg-transparent px-3 py-2 text-sm disabled:opacity-50"
      />
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
  );
}

function NumberField({
  label,
  value,
  min,
  max,
  step,
  nullable,
  onCommit,
}: {
  label: string;
  value: number | null;
  min?: number;
  max?: number;
  step?: number;
  nullable?: boolean;
  onCommit: (value: any) => void;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs text-slate-400">{label}</label>
      <input
        type="number"
        defaultValue={value ?? ''}
        min={min}
        max={max}
        step={step}
        onBlur={(e) => {
          const raw = e.target.value;
          if (raw === '' && nullable) {
            onCommit(null);
            return;
          }
          const parsed = Number(raw);
          if (!Number.isNaN(parsed)) onCommit(parsed);
        }}
        className="w-full rounded-lg border border-border-DEFAULT bg-transparent px-3 py-2 text-sm"
      />
    </div>
  );
}
