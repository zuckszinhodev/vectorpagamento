import { useState, useEffect, Fragment } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Dialog, Transition, Menu } from '@headlessui/react';
import {
  PlusIcon,
  TrashIcon,
  PencilSquareIcon,
  EllipsisVerticalIcon,
  XMarkIcon,
  ArrowPathIcon,
  ChartBarIcon,
  PlayIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  BoltIcon,
  CurrencyDollarIcon,
  UserGroupIcon,
  ShieldCheckIcon,
  AdjustmentsHorizontalIcon,
  BeakerIcon,
  ArrowsRightLeftIcon,
  InformationCircleIcon,
  ClipboardDocumentIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import { SectionSpinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/stores/authStore';
import {
  splitsService,
  type SplitRule,
  type SplitRecipient,
  type CreateSplitRuleData,
  type SplitConfig,
  type SimulationResult,
} from '@/services/api/splits.service';

// ── Helpers ────────────────────────────────────────────

function formatCurrency(cents: number): string {
  return `R$ ${(cents / 100).toFixed(2).replace('.', ',')}`;
}

function statusBadge(isActive: boolean) {
  return isActive
    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
    : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
}

const emptyRecipient: SplitRecipient = {
  recipient_id: '',
  recipient_type: 'account',
  type: 'percentage',
  value: 0,
  description: '',
};

// ══════════════════════════════════════════════════════
//  MAIN COMPONENT
// ══════════════════════════════════════════════════════

export default function SplitManagement() {
  const queryClient = useQueryClient();
  const { user } = useAuthStore();
  const [view, setView] = useState<'rules' | 'config'>('rules');
  const [ruleModalOpen, setRuleModalOpen] = useState(false);
  const [simulateModalOpen, setSimulateModalOpen] = useState(false);
  const [editingRule, setEditingRule] = useState<SplitRule | null>(null);
  const [simulatingRule, setSimulatingRule] = useState<SplitRule | null>(null);

  // ── Data fetching ──────────────────────────────────

  const { data: rulesData, isLoading: rulesLoading } = useQuery({
    queryKey: ['split-rules'],
    queryFn: () => splitsService.listRules(),
  });

  const { data: configData, isLoading: configLoading } = useQuery({
    queryKey: ['split-config'],
    queryFn: () => splitsService.getConfig(),
  });

  const { data: productsData } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 100 } });
      return res.data;
    },
  });

  const { data: checkoutsData } = useQuery({
    queryKey: ['checkouts-list'],
    queryFn: async () => {
      const res = await api.get('/checkouts', { params: { per_page: 100 } });
      return res.data;
    },
  });

  const rules: SplitRule[] = rulesData?.data || [];
  const products = productsData?.data || [];
  const checkouts = checkoutsData?.data || [];

  // ── Mutations ──────────────────────────────────────

  const deleteRuleMutation = useMutation({
    mutationFn: (id: string) => splitsService.deleteRule(id),
    onSuccess: () => {
      toast.success('Regra excluída com sucesso');
      queryClient.invalidateQueries({ queryKey: ['split-rules'] });
    },
    onError: () => toast.error('Erro ao excluir regra'),
  });

  const processPendingMutation = useMutation({
    mutationFn: () => splitsService.processPending(),
    onSuccess: (res) => {
      const d = res.data;
      toast.success(`Processados: ${d.processed} | Falhas: ${d.failed}`);
      queryClient.invalidateQueries({ queryKey: ['split-rules'] });
    },
    onError: () => toast.error('Erro ao processar splits pendentes'),
  });

  // ── Handlers ───────────────────────────────────────

  const openCreate = () => {
    setEditingRule(null);
    setRuleModalOpen(true);
  };

  const openEdit = (rule: SplitRule) => {
    setEditingRule(rule);
    setRuleModalOpen(true);
  };

  const openSimulate = (rule: SplitRule) => {
    setSimulatingRule(rule);
    setSimulateModalOpen(true);
  };

  const confirmDelete = (rule: SplitRule) => {
    if (window.confirm(`Tem certeza que deseja excluir a regra "${rule.name}"?`)) {
      deleteRuleMutation.mutate(rule.id);
    }
  };

  // ── Stats ──────────────────────────────────────────

  const activeRules = rules.filter(r => r.is_active).length;
  const defaultRule = rules.find(r => r.is_default && r.is_active);
  const totalRecipients = rules.reduce((sum, r) => sum + (r.recipients?.length || 0), 0);

  // ── Render ─────────────────────────────────────────

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold text-tx-primary flex items-center gap-2">
            <ArrowsRightLeftIcon className="h-6 w-6 text-accent-primary" />
            Gerenciamento de Split de Pagamento
          </h2>
          <p className="text-sm text-tx-muted mt-1">
            Configure regras automáticas para dividir pagamentos entre múltiplos destinatários
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={openCreate} className="btn-primary inline-flex items-center gap-2 text-sm">
            <PlusIcon className="h-4 w-4" />
            Nova Regra
          </button>
        </div>
      </div>

      {/* Split ID do Usuário */}
      <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 flex items-center justify-between gap-4">
        <div className="flex items-center gap-3 min-w-0">
          <div className="rounded-element bg-accent-primary/10 p-2 shrink-0">
            <InformationCircleIcon className="h-5 w-5 text-accent-primary" />
          </div>
          <div className="min-w-0">
            <p className="text-xs text-tx-muted">Seu ID de Split</p>
            <p className="text-sm font-mono font-semibold text-tx-primary truncate">{user?.account_id || '—'}</p>
          </div>
        </div>
        <button
          onClick={() => {
            if (user?.account_id) {
              navigator.clipboard.writeText(user.account_id);
              toast.success('ID copiado!');
            }
          }}
          className="btn-secondary inline-flex items-center gap-1.5 text-xs shrink-0"
        >
          <ClipboardDocumentIcon className="h-4 w-4" />
          Copiar
        </button>
      </div>

      {/* Tab Toggle */}
      <div className="flex gap-1 bg-surface-elevated rounded-element p-1">
        <button
          onClick={() => setView('rules')}
          className={`flex-1 px-4 py-2 text-sm font-medium rounded-element transition-colors ${
            view === 'rules'
              ? 'bg-surface-card text-tx-primary shadow-sm'
              : 'text-tx-muted hover:text-tx-secondary'
          }`}
        >
          <AdjustmentsHorizontalIcon className="h-4 w-4 inline-block mr-1.5 -mt-0.5" />
          Regras de Split ({rules.length})
        </button>
        <button
          onClick={() => setView('config')}
          className={`flex-1 px-4 py-2 text-sm font-medium rounded-element transition-colors ${
            view === 'config'
              ? 'bg-surface-card text-tx-primary shadow-sm'
              : 'text-tx-muted hover:text-tx-secondary'
          }`}
        >
          <ChartBarIcon className="h-4 w-4 inline-block mr-1.5 -mt-0.5" />
          Configuração Global
        </button>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="card p-4">
          <div className="flex items-center gap-3">
            <ShieldCheckIcon className="h-8 w-8 text-accent-primary" />
            <div>
              <p className="text-xl sm:text-2xl font-bold text-tx-primary">{activeRules}</p>
              <p className="text-xs text-tx-muted">Regras Ativas</p>
            </div>
          </div>
        </div>
        <div className="card p-4">
          <div className="flex items-center gap-3">
            <UserGroupIcon className="h-8 w-8 text-blue-500" />
            <div>
              <p className="text-xl sm:text-2xl font-bold text-tx-primary">{totalRecipients}</p>
              <p className="text-xs text-tx-muted">Destinatários Configurados</p>
            </div>
          </div>
        </div>
        <div className="card p-4">
          <div className="flex items-center gap-3">
            {defaultRule ? (
              <CheckCircleIcon className="h-8 w-8 text-green-500" />
            ) : (
              <ExclamationTriangleIcon className="h-8 w-8 text-yellow-500" />
            )}
            <div>
              <p className="text-sm font-semibold text-tx-primary">
                {defaultRule ? defaultRule.name : 'Nenhuma'}
              </p>
              <p className="text-xs text-tx-muted">Regra Padrão</p>
            </div>
          </div>
        </div>
      </div>

      {/* Content */}
      {view === 'rules' ? (
        <RulesList
          rules={rules}
          loading={rulesLoading}
          products={products}
          checkouts={checkouts}
          onEdit={openEdit}
          onDelete={confirmDelete}
          onSimulate={openSimulate}
          onCreate={openCreate}
        />
      ) : (
        <GlobalConfig
          config={configData?.data}
          loading={configLoading}
        />
      )}

      {/* Modals */}
      <RuleModal
        open={ruleModalOpen}
        onClose={() => setRuleModalOpen(false)}
        rule={editingRule}
        products={products}
        checkouts={checkouts}
      />

      <SimulateModal
        open={simulateModalOpen}
        onClose={() => setSimulateModalOpen(false)}
        rule={simulatingRule}
      />
    </div>
  );
}

// ══════════════════════════════════════════════════════
//  RULES LIST
// ══════════════════════════════════════════════════════

function RulesList({
  rules,
  loading,
  products,
  checkouts,
  onEdit,
  onDelete,
  onSimulate,
  onCreate,
}: {
  rules: SplitRule[];
  loading: boolean;
  products: any[];
  checkouts: any[];
  onEdit: (r: SplitRule) => void;
  onDelete: (r: SplitRule) => void;
  onSimulate: (r: SplitRule) => void;
  onCreate: () => void;
}) {
  if (loading) return <SectionSpinner className="py-12" />;

  if (rules.length === 0) {
    return (
      <div className="card p-12 text-center">
        <ArrowsRightLeftIcon className="h-12 w-12 mx-auto text-tx-muted/40 mb-4" />
        <h3 className="text-lg font-semibold text-tx-primary mb-2">Nenhuma regra de split</h3>
        <p className="text-sm text-tx-muted mb-6 max-w-md mx-auto">
          Crie regras para dividir automaticamente os pagamentos entre múltiplos destinatários como afiliados, parceiros ou plataformas.
        </p>
        <button onClick={onCreate} className="btn-primary inline-flex items-center gap-2">
          <PlusIcon className="h-4 w-4" />
          Criar Primeira Regra
        </button>
      </div>
    );
  }

  const getProductName = (id?: string | null) => {
    if (!id) return null;
    return products.find((p: any) => p.id === id)?.name || 'Produto removido';
  };

  const getCheckoutName = (id?: string | null) => {
    if (!id) return null;
    return checkouts.find((c: any) => c.id === id)?.name || 'Checkout removido';
  };

  return (
    <div className="space-y-3">
      {rules.map((rule) => (
        <div
          key={rule.id}
          className="card hover:shadow-md transition-shadow"
        >
          <div className="p-4 sm:p-5">
            {/* Top row: name, badges, actions */}
            <div className="flex items-start justify-between gap-4">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <h3 className="font-semibold text-tx-primary truncate">{rule.name}</h3>
                  <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${statusBadge(rule.is_active)}`}>
                    {rule.is_active ? 'Ativa' : 'Inativa'}
                  </span>
                  {rule.is_default && (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent-primary/10 text-accent-primary">
                      Padrão
                    </span>
                  )}
                  {rule.auto_process && (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                      <BoltIcon className="h-3 w-3 mr-0.5" />
                      Auto
                    </span>
                  )}
                </div>

                {/* Scope info */}
                <div className="flex items-center gap-3 mt-1.5 text-xs text-tx-muted">
                  {rule.product_id && (
                    <span className="inline-flex items-center gap-1">
                      <CurrencyDollarIcon className="h-3.5 w-3.5" />
                      {getProductName(rule.product_id)}
                    </span>
                  )}
                  {rule.checkout_id && (
                    <span className="inline-flex items-center gap-1">
                      <ChartBarIcon className="h-3.5 w-3.5" />
                      {getCheckoutName(rule.checkout_id)}
                    </span>
                  )}
                  {!rule.product_id && !rule.checkout_id && !rule.is_default && (
                    <span className="text-tx-muted/60">Sem vínculo específico</span>
                  )}
                  {rule.minimum_amount > 0 && (
                    <span>Mín: {formatCurrency(rule.minimum_amount)}</span>
                  )}
                  {rule.transfer_delay_hours > 0 && (
                    <span className="inline-flex items-center gap-1">
                      <ClockIcon className="h-3.5 w-3.5" />
                      {rule.transfer_delay_hours}h delay
                    </span>
                  )}
                  <span>Prioridade: {rule.priority}</span>
                </div>
              </div>

              {/* Actions */}
              <Menu as="div" className="relative flex-shrink-0">
                <Menu.Button className="p-1.5 rounded-element hover:bg-surface-hover text-tx-muted">
                  <EllipsisVerticalIcon className="h-5 w-5" />
                </Menu.Button>
                <Transition
                  as={Fragment}
                  enter="transition ease-out duration-100"
                  enterFrom="transform opacity-0 scale-95"
                  enterTo="transform opacity-100 scale-100"
                  leave="transition ease-in duration-75"
                  leaveFrom="transform opacity-100 scale-100"
                  leaveTo="transform opacity-0 scale-95"
                >
                  <Menu.Items className="absolute right-0 mt-1 w-48 origin-top-right rounded-element bg-surface-card shadow-lg ring-1 ring-border-DEFAULT focus:outline-none z-20">
                    <div className="py-1">
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => onEdit(rule)}
                            className={`${active ? 'bg-surface-hover' : ''} group flex w-full items-center px-3 py-2 text-sm text-tx-secondary`}
                          >
                            <PencilSquareIcon className="h-4 w-4 mr-2 text-tx-muted" />
                            Editar
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => onSimulate(rule)}
                            className={`${active ? 'bg-surface-hover' : ''} group flex w-full items-center px-3 py-2 text-sm text-tx-secondary`}
                          >
                            <BeakerIcon className="h-4 w-4 mr-2 text-tx-muted" />
                            Simular
                          </button>
                        )}
                      </Menu.Item>
                      <Menu.Item>
                        {({ active }) => (
                          <button
                            onClick={() => onDelete(rule)}
                            className={`${active ? 'bg-red-50 dark:bg-red-900/20' : ''} group flex w-full items-center px-3 py-2 text-sm text-red-600 dark:text-red-400`}
                          >
                            <TrashIcon className="h-4 w-4 mr-2" />
                            Excluir
                          </button>
                        )}
                      </Menu.Item>
                    </div>
                  </Menu.Items>
                </Transition>
              </Menu>
            </div>

            {/* Recipients table */}
            {rule.recipients && rule.recipients.length > 0 && (
              <div className="mt-4 border border-border-DEFAULT rounded-element overflow-hidden">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-surface-elevated">
                      <th className="text-left px-3 py-2 text-xs font-medium text-tx-muted uppercase tracking-wider">Destinatário</th>
                      <th className="text-left px-3 py-2 text-xs font-medium text-tx-muted uppercase tracking-wider">Tipo</th>
                      <th className="text-right px-3 py-2 text-xs font-medium text-tx-muted uppercase tracking-wider">Valor</th>
                      <th className="text-left px-3 py-2 text-xs font-medium text-tx-muted uppercase tracking-wider">Descrição</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border-DEFAULT">
                    {rule.recipients.map((r, idx) => (
                      <tr key={idx} className="hover:bg-surface-hover/50">
                        <td className="px-3 py-2">
                          <div className="flex items-center gap-2">
                            <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase ${
                              r.recipient_type === 'account' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' :
                              r.recipient_type === 'affiliate' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' :
                              'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'
                            }`}>
                              {r.recipient_type === 'account' ? 'Conta' : r.recipient_type === 'affiliate' ? 'Afiliado' : 'Externo'}
                            </span>
                            <span className="text-tx-secondary font-mono text-xs truncate max-w-[140px]">
                              {r.recipient_id.substring(0, 8)}...
                            </span>
                          </div>
                        </td>
                        <td className="px-3 py-2 text-tx-secondary">
                          {r.type === 'percentage' ? 'Percentual' : 'Fixo'}
                        </td>
                        <td className="px-3 py-2 text-right font-semibold text-tx-primary">
                          {r.type === 'percentage' ? `${r.value}%` : formatCurrency(r.value)}
                        </td>
                        <td className="px-3 py-2 text-tx-muted text-xs">
                          {r.description || '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  {rule.recipients.some(r => r.type === 'percentage') && (
                    <tfoot>
                      <tr className="bg-surface-elevated/50">
                        <td colSpan={2} className="px-3 py-1.5 text-xs text-tx-muted text-right">Total percentual:</td>
                        <td className="px-3 py-1.5 text-right text-xs font-semibold text-tx-primary">
                          {rule.recipients.filter(r => r.type === 'percentage').reduce((s, r) => s + r.value, 0)}%
                        </td>
                        <td className="px-3 py-1.5">
                          {rule.charge_fee_to_producer && (
                            <span className="text-[10px] text-tx-muted">Taxa: produtor</span>
                          )}
                        </td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

// ══════════════════════════════════════════════════════
//  GLOBAL CONFIG
// ══════════════════════════════════════════════════════

function GlobalConfig({
  config,
  loading,
}: {
  config?: SplitConfig;
  loading: boolean;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<SplitConfig>({
    enabled: false,
    default_split_type: 'percentage',
    default_affiliate_commission: 10,
    auto_process: true,
    minimum_amount: 100,
  });

  useEffect(() => {
    if (config) setForm(config);
  }, [config]);

  const saveMutation = useMutation({
    mutationFn: (data: SplitConfig) => splitsService.updateConfig(data),
    onSuccess: () => {
      toast.success('Configuração global salva!');
      queryClient.invalidateQueries({ queryKey: ['split-config'] });
    },
    onError: () => toast.error('Erro ao salvar configuração'),
  });

  if (loading) return <SectionSpinner className="py-12" />;

  const Toggle = ({ value, onChange, label, description }: { value: boolean; onChange: () => void; label: string; description: string }) => (
    <div className="flex items-center justify-between rounded-element border border-border-DEFAULT p-4">
      <div>
        <h3 className="font-medium text-tx-primary">{label}</h3>
        <p className="text-sm text-tx-muted">{description}</p>
      </div>
      <button
        onClick={onChange}
        className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${value ? 'bg-accent-primary' : 'bg-surface-hover'}`}
      >
        <span className={`inline-block h-4 w-4 transform rounded-full bg-surface-card transition-transform ${value ? 'translate-x-6' : 'translate-x-1'}`} />
      </button>
    </div>
  );

  return (
    <div className="card">
      <div className="flex items-center gap-3 border-b border-border-DEFAULT px-4 sm:px-6 py-4">
        <ChartBarIcon className="h-5 w-5 text-tx-muted" />
        <h2 className="text-lg font-semibold text-tx-primary">Configuração Global de Split</h2>
      </div>
      <div className="p-4 sm:p-6 space-y-4 sm:space-y-6">
        <div className="flex items-start gap-3 p-4 rounded-element bg-blue-50 dark:bg-blue-900/10 border border-blue-200 dark:border-blue-800/30">
          <InformationCircleIcon className="h-5 w-5 text-blue-500 flex-shrink-0 mt-0.5" />
          <div className="text-sm text-blue-700 dark:text-blue-300">
            <p className="font-medium mb-1">Configuração Legada vs Regras</p>
            <p>Estas configurações são o fallback para cobranças sem regra específica. Para maior controle, use as <strong>Regras de Split</strong> que permitem múltiplos destinatários por produto/checkout.</p>
          </div>
        </div>

        <Toggle
          value={form.enabled}
          onChange={() => setForm(prev => ({ ...prev, enabled: !prev.enabled }))}
          label="Ativar Split de Pagamento"
          description="Divide automaticamente os pagamentos para seu sistema ou afiliados"
        />

        {form.enabled && (
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Tipo de Split Padrão</label>
              <select
                value={form.default_split_type}
                onChange={(e) => setForm(prev => ({ ...prev, default_split_type: e.target.value as 'percentage' | 'fixed' }))}
                className="input w-full max-w-md"
              >
                <option value="percentage">Porcentagem (%)</option>
                <option value="fixed">Valor Fixo (R$)</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">
                {form.default_split_type === 'percentage' ? 'Comissão do Afiliado (%)' : 'Comissão do Afiliado (R$)'}
              </label>
              <div className="flex items-center gap-2 max-w-md">
                <input
                  type="number"
                  value={form.default_affiliate_commission}
                  onChange={(e) => setForm(prev => ({ ...prev, default_affiliate_commission: parseFloat(e.target.value) || 0 }))}
                  className="input w-full"
                  min="0"
                  max={form.default_split_type === 'percentage' ? 100 : 999999}
                  step={form.default_split_type === 'percentage' ? 0.5 : 1}
                />
                <span className="text-sm text-tx-muted">{form.default_split_type === 'percentage' ? '%' : 'centavos'}</span>
              </div>
              <p className="mt-1 text-xs text-tx-muted">
                {form.default_split_type === 'percentage'
                  ? `O afiliado recebe ${form.default_affiliate_commission}% de cada venda`
                  : `O afiliado recebe R$ ${(form.default_affiliate_commission / 100).toFixed(2)} por venda`}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Valor Mínimo para Split (centavos)</label>
              <input
                type="number"
                value={form.minimum_amount}
                onChange={(e) => setForm(prev => ({ ...prev, minimum_amount: parseInt(e.target.value) || 0 }))}
                className="input w-full max-w-md"
                min="0"
              />
              <p className="mt-1 text-xs text-tx-muted">
                Splits só são criados para cobranças acima de R$ {(form.minimum_amount / 100).toFixed(2)}
              </p>
            </div>

            <Toggle
              value={form.auto_process}
              onChange={() => setForm(prev => ({ ...prev, auto_process: !prev.auto_process }))}
              label="Processamento Automático"
              description="Credita automaticamente os splits nas carteiras assim que o pagamento é confirmado"
            />
          </div>
        )}

        <div className="flex justify-end pt-4 border-t border-border-DEFAULT">
          <button
            onClick={() => saveMutation.mutate(form)}
            disabled={saveMutation.isPending}
            className="btn-primary inline-flex items-center gap-2"
          >
            {saveMutation.isPending && <ArrowPathIcon className="h-4 w-4 animate-spin" />}
            Salvar Configuração
          </button>
        </div>
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════════════
//  RULE MODAL (Create / Edit)
// ══════════════════════════════════════════════════════

function RuleModal({
  open,
  onClose,
  rule,
  products,
  checkouts,
}: {
  open: boolean;
  onClose: () => void;
  rule: SplitRule | null;
  products: any[];
  checkouts: any[];
}) {
  const queryClient = useQueryClient();
  const isEdit = !!rule;

  const [form, setForm] = useState<CreateSplitRuleData>({
    name: '',
    product_id: null,
    checkout_id: null,
    is_default: false,
    is_active: true,
    priority: 0,
    recipients: [{ ...emptyRecipient }],
    charge_fee_to_producer: true,
    minimum_amount: 0,
    auto_process: true,
    transfer_delay_hours: 0,
  });

  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (rule) {
      setForm({
        name: rule.name,
        product_id: rule.product_id || null,
        checkout_id: rule.checkout_id || null,
        is_default: rule.is_default,
        is_active: rule.is_active,
        priority: rule.priority,
        recipients: rule.recipients?.length ? [...rule.recipients] : [{ ...emptyRecipient }],
        charge_fee_to_producer: rule.charge_fee_to_producer,
        minimum_amount: rule.minimum_amount,
        auto_process: rule.auto_process,
        transfer_delay_hours: rule.transfer_delay_hours,
      });
    } else {
      setForm({
        name: '',
        product_id: null,
        checkout_id: null,
        is_default: false,
        is_active: true,
        priority: 0,
        recipients: [{ ...emptyRecipient }],
        charge_fee_to_producer: true,
        minimum_amount: 0,
        auto_process: true,
        transfer_delay_hours: 0,
      });
    }
    setErrors({});
  }, [rule, open]);

  const createMutation = useMutation({
    mutationFn: (data: CreateSplitRuleData) => splitsService.createRule(data),
    onSuccess: () => {
      toast.success('Regra criada com sucesso!');
      queryClient.invalidateQueries({ queryKey: ['split-rules'] });
      onClose();
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message || 'Erro ao criar regra';
      toast.error(msg);
      if (err?.response?.data?.errors) setErrors(err.response.data.errors);
    },
  });

  const updateMutation = useMutation({
    mutationFn: (data: CreateSplitRuleData) => splitsService.updateRule(rule!.id, data),
    onSuccess: () => {
      toast.success('Regra atualizada com sucesso!');
      queryClient.invalidateQueries({ queryKey: ['split-rules'] });
      onClose();
    },
    onError: (err: any) => {
      const msg = err?.response?.data?.message || 'Erro ao atualizar regra';
      toast.error(msg);
      if (err?.response?.data?.errors) setErrors(err.response.data.errors);
    },
  });

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!form.name.trim()) e.name = 'Nome é obrigatório';
    if (!form.recipients || form.recipients.length === 0) e.recipients = 'Adicione pelo menos 1 destinatário';
    const totalPct = form.recipients
      .filter(r => r.type === 'percentage')
      .reduce((s, r) => s + r.value, 0);
    if (totalPct > 100) e.recipients = 'Soma dos percentuais não pode exceder 100%';
    form.recipients.forEach((r, i) => {
      if (!r.recipient_id.trim()) e[`recipient_${i}`] = 'ID do destinatário é obrigatório';
      if (r.value <= 0) e[`value_${i}`] = 'Valor deve ser maior que 0';
    });
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = () => {
    if (!validate()) return;
    if (isEdit) {
      updateMutation.mutate(form);
    } else {
      createMutation.mutate(form);
    }
  };

  const addRecipient = () => {
    if (form.recipients.length >= 10) {
      toast.error('Máximo de 10 destinatários por regra');
      return;
    }
    setForm(prev => ({ ...prev, recipients: [...prev.recipients, { ...emptyRecipient }] }));
  };

  const removeRecipient = (idx: number) => {
    setForm(prev => ({ ...prev, recipients: prev.recipients.filter((_, i) => i !== idx) }));
  };

  const updateRecipient = (idx: number, field: keyof SplitRecipient, value: any) => {
    setForm(prev => ({
      ...prev,
      recipients: prev.recipients.map((r, i) => i === idx ? { ...r, [field]: value } : r),
    }));
  };

  const isPending = createMutation.isPending || updateMutation.isPending;
  const totalPct = form.recipients.filter(r => r.type === 'percentage').reduce((s, r) => s + r.value, 0);

  return (
    <Transition appear show={open} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300" enterFrom="opacity-0" enterTo="opacity-100"
          leave="ease-in duration-200" leaveFrom="opacity-100" leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100"
              leave="ease-in duration-200" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-2xl transform rounded-2xl bg-surface-card shadow-xl transition-all">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary">
                    {isEdit ? 'Editar Regra de Split' : 'Nova Regra de Split'}
                  </Dialog.Title>
                  <button onClick={onClose} className="p-1 rounded-element hover:bg-surface-hover text-tx-muted">
                    <XMarkIcon className="h-5 w-5" />
                  </button>
                </div>

                {/* Body */}
                <div className="px-4 sm:px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto">
                  {/* Name */}
                  <div>
                    <label className="block text-sm font-medium text-tx-secondary mb-1">Nome da Regra *</label>
                    <input
                      value={form.name}
                      onChange={(e) => setForm(prev => ({ ...prev, name: e.target.value }))}
                      className={`input w-full ${errors.name ? 'border-red-500' : ''}`}
                      placeholder="Ex: Comissão Afiliados Premium"
                    />
                    {errors.name && <p className="text-xs text-red-500 mt-1">{errors.name}</p>}
                  </div>

                  {/* Scope: Product & Checkout */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Produto (opcional)</label>
                      <select
                        value={form.product_id || ''}
                        onChange={(e) => setForm(prev => ({ ...prev, product_id: e.target.value || null }))}
                        className="input w-full"
                      >
                        <option value="">Todos os produtos</option>
                        {products.map((p: any) => (
                          <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                      </select>
                      <p className="text-xs text-tx-muted mt-1">Selecione para aplicar apenas a este produto</p>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Checkout (opcional)</label>
                      <select
                        value={form.checkout_id || ''}
                        onChange={(e) => setForm(prev => ({ ...prev, checkout_id: e.target.value || null }))}
                        className="input w-full"
                      >
                        <option value="">Todos os checkouts</option>
                        {checkouts.map((c: any) => (
                          <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                      </select>
                      <p className="text-xs text-tx-muted mt-1">Selecione para aplicar apenas a este checkout</p>
                    </div>
                  </div>

                  {/* Toggles row */}
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label className="flex items-center gap-2 p-3 rounded-element border border-border-DEFAULT cursor-pointer hover:bg-surface-hover/50">
                      <input
                        type="checkbox"
                        checked={form.is_default || false}
                        onChange={(e) => setForm(prev => ({ ...prev, is_default: e.target.checked }))}
                        className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary"
                      />
                      <div>
                        <span className="text-sm font-medium text-tx-primary">Regra Padrão</span>
                        <p className="text-[10px] text-tx-muted">Fallback geral</p>
                      </div>
                    </label>
                    <label className="flex items-center gap-2 p-3 rounded-element border border-border-DEFAULT cursor-pointer hover:bg-surface-hover/50">
                      <input
                        type="checkbox"
                        checked={form.is_active !== false}
                        onChange={(e) => setForm(prev => ({ ...prev, is_active: e.target.checked }))}
                        className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary"
                      />
                      <div>
                        <span className="text-sm font-medium text-tx-primary">Ativa</span>
                        <p className="text-[10px] text-tx-muted">Regra funcional</p>
                      </div>
                    </label>
                    <label className="flex items-center gap-2 p-3 rounded-element border border-border-DEFAULT cursor-pointer hover:bg-surface-hover/50">
                      <input
                        type="checkbox"
                        checked={form.auto_process !== false}
                        onChange={(e) => setForm(prev => ({ ...prev, auto_process: e.target.checked }))}
                        className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary"
                      />
                      <div>
                        <span className="text-sm font-medium text-tx-primary">Auto-processar</span>
                        <p className="text-[10px] text-tx-muted">Crédito imediato</p>
                      </div>
                    </label>
                  </div>

                  {/* Advanced options row */}
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Prioridade</label>
                      <input
                        type="number"
                        value={form.priority || 0}
                        onChange={(e) => setForm(prev => ({ ...prev, priority: parseInt(e.target.value) || 0 }))}
                        className="input w-full"
                        min="0"
                      />
                      <p className="text-[10px] text-tx-muted mt-0.5">Maior = avaliada primeiro</p>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Valor Mínimo (centavos)</label>
                      <input
                        type="number"
                        value={form.minimum_amount || 0}
                        onChange={(e) => setForm(prev => ({ ...prev, minimum_amount: parseInt(e.target.value) || 0 }))}
                        className="input w-full"
                        min="0"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Delay (horas)</label>
                      <input
                        type="number"
                        value={form.transfer_delay_hours || 0}
                        onChange={(e) => setForm(prev => ({ ...prev, transfer_delay_hours: parseInt(e.target.value) || 0 }))}
                        className="input w-full"
                        min="0"
                      />
                    </div>
                  </div>

                  {/* Fee config */}
                  <label className="flex items-center gap-2 p-3 rounded-element border border-border-DEFAULT cursor-pointer hover:bg-surface-hover/50">
                    <input
                      type="checkbox"
                      checked={form.charge_fee_to_producer !== false}
                      onChange={(e) => setForm(prev => ({ ...prev, charge_fee_to_producer: e.target.checked }))}
                      className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary"
                    />
                    <div>
                      <span className="text-sm font-medium text-tx-primary">Taxa cobrada do produtor</span>
                      <p className="text-xs text-tx-muted">Se desativado, a taxa do gateway é distribuída entre todos os destinatários</p>
                    </div>
                  </label>

                  {/* Recipients */}
                  <div>
                    <div className="flex items-center justify-between mb-3">
                      <div>
                        <h3 className="text-sm font-semibold text-tx-primary">
                          Destinatários ({form.recipients.length}/10)
                        </h3>
                        {totalPct > 0 && (
                          <p className={`text-xs mt-0.5 ${totalPct > 100 ? 'text-red-500 font-semibold' : 'text-tx-muted'}`}>
                            Total percentual: {totalPct}%{totalPct > 100 ? ' — excede 100%!' : ''}
                          </p>
                        )}
                      </div>
                      <button
                        onClick={addRecipient}
                        className="text-sm text-accent-primary hover:text-accent-primary/80 font-medium inline-flex items-center gap-1"
                      >
                        <PlusIcon className="h-4 w-4" />
                        Adicionar
                      </button>
                    </div>

                    {errors.recipients && <p className="text-xs text-red-500 mb-2">{errors.recipients}</p>}

                    <div className="space-y-3">
                      {form.recipients.map((recipient, idx) => (
                        <div key={idx} className="p-3 rounded-element border border-border-DEFAULT bg-surface-elevated/30 space-y-3">
                          <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-tx-muted uppercase">Destinatário {idx + 1}</span>
                            {form.recipients.length > 1 && (
                              <button
                                onClick={() => removeRecipient(idx)}
                                className="p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded"
                              >
                                <TrashIcon className="h-4 w-4" />
                              </button>
                            )}
                          </div>

                          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                              <label className="block text-xs font-medium text-tx-muted mb-1">ID do Destinatário *</label>
                              <input
                                value={recipient.recipient_id}
                                onChange={(e) => updateRecipient(idx, 'recipient_id', e.target.value)}
                                className={`input w-full text-sm ${errors[`recipient_${idx}`] ? 'border-red-500' : ''}`}
                                placeholder="UUID da conta ou afiliado"
                              />
                              {errors[`recipient_${idx}`] && <p className="text-xs text-red-500 mt-0.5">{errors[`recipient_${idx}`]}</p>}
                            </div>
                            <div>
                              <label className="block text-xs font-medium text-tx-muted mb-1">Tipo de Destinatário</label>
                              <select
                                value={recipient.recipient_type}
                                onChange={(e) => updateRecipient(idx, 'recipient_type', e.target.value)}
                                className="input w-full text-sm"
                              >
                                <option value="account">Conta Vector Pagamentos</option>
                                <option value="affiliate">Afiliado</option>
                                <option value="external">Externo</option>
                              </select>
                            </div>
                          </div>

                          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                              <label className="block text-xs font-medium text-tx-muted mb-1">Tipo de Split</label>
                              <select
                                value={recipient.type}
                                onChange={(e) => updateRecipient(idx, 'type', e.target.value)}
                                className="input w-full text-sm"
                              >
                                <option value="percentage">Percentual (%)</option>
                                <option value="fixed">Valor Fixo (centavos)</option>
                              </select>
                            </div>
                            <div>
                              <label className="block text-xs font-medium text-tx-muted mb-1">
                                Valor {recipient.type === 'percentage' ? '(%)' : '(centavos)'} *
                              </label>
                              <input
                                type="number"
                                value={recipient.value}
                                onChange={(e) => updateRecipient(idx, 'value', parseFloat(e.target.value) || 0)}
                                className={`input w-full text-sm ${errors[`value_${idx}`] ? 'border-red-500' : ''}`}
                                min="0"
                                max={recipient.type === 'percentage' ? 100 : undefined}
                                step={recipient.type === 'percentage' ? 0.5 : 1}
                              />
                              {errors[`value_${idx}`] && <p className="text-xs text-red-500 mt-0.5">{errors[`value_${idx}`]}</p>}
                            </div>
                            <div>
                              <label className="block text-xs font-medium text-tx-muted mb-1">Descrição</label>
                              <input
                                value={recipient.description || ''}
                                onChange={(e) => updateRecipient(idx, 'description', e.target.value)}
                                className="input w-full text-sm"
                                placeholder="Ex: Comissão afiliado"
                              />
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                  <button onClick={onClose} className="btn-secondary">Cancelar</button>
                  <button
                    onClick={handleSubmit}
                    disabled={isPending}
                    className="btn-primary inline-flex items-center gap-2"
                  >
                    {isPending && <ArrowPathIcon className="h-4 w-4 animate-spin" />}
                    {isEdit ? 'Salvar Alterações' : 'Criar Regra'}
                  </button>
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}

// ══════════════════════════════════════════════════════
//  SIMULATE MODAL
// ══════════════════════════════════════════════════════

function SimulateModal({
  open,
  onClose,
  rule,
}: {
  open: boolean;
  onClose: () => void;
  rule: SplitRule | null;
}) {
  const [amount, setAmount] = useState(10000);
  const [feeAmount, setFeeAmount] = useState(90);
  const [result, setResult] = useState<SimulationResult | null>(null);

  const simulateMutation = useMutation({
    mutationFn: () => splitsService.simulate(rule!.id, amount, feeAmount),
    onSuccess: (res) => {
      setResult(res.data);
    },
    onError: () => toast.error('Erro ao simular split'),
  });

  useEffect(() => {
    setResult(null);
    setAmount(10000);
    setFeeAmount(90);
  }, [open, rule]);

  if (!rule) return null;

  return (
    <Transition appear show={open} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300" enterFrom="opacity-0" enterTo="opacity-100"
          leave="ease-in duration-200" leaveFrom="opacity-100" leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100"
              leave="ease-in duration-200" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-lg transform rounded-2xl bg-surface-card shadow-xl transition-all">
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary flex items-center gap-2">
                    <BeakerIcon className="h-5 w-5 text-accent-primary" />
                    Simular Split
                  </Dialog.Title>
                  <button onClick={onClose} className="p-1 rounded-element hover:bg-surface-hover text-tx-muted">
                    <XMarkIcon className="h-5 w-5" />
                  </button>
                </div>

                <div className="px-4 sm:px-6 py-5 space-y-5">
                  <div className="p-3 rounded-element bg-surface-elevated border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">{rule.name}</p>
                    <p className="text-xs text-tx-muted mt-0.5">{rule.recipients.length} destinatário(s)</p>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Valor da Cobrança</label>
                      <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-tx-muted">R$</span>
                        <input
                          type="number"
                          value={(amount / 100).toFixed(2)}
                          onChange={(e) => setAmount(Math.round(parseFloat(e.target.value || '0') * 100))}
                          className="input w-full pl-9"
                          step="0.01"
                          min="0"
                        />
                      </div>
                      <p className="text-xs text-tx-muted mt-0.5">{amount} centavos</p>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">Taxa do Gateway</label>
                      <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-tx-muted">R$</span>
                        <input
                          type="number"
                          value={(feeAmount / 100).toFixed(2)}
                          onChange={(e) => setFeeAmount(Math.round(parseFloat(e.target.value || '0') * 100))}
                          className="input w-full pl-9"
                          step="0.01"
                          min="0"
                        />
                      </div>
                      <p className="text-xs text-tx-muted mt-0.5">{feeAmount} centavos</p>
                    </div>
                  </div>

                  <button
                    onClick={() => simulateMutation.mutate()}
                    disabled={simulateMutation.isPending}
                    className="btn-primary w-full inline-flex items-center justify-center gap-2"
                  >
                    {simulateMutation.isPending ? (
                      <ArrowPathIcon className="h-4 w-4 animate-spin" />
                    ) : (
                      <PlayIcon className="h-4 w-4" />
                    )}
                    Simular Distribuição
                  </button>

                  {/* Results */}
                  {result && (
                    <div className="space-y-4">
                      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div className="text-center p-3 rounded-element bg-surface-elevated">
                          <p className="text-lg font-bold text-tx-primary">{formatCurrency(result.input_amount)}</p>
                          <p className="text-[10px] text-tx-muted uppercase">Valor Total</p>
                        </div>
                        <div className="text-center p-3 rounded-element bg-surface-elevated">
                          <p className="text-lg font-bold text-accent-primary">{formatCurrency(result.distributed_amount)}</p>
                          <p className="text-[10px] text-tx-muted uppercase">Distribuído</p>
                        </div>
                        <div className="text-center p-3 rounded-element bg-surface-elevated">
                          <p className="text-lg font-bold text-green-600">{formatCurrency(result.distributable_amount - result.distributed_amount)}</p>
                          <p className="text-[10px] text-tx-muted uppercase">Produtor</p>
                        </div>
                      </div>

                      <div className="border border-border-DEFAULT rounded-element overflow-hidden">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="bg-surface-elevated">
                              <th className="text-left px-3 py-2 text-xs font-medium text-tx-muted">Destinatário</th>
                              <th className="text-right px-3 py-2 text-xs font-medium text-tx-muted">Bruto</th>
                              <th className="text-right px-3 py-2 text-xs font-medium text-tx-muted">Taxa</th>
                              <th className="text-right px-3 py-2 text-xs font-medium text-tx-muted">Líquido</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-border-DEFAULT">
                            {result.recipients.map((r, idx) => (
                              <tr key={idx}>
                                <td className="px-3 py-2">
                                  <p className="font-medium text-tx-primary text-xs">{r.description || r.recipient_type}</p>
                                  <p className="text-[10px] text-tx-muted">
                                    {r.type === 'percentage' ? `${r.value}%` : `Fixo ${formatCurrency(r.value)}`}
                                  </p>
                                </td>
                                <td className="px-3 py-2 text-right font-mono text-xs text-tx-secondary">{formatCurrency(r.gross_amount)}</td>
                                <td className="px-3 py-2 text-right font-mono text-xs text-red-500">-{formatCurrency(r.fee_amount)}</td>
                                <td className="px-3 py-2 text-right font-mono text-xs font-semibold text-green-600">{formatCurrency(r.net_amount)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                </div>

                <div className="flex justify-end border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                  <button onClick={onClose} className="btn-secondary">Fechar</button>
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}
