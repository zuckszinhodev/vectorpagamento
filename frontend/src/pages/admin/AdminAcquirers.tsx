import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  CreditCardIcon,
  CheckCircleIcon,
  XCircleIcon,
  PencilSquareIcon,
  ArrowsUpDownIcon,
  XMarkIcon,
  ArrowPathIcon,
  ShieldCheckIcon,
  TrashIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Acquirer {
  id: string;
  name: string;
  slug: string;
  display_name: string;
  logo_url?: string;
  is_active: boolean;
  is_primary: boolean;
  priority: number;
  supports_pix: boolean;
  supports_credit_card: boolean;
  supports_boleto: boolean;
  acquirer_fee_percentage: number;
  acquirer_fee_fixed: number;
  gateway_fee_percentage: number;
  gateway_fee_fixed: number;
  sandbox_mode: boolean;
  last_health_check?: string;
  health_status?: 'healthy' | 'degraded' | 'down';
  config?: Record<string, unknown>;
}

type RotationMethod = 'pix' | 'credit_card' | 'boleto' | 'pix_out';
type RotationStrategy = 'priority' | 'round_robin' | 'weighted';

interface RotationMethodSettings {
  strategy: RotationStrategy;
  weights: Record<string, number>;
}

interface RotationSettings {
  enabled: boolean;
  failover_enabled: boolean;
  strict_health_check: boolean;
  use_cooldown: boolean;
  cooldown_seconds: number;
  methods: Record<RotationMethod, RotationMethodSettings>;
}

interface RotationPreviewMethod {
  strategy: RotationStrategy;
  candidates: string[];
  eligible: string[];
  order: string[];
  primary: string | null;
}

interface RotationSettingsResponse {
  settings: RotationSettings;
  preview: Record<RotationMethod, RotationPreviewMethod>;
}

const rotationMethods: RotationMethod[] = ['pix', 'credit_card', 'boleto', 'pix_out'];

export default function AdminAcquirers() {
  const queryClient = useQueryClient();
  const [selectedAcquirer, setSelectedAcquirer] = useState<Acquirer | null>(null);
  const [showEditModal, setShowEditModal] = useState(false);
  const [routingForm, setRoutingForm] = useState<RotationSettings | null>(null);

  // Fetch acquirers
  const { data: acquirers = [], isLoading, refetch } = useQuery<Acquirer[]>({
    queryKey: ['admin-acquirers'],
    queryFn: async () => {
      const response = await api.get('/admin/acquirers');
      return response.data?.data || [];
    },
  });

  const { data: routingData, isLoading: isRoutingLoading } = useQuery<RotationSettingsResponse>({
    queryKey: ['admin-acquirers-routing'],
    queryFn: async () => {
      const response = await api.get('/admin/acquirers/routing/settings');
      return response.data?.data as RotationSettingsResponse;
    },
  });

  useEffect(() => {
    if (routingData?.settings) {
      setRoutingForm(routingData.settings);
    }
  }, [routingData?.settings]);

  const saveRoutingSettingsMutation = useMutation({
    mutationFn: async (payload: RotationSettings) => {
      await api.put('/admin/acquirers/routing/settings', payload);
    },
    onSuccess: () => {
      toast.success('Regras de rotação atualizadas');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: () => {
      toast.error('Erro ao salvar regras de rotação');
    },
  });

  // Toggle status
  const toggleStatusMutation = useMutation({
    mutationFn: async ({ id, is_active }: { id: string; is_active: boolean }) => {
      await api.put(`/admin/acquirers/${id}`, { is_active });
    },
    onSuccess: () => {
      toast.success('Status atualizado');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: () => {
      toast.error('Erro ao atualizar status');
    },
  });

  // Set primary
  const setPrimaryMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/admin/acquirers/${id}/set-primary`);
    },
    onSuccess: () => {
      toast.success('Gateway primário definido');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: () => {
      toast.error('Erro ao definir gateway primário');
    },
  });

  // Update acquirer
  const updateMutation = useMutation({
    mutationFn: async (data: Partial<Acquirer> & { id: string }) => {
      const { id, ...rest } = data;
      await api.put(`/admin/acquirers/${id}`, rest);
    },
    onSuccess: () => {
      toast.success('Gateway atualizado');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
      setShowEditModal(false);
    },
    onError: () => {
      toast.error('Erro ao atualizar gateway');
    },
  });

  // Health check
  const healthCheckMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.post(`/admin/acquirers/${id}/health-check`);
    },
    onSuccess: () => {
      toast.success('Health check executado');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: () => {
      toast.error('Erro no health check');
    },
  });

  // Delete acquirer
  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/admin/acquirers/${id}`);
    },
    onSuccess: () => {
      toast.success('Gateway excluído com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao excluir gateway');
    },
  });

  const getHealthBadge = (status?: string) => {
    switch (status) {
      case 'healthy':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-500">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
            Saudável
          </span>
        );
      case 'degraded':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-500">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
            Degradado
          </span>
        );
      case 'down':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-500">
            <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
            Offline
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-gray-500/10 px-2 py-0.5 text-xs font-medium text-gray-500">
            <span className="h-1.5 w-1.5 rounded-full bg-gray-500" />
            Não verificado
          </span>
        );
    }
  };

    const methodLabel: Record<RotationMethod, string> = {
      pix: 'PIX (entrada)',
      credit_card: 'Cartão de Crédito',
      boleto: 'Boleto',
      pix_out: 'PIX OUT (saques)',
    };

    const supportsMethod = (acquirer: Acquirer, method: RotationMethod): boolean => {
      if (method === 'pix' || method === 'pix_out') {
        return acquirer.supports_pix;
      }

      if (method === 'credit_card') {
        return acquirer.supports_credit_card;
      }

      return acquirer.supports_boleto;
    };

    const updateMethodStrategy = (method: RotationMethod, strategy: RotationStrategy) => {
      setRoutingForm((prev) => {
        if (!prev) return prev;

        return {
          ...prev,
          methods: {
            ...prev.methods,
            [method]: {
              ...prev.methods[method],
              strategy,
            },
          },
        };
      });
    };

    const updateMethodWeight = (method: RotationMethod, acquirerKey: string, weight: number) => {
      setRoutingForm((prev) => {
        if (!prev) return prev;

        return {
          ...prev,
          methods: {
            ...prev.methods,
            [method]: {
              ...prev.methods[method],
              weights: {
                ...prev.methods[method].weights,
                [acquirerKey]: weight,
              },
            },
          },
        };
      });
    };

    const saveRoutingSettings = () => {
      if (!routingForm) {
        return;
      }

      saveRoutingSettingsMutation.mutate(routingForm);
    };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={CreditCardIcon}
        title="Gateways de Pagamento"
        subtitle="Gerencie e configure os adquirentes de pagamento"
        actions={
          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        }
      />

      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-5">
        <div className="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-base font-semibold text-tx-primary">Rotação Inteligente de Adquirentes</h2>
            <p className="mt-1 text-sm text-tx-muted">
              Defina estratégias por método, cooldown automático após falhas e visualize a ordem real de roteamento.
            </p>
          </div>
          <button
            onClick={saveRoutingSettings}
            disabled={!routingForm || saveRoutingSettingsMutation.isPending}
            className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saveRoutingSettingsMutation.isPending ? 'Salvando...' : 'Salvar Rotação'}
          </button>
        </div>

        {isRoutingLoading || !routingForm ? (
          <div className="rounded-xl border border-border-DEFAULT bg-surface-page px-4 py-6 text-sm text-tx-muted">
            Carregando configurações de rotação...
          </div>
        ) : (
          <>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
              <label className="flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary">
                <input
                  type="checkbox"
                  checked={routingForm.enabled}
                  onChange={(e) => setRoutingForm({ ...routingForm, enabled: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                Rotação ativa
              </label>

              <label className="flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary">
                <input
                  type="checkbox"
                  checked={routingForm.failover_enabled}
                  onChange={(e) => setRoutingForm({ ...routingForm, failover_enabled: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                Failover habilitado
              </label>

              <label className="flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary">
                <input
                  type="checkbox"
                  checked={routingForm.strict_health_check}
                  onChange={(e) => setRoutingForm({ ...routingForm, strict_health_check: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                Health check estrito
              </label>

              <label className="flex items-center gap-2 rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary">
                <input
                  type="checkbox"
                  checked={routingForm.use_cooldown}
                  onChange={(e) => setRoutingForm({ ...routingForm, use_cooldown: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                Cooldown automático
              </label>

              <label className="rounded-xl border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary">
                Cooldown (segundos)
                <input
                  type="number"
                  min={5}
                  max={3600}
                  value={routingForm.cooldown_seconds}
                  onChange={(e) =>
                    setRoutingForm({
                      ...routingForm,
                      cooldown_seconds: Math.min(3600, Math.max(5, Number(e.target.value) || 5)),
                    })
                  }
                  className="mt-1 w-full rounded-md border border-border-DEFAULT bg-surface-card px-2 py-1 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </label>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
              {rotationMethods.map((method) => {
                const methodConfig = routingForm.methods[method];
                const methodPreview = routingData?.preview?.[method];
                const methodAcquirers = acquirers.filter((acquirer) => supportsMethod(acquirer, method));

                return (
                  <div key={method} className="rounded-xl border border-border-DEFAULT bg-surface-page p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                      <h3 className="text-sm font-semibold text-tx-primary">{methodLabel[method]}</h3>
                      <select
                        value={methodConfig.strategy}
                        onChange={(e) => updateMethodStrategy(method, e.target.value as RotationStrategy)}
                        className="rounded-md border border-border-DEFAULT bg-surface-card px-2 py-1 text-xs text-tx-primary focus:border-green-500 focus:outline-none"
                      >
                        <option value="priority">Prioridade</option>
                        <option value="round_robin">Round Robin</option>
                        <option value="weighted">Peso (Weighted)</option>
                      </select>
                    </div>

                    {methodConfig.strategy === 'weighted' && (
                      <div className="mb-3 space-y-2">
                        {methodAcquirers.length === 0 ? (
                          <p className="text-xs text-tx-muted">Nenhum adquirente ativo para este método.</p>
                        ) : (
                          methodAcquirers.map((acquirer) => (
                            <div key={`${method}-${acquirer.id}`} className="flex items-center justify-between gap-3">
                              <span className="text-xs text-tx-secondary">{acquirer.display_name}</span>
                              <input
                                type="number"
                                min={0}
                                max={100}
                                value={methodConfig.weights[acquirer.slug] ?? 1}
                                onChange={(e) => updateMethodWeight(method, acquirer.slug, Math.max(0, Number(e.target.value) || 0))}
                                className="w-20 rounded-md border border-border-DEFAULT bg-surface-card px-2 py-1 text-xs text-tx-primary focus:border-green-500 focus:outline-none"
                              />
                            </div>
                          ))
                        )}
                      </div>
                    )}

                    <div className="rounded-lg border border-border-DEFAULT bg-surface-card p-3">
                      <p className="text-xs font-medium text-tx-secondary">Ordem atual de roteamento</p>
                      {methodPreview?.order?.length ? (
                        <div className="mt-2 flex flex-wrap gap-2">
                          {methodPreview.order.map((key, index) => (
                            <span
                              key={`${method}-order-${key}-${index}`}
                              className={`rounded-md px-2 py-1 text-xs ${
                                index === 0
                                  ? 'bg-emerald-500/15 text-emerald-500'
                                  : 'bg-surface-page text-tx-muted'
                              }`}
                            >
                              {index + 1}. {key}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <p className="mt-2 text-xs text-tx-muted">Sem adquirentes elegíveis para este método.</p>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </>
        )}
      </section>

      {/* Acquirers Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
        </div>
      ) : acquirers.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 rounded-2xl bg-surface-card">
          <CreditCardIcon className="h-12 w-12 text-tx-muted" />
          <p className="mt-4 text-sm text-tx-muted">Nenhum gateway encontrado</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {acquirers.map((acquirer) => (
            <div
              key={acquirer.id}
              className={`relative rounded-2xl bg-surface-card p-6 shadow-sm transition-all ${
                acquirer.is_primary ? 'ring-2 ring-green-500' : ''
              }`}
            >
              {acquirer.is_primary && (
                <div className="absolute -top-2 -right-2 rounded-full bg-green-500 p-1">
                  <ShieldCheckIcon className="h-4 w-4 text-white" />
                </div>
              )}

              {/* Header */}
              <div className="flex items-start justify-between mb-4">
                <div className="flex items-center gap-3">
                  {acquirer.logo_url ? (
                    <img src={acquirer.logo_url} alt={acquirer.name} className="h-10 w-10 rounded-lg object-contain" />
                  ) : (
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-500/10">
                      <CreditCardIcon className="h-5 w-5 text-green-500" />
                    </div>
                  )}
                  <div>
                    <h3 className="font-semibold text-tx-primary">{acquirer.display_name}</h3>
                    <p className="text-xs text-tx-muted">{acquirer.slug}</p>
                  </div>
                </div>
                <button
                  onClick={() => toggleStatusMutation.mutate({ id: acquirer.id, is_active: !acquirer.is_active })}
                  className={`relative h-6 w-11 rounded-full transition-colors ${
                    acquirer.is_active ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'
                  }`}
                >
                  <span
                    className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white transition-transform ${
                      acquirer.is_active ? 'translate-x-5' : 'translate-x-0'
                    }`}
                  />
                </button>
              </div>

              {/* Status & Health */}
              <div className="flex items-center gap-2 mb-4">
                {getHealthBadge(acquirer.health_status)}
                {acquirer.sandbox_mode && (
                  <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-500">
                    Sandbox
                  </span>
                )}
              </div>

              {/* Supported Methods */}
              <div className="flex flex-wrap gap-2 mb-4">
                {acquirer.supports_pix && (
                  <span className="rounded bg-emerald-500/10 px-2 py-1 text-xs text-emerald-500">PIX</span>
                )}
                {acquirer.supports_credit_card && (
                  <span className="rounded bg-blue-500/10 px-2 py-1 text-xs text-blue-500">Cartão</span>
                )}
                {acquirer.supports_boleto && (
                  <span className="rounded bg-green-500/10 px-2 py-1 text-xs text-green-500">Boleto</span>
                )}
              </div>

              {/* Fees */}
              <div className="grid grid-cols-2 gap-2 mb-4">
                <div className="rounded-lg bg-surface-page p-2 text-center">
                  <p className="text-xs text-tx-muted">Taxa Adquirente</p>
                  <p className="text-sm font-medium text-tx-primary">
                    {acquirer.acquirer_fee_percentage ? `${acquirer.acquirer_fee_percentage}%` : '-'}
                    {acquirer.acquirer_fee_fixed ? ` + R$${acquirer.acquirer_fee_fixed}` : ''}
                  </p>
                </div>
                <div className="rounded-lg bg-surface-page p-2 text-center">
                  <p className="text-xs text-tx-muted">Taxa Plataforma</p>
                  <p className="text-sm font-medium text-green-500">
                    {acquirer.gateway_fee_percentage ? `${acquirer.gateway_fee_percentage}%` : '-'}
                    {acquirer.gateway_fee_fixed ? ` + R$${acquirer.gateway_fee_fixed}` : ''}
                  </p>
                </div>
              </div>

              {/* Priority */}
              <div className="flex items-center gap-2 mb-4 text-sm text-tx-muted">
                <ArrowsUpDownIcon className="h-4 w-4" />
                Prioridade: {acquirer.priority}
              </div>

              {/* Actions */}
              <div className="flex gap-2">
                <button
                  onClick={() => healthCheckMutation.mutate(acquirer.id)}
                  disabled={healthCheckMutation.isPending}
                  className="flex-1 rounded-lg bg-surface-hover px-3 py-2 text-xs font-medium text-tx-secondary hover:bg-border-DEFAULT transition-colors"
                >
                  Health Check
                </button>
                <button
                  onClick={() => {
                    setSelectedAcquirer(acquirer);
                    setShowEditModal(true);
                  }}
                  className="flex-1 rounded-lg bg-green-500/10 px-3 py-2 text-xs font-medium text-green-500 hover:bg-green-500/20 transition-colors"
                >
                  <PencilSquareIcon className="inline h-4 w-4 mr-1" />
                  Editar
                </button>
                {!acquirer.is_primary && acquirer.is_active && (
                  <button
                    onClick={() => setPrimaryMutation.mutate(acquirer.id)}
                    disabled={setPrimaryMutation.isPending}
                    className="rounded-lg bg-emerald-500/10 px-3 py-2 text-xs font-medium text-emerald-500 hover:bg-emerald-500/20 transition-colors"
                  >
                    Definir Primário
                  </button>
                )}
                {!acquirer.is_primary && (
                  <button
                    onClick={() => {
                      if (window.confirm(`Tem certeza que deseja excluir o gateway "${acquirer.display_name}"? Ele não será mais usado para pagamentos.`)) {
                        deleteMutation.mutate(acquirer.id);
                      }
                    }}
                    disabled={deleteMutation.isPending}
                    className="rounded-lg bg-red-500/10 px-3 py-2 text-xs font-medium text-red-500 hover:bg-red-500/20 transition-colors"
                    title="Excluir gateway"
                  >
                    <TrashIcon className="inline h-4 w-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Edit Modal */}
      {showEditModal && selectedAcquirer && (
        <EditAcquirerModal
          acquirer={selectedAcquirer}
          onClose={() => {
            setShowEditModal(false);
            setSelectedAcquirer(null);
          }}
          onSave={(data) => updateMutation.mutate({ id: selectedAcquirer.id, ...data })}
          isSaving={updateMutation.isPending}
        />
      )}
    </AdminPageShell>
  );
}

function EditAcquirerModal({
  acquirer,
  onClose,
  onSave,
  isSaving,
}: {
  acquirer: Acquirer;
  onClose: () => void;
  onSave: (data: Partial<Acquirer>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState({
    display_name: acquirer.display_name,
    priority: acquirer.priority,
    acquirer_fee_percentage: acquirer.acquirer_fee_percentage || 0,
    acquirer_fee_fixed: acquirer.acquirer_fee_fixed || 0,
    gateway_fee_percentage: acquirer.gateway_fee_percentage || 0,
    gateway_fee_fixed: acquirer.gateway_fee_fixed || 0,
    sandbox_mode: acquirer.sandbox_mode,
    supports_pix: acquirer.supports_pix,
    supports_credit_card: acquirer.supports_credit_card,
    supports_boleto: acquirer.supports_boleto,
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
      <div className="w-full max-w-2xl rounded-2xl bg-surface-card p-6 shadow-xl my-8">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-bold text-tx-primary">Editar {acquirer.display_name}</h2>
          <button onClick={onClose} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-6 max-h-[calc(100vh-200px)] overflow-y-auto pr-2">
          {/* Basic Info */}
          <div>
            <h3 className="text-sm font-semibold text-tx-secondary mb-3">Informações Básicas</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-tx-muted mb-1">Nome de Exibição</label>
                <input
                  type="text"
                  value={form.display_name}
                  onChange={(e) => setForm({ ...form, display_name: e.target.value })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Prioridade</label>
                <input
                  type="number"
                  value={form.priority}
                  onChange={(e) => setForm({ ...form, priority: parseInt(e.target.value) })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Acquirer Fees */}
          <div>
            <h3 className="text-sm font-semibold text-tx-secondary mb-1">Taxa do Adquirente</h3>
            <p className="text-xs text-tx-muted mb-3">Custo que o adquirente cobra por transação</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-tx-muted mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_percentage}
                  onChange={(e) => setForm({ ...form, acquirer_fee_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_fixed}
                  onChange={(e) => setForm({ ...form, acquirer_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Gateway/Platform Fees */}
          <div>
            <h3 className="text-sm font-semibold text-tx-secondary mb-1">Taxa da Plataforma (GatePro)</h3>
            <p className="text-xs text-tx-muted mb-3">Taxa que cobramos do vendedor por transação</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-tx-muted mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_percentage}
                  onChange={(e) => setForm({ ...form, gateway_fee_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_fixed}
                  onChange={(e) => setForm({ ...form, gateway_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Supported Methods */}
          <div>
            <h3 className="text-sm font-semibold text-tx-secondary mb-3">Métodos Suportados</h3>
            <div className="flex flex-wrap gap-4">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_pix}
                  onChange={(e) => setForm({ ...form, supports_pix: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                <span className="text-sm text-tx-primary">PIX</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_credit_card}
                  onChange={(e) => setForm({ ...form, supports_credit_card: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                <span className="text-sm text-tx-primary">Cartão de Crédito</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_boleto}
                  onChange={(e) => setForm({ ...form, supports_boleto: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
                />
                <span className="text-sm text-tx-primary">Boleto</span>
              </label>
            </div>
          </div>

          {/* Sandbox Mode */}
          <div>
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                checked={form.sandbox_mode}
                onChange={(e) => setForm({ ...form, sandbox_mode: e.target.checked })}
                className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
              />
              <span className="text-sm text-tx-primary">Modo Sandbox (Teste)</span>
            </label>
          </div>
        </div>

        <div className="flex gap-3 pt-6 mt-4 border-t border-border-DEFAULT">
          <button
            onClick={onClose}
            className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
          >
            Cancelar
          </button>
          <button
            onClick={() => onSave(form)}
            disabled={isSaving}
            className="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
          >
            {isSaving ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}


