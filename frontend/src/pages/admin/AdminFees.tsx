import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import { BanknotesIcon, ReceiptPercentIcon } from '@heroicons/react/24/outline';
import { api, formatCurrency } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';

type Method = 'pix' | 'credit_card' | 'boleto';
type Period = '7d' | '30d' | '90d' | 'all';

interface FeeRate {
  percentage: number;
  fixed: number;
}

interface WithdrawalFees {
  pix_fee: number;
  bank_fee: number;
  extra_enabled: boolean;
  extra_type: 'percentage' | 'fixed';
  extra_value: number;
}

interface AcquirerCost {
  key: string;
  name: string;
  enabled: boolean;
  cash_in_percentage: number;
  cash_in_fixed: number;
  cash_out_percentage: number;
  cash_out_fixed: number;
}

interface FeesResponse {
  rates: Record<Method, FeeRate>;
  withdrawal: WithdrawalFees;
  acquirers: AcquirerCost[];
}

interface ProfitRow {
  acquirer: string;
  name: string;
  transactions: number;
  volume: number;
  fee_revenue: number;
  acquirer_cost: number;
  withdrawals: number;
  withdrawal_fee_revenue: number;
  withdrawal_cost: number;
  profit: number;
}

interface ProfitResponse {
  totals: Omit<ProfitRow, 'acquirer' | 'name'>;
  acquirers: ProfitRow[];
}

const METHODS: Array<{ key: Method; label: string }> = [
  { key: 'pix', label: 'Pix' },
  { key: 'credit_card', label: 'Cartão de crédito' },
  { key: 'boleto', label: 'Boleto' },
];

const PERIODS: Array<{ key: Period; label: string }> = [
  { key: '7d', label: '7 dias' },
  { key: '30d', label: '30 dias' },
  { key: '90d', label: '90 dias' },
  { key: 'all', label: 'Tudo' },
];

const cardClass = 'rounded-2xl border border-white/[0.06] bg-[#0b0b0d] p-5';
const inputClass = 'h-10 w-full rounded-lg border border-white/10 bg-[#0e0e11] px-3 text-sm text-white focus:border-red-500/60 focus:outline-none';
const labelClass = 'mb-1 block text-xs font-medium text-gray-400';

const toNumber = (value: string) => {
  const parsed = parseFloat(value.replace(',', '.'));
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
};

const feeCents = (amountCents: number, percentage: number, fixedReais: number) =>
  Math.round(amountCents * (percentage / 100)) + Math.round(fixedReais * 100);

export default function AdminFees() {
  const queryClient = useQueryClient();
  const [rates, setRates] = useState<Record<Method, FeeRate> | null>(null);
  const [withdrawal, setWithdrawal] = useState<WithdrawalFees | null>(null);
  const [simulatedAmount, setSimulatedAmount] = useState('100');
  const [period, setPeriod] = useState<Period>('30d');

  const { data, isLoading, isError } = useQuery<FeesResponse>({
    queryKey: ['admin-fees'],
    queryFn: async () => (await api.get('/admin/fees')).data?.data,
  });

  const { data: profit } = useQuery<ProfitResponse>({
    queryKey: ['admin-fees-profit', period],
    queryFn: async () => (await api.get('/admin/fees/profit', { params: { period } })).data?.data,
  });

  useEffect(() => {
    if (data) {
      setRates(data.rates);
      setWithdrawal(data.withdrawal);
    }
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: () => api.put('/admin/fees', { rates, withdrawal }),
    onSuccess: () => {
      toast.success('Taxas salvas. Valem para as próximas vendas e saques.');
      queryClient.invalidateQueries({ queryKey: ['admin-fees'] });
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Não foi possível salvar as taxas');
    },
  });

  const updateRate = (method: Method, part: keyof FeeRate, value: string) => {
    setRates((current) => (current ? { ...current, [method]: { ...current[method], [part]: toNumber(value) } } : current));
  };

  const updateWithdrawal = (patch: Partial<WithdrawalFees>) => {
    setWithdrawal((current) => (current ? { ...current, ...patch } : current));
  };

  const amountCents = Math.round(toNumber(simulatedAmount) * 100);
  const activeAcquirers = (data?.acquirers ?? []).filter((acquirer) => acquirer.enabled);
  const simulated = activeAcquirers.length > 0 ? activeAcquirers : (data?.acquirers ?? []).slice(0, 5);
  const totals = profit?.totals;

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Taxas"
        subtitle="O que o seller paga, o que a adquirente custa e quanto sobra para o gateway"
        icon={ReceiptPercentIcon}
        actions={
          <button
            type="button"
            onClick={() => saveMutation.mutate()}
            disabled={!rates || !withdrawal || saveMutation.isPending}
            className="h-10 rounded-lg bg-red-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-red-500 disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Salvando...' : 'Salvar taxas'}
          </button>
        }
      />

      {isError && <p className="text-sm text-red-400">Não foi possível carregar as taxas.</p>}

      <div className="grid gap-4 lg:grid-cols-2">
        <section className={cardClass}>
          <h2 className="text-sm font-semibold text-white">Taxas por venda</h2>
          <p className="mt-1 text-xs text-gray-500">Cobradas do seller em cada venda aprovada.</p>
          <div className="mt-4 space-y-3">
            {METHODS.map(({ key, label }) => (
              <div key={key} className="grid grid-cols-[1fr_6rem_6rem] items-end gap-2 sm:grid-cols-[1fr_7rem_7rem]">
                <p className="pb-2.5 text-sm text-gray-200">{label}</p>
                <div>
                  <label htmlFor={`fee-${key}-percentage`} className={labelClass}>%</label>
                  <input
                    id={`fee-${key}-percentage`}
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    value={rates?.[key].percentage ?? ''}
                    onChange={(e) => updateRate(key, 'percentage', e.target.value)}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label htmlFor={`fee-${key}-fixed`} className={labelClass}>Fixa (R$)</label>
                  <input
                    id={`fee-${key}-fixed`}
                    type="number"
                    min="0"
                    step="0.01"
                    value={rates?.[key].fixed ?? ''}
                    onChange={(e) => updateRate(key, 'fixed', e.target.value)}
                    className={inputClass}
                  />
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className={cardClass}>
          <h2 className="text-sm font-semibold text-white">Taxas de saque</h2>
          <p className="mt-1 text-xs text-gray-500">Descontadas do valor sacado pelo seller.</p>
          {withdrawal && (
            <div className="mt-4 space-y-3">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label htmlFor="fee-withdrawal-pix" className={labelClass}>Pix (R$)</label>
                  <input
                    id="fee-withdrawal-pix"
                    type="number"
                    min="0"
                    step="0.01"
                    value={withdrawal.pix_fee / 100}
                    onChange={(e) => updateWithdrawal({ pix_fee: Math.round(toNumber(e.target.value) * 100) })}
                    className={inputClass}
                  />
                </div>
                <div>
                  <label htmlFor="fee-withdrawal-bank" className={labelClass}>TED (R$)</label>
                  <input
                    id="fee-withdrawal-bank"
                    type="number"
                    min="0"
                    step="0.01"
                    value={withdrawal.bank_fee / 100}
                    onChange={(e) => updateWithdrawal({ bank_fee: Math.round(toNumber(e.target.value) * 100) })}
                    className={inputClass}
                  />
                </div>
              </div>
              <label className="flex items-center gap-2 text-sm text-gray-200">
                <input
                  type="checkbox"
                  checked={withdrawal.extra_enabled}
                  onChange={(e) => updateWithdrawal({ extra_enabled: e.target.checked })}
                  className="h-4 w-4"
                />
                Taxa adicional em todo saque
              </label>
              {withdrawal.extra_enabled && (
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label htmlFor="fee-withdrawal-extra-type" className={labelClass}>Tipo</label>
                    <select
                      id="fee-withdrawal-extra-type"
                      value={withdrawal.extra_type}
                      onChange={(e) => updateWithdrawal({ extra_type: e.target.value as WithdrawalFees['extra_type'], extra_value: 0 })}
                      className={inputClass}
                    >
                      <option value="percentage">Percentual</option>
                      <option value="fixed">Valor fixo</option>
                    </select>
                  </div>
                  <div>
                    <label htmlFor="fee-withdrawal-extra-value" className={labelClass}>
                      {withdrawal.extra_type === 'percentage' ? 'Valor (%)' : 'Valor (R$)'}
                    </label>
                    <input
                      id="fee-withdrawal-extra-value"
                      type="number"
                      min="0"
                      step="0.01"
                      value={withdrawal.extra_type === 'percentage' ? withdrawal.extra_value : withdrawal.extra_value / 100}
                      onChange={(e) => {
                        const value = toNumber(e.target.value);
                        updateWithdrawal({ extra_value: withdrawal.extra_type === 'percentage' ? value : Math.round(value * 100) });
                      }}
                      className={inputClass}
                    />
                  </div>
                </div>
              )}
            </div>
          )}
        </section>
      </div>

      <section className={cardClass}>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h2 className="text-sm font-semibold text-white">Margem por adquirente</h2>
            <p className="mt-1 text-xs text-gray-500">
              Simula uma venda Pix com as taxas acima. O custo vem da tela de{' '}
              <Link to="/admin/acquirers" className="text-red-400 hover:text-red-300">Adquirentes</Link>.
            </p>
          </div>
          <div className="w-full sm:w-40">
            <label htmlFor="fee-simulated-amount" className={labelClass}>Valor da venda (R$)</label>
            <input
              id="fee-simulated-amount"
              type="number"
              min="0"
              step="0.01"
              value={simulatedAmount}
              onChange={(e) => setSimulatedAmount(e.target.value)}
              className={inputClass}
            />
          </div>
        </div>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[520px] text-sm">
            <thead>
              <tr className="border-b border-white/[0.06] text-left text-[11px] uppercase tracking-wider text-gray-500">
                <th className="py-2 font-semibold">Adquirente</th>
                <th className="py-2 text-right font-semibold">Seller paga</th>
                <th className="py-2 text-right font-semibold">Custo</th>
                <th className="py-2 text-right font-semibold">Lucro</th>
              </tr>
            </thead>
            <tbody>
              {simulated.map((acquirer) => {
                const charged = Math.min(feeCents(amountCents, rates?.pix.percentage ?? 0, rates?.pix.fixed ?? 0), amountCents);
                const cost = feeCents(amountCents, acquirer.cash_in_percentage, acquirer.cash_in_fixed);
                const margin = charged - cost;
                return (
                  <tr key={acquirer.key} className="border-b border-white/[0.04] last:border-0">
                    <td className="py-2.5 text-gray-200">
                      {acquirer.name}
                      {!acquirer.enabled && <span className="ml-2 text-xs text-gray-500">desativada</span>}
                    </td>
                    <td className="py-2.5 text-right tabular-nums text-gray-300">{formatCurrency(charged)}</td>
                    <td className="py-2.5 text-right tabular-nums text-gray-300">{formatCurrency(cost)}</td>
                    <td className={clsx('py-2.5 text-right font-semibold tabular-nums', margin < 0 ? 'text-red-400' : 'text-emerald-400')}>
                      {formatCurrency(margin)}
                    </td>
                  </tr>
                );
              })}
              {simulated.length === 0 && (
                <tr>
                  <td colSpan={4} className="py-8 text-center text-gray-500">{isLoading ? 'Carregando...' : 'Nenhuma adquirente cadastrada.'}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className={cardClass}>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-2">
            <BanknotesIcon className="h-5 w-5 text-red-400" />
            <h2 className="text-sm font-semibold text-white">Lucro do gateway</h2>
          </div>
          <div className="flex gap-1">
            {PERIODS.map((item) => (
              <button
                key={item.key}
                type="button"
                onClick={() => setPeriod(item.key)}
                aria-pressed={period === item.key}
                className={clsx(
                  'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                  period === item.key ? 'bg-red-600 text-white' : 'text-gray-400 hover:text-white'
                )}
              >
                {item.label}
              </button>
            ))}
          </div>
        </div>

        <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[
            { label: 'Volume aprovado', value: totals?.volume ?? 0 },
            { label: 'Taxas recebidas', value: (totals?.fee_revenue ?? 0) + (totals?.withdrawal_fee_revenue ?? 0) },
            { label: 'Custo das adquirentes', value: (totals?.acquirer_cost ?? 0) + (totals?.withdrawal_cost ?? 0) },
            { label: 'Lucro líquido', value: totals?.profit ?? 0, highlight: true },
          ].map(({ label, value, highlight }) => (
            <div key={label} className={clsx('rounded-xl border p-4', highlight ? 'border-red-500/30 bg-red-500/[0.06]' : 'border-white/[0.06]')}>
              <p className="text-xs font-medium text-gray-400">{label}</p>
              <p className={clsx('mt-1 truncate text-xl font-bold tabular-nums', value < 0 ? 'text-red-400' : 'text-white')}>{formatCurrency(value)}</p>
            </div>
          ))}
        </div>

        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="border-b border-white/[0.06] text-left text-[11px] uppercase tracking-wider text-gray-500">
                <th className="py-2 font-semibold">Adquirente</th>
                <th className="py-2 text-right font-semibold">Vendas</th>
                <th className="py-2 text-right font-semibold">Volume</th>
                <th className="py-2 text-right font-semibold">Taxas</th>
                <th className="py-2 text-right font-semibold">Custo</th>
                <th className="py-2 text-right font-semibold">Lucro</th>
              </tr>
            </thead>
            <tbody>
              {(profit?.acquirers ?? []).map((row) => (
                <tr key={row.acquirer} className="border-b border-white/[0.04] last:border-0">
                  <td className="py-2.5 text-gray-200">{row.name}</td>
                  <td className="py-2.5 text-right tabular-nums text-gray-300">{row.transactions.toLocaleString('pt-BR')}</td>
                  <td className="py-2.5 text-right tabular-nums text-gray-300">{formatCurrency(row.volume)}</td>
                  <td className="py-2.5 text-right tabular-nums text-gray-300">{formatCurrency(row.fee_revenue + row.withdrawal_fee_revenue)}</td>
                  <td className="py-2.5 text-right tabular-nums text-gray-300">{formatCurrency(row.acquirer_cost + row.withdrawal_cost)}</td>
                  <td className={clsx('py-2.5 text-right font-semibold tabular-nums', row.profit < 0 ? 'text-red-400' : 'text-emerald-400')}>
                    {formatCurrency(row.profit)}
                  </td>
                </tr>
              ))}
              {(profit?.acquirers ?? []).length === 0 && (
                <tr>
                  <td colSpan={6} className="py-8 text-center text-gray-500">Sem vendas aprovadas nem saques concluídos no período.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <p className="mt-3 text-xs text-gray-500">
          Vendas anteriores a esta tela usam a taxa atual da adquirente como estimativa de custo. Saques entram quando concluídos.
        </p>
      </section>
    </AdminPageShell>
  );
}
