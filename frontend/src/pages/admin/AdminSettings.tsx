import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface PlatformSettingsResponse {
  general: {
    platform_name: string;
    support_email: string;
    support_phone: string;
    default_currency: string;
    timezone: string;
    maintenance_mode: boolean;
  };
  email: {
    smtp_host: string;
    smtp_port: number;
    smtp_username: string;
    smtp_password: string;
    smtp_encryption: string;
    from_name: string;
    from_email: string;
  };
  payments: {
    pix_enabled: boolean;
    credit_card_enabled: boolean;
    boleto_enabled: boolean;
    max_installments: number;
    min_installment_value: number;
    default_gateway_fee: number;
    pix_expiration_minutes: number;
  };
  security: {
    require_mfa: boolean;
    session_timeout_minutes: number;
    max_login_attempts: number;
    password_min_length: number;
    require_kyc: boolean;
    ip_whitelist_enabled: boolean;
    ip_whitelist: string;
  };
  features: {
    affiliates_enabled: boolean;
    marketplace_enabled: boolean;
    member_area_enabled: boolean;
    custom_domains_enabled: boolean;
    webhooks_enabled: boolean;
    api_access_enabled: boolean;
  };
}

const defaultSettings: PlatformSettingsResponse = {
  general: { platform_name: 'Vector Pagamentos', support_email: '', support_phone: '', default_currency: 'BRL', timezone: 'America/Sao_Paulo', maintenance_mode: false },
  email: { smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', smtp_encryption: 'tls', from_name: 'Vector Pagamentos', from_email: '' },
  payments: { pix_enabled: true, credit_card_enabled: true, boleto_enabled: true, max_installments: 12, min_installment_value: 10, default_gateway_fee: 4.99, pix_expiration_minutes: 30 },
  security: { require_mfa: false, session_timeout_minutes: 60, max_login_attempts: 5, password_min_length: 8, require_kyc: true, ip_whitelist_enabled: false, ip_whitelist: '' },
  features: { affiliates_enabled: true, marketplace_enabled: true, member_area_enabled: true, custom_domains_enabled: true, webhooks_enabled: true, api_access_enabled: true },
};

export default function AdminSettings() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'general' | 'email' | 'payments' | 'security' | 'features'>('general');

  const { data: settings, isLoading } = useQuery<PlatformSettingsResponse>({
    queryKey: ['platform-settings'],
    queryFn: async () => {
      const response = await api.get('/admin/settings');
      return response.data?.data || defaultSettings;
    },
  });

  const updateSettingsMutation = useMutation({
    mutationFn: async (data: Partial<PlatformSettingsResponse>) => {
      await api.put('/admin/settings', data);
    },
    onSuccess: () => {
      toast.success('Configurações salvas');
      queryClient.invalidateQueries({ queryKey: ['platform-settings'] });
    },
    onError: () => toast.error('Erro ao salvar'),
  });

  const pruneStaleChargesMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/admin/settings/prune-stale-charges');
      return response.data;
    },
    onSuccess: (response) => {
      const deleted = response?.data?.deleted ?? 0;
      toast.success(`Limpeza concluída. ${deleted} removidas.`);
      queryClient.invalidateQueries({ queryKey: ['charges'] });
      queryClient.invalidateQueries({ queryKey: ['admin-charges'] });
    },
    onError: () => toast.error('Erro ao executar limpeza'),
  });

  if (isLoading || !settings) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  const tabs = [
    { id: 'general', label: 'Geral' },
    { id: 'email', label: 'Email' },
    { id: 'payments', label: 'Pagamentos' },
    { id: 'security', label: 'Segurança' },
    { id: 'features', label: 'Recursos' },
  ] as const;

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Configurações"
        subtitle="Configurações globais da plataforma"
        actions={(
          <button
            onClick={() => pruneStaleChargesMutation.mutate()}
            disabled={pruneStaleChargesMutation.isPending}
            className="rounded-md border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {pruneStaleChargesMutation.isPending ? 'Limpando...' : 'Limpar Agora'}
          </button>
        )}
      />

      <div className="flex flex-col gap-6 lg:flex-row">
        <nav className="flex flex-row gap-1 overflow-x-auto lg:w-48 lg:flex-col">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors ${
                activeTab === tab.id ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>

        <div className="flex-1 rounded-lg border border-gray-200 bg-white p-6">
          {activeTab === 'general' && <GeneralSettings settings={settings.general} onSave={(data) => updateSettingsMutation.mutate({ general: data })} isSaving={updateSettingsMutation.isPending} />}
          {activeTab === 'email' && <EmailSettings settings={settings.email} onSave={(data) => updateSettingsMutation.mutate({ email: data })} isSaving={updateSettingsMutation.isPending} />}
          {activeTab === 'payments' && <PaymentSettings settings={settings.payments} onSave={(data) => updateSettingsMutation.mutate({ payments: data })} isSaving={updateSettingsMutation.isPending} />}
          {activeTab === 'security' && <SecuritySettings settings={settings.security} onSave={(data) => updateSettingsMutation.mutate({ security: data })} isSaving={updateSettingsMutation.isPending} />}
          {activeTab === 'features' && <FeatureSettings settings={settings.features} onSave={(data) => updateSettingsMutation.mutate({ features: data })} isSaving={updateSettingsMutation.isPending} />}
        </div>
      </div>
    </AdminPageShell>
  );
}

function GeneralSettings({ settings, onSave, isSaving }: { settings: PlatformSettingsResponse['general']; onSave: (data: any) => void; isSaving: boolean }) {
  const [form, setForm] = useState(settings);
  return (
    <div className="space-y-5">
      <h2 className="text-base font-semibold text-gray-900">Geral</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Nome da Plataforma" value={form.platform_name} onChange={(v) => setForm({ ...form, platform_name: v })} />
        <Field label="Moeda" value={form.default_currency} onChange={(v) => setForm({ ...form, default_currency: v })} />
        <Field label="Email de Suporte" value={form.support_email} onChange={(v) => setForm({ ...form, support_email: v })} />
        <Field label="Telefone" value={form.support_phone} onChange={(v) => setForm({ ...form, support_phone: v })} />
        <Field label="Fuso Horário" value={form.timezone} onChange={(v) => setForm({ ...form, timezone: v })} />
      </div>
      <Toggle label="Modo de manutenção" checked={form.maintenance_mode} onChange={(c) => setForm({ ...form, maintenance_mode: c })} />
      <SaveBtn isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function EmailSettings({ settings, onSave, isSaving }: { settings: PlatformSettingsResponse['email']; onSave: (data: any) => void; isSaving: boolean }) {
  const [form, setForm] = useState({ ...settings, smtp_password: '' });
  const [showPw, setShowPw] = useState(false);
  return (
    <div className="space-y-5">
      <h2 className="text-base font-semibold text-gray-900">Email (SMTP)</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Remetente (nome)" value={form.from_name} onChange={(v) => setForm({ ...form, from_name: v })} />
        <Field label="Remetente (email)" value={form.from_email} onChange={(v) => setForm({ ...form, from_email: v })} />
        <Field label="Host SMTP" value={form.smtp_host} onChange={(v) => setForm({ ...form, smtp_host: v })} />
        <Field label="Porta" value={String(form.smtp_port)} onChange={(v) => setForm({ ...form, smtp_port: Number(v) || 0 })} />
        <Field label="Usuário" value={form.smtp_username} onChange={(v) => setForm({ ...form, smtp_username: v })} />
        <div>
          <label className="block text-xs font-medium text-gray-500 mb-1">Senha SMTP</label>
          <div className="relative">
            <input type={showPw ? 'text' : 'password'} value={form.smtp_password} onChange={(e) => setForm({ ...form, smtp_password: e.target.value })} className="w-full rounded-md border border-gray-200 bg-white px-3 py-2 pr-9 text-sm text-gray-900 focus:border-gray-400 focus:outline-none" />
            <button type="button" onClick={() => setShowPw(!showPw)} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400">
              {showPw ? <EyeSlashIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
            </button>
          </div>
        </div>
        <Field label="Criptografia" value={form.smtp_encryption} onChange={(v) => setForm({ ...form, smtp_encryption: v })} />
      </div>
      <SaveBtn isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function PaymentSettings({ settings, onSave, isSaving }: { settings: PlatformSettingsResponse['payments']; onSave: (data: any) => void; isSaving: boolean }) {
  const [form, setForm] = useState(settings);
  return (
    <div className="space-y-5">
      <h2 className="text-base font-semibold text-gray-900">Pagamentos</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Máx. Parcelas" value={String(form.max_installments)} onChange={(v) => setForm({ ...form, max_installments: Number(v) || 1 })} />
        <Field label="Valor Mín. Parcela" value={String(form.min_installment_value)} onChange={(v) => setForm({ ...form, min_installment_value: Number(v) || 0 })} />
        <Field label="Taxa Gateway (%)" value={String(form.default_gateway_fee)} onChange={(v) => setForm({ ...form, default_gateway_fee: Number(v) || 0 })} />
        <Field label="Expiração PIX (min)" value={String(form.pix_expiration_minutes)} onChange={(v) => setForm({ ...form, pix_expiration_minutes: Number(v) || 0 })} />
      </div>
      <div className="flex flex-wrap gap-4">
        <Toggle label="PIX" checked={form.pix_enabled} onChange={(c) => setForm({ ...form, pix_enabled: c })} />
        <Toggle label="Cartão" checked={form.credit_card_enabled} onChange={(c) => setForm({ ...form, credit_card_enabled: c })} />
        <Toggle label="Boleto" checked={form.boleto_enabled} onChange={(c) => setForm({ ...form, boleto_enabled: c })} />
      </div>
      <SaveBtn isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function SecuritySettings({ settings, onSave, isSaving }: { settings: PlatformSettingsResponse['security']; onSave: (data: any) => void; isSaving: boolean }) {
  const [form, setForm] = useState(settings);
  return (
    <div className="space-y-5">
      <h2 className="text-base font-semibold text-gray-900">Segurança</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Timeout Sessão (min)" value={String(form.session_timeout_minutes)} onChange={(v) => setForm({ ...form, session_timeout_minutes: Number(v) || 0 })} />
        <Field label="Máx. Tentativas Login" value={String(form.max_login_attempts)} onChange={(v) => setForm({ ...form, max_login_attempts: Number(v) || 0 })} />
        <Field label="Tamanho Mín. Senha" value={String(form.password_min_length)} onChange={(v) => setForm({ ...form, password_min_length: Number(v) || 0 })} />
        <Field label="Whitelist IP (csv)" value={form.ip_whitelist} onChange={(v) => setForm({ ...form, ip_whitelist: v })} />
      </div>
      <div className="flex flex-wrap gap-4">
        <Toggle label="MFA obrigatório" checked={form.require_mfa} onChange={(c) => setForm({ ...form, require_mfa: c })} />
        <Toggle label="KYC obrigatório" checked={form.require_kyc} onChange={(c) => setForm({ ...form, require_kyc: c })} />
        <Toggle label="Whitelist IP" checked={form.ip_whitelist_enabled} onChange={(c) => setForm({ ...form, ip_whitelist_enabled: c })} />
      </div>
      <SaveBtn isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function FeatureSettings({ settings, onSave, isSaving }: { settings: PlatformSettingsResponse['features']; onSave: (data: any) => void; isSaving: boolean }) {
  const [form, setForm] = useState(settings);
  const features = [
    { key: 'affiliates_enabled', label: 'Afiliados' },
    { key: 'marketplace_enabled', label: 'Marketplace' },
    { key: 'member_area_enabled', label: 'Área de Membros' },
    { key: 'custom_domains_enabled', label: 'Domínios Custom' },
    { key: 'webhooks_enabled', label: 'Webhooks' },
    { key: 'api_access_enabled', label: 'Acesso API' },
  ] as const;
  return (
    <div className="space-y-5">
      <h2 className="text-base font-semibold text-gray-900">Recursos</h2>
      <div className="grid gap-3 sm:grid-cols-2">
        {features.map((f) => (
          <Toggle key={f.key} label={f.label} checked={(form as any)[f.key]} onChange={(c) => setForm({ ...form, [f.key]: c })} />
        ))}
      </div>
      <SaveBtn isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="block text-xs font-medium text-gray-500 mb-1">{label}</label>
      <input type="text" value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none" />
    </div>
  );
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (c: boolean) => void }) {
  return (
    <label className="flex items-center gap-2 cursor-pointer">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500" />
      <span className="text-sm text-gray-700">{label}</span>
    </label>
  );
}

function SaveBtn({ isSaving, onClick }: { isSaving: boolean; onClick: () => void }) {
  return (
    <div className="pt-2">
      <button onClick={onClick} disabled={isSaving} className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50">
        {isSaving ? 'Salvando...' : 'Salvar'}
      </button>
    </div>
  );
}
