import { useState } from 'react';
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

export default function AdminAcquirers() {
  const queryClient = useQueryClient();
  const [selectedAcquirer, setSelectedAcquirer] = useState<Acquirer | null>(null);
  const [showEditModal, setShowEditModal] = useState(false);

  // Fetch acquirers
  const { data: acquirers = [], isLoading, refetch } = useQuery<Acquirer[]>({
    queryKey: ['admin-acquirers'],
    queryFn: async () => {
      const response = await api.get('/admin/acquirers');
      return response.data?.data || [];
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

      {/* Acquirers Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <div className="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
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
                acquirer.is_primary ? 'ring-2 ring-emerald-500' : ''
              }`}
            >
              {acquirer.is_primary && (
                <div className="absolute -top-2 -right-2 rounded-full bg-emerald-500 p-1">
                  <ShieldCheckIcon className="h-4 w-4 text-white" />
                </div>
              )}

              {/* Header */}
              <div className="flex items-start justify-between mb-4">
                <div className="flex items-center gap-3">
                  {acquirer.logo_url ? (
                    <img src={acquirer.logo_url} alt={acquirer.name} className="h-10 w-10 rounded-lg object-contain" />
                  ) : (
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10">
                      <CreditCardIcon className="h-5 w-5 text-emerald-500" />
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
                  <span className="rounded bg-emerald-500/10 px-2 py-1 text-xs text-emerald-500">Boleto</span>
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
                  <p className="text-sm font-medium text-emerald-500">
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
                  className="flex-1 rounded-lg bg-emerald-500/10 px-3 py-2 text-xs font-medium text-emerald-500 hover:bg-emerald-500/20 transition-colors"
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
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Prioridade</label>
                <input
                  type="number"
                  value={form.priority}
                  onChange={(e) => setForm({ ...form, priority: parseInt(e.target.value) })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
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
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.acquirer_fee_fixed}
                  onChange={(e) => setForm({ ...form, acquirer_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                />
              </div>
            </div>
          </div>

          {/* Gateway/Platform Fees */}
          <div>
            <h3 className="text-sm font-semibold text-tx-secondary mb-1">Taxa da Plataforma (Vector Pagamentos)</h3>
            <p className="text-xs text-tx-muted mb-3">Taxa que cobramos do vendedor por transação</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="block text-sm text-tx-muted mb-1">Percentual (%)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_percentage}
                  onChange={(e) => setForm({ ...form, gateway_fee_percentage: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                />
              </div>
              <div>
                <label className="block text-sm text-tx-muted mb-1">Fixa (R$)</label>
                <input
                  type="number"
                  step="0.01"
                  value={form.gateway_fee_fixed}
                  onChange={(e) => setForm({ ...form, gateway_fee_fixed: parseFloat(e.target.value) || 0 })}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
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
                  className="h-4 w-4 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
                />
                <span className="text-sm text-tx-primary">PIX</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_credit_card}
                  onChange={(e) => setForm({ ...form, supports_credit_card: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
                />
                <span className="text-sm text-tx-primary">Cartão de Crédito</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.supports_boleto}
                  onChange={(e) => setForm({ ...form, supports_boleto: e.target.checked })}
                  className="h-4 w-4 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
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
                className="h-4 w-4 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
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
            className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
          >
            {isSaving ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}
