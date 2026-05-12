import { type ComponentType, useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  CreditCardIcon,
  PencilSquareIcon,
  ArrowsUpDownIcon,
  XMarkIcon,
  ArrowPathIcon,
  ShieldCheckIcon,
  TrashIcon,
  BanknotesIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Acquirer {
  id: string;
  name: string;
  slug: string;
  display_name: string;
  nominal_name?: string;
  logo_url?: string;
  is_active: boolean;
  is_primary: boolean;
  priority: number;
  supports_pix: boolean;
  supports_pix_out: boolean;
  supports_credit_card: boolean;
  supports_boleto: boolean;
  acquirer_fee_percentage: number;
  acquirer_fee_fixed: number;
  acquirer_fee_pix_out_percentage: number;
  acquirer_fee_pix_out_fixed: number;
  gateway_fee_percentage: number;
  gateway_fee_fixed: number;
  gateway_fee_pix_out_percentage: number;
  gateway_fee_pix_out_fixed: number;
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

interface PlatformSettingsResponse {
  payments?: {
    default_gateway_fee?: number;
  };
}

const rotationMethods: RotationMethod[] = ['pix', 'credit_card', 'boleto', 'pix_out'];

export default function AdminAcquirers() {
  const queryClient = useQueryClient();
  const [selectedAcquirer, setSelectedAcquirer] = useState<Acquirer | null>(null);
  const [showEditModal, setShowEditModal] = useState(false);
  const [routingForm, setRoutingForm] = useState<RotationSettings | null>(null);
  const [globalGatewayFee, setGlobalGatewayFee] = useState('0');

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

  const { data: platformSettings } = useQuery<PlatformSettingsResponse>({
    queryKey: ['platform-settings-acquirer-fees'],
    queryFn: async () => {
      const response = await api.get('/admin/settings');
      return response.data?.data || {};
    },
  });

  useEffect(() => {
    if (routingData?.settings) {
      setRoutingForm(routingData.settings);
    }
  }, [routingData?.settings]);

  useEffect(() => {
    if (platformSettings?.payments?.default_gateway_fee !== undefined) {
      setGlobalGatewayFee(String(platformSettings.payments.default_gateway_fee));
    }
  }, [platformSettings?.payments?.default_gateway_fee]);

  const updateGlobalFeeMutation = useMutation({
    mutationFn: async (fee: number) => {
      await api.put('/admin/settings', { payments: { default_gateway_fee: fee } });
    },
    onSuccess: () => {
      toast.success('Taxa global atualizada');
      queryClient.invalidateQueries({ queryKey: ['platform-settings-acquirer-fees'] });
      queryClient.invalidateQueries({ queryKey: ['platform-settings'] });
    },
    onError: () => {
      toast.error('Erro ao salvar taxa global');
    },
  });

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

  // Refresh nominal from latest generated PIX
  const refreshNominalMutation = useMutation({
    mutationFn: async (id: string) => {
      const response = await api.post(`/admin/acquirers/${id}/refresh-nominal`);
      return response.data;
    },
    onSuccess: (response) => {
      toast.success(response?.message || 'Nominal atualizada');
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers'] });
      queryClient.invalidateQueries({ queryKey: ['admin-acquirers-routing'] });
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao atualizar nominal');
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
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
            Saudável
          </span>
        );
      case 'degraded':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
            Degradado
          </span>
        );
      case 'down':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">
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
      pix: 'Cashin PIX',
      credit_card: 'Cartão de crédito',
      boleto: 'Boleto',
      pix_out: 'Cashout PIX OUT',
    };

    const acquirerNameBySlug = acquirers.reduce<Record<string, string>>((acc, acquirer) => {
      acc[acquirer.slug] = acquirer.nominal_name || acquirer.display_name || acquirer.slug;
      return acc;
    }, {});

    const activeCount = acquirers.filter((acquirer) => acquirer.is_active).length;
    const cashinCount = acquirers.filter(
      (acquirer) => acquirer.is_active && (acquirer.supports_pix || acquirer.supports_credit_card || acquirer.supports_boleto)
    ).length;
    const cashoutCount = acquirers.filter((acquirer) => acquirer.is_active && acquirer.supports_pix_out).length;
    const primaryAcquirer = acquirers.find((acquirer) => acquirer.is_primary);

    const formatFee = (percent?: number, fixed?: number) => {
      const parts: string[] = [];
      if (Number(percent) > 0) {
        parts.push(`${Number(percent).toFixed(2)}%`);
      }
      if (Number(fixed) > 0) {
        parts.push(`R$ ${Number(fixed).toFixed(2).replace('.', ',')}`);
      }
      return parts.length ? parts.join(' + ') : 'Sem taxa';
    };

    const supportsMethod = (acquirer: Acquirer, method: RotationMethod): boolean => {
      if (method === 'pix') {
        return acquirer.supports_pix;
      }

      if (method === 'pix_out') {
        return acquirer.supports_pix_out;
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
        
        title="Adquirentes"
        subtitle="Configure cashin, cashout, roteamento e taxas por adquirente"
        actions={
          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-900 transition-colors hover:bg-gray-100"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        }
      />

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard label="Ativos" value={`${activeCount}/${acquirers.length}`} helper="adquirentes habilitados"  />
        <SummaryCard label="Cashin" value={cashinCount.toString()} helper="PIX, cartão ou boleto"  />
        <SummaryCard label="Cashout" value={cashoutCount.toString()} helper="PIX OUT habilitado"  />
        <SummaryCard
          label="Primário"
          value={primaryAcquirer?.nominal_name || primaryAcquirer?.display_name || '-'}
          helper={primaryAcquirer ? `prioridade ${primaryAcquirer.priority}` : 'nenhum definido'}
          
        />
      </section>

      <section className="rounded-md border border-gray-200 bg-white p-4">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div className="min-w-0">
            <h2 className="text-base font-semibold text-gray-900">Taxa global padrão</h2>
            <p className="mt-1 text-sm text-gray-500">
              Usada como taxa padrão do gateway quando uma regra específica não sobrescreve o valor.
            </p>
          </div>
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <label className="relative w-full sm:w-44">
              <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-gray-500">%</span>
              <input
                type="number"
                step="0.01"
                min={0}
                max={100}
                value={globalGatewayFee}
                onChange={(event) => setGlobalGatewayFee(event.target.value)}
                className="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 pr-8 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
              />
            </label>
            <button
              type="button"
              onClick={() => updateGlobalFeeMutation.mutate(Number(globalGatewayFee) || 0)}
              disabled={updateGlobalFeeMutation.isPending}
              className="rounded-lg bg-accent-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
            >
              {updateGlobalFeeMutation.isPending ? 'Salvando...' : 'Salvar taxa global'}
            </button>
          </div>
        </div>
      </section>

      <section className="rounded-lg border border-gray-200 bg-white p-5">
        <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <h2 className="text-base font-semibold text-gray-900">Roteamento de adquirentes</h2>
            <p className="mt-1 text-sm text-gray-500">
              Separe as regras de cashin e cashout, defina prioridade ou peso e confira a ordem real antes de salvar.
            </p>
          </div>
          <button
            onClick={saveRoutingSettings}
            disabled={!routingForm || saveRoutingSettingsMutation.isPending}
            className="rounded-lg bg-accent-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
          >
            {saveRoutingSettingsMutation.isPending ? 'Salvando...' : 'Salvar roteamento'}
          </button>
        </div>

        {isRoutingLoading || !routingForm ? (
          <div className="rounded-md border border-gray-100 bg-gray-50 px-4 py-6 text-sm text-gray-500">
            Carregando configurações de rotação...
          </div>
        ) : (
          <>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
              <label className="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-900">
                <input
                  type="checkbox"
                  checked={routingForm.enabled}
                  onChange={(e) => setRoutingForm({ ...routingForm, enabled: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                Roteamento ativo
              </label>

              <label className="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-900">
                <input
                  type="checkbox"
                  checked={routingForm.failover_enabled}
                  onChange={(e) => setRoutingForm({ ...routingForm, failover_enabled: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                Failover habilitado
              </label>

              <label className="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-900">
                <input
                  type="checkbox"
                  checked={routingForm.strict_health_check}
                  onChange={(e) => setRoutingForm({ ...routingForm, strict_health_check: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                Health check estrito
              </label>

              <label className="flex items-center gap-2 rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-900">
                <input
                  type="checkbox"
                  checked={routingForm.use_cooldown}
                  onChange={(e) => setRoutingForm({ ...routingForm, use_cooldown: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                Cooldown automático
              </label>

              <label className="rounded-md border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-900">
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
                  className="mt-1 w-full rounded-md border border-gray-200 bg-white px-2 py-1 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </label>
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
              {rotationMethods.map((method) => {
                const methodConfig = routingForm.methods[method];
                const methodPreview = routingData?.preview?.[method];
                const methodAcquirers = acquirers.filter((acquirer) => supportsMethod(acquirer, method));

                return (
                  <div key={method} className="rounded-md border border-gray-100 bg-gray-50 p-4">
                    <div className="mb-3 flex items-center justify-between gap-3">
                      <h3 className="text-sm font-semibold text-gray-900">{methodLabel[method]}</h3>
                      <select
                        value={methodConfig.strategy}
                        onChange={(e) => updateMethodStrategy(method, e.target.value as RotationStrategy)}
                        className="rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
                      >
                        <option value="priority">Prioridade</option>
                        <option value="round_robin">Round Robin</option>
                        <option value="weighted">Peso (Weighted)</option>
                      </select>
                    </div>

                    {methodConfig.strategy === 'weighted' && (
                      <div className="mb-3 space-y-2">
                        {methodAcquirers.length === 0 ? (
                          <p className="text-xs text-gray-500">Nenhum adquirente ativo para este método.</p>
                        ) : (
                          methodAcquirers.map((acquirer) => (
                            <div key={`${method}-${acquirer.id}`} className="flex items-center justify-between gap-3">
                              <span className="text-xs text-gray-600">{acquirer.display_name}</span>
                              <input
                                type="number"
                                min={0}
                                max={100}
                                value={methodConfig.weights[acquirer.slug] ?? 1}
                                onChange={(e) => updateMethodWeight(method, acquirer.slug, Math.max(0, Number(e.target.value) || 0))}
                                className="w-20 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 focus:border-gray-400 focus:outline-none"
                              />
                            </div>
                          ))
                        )}
                      </div>
                    )}

                    <div className="rounded-lg border border-gray-200 bg-white p-3">
                      <p className="text-xs font-medium text-gray-600">Ordem atual de roteamento</p>
                      {methodPreview?.order?.length ? (
                        <div className="mt-2 flex flex-wrap gap-2">
                          {methodPreview.order.map((key, index) => (
                            <span
                              key={`${method}-order-${key}-${index}`}
                              className={`rounded-md px-2 py-1 text-xs ${
                                index === 0
                                  ? 'bg-emerald-500/15 text-emerald-700'
                                  : 'bg-gray-50 text-gray-500'
                              }`}
                            >
                              {index + 1}. {acquirerNameBySlug[key] || key}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <p className="mt-2 text-xs text-gray-500">Sem adquirentes elegíveis para este método.</p>
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
          <div className="h-10 w-10 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
        </div>
      ) : acquirers.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20 rounded-lg bg-white">
          <CreditCardIcon className="h-12 w-12 text-gray-500" />
          <p className="mt-4 text-sm text-gray-500">Nenhum gateway encontrado</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {acquirers.map((acquirer) => (
            <div
              key={acquirer.id}
              className={`relative rounded-md border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-100/40 ${
                acquirer.is_primary ? 'ring-1 ring-green-500' : ''
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
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-50">
                      <CreditCardIcon className="h-5 w-5 text-green-700" />
                    </div>
                  )}
                  <div>
                    <h3 className="font-semibold text-gray-900">{acquirer.display_name}</h3>
                    <p className="text-xs text-gray-500">{acquirer.slug}</p>
                    {acquirer.nominal_name && (
                      <p className="text-xs text-green-700 font-medium">Nominal: {acquirer.nominal_name}</p>
                    )}
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
                  <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                    Sandbox
                  </span>
                )}
              </div>

              {/* Supported Methods */}
              <div className="flex flex-wrap gap-2 mb-4">
                {acquirer.supports_pix && (
                  <span className="rounded bg-emerald-50 px-2 py-1 text-xs text-emerald-700">PIX</span>
                )}
                {acquirer.supports_pix_out && (
                  <span className="rounded bg-cyan-500/10 px-2 py-1 text-xs text-cyan-500">PIX OUT</span>
                )}
                {acquirer.supports_credit_card && (
                  <span className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-700">Cartão</span>
                )}
                {acquirer.supports_boleto && (
                  <span className="rounded bg-green-50 px-2 py-1 text-xs text-green-700">Boleto</span>
                )}
              </div>

              {/* Fees */}
              <div className="grid grid-cols-2 gap-2 mb-4">
                <div className="rounded-lg bg-gray-50 p-2 text-center">
                  <p className="text-xs text-gray-500">Taxa Cashin</p>
                  <p className="text-sm font-medium text-gray-900">
                    {formatFee(acquirer.acquirer_fee_percentage, acquirer.acquirer_fee_fixed)}
                  </p>
                </div>
                <div className="rounded-lg bg-gray-50 p-2 text-center">
                  <p className="text-xs text-gray-500">Taxa Cashout</p>
                  <p className="text-sm font-medium text-gray-900">
                    {formatFee(acquirer.acquirer_fee_pix_out_percentage, acquirer.acquirer_fee_pix_out_fixed)}
                  </p>
                </div>
                <div className="rounded-lg bg-gray-50 p-2 text-center">
                  <p className="text-xs text-gray-500">Global Cashin</p>
                  <p className="text-sm font-medium text-green-700">
                    {formatFee(acquirer.gateway_fee_percentage, acquirer.gateway_fee_fixed)}
                  </p>
                </div>
                <div className="rounded-lg bg-gray-50 p-2 text-center">
                  <p className="text-xs text-gray-500">Global Cashout</p>
                  <p className="text-sm font-medium text-green-700">
                    {formatFee(acquirer.gateway_fee_pix_out_percentage, acquirer.gateway_fee_pix_out_fixed)}
                  </p>
                </div>
              </div>

              {/* Priority */}
              <div className="flex items-center gap-2 mb-4 text-sm text-gray-500">
                <ArrowsUpDownIcon className="h-4 w-4" />
                Prioridade: {acquirer.priority}
              </div>

              {/* Actions */}
              <div className="flex flex-wrap gap-2">
                <button
                  onClick={() => healthCheckMutation.mutate(acquirer.id)}
                  disabled={healthCheckMutation.isPending}
                  className="flex-1 rounded-lg bg-gray-100 px-3 py-2 text-xs font-medium text-gray-600 hover:bg-border-DEFAULT transition-colors"
                >
                  Health Check
                </button>
                <button
                  onClick={() => refreshNominalMutation.mutate(acquirer.id)}
                  disabled={refreshNominalMutation.isPending}
                  className="flex-1 rounded-lg bg-sky-500/10 px-3 py-2 text-xs font-medium text-sky-500 hover:bg-sky-500/20 transition-colors"
                  title="Atualizar nominal pelo ultimo PIX gerado"
                >
                  <ArrowPathIcon className="inline h-4 w-4 mr-1" />
                  Nominal
                </button>
                <button
                  onClick={() => {
                    setSelectedAcquirer(acquirer);
                    setShowEditModal(true);
                  }}
                  className="flex-1 rounded-lg bg-green-50 px-3 py-2 text-xs font-medium text-green-700 hover:bg-green-500/20 transition-colors"
                >
                  <PencilSquareIcon className="inline h-4 w-4 mr-1" />
                  Editar
                </button>
                {!acquirer.is_primary && acquirer.is_active && (
                  <button
                    onClick={() => setPrimaryMutation.mutate(acquirer.id)}
                    disabled={setPrimaryMutation.isPending}
                    className="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-500/20 transition-colors"
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
                    className="rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-500/20 transition-colors"
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

function SummaryCard({
  label,
  value,
  helper,
  icon: Icon,
}: {
  label: string;
  value: string;
  helper: string;
  icon: ComponentType<{ className?: string }>;
}) {
  return (
    <div className="rounded-md border border-gray-200 bg-white p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
          <p className="mt-1 truncate text-xl font-semibold text-gray-900">{value}</p>
          <p className="mt-1 text-xs text-gray-500">{helper}</p>
        </div>
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-2">
          <Icon className="h-5 w-5 text-gray-600" />
        </div>
      </div>
    </div>
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
    acquirer_fee_pix_out_percentage: acquirer.acquirer_fee_pix_out_percentage || 0,
    acquirer_fee_pix_out_fixed: acquirer.acquirer_fee_pix_out_fixed || 0,
    gateway_fee_percentage: acquirer.gateway_fee_percentage || 0,
    gateway_fee_fixed: acquirer.gateway_fee_fixed || 0,
    gateway_fee_pix_out_percentage: acquirer.gateway_fee_pix_out_percentage || 0,
    gateway_fee_pix_out_fixed: acquirer.gateway_fee_pix_out_fixed || 0,
    sandbox_mode: acquirer.sandbox_mode,
    supports_pix: acquirer.supports_pix,
    supports_pix_out: acquirer.supports_pix_out,
    supports_credit_card: acquirer.supports_credit_card,
    supports_boleto: acquirer.supports_boleto,
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
      <div className="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl my-8">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-bold text-gray-900">Editar {acquirer.display_name}</h2>
          <button onClick={onClose} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-6 max-h-[calc(100vh-200px)] overflow-y-auto pr-2">
          {/* Basic Info */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-3">Informações Básicas</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-gray-500 mb-1">Nome de Exibição</label>
                <input
                  type="text"
                  value={form.display_name}
                  onChange={(e) => setForm({ ...form, display_name: e.target.value })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Nominal detectada</label>
                <input
                  type="text"
                  value={acquirer.nominal_name || ''}
                  readOnly
                  placeholder="Detectada automaticamente pelo QR Code"
                  className="w-full cursor-not-allowed rounded-lg border border-gray-200 bg-gray-50/70 px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-500 focus:outline-none"
                />
                <p className="mt-1 text-xs text-gray-500">Esse nome vem da nominal real do PIX gerado e não pode ser alterado manualmente.</p>
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Prioridade</label>
                <input
                  type="number"
                  value={form.priority}
                  onChange={(e) => setForm({ ...form, priority: parseInt(e.target.value) })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Acquirer Fees - Cashin */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-1">Taxa do Adquirente (Cashin)</h3>
            <p className="text-xs text-gray-500 mb-3">Custo que o adquirente cobra por transação de entrada</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-gray-500 mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_percentage}
                  onChange={(e) => setForm({ ...form, acquirer_fee_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_fixed}
                  onChange={(e) => setForm({ ...form, acquirer_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Acquirer Fees - Cashout */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-1">Taxa do Adquirente (Cashout / PIX OUT)</h3>
            <p className="text-xs text-gray-500 mb-3">Custo que o adquirente cobra por saque/transferência</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-gray-500 mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_pix_out_percentage}
                  onChange={(e) => setForm({ ...form, acquirer_fee_pix_out_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_pix_out_fixed}
                  onChange={(e) => setForm({ ...form, acquirer_fee_pix_out_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Gateway/Platform Fees - Cashin */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-1">Taxa da Plataforma - Cashin (Vector Pagamentos)</h3>
            <p className="text-xs text-gray-500 mb-3">Taxa que cobramos do vendedor por transação de entrada</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-gray-500 mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_percentage}
                  onChange={(e) => setForm({ ...form, gateway_fee_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_fixed}
                  onChange={(e) => setForm({ ...form, gateway_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Gateway/Platform Fees - Cashout */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-1">Taxa da Plataforma - Cashout (Vector Pagamentos)</h3>
            <p className="text-xs text-gray-500 mb-3">Taxa que cobramos do vendedor por saque/transferência</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-gray-500 mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_pix_out_percentage}
                  onChange={(e) => setForm({ ...form, gateway_fee_pix_out_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-gray-500 mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_pix_out_fixed}
                  onChange={(e) => setForm({ ...form, gateway_fee_pix_out_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Supported Methods */}
          <div>
            <h3 className="text-sm font-semibold text-gray-600 mb-3">Métodos Suportados</h3>
            <div className="flex flex-wrap gap-4">
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_pix}
                  onChange={(e) => setForm({ ...form, supports_pix: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                <span className="text-sm text-gray-900">PIX</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_pix_out}
                  onChange={(e) => setForm({ ...form, supports_pix_out: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                <span className="text-sm text-gray-900">PIX OUT (Saque)</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_credit_card}
                  onChange={(e) => setForm({ ...form, supports_credit_card: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                <span className="text-sm text-gray-900">Cartão de Crédito</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_boleto}
                  onChange={(e) => setForm({ ...form, supports_boleto: e.target.checked })}
                  className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
                />
                <span className="text-sm text-gray-900">Boleto</span>
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
                className="h-4 w-4 rounded border-gray-200 text-green-700 focus:ring-green-500"
              />
              <span className="text-sm text-gray-900">Modo Sandbox (Teste)</span>
            </label>
          </div>
        </div>

        <div className="flex gap-3 pt-6 mt-4 border-t border-gray-200">
          <button
            onClick={onClose}
            className="flex-1 rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-medium text-gray-900 hover:bg-border-DEFAULT transition-colors"
          >
            Cancelar
          </button>
          <button
            onClick={() => onSave(form)}
            disabled={isSaving}
            className="flex-1 rounded-md bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50 transition-colors"
          >
            {isSaving ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}


