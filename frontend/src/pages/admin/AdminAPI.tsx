import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  KeyIcon,
  PlusIcon,
  XMarkIcon,
  ClipboardDocumentIcon,
  GlobeAltIcon,
  ArrowPathIcon,
  TrashIcon,
  ShieldCheckIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface ApiKey {
  id: string;
  name: string;
  key?: string;
  key_prefix?: string;
  type: 'live' | 'test' | 'admin';
  permissions?: string[];
  last_used_at?: string;
  created_at: string;
  expires_at?: string;
}

interface Webhook {
  id: string;
  url: string;
  events: string[];
  active?: boolean;
  is_active?: boolean;
  secret?: string;
  last_triggered_at?: string;
  created_at: string;
}

export default function AdminAPI() {
  const queryClient = useQueryClient();
  const [showKeyModal, setShowKeyModal] = useState(false);
  const [showWebhookModal, setShowWebhookModal] = useState(false);
  const [newKey, setNewKey] = useState<string | null>(null);

  // Fetch API Keys
  const { data: apiKeys = [], isLoading: loadingKeys } = useQuery<ApiKey[]>({
    queryKey: ['admin-api-keys'],
    queryFn: async () => {
      const response = await api.get('/admin/api-keys');
      return response.data?.data || [];
    },
  });

  // Fetch Webhooks
  const { data: webhooks = [], isLoading: loadingWebhooks } = useQuery<Webhook[]>({
    queryKey: ['admin-webhooks'],
    queryFn: async () => {
      const response = await api.get('/admin/webhooks');
      return response.data?.data || [];
    },
  });

  // Create API Key
  const createKeyMutation = useMutation({
    mutationFn: async (data: { name: string; type: 'live' | 'test' }) => {
      const response = await api.post('/admin/api-keys', data);
      return response.data?.data?.key;
    },
    onSuccess: (key) => {
      setNewKey(key);
      queryClient.invalidateQueries({ queryKey: ['admin-api-keys'] });
    },
    onError: () => toast.error('Erro ao criar chave'),
  });

  // Revoke API Key
  const revokeKeyMutation = useMutation({
    mutationFn: async (keyId: string) => {
      await api.delete(`/admin/api-keys/${keyId}`);
    },
    onSuccess: () => {
      toast.success('Chave revogada');
      queryClient.invalidateQueries({ queryKey: ['admin-api-keys'] });
    },
    onError: () => toast.error('Erro ao revogar chave'),
  });

  // Create Webhook
  const createWebhookMutation = useMutation({
    mutationFn: async (data: { url: string; events: string[] }) => {
      const response = await api.post('/admin/webhooks', data);
      return response.data?.data;
    },
    onSuccess: () => {
      toast.success('Webhook criado');
      setShowWebhookModal(false);
      queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] });
    },
    onError: () => toast.error('Erro ao criar webhook'),
  });

  // Delete Webhook
  const deleteWebhookMutation = useMutation({
    mutationFn: async (webhookId: string) => {
      await api.delete(`/admin/webhooks/${webhookId}`);
    },
    onSuccess: () => {
      toast.success('Webhook removido');
      queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] });
    },
    onError: () => toast.error('Erro ao remover webhook'),
  });

  // Regenerate Webhook Secret
  const regenerateSecretMutation = useMutation({
    mutationFn: async (webhookId: string) => {
      const response = await api.post(`/admin/webhooks/${webhookId}/regenerate-secret`);
      return response.data?.data?.secret;
    },
    onSuccess: (secret) => {
      toast.success('Secret regenerado');
      queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] });
    },
    onError: () => toast.error('Erro ao regenerar secret'),
  });

  const isWebhookActive = (webhook: Webhook) => webhook.active ?? webhook.is_active ?? false;

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={KeyIcon}
        title="API & Webhooks"
        subtitle="Gerencie chaves de API e configuracoes de webhooks da plataforma"
      />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* API Keys Section */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center justify-between mb-6">
            <div className="flex items-center gap-3">
              <div className="rounded-xl bg-emerald-500/10 p-2.5">
                <KeyIcon className="h-6 w-6 text-emerald-500" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-tx-primary">Chaves de API</h2>
                <p className="text-sm text-tx-muted">{apiKeys.length} chave(s) ativa(s)</p>
              </div>
            </div>
            <button
              onClick={() => setShowKeyModal(true)}
              className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 transition-colors"
            >
              <PlusIcon className="h-4 w-4" />
              Nova Chave
            </button>
          </div>

          {loadingKeys ? (
            <div className="flex items-center justify-center py-8">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
            </div>
          ) : apiKeys.length === 0 ? (
            <div className="text-center py-8">
              <KeyIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
              <p className="text-sm text-tx-muted">Nenhuma chave de API criada</p>
            </div>
          ) : (
            <div className="space-y-3">
              {apiKeys.map((key) => (
                <div key={key.id} className="flex items-center justify-between p-4 rounded-xl bg-surface-page">
                  <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-lg ${key.type === 'test' ? 'bg-amber-500/10' : 'bg-emerald-500/10'}`}>
                      <KeyIcon className={`h-5 w-5 ${key.type === 'test' ? 'text-amber-500' : 'text-emerald-500'}`} />
                    </div>
                    <div>
                      <p className="text-sm font-medium text-tx-primary">{key.name}</p>
                      <p className="text-xs font-mono text-tx-muted">{key.key_prefix || key.key?.substring(0, 12)}...</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                      key.type === 'test' ? 'bg-amber-500/10 text-amber-500' : 'bg-emerald-500/10 text-emerald-500'
                    }`}>
                      {key.type === 'test' ? 'Teste' : 'Produção'}
                    </span>
                    <button
                      onClick={() => revokeKeyMutation.mutate(key.id)}
                      disabled={revokeKeyMutation.isPending}
                      className="p-2 rounded-lg text-red-500 hover:bg-red-500/10 transition-colors"
                      title="Revogar chave"
                    >
                      <TrashIcon className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Webhooks Section */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center justify-between mb-6">
            <div className="flex items-center gap-3">
              <div className="rounded-xl bg-blue-500/10 p-2.5">
                <GlobeAltIcon className="h-6 w-6 text-blue-500" />
              </div>
              <div>
                <h2 className="text-lg font-semibold text-tx-primary">Webhooks Globais</h2>
                <p className="text-sm text-tx-muted">{webhooks.length} webhook(s) configurado(s)</p>
              </div>
            </div>
            <button
              onClick={() => setShowWebhookModal(true)}
              className="flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
            >
              <PlusIcon className="h-4 w-4" />
              Novo Webhook
            </button>
          </div>

          {loadingWebhooks ? (
            <div className="flex items-center justify-center py-8">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
            </div>
          ) : webhooks.length === 0 ? (
            <div className="text-center py-8">
              <GlobeAltIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
              <p className="text-sm text-tx-muted">Nenhum webhook configurado</p>
            </div>
          ) : (
            <div className="space-y-3">
              {webhooks.map((webhook) => {
                const isActive = isWebhookActive(webhook);
                return (
                <div key={webhook.id} className="p-4 rounded-xl bg-surface-page">
                  <div className="flex items-center justify-between mb-2">
                    <p className="text-sm font-mono text-tx-primary truncate max-w-[200px]">{webhook.url}</p>
                    <div className="flex items-center gap-2">
                      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                        isActive ? 'bg-emerald-500/10 text-emerald-500' : 'bg-gray-500/10 text-gray-500'
                      }`}>
                        {isActive ? 'Ativo' : 'Inativo'}
                      </span>
                      <button
                        onClick={() => regenerateSecretMutation.mutate(webhook.id)}
                        className="p-1.5 rounded-lg text-tx-muted hover:bg-surface-hover transition-colors"
                        title="Regenerar secret"
                      >
                        <ArrowPathIcon className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => deleteWebhookMutation.mutate(webhook.id)}
                        className="p-1.5 rounded-lg text-red-500 hover:bg-red-500/10 transition-colors"
                        title="Remover webhook"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </button>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {webhook.events.slice(0, 3).map((event) => (
                      <span key={event} className="rounded-full bg-surface-hover px-2 py-0.5 text-xs text-tx-muted">
                        {event}
                      </span>
                    ))}
                    {webhook.events.length > 3 && (
                      <span className="rounded-full bg-surface-hover px-2 py-0.5 text-xs text-tx-muted">
                        +{webhook.events.length - 3}
                      </span>
                    )}
                  </div>
                </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Rate Limiting Info */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm lg:col-span-2">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-amber-500/10 p-2.5">
              <ShieldCheckIcon className="h-6 w-6 text-amber-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Rate Limiting & Segurança</h2>
              <p className="text-sm text-tx-muted">Configurações de limite de requisições</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="p-4 rounded-xl bg-surface-page">
              <p className="text-sm text-tx-muted mb-1">API Pública</p>
              <p className="text-2xl font-bold text-tx-primary">30</p>
              <p className="text-xs text-tx-muted">requisições/segundo</p>
            </div>
            <div className="p-4 rounded-xl bg-surface-page">
              <p className="text-sm text-tx-muted mb-1">Autenticação</p>
              <p className="text-2xl font-bold text-tx-primary">5</p>
              <p className="text-xs text-tx-muted">requisições/segundo</p>
            </div>
            <div className="p-4 rounded-xl bg-surface-page">
              <p className="text-sm text-tx-muted mb-1">Timeout Webhook</p>
              <p className="text-2xl font-bold text-tx-primary">30</p>
              <p className="text-xs text-tx-muted">segundos</p>
            </div>
          </div>
        </div>
      </div>

      {/* Create API Key Modal */}
      {showKeyModal && (
        <CreateKeyModal
          onClose={() => {
            setShowKeyModal(false);
            setNewKey(null);
          }}
          onCreate={(data) => createKeyMutation.mutate(data)}
          isCreating={createKeyMutation.isPending}
          newKey={newKey}
        />
      )}

      {/* Create Webhook Modal */}
      {showWebhookModal && (
        <CreateWebhookModal
          onClose={() => setShowWebhookModal(false)}
          onCreate={(data) => createWebhookMutation.mutate(data)}
          isCreating={createWebhookMutation.isPending}
        />
      )}
    </AdminPageShell>
  );
}

function CreateKeyModal({ 
  onClose, 
  onCreate, 
  isCreating, 
  newKey 
}: { 
  onClose: () => void; 
  onCreate: (data: { name: string; type: 'live' | 'test' }) => void;
  isCreating: boolean; 
  newKey: string | null;
}) {
  const [form, setForm] = useState({ name: '', type: 'live' as 'live' | 'test' });

  const copyToClipboard = () => {
    if (newKey) {
      navigator.clipboard.writeText(newKey);
      toast.success('Chave copiada!');
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-md rounded-2xl bg-surface-card p-6 shadow-xl">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-bold text-tx-primary">
            {newKey ? 'Chave Criada' : 'Nova Chave de API'}
          </h2>
          <button onClick={onClose} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        {newKey ? (
          <div className="space-y-4">
            <div className="rounded-xl bg-amber-500/10 p-4">
              <div className="flex items-start gap-3">
                <ExclamationTriangleIcon className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" />
                <p className="text-sm text-amber-600">
                  Copie esta chave agora. Ela não será exibida novamente.
                </p>
              </div>
              <div className="flex items-center gap-2 mt-3">
                <code className="flex-1 text-xs font-mono text-tx-primary bg-surface-page p-2.5 rounded-lg overflow-x-auto">
                  {newKey}
                </code>
                <button
                  onClick={copyToClipboard}
                  className="p-2 rounded-lg bg-surface-hover hover:bg-border-DEFAULT transition-colors"
                >
                  <ClipboardDocumentIcon className="h-5 w-5 text-tx-secondary" />
                </button>
              </div>
            </div>
            <button
              onClick={onClose}
              className="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 transition-colors"
            >
              Entendi, fechar
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Nome</label>
              <input
                type="text"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="ex: Integração Webhook"
                className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Tipo</label>
              <select
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value as 'live' | 'test' })}
                className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
              >
                <option value="live">Produção (pk_live_)</option>
                <option value="test">Teste (pk_test_)</option>
              </select>
              <p className="text-xs text-tx-muted mt-1">
                {form.type === 'live'
                  ? 'Use em integrações de produção.'
                  : 'Use para homologação e testes internos.'}
              </p>
            </div>

            <div className="flex gap-3 pt-2">
              <button
                onClick={onClose}
                className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={() => onCreate(form)}
                disabled={isCreating || !form.name}
                className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
              >
                {isCreating ? 'Criando...' : 'Criar Chave'}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function CreateWebhookModal({ 
  onClose, 
  onCreate, 
  isCreating 
}: { 
  onClose: () => void; 
  onCreate: (data: { url: string; events: string[] }) => void; 
  isCreating: boolean;
}) {
  const [url, setUrl] = useState('');
  const [selectedEvents, setSelectedEvents] = useState<string[]>([]);

  const availableEvents = [
    { id: 'charge.created', label: 'Cobrança Criada' },
    { id: 'charge.paid', label: 'Cobrança Paga' },
    { id: 'charge.failed', label: 'Cobrança Falhou' },
    { id: 'charge.refunded', label: 'Cobrança Estornada' },
    { id: 'charge.canceled', label: 'Cobrança Cancelada' },
    { id: 'withdrawal.requested', label: 'Saque Solicitado' },
    { id: 'withdrawal.completed', label: 'Saque Concluído' },
    { id: 'kyc.approved', label: 'KYC Aprovado' },
    { id: 'kyc.rejected', label: 'KYC Rejeitado' },
    { id: 'account.created', label: 'Conta Criada' },
  ];

  const toggleEvent = (eventId: string) => {
    setSelectedEvents((prev) =>
      prev.includes(eventId) ? prev.filter((e) => e !== eventId) : [...prev, eventId]
    );
  };

  const selectAll = () => setSelectedEvents(availableEvents.map((e) => e.id));
  const clearAll = () => setSelectedEvents([]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-surface-card p-6 shadow-xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-6">
          <h2 className="text-lg font-bold text-tx-primary">Novo Webhook</h2>
          <button onClick={onClose} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Webhook</label>
            <input
              type="url"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://example.com/webhook"
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
            />
          </div>

          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="block text-sm font-medium text-tx-secondary">Eventos</label>
              <div className="flex gap-2">
                <button onClick={selectAll} className="text-xs text-emerald-500 hover:underline">
                  Selecionar todos
                </button>
                <span className="text-tx-muted">|</span>
                <button onClick={clearAll} className="text-xs text-tx-muted hover:underline">
                  Limpar
                </button>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {availableEvents.map((event) => (
                <label
                  key={event.id}
                  className={`flex items-center gap-2 p-3 rounded-lg cursor-pointer transition-colors ${
                    selectedEvents.includes(event.id)
                      ? 'bg-emerald-500/10 border border-emerald-500/30'
                      : 'bg-surface-page hover:bg-surface-hover'
                  }`}
                >
                  <input
                    type="checkbox"
                    checked={selectedEvents.includes(event.id)}
                    onChange={() => toggleEvent(event.id)}
                    className="h-4 w-4 rounded border-border-DEFAULT text-emerald-500 focus:ring-emerald-500"
                  />
                  <span className="text-xs text-tx-primary">{event.label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="flex gap-3 pt-2">
            <button
              onClick={onClose}
              className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
            >
              Cancelar
            </button>
            <button
              onClick={() => onCreate({ url, events: selectedEvents })}
              disabled={isCreating || !url || selectedEvents.length === 0}
              className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
            >
              {isCreating ? 'Criando...' : 'Criar Webhook'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
