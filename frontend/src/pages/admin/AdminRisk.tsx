import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import {
  ChartBarIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  NoSymbolIcon,
  ShieldExclamationIcon,
  TrashIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';

type Period = '7d' | '30d' | '90d';

interface FraudDashboard {
  total_analyzed: number;
  high_risk_count: number;
  blocked_count: number;
  average_score: number;
  active_blacklist_entries: number;
}

interface TriggeredRule {
  category: string;
  score: number;
  details?: { flags?: string[]; violations?: string[]; matches?: string[] };
}

interface RiskScore {
  id: string;
  charge_id: string;
  total_score: number;
  risk_level: 'low' | 'medium' | 'high' | 'critical';
  recommendation: 'approve' | 'review' | 'decline';
  triggered_rules: TriggeredRule[] | null;
  ip_address: string | null;
  created_at: string;
  charge?: {
    id: string;
    amount: number;
    status: string;
    payment_method: string;
    customer?: { name?: string; email?: string; document?: string; cpf?: string };
  } | null;
}

interface BlacklistItem {
  id: string;
  type: string;
  value: string;
  reason: string | null;
  expires_at: string | null;
  created_at: string;
}

const LEVELS: Record<RiskScore['risk_level'], { label: string; className: string }> = {
  low: { label: 'Baixo', className: 'bg-emerald-500/10 text-emerald-400' },
  medium: { label: 'Médio', className: 'bg-amber-500/10 text-amber-400' },
  high: { label: 'Alto', className: 'bg-orange-500/10 text-orange-400' },
  critical: { label: 'Crítico', className: 'bg-red-500/15 text-red-400' },
};

const RECOMMENDATIONS: Record<RiskScore['recommendation'], string> = {
  approve: 'Aprovar',
  review: 'Revisar',
  decline: 'Recusar',
};

const RULE_LABELS: Record<string, string> = {
  device: 'Dispositivo',
  blacklist: 'Lista de bloqueio',
  velocity: 'Velocidade',
  amount: 'Valor',
  time_pattern: 'Horário',
  bin: 'BIN',
  geolocation: 'Geolocalização',
  card_testing: 'Teste de cartão',
  email_pattern: 'Padrão de e-mail',
};

const BLACKLIST_TYPES: Record<string, string> = {
  email: 'E-mail',
  document: 'Documento',
  ip: 'IP',
  bin: 'BIN',
  fingerprint: 'Dispositivo',
};

const cardClass = 'rounded-2xl border border-white/[0.06] bg-[#0b0b0d]';
const fieldClass = 'h-10 rounded-lg border border-white/10 bg-[#0e0e11] px-3 text-sm text-white focus:border-red-500/60 focus:outline-none';

export default function AdminRisk() {
  const [tab, setTab] = useState<'queue' | 'blacklist'>('queue');
  const [period, setPeriod] = useState<Period>('30d');

  const { data: dashboard } = useQuery<FraudDashboard>({
    queryKey: ['admin-fraud-dashboard', period],
    queryFn: async () => (await api.get('/admin/fraud/dashboard', { params: { period } })).data?.data,
  });

  const kpis = [
    { label: 'Cobranças analisadas', value: dashboard?.total_analyzed ?? 0, Icon: ChartBarIcon },
    { label: 'Risco alto ou crítico', value: dashboard?.high_risk_count ?? 0, Icon: ExclamationTriangleIcon },
    { label: 'Recomendação de recusa', value: dashboard?.blocked_count ?? 0, Icon: ShieldExclamationIcon },
    { label: 'Nota média', value: dashboard?.average_score ?? 0, Icon: InformationCircleIcon },
  ];

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Risco e fraude"
        subtitle="Notas de risco das cobranças e lista de bloqueio"
        icon={ShieldExclamationIcon}
        actions={
          <select value={period} onChange={(e) => setPeriod(e.target.value as Period)} aria-label="Período" className={fieldClass}>
            <option value="7d">7 dias</option>
            <option value="30d">30 dias</option>
            <option value="90d">90 dias</option>
          </select>
        }
      />

      <div className="flex items-start gap-3 rounded-xl border border-amber-500/20 bg-amber-500/[0.06] px-4 py-3 text-sm text-amber-200">
        <InformationCircleIcon className="mt-0.5 h-5 w-5 shrink-0" />
        <p>
          <strong className="font-semibold">Modo observação.</strong> O antifraude só dá nota: nenhuma cobrança é recusada,
          e a lista de bloqueio não impede pagamentos.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {kpis.map(({ label, value, Icon }) => (
          <div key={label} className={clsx(cardClass, 'p-4')}>
            <div className="flex items-center gap-2 text-gray-400">
              <Icon className="h-4 w-4 text-red-400" />
              <p className="text-[11px] font-semibold uppercase tracking-wider">{label}</p>
            </div>
            <p className="mt-2 text-2xl font-bold text-white">{value.toLocaleString('pt-BR')}</p>
          </div>
        ))}
      </div>

      <div className="flex gap-1 border-b border-white/[0.06]">
        {[
          { id: 'queue' as const, label: 'Fila de análise' },
          { id: 'blacklist' as const, label: `Lista de bloqueio (${dashboard?.active_blacklist_entries ?? 0})` },
        ].map((item) => (
          <button
            key={item.id}
            type="button"
            onClick={() => setTab(item.id)}
            className={clsx(
              '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
              tab === item.id ? 'border-red-500 text-white' : 'border-transparent text-gray-400 hover:text-white'
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'queue' ? <RiskQueue /> : <Blacklist />}
    </AdminPageShell>
  );
}

function RiskQueue() {
  const [level, setLevel] = useState('');
  const [recommendation, setRecommendation] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<RiskScore | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-risk-scores', level, recommendation, page],
    queryFn: async () => {
      const params = { page, per_page: 20, risk_level: level || undefined, recommendation: recommendation || undefined };
      return (await api.get('/admin/fraud/risk-scores', { params })).data as {
        data: RiskScore[];
        meta: { current_page: number; last_page: number; total: number };
      };
    },
  });

  const scores = data?.data ?? [];
  const lastPage = data?.meta?.last_page ?? 1;

  return (
    <>
      <div className="flex flex-wrap gap-2">
        <select value={level} onChange={(e) => { setLevel(e.target.value); setPage(1); }} aria-label="Nível de risco" className={fieldClass}>
          <option value="">Todos os níveis</option>
          {Object.entries(LEVELS).map(([key, { label }]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <select value={recommendation} onChange={(e) => { setRecommendation(e.target.value); setPage(1); }} aria-label="Recomendação" className={fieldClass}>
          <option value="">Todas as recomendações</option>
          {Object.entries(RECOMMENDATIONS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
      </div>

      <div className={clsx(cardClass, 'overflow-x-auto')}>
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="border-b border-white/[0.06] text-left text-[11px] uppercase tracking-wider text-gray-500">
              <th className="px-4 py-3 font-semibold">Data</th>
              <th className="px-4 py-3 font-semibold">Comprador</th>
              <th className="px-4 py-3 font-semibold">Valor</th>
              <th className="px-4 py-3 font-semibold">Nota</th>
              <th className="px-4 py-3 font-semibold">Nível</th>
              <th className="px-4 py-3 font-semibold">Recomendação</th>
            </tr>
          </thead>
          <tbody>
            {scores.map((score) => (
              <tr
                key={score.id}
                onClick={() => setSelected(score)}
                className="cursor-pointer border-b border-white/[0.04] text-gray-300 transition-colors last:border-0 hover:bg-white/[0.03]"
              >
                <td className="whitespace-nowrap px-4 py-3 text-gray-400">{formatDate(score.created_at)}</td>
                <td className="max-w-[240px] truncate px-4 py-3">{score.charge?.customer?.email || '—'}</td>
                <td className="whitespace-nowrap px-4 py-3">{score.charge ? formatCurrency(score.charge.amount) : '—'}</td>
                <td className="px-4 py-3 font-semibold text-white">{score.total_score}</td>
                <td className="px-4 py-3">
                  <span className={clsx('rounded-full px-2.5 py-0.5 text-xs font-medium', LEVELS[score.risk_level]?.className)}>
                    {LEVELS[score.risk_level]?.label ?? score.risk_level}
                  </span>
                </td>
                <td className="px-4 py-3">{RECOMMENDATIONS[score.recommendation] ?? score.recommendation}</td>
              </tr>
            ))}
            {scores.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-12 text-center text-gray-500">
                  {isLoading ? 'Carregando...' : isError ? 'Não foi possível carregar a fila.' : 'Nenhuma cobrança analisada com esses filtros.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {lastPage > 1 && (
        <div className="flex items-center justify-end gap-3 text-sm text-gray-400">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Anterior</button>
          <span>Página {page} de {lastPage}</span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage(page + 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Próxima</button>
        </div>
      )}

      {selected && <RiskDetail score={selected} onClose={() => setSelected(null)} />}
    </>
  );
}

function RiskDetail({ score, onClose }: { score: RiskScore; onClose: () => void }) {
  const queryClient = useQueryClient();
  const rules = score.triggered_rules ?? [];
  const email = score.charge?.customer?.email;
  const document = score.charge?.customer?.document || score.charge?.customer?.cpf;

  const blockMutation = useMutation({
    mutationFn: (payload: { type: string; value: string }) =>
      api.post('/admin/fraud/blacklist', { ...payload, reason: `Fila de risco, cobrança ${score.charge_id}` }),
    onSuccess: () => {
      toast.success('Adicionado à lista de bloqueio');
      queryClient.invalidateQueries({ queryKey: ['admin-blacklist'] });
      queryClient.invalidateQueries({ queryKey: ['admin-fraud-dashboard'] });
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Não foi possível adicionar');
    },
  });

  const blockable = [
    { type: 'email', value: email },
    { type: 'document', value: document },
    { type: 'ip', value: score.ip_address },
  ].filter((item): item is { type: string; value: string } => Boolean(item.value));

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/60" onClick={onClose}>
      <aside
        role="dialog"
        aria-label="Detalhes da análise"
        className="h-full w-full max-w-md overflow-y-auto border-l border-white/10 bg-[#0e0e11] p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-wider text-red-400">Análise de risco</p>
            <p className="mt-1 text-3xl font-bold text-white">{score.total_score} pontos</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Fechar" className="rounded-lg p-1.5 text-gray-400 hover:bg-white/5 hover:text-white">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <dl className="mt-6 space-y-3 text-sm">
          {[
            ['Comprador', score.charge?.customer?.name || '—'],
            ['E-mail', email || '—'],
            ['IP', score.ip_address || 'Não informado'],
            ['Valor', score.charge ? formatCurrency(score.charge.amount) : '—'],
            ['Status da cobrança', score.charge?.status || '—'],
            ['Recomendação', RECOMMENDATIONS[score.recommendation] ?? score.recommendation],
          ].map(([label, value]) => (
            <div key={label} className="flex justify-between gap-4">
              <dt className="text-gray-500">{label}</dt>
              <dd className="min-w-0 truncate text-right text-gray-200">{value}</dd>
            </div>
          ))}
        </dl>

        <h3 className="mt-6 text-sm font-semibold text-white">Regras acionadas</h3>
        <ul className="mt-2 space-y-2">
          {rules.map((rule) => {
            const notes = [...(rule.details?.flags ?? []), ...(rule.details?.violations ?? []), ...(rule.details?.matches ?? [])];
            return (
              <li key={rule.category} className="rounded-lg border border-white/[0.06] p-3 text-sm">
                <div className="flex justify-between gap-3 text-gray-200">
                  <span>{RULE_LABELS[rule.category] ?? rule.category}</span>
                  <span className="font-semibold text-red-400">+{rule.score}</span>
                </div>
                {notes.length > 0 && <p className="mt-1 break-all font-mono text-xs text-gray-500">{notes.join(', ')}</p>}
              </li>
            );
          })}
          {rules.length === 0 && <li className="text-sm text-gray-500">Nenhuma regra acionada.</li>}
        </ul>

        {blockable.length > 0 && (
          <>
            <h3 className="mt-6 text-sm font-semibold text-white">Adicionar à lista de bloqueio</h3>
            <div className="mt-2 flex flex-wrap gap-2">
              {blockable.map((item) => (
                <button
                  key={item.type}
                  type="button"
                  disabled={blockMutation.isPending}
                  onClick={() => blockMutation.mutate(item)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-gray-300 hover:border-red-500/50 hover:text-white disabled:opacity-50"
                >
                  <NoSymbolIcon className="h-3.5 w-3.5" />
                  {BLACKLIST_TYPES[item.type]}
                </button>
              ))}
            </div>
          </>
        )}

        <Link to="/admin/transactions" className="mt-6 inline-block text-sm text-red-400 hover:text-red-300">
          Ver transações
        </Link>
      </aside>
    </div>
  );
}

function Blacklist() {
  const queryClient = useQueryClient();
  const [type, setType] = useState('email');
  const [value, setValue] = useState('');
  const [reason, setReason] = useState('');

  const { data: items = [], isLoading } = useQuery<BlacklistItem[]>({
    queryKey: ['admin-blacklist'],
    queryFn: async () => (await api.get('/admin/fraud/blacklist', { params: { per_page: 100 } })).data?.data ?? [],
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-blacklist'] });
    queryClient.invalidateQueries({ queryKey: ['admin-fraud-dashboard'] });
  };

  const addMutation = useMutation({
    mutationFn: () => api.post('/admin/fraud/blacklist', { type, value: value.trim(), reason: reason.trim() || null }),
    onSuccess: () => {
      toast.success('Adicionado à lista de bloqueio');
      setValue('');
      setReason('');
      refresh();
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Não foi possível adicionar');
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/admin/fraud/blacklist/${id}`),
    onSuccess: () => {
      toast.success('Removido da lista');
      refresh();
    },
    onError: () => toast.error('Não foi possível remover'),
  });

  return (
    <>
      <form
        className={clsx(cardClass, 'flex flex-col gap-2 p-4 sm:flex-row')}
        onSubmit={(e) => {
          e.preventDefault();
          if (value.trim()) addMutation.mutate();
        }}
      >
        <select value={type} onChange={(e) => setType(e.target.value)} aria-label="Tipo" className={fieldClass}>
          {Object.entries(BLACKLIST_TYPES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <input value={value} onChange={(e) => setValue(e.target.value)} placeholder="Valor" aria-label="Valor" maxLength={255} className={clsx(fieldClass, 'min-w-0 flex-1')} />
        <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Motivo (opcional)" aria-label="Motivo" maxLength={500} className={clsx(fieldClass, 'min-w-0 flex-1')} />
        <button
          type="submit"
          disabled={addMutation.isPending || !value.trim()}
          className="h-10 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white transition-colors hover:bg-red-500 disabled:opacity-50"
        >
          Adicionar
        </button>
      </form>

      <div className={clsx(cardClass, 'divide-y divide-white/[0.04]')}>
        {items.map((item) => (
          <div key={item.id} className="flex items-center gap-3 px-4 py-3 text-sm">
            <span className="w-24 shrink-0 text-xs font-medium text-red-400">{BLACKLIST_TYPES[item.type] ?? item.type}</span>
            <div className="min-w-0 flex-1">
              <p className="truncate font-mono text-gray-200">{item.value}</p>
              {item.reason && <p className="truncate text-xs text-gray-500">{item.reason}</p>}
            </div>
            <button
              type="button"
              aria-label={`Remover ${item.value}`}
              disabled={removeMutation.isPending}
              onClick={() => {
                if (window.confirm(`Remover ${item.value} da lista de bloqueio?`)) removeMutation.mutate(item.id);
              }}
              className="rounded-lg p-1.5 text-gray-500 hover:bg-white/5 hover:text-red-400 disabled:opacity-50"
            >
              <TrashIcon className="h-4 w-4" />
            </button>
          </div>
        ))}
        {items.length === 0 && (
          <p className="px-4 py-12 text-center text-sm text-gray-500">{isLoading ? 'Carregando...' : 'A lista de bloqueio está vazia.'}</p>
        )}
      </div>
    </>
  );
}
