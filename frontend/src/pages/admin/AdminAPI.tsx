import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  KeyIcon,
  PlusIcon,
  XMarkIcon,
  ClipboardDocumentIcon,
  ArrowPathIcon,
  TrashIcon,
  ExclamationTriangleIcon,
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

  const { data: apiKeys = [], isLoading: loadingKeys } = useQuery<ApiKey[]>({
    queryKey: ['admin-api-keys'],
    queryFn: async () => { const r = await api.get('/admin/api-keys'); return r.data?.data || []; },
  });

  const { data: webhooks = [], isLoading: loadingWebhooks } = useQuery<Webhook[]>({
    queryKey: ['admin-webhooks'],
    queryFn: async () => { const r = await api.get('/admin/webhooks'); return r.data?.data || []; },
  });

  const createKeyMutation = useMutation({
    mutationFn: async (data: { name: string; type: 'live' | 'test' }) => {
      const r = await api.post('/admin/api-keys', data);
      return r.data?.data?.key;
    },
    onSuccess: (key) => { setNewKey(key); queryClient.invalidateQueries({ queryKey: ['admin-api-keys'] }); },
    onError: () => toast.error('Erro ao criar chave'),
  });

  const revokeKeyMutation = useMutation({
    mutationFn: async (keyId: string) => { await api.delete(`/admin/api-keys/${keyId}`); },
    onSuccess: () => { toast.success('Chave revogada'); queryClient.invalidateQueries({ queryKey: ['admin-api-keys'] }); },
    onError: () => toast.error('Erro ao revogar'),
  });

  const createWebhookMutation = useMutation({
    mutationFn: async (data: { url: string; events: string[] }) => {
      await api.post('/admin/webhooks', data);
    },
    onSuccess: () => { toast.success('Webhook criado'); setShowWebhookModal(false); queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] }); },
    onError: () => toast.error('Erro ao criar webhook'),
  });

  const deleteWebhookMutation = useMutation({
    mutationFn: async (id: string) => { await api.delete(`/admin/webhooks/${id}`); },
    onSuccess: () => { toast.success('Webhook removido'); queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] }); },
    onError: () => toast.error('Erro ao remover'),
  });

  const regenerateSecretMutation = useMutation({
    mutationFn: async (id: string) => { await api.post(`/admin/webhooks/${id}/regenerate-secret`); },
    onSuccess: () => { toast.success('Secret regenerado'); queryClient.invalidateQueries({ queryKey: ['admin-webhooks'] }); },
    onError: () => toast.error('Erro ao regenerar'),
  });

  const isWebhookActive = (w: Webhook) => w.active ?? w.is_active ?? false;

  return (
    <AdminPageShell>
      <AdminPageHeader title="API & Webhooks" subtitle="Chaves de API e configurações de webhooks" />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* API Keys */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-sm font-semibold text-gray-900">Chaves de API</h2>
              <p className="text-xs text-gray-500">{apiKeys.length} chave(s)</p>
            </div>
            <button onClick={() => setShowKeyModal(true)} className="flex items-center gap-1.5 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
              <PlusIcon className="h-3.5 w-3.5" /> Nova Chave
            </button>
          </div>

          {loadingKeys ? (
            <div className="flex justify-center py-8"><div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" /></div>
          ) : apiKeys.length === 0 ? (
            <p className="py-8 text-center text-sm text-gray-500">Nenhuma chave criada</p>
          ) : (
            <div className="space-y-2">
              {apiKeys.map((key) => (
                <div key={key.id} className="flex items-center justify-between rounded-md border border-gray-100 px-3 py-2.5">
                  <div>
                    <p className="text-sm font-medium text-gray-900">{key.name}</p>
                    <p className="text-xs font-mono text-gray-400">{key.key_prefix || key.key?.substring(0, 12)}...</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${key.type === 'test' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
                      {key.type === 'test' ? 'Teste' : 'Produção'}
                    </span>
                    <button onClick={() => revokeKeyMutation.mutate(key.id)} className="rounded p-1 text-gray-400 hover:text-red-600">
                      <TrashIcon className="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Webhooks */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-sm font-semibold text-gray-900">Webhooks</h2>
              <p className="text-xs text-gray-500">{webhooks.length} configurado(s)</p>
            </div>
            <button onClick={() => setShowWebhookModal(true)} className="flex items-center gap-1.5 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800">
              <PlusIcon className="h-3.5 w-3.5" /> Novo
            </button>
          </div>

          {loadingWebhooks ? (
            <div className="flex justify-center py-8"><div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" /></div>
          ) : webhooks.length === 0 ? (
            <p className="py-8 text-center text-sm text-gray-500">Nenhum webhook configurado</p>
          ) : (
            <div className="space-y-2">
              {webhooks.map((wh) => (
                <div key={wh.id} className="rounded-md border border-gray-100 px-3 py-2.5">
                  <div className="flex items-center justify-between mb-1.5">
                    <p className="text-xs font-mono text-gray-700 truncate max-w-[200px]">{wh.url}</p>
                    <div className="flex items-center gap-1">
                      <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${isWebhookActive(wh) ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>
                        {isWebhookActive(wh) ? 'Ativo' : 'Inativo'}
                      </span>
                      <button onClick={() => regenerateSecretMutation.mutate(wh.id)} className="rounded p-1 text-gray-400 hover:text-gray-700" title="Regenerar secret">
                        <ArrowPathIcon className="h-3.5 w-3.5" />
                      </button>
                      <button onClick={() => deleteWebhookMutation.mutate(wh.id)} className="rounded p-1 text-gray-400 hover:text-red-600" title="Remover">
                        <TrashIcon className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {wh.events.slice(0, 3).map((ev) => (
                      <span key={ev} className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">{ev}</span>
                    ))}
                    {wh.events.length > 3 && <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">+{wh.events.length - 3}</span>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Rate Limiting */}
        <div className="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-2">
          <h2 className="text-sm font-semibold text-gray-900 mb-4">Rate Limiting</h2>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-md bg-gray-50 p-3">
              <p className="text-xs text-gray-500">API Pública</p>
              <p className="text-xl font-semibold text-gray-900">30 <span className="text-xs font-normal text-gray-500">req/s</span></p>
            </div>
            <div className="rounded-md bg-gray-50 p-3">
              <p className="text-xs text-gray-500">Autenticação</p>
              <p className="text-xl font-semibold text-gray-900">5 <span className="text-xs font-normal text-gray-500">req/s</span></p>
            </div>
            <div className="rounded-md bg-gray-50 p-3">
              <p className="text-xs text-gray-500">Timeout Webhook</p>
              <p className="text-xl font-semibold text-gray-900">30 <span className="text-xs font-normal text-gray-500">seg</span></p>
            </div>
          </div>
        </div>
      </div>

      {/* Create Key Modal */}
      {showKeyModal && <CreateKeyModal onClose={() => { setShowKeyModal(false); setNewKey(null); }} onCreate={(d) => createKeyMutation.mutate(d)} isCreating={createKeyMutation.isPending} newKey={newKey} />}

      {/* Create Webhook Modal */}
      {showWebhookModal && <CreateWebhookModal onClose={() => setShowWebhookModal(false)} onCreate={(d) => createWebhookMutation.mutate(d)} isCreating={createWebhookMutation.isPending} />}
    </AdminPageShell>
  );
}

function CreateKeyModal({ onClose, onCreate, isCreating, newKey }: { onClose: () => void; onCreate: (d: { name: string; type: 'live' | 'test' }) => void; isCreating: boolean; newKey: string | null }) {
  const [form, setForm] = useState({ name: '', type: 'live' as 'live' | 'test' });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-base font-semibold text-gray-900">{newKey ? 'Chave Criada' : 'Nova Chave de API'}</h2>
          <button onClick={onClose} className="rounded p-1 text-gray-400 hover:text-gray-600"><XMarkIcon className="h-5 w-5" /></button>
        </div>

        {newKey ? (
          <div className="space-y-3">
            <div className="rounded-md border border-amber-200 bg-amber-50 p-3">
              <div className="flex items-start gap-2">
                <ExclamationTriangleIcon className="h-4 w-4 text-amber-600 mt-0.5" />
                <p className="text-xs text-amber-700">Copie esta chave agora. Ela não será exibida novamente.</p>
              </div>
            </div>
            <div className="flex items-center gap-2 rounded-md bg-gray-50 p-3">
              <code className="flex-1 text-xs font-mono text-gray-900 break-all">{newKey}</code>
              <button onClick={() => { navigator.clipboard.writeText(newKey); toast.success('Copiada!'); }} className="rounded p-1.5 text-gray-500 hover:bg-gray-200">
                <ClipboardDocumentIcon className="h-4 w-4" />
              </button>
            </div>
            <button onClick={onClose} className="w-full rounded-md bg-gray-900 py-2 text-sm font-medium text-white hover:bg-gray-800">Fechar</button>
          </div>
        ) : (
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Nome</label>
              <input type="text" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Ex: Integração ERP" className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Tipo</label>
              <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as 'live' | 'test' })} className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none">
                <option value="live">Produção</option>
                <option value="test">Teste</option>
              </select>
            </div>
            <button onClick={() => onCreate(form)} disabled={!form.name.trim() || isCreating} className="w-full rounded-md bg-gray-900 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
              {isCreating ? 'Criando...' : 'Criar Chave'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function CreateWebhookModal({ onClose, onCreate, isCreating }: { onClose: () => void; onCreate: (d: { url: string; events: string[] }) => void; isCreating: boolean }) {
  const [url, setUrl] = useState('');
  const [events, setEvents] = useState<string[]>([]);

  const availableEvents = ['payment.created', 'payment.approved', 'payment.failed', 'payment.refunded', 'withdrawal.requested', 'withdrawal.processed', 'kyc.approved', 'kyc.rejected'];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-base font-semibold text-gray-900">Novo Webhook</h2>
          <button onClick={onClose} className="rounded p-1 text-gray-400 hover:text-gray-600"><XMarkIcon className="h-5 w-5" /></button>
        </div>
        <div className="space-y-4">
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">URL</label>
            <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://..." className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none" />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-2">Eventos</label>
            <div className="grid grid-cols-2 gap-1.5">
              {availableEvents.map((ev) => (
                <label key={ev} className="flex items-center gap-1.5 cursor-pointer">
                  <input type="checkbox" checked={events.includes(ev)} onChange={(e) => setEvents(e.target.checked ? [...events, ev] : events.filter((x) => x !== ev))} className="h-3.5 w-3.5 rounded border-gray-300 text-gray-900" />
                  <span className="text-xs text-gray-700">{ev}</span>
                </label>
              ))}
            </div>
          </div>
          <button onClick={() => onCreate({ url, events })} disabled={!url.trim() || events.length === 0 || isCreating} className="w-full rounded-md bg-gray-900 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
            {isCreating ? 'Criando...' : 'Criar Webhook'}
          </button>
        </div>
      </div>
    </div>
  );
}
