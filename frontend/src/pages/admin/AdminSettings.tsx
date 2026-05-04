import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  Cog6ToothIcon,
  BellIcon,
  EnvelopeIcon,
  ShieldCheckIcon,
  ServerIcon,
  EyeIcon,
  EyeSlashIcon,
  CheckCircleIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
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
  general: {
    platform_name: 'GatePro',
    support_email: '',
    support_phone: '',
    default_currency: 'BRL',
    timezone: 'America/Sao_Paulo',
    maintenance_mode: false,
  },
  email: {
    smtp_host: '',
    smtp_port: 587,
    smtp_username: '',
    smtp_password: '',
    smtp_encryption: 'tls',
    from_name: 'GatePro',
    from_email: '',
  },
  payments: {
    pix_enabled: true,
    credit_card_enabled: true,
    boleto_enabled: true,
    max_installments: 12,
    min_installment_value: 10,
    default_gateway_fee: 4.99,
    pix_expiration_minutes: 30,
  },
  security: {
    require_mfa: false,
    session_timeout_minutes: 60,
    max_login_attempts: 5,
    password_min_length: 8,
    require_kyc: true,
    ip_whitelist_enabled: false,
    ip_whitelist: '',
  },
  features: {
    affiliates_enabled: true,
    marketplace_enabled: true,
    member_area_enabled: true,
    custom_domains_enabled: true,
    webhooks_enabled: true,
    api_access_enabled: true,
  },
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
      toast.success('Configuracoes salvas');
      queryClient.invalidateQueries({ queryKey: ['platform-settings'] });
    },
    onError: () => {
      toast.error('Erro ao salvar configuracoes');
    },
  });

  if (isLoading || !settings) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
      </div>
    );
  }

  const tabs = [
    { id: 'general', label: 'Geral', icon: Cog6ToothIcon },
    { id: 'email', label: 'Email', icon: EnvelopeIcon },
    { id: 'payments', label: 'Pagamentos', icon: ServerIcon },
    { id: 'security', label: 'Seguranca', icon: ShieldCheckIcon },
    { id: 'features', label: 'Recursos', icon: BellIcon },
  ] as const;

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={Cog6ToothIcon}
        title="Configuracoes da Plataforma"
        subtitle="Gerencie as configuracoes globais do sistema"
      />

      <div className="flex flex-col gap-6 lg:flex-row">
        <div className="lg:w-56">
          <nav className="flex flex-row lg:flex-col gap-1 overflow-x-auto pb-2 lg:pb-0">
            {tabs.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors ${
                  activeTab === tab.id ? 'bg-green-500 text-white' : 'text-tx-secondary hover:bg-surface-hover'
                }`}
              >
                <tab.icon className="h-5 w-5" />
                {tab.label}
              </button>
            ))}
          </nav>
        </div>

        <div className="flex-1 rounded-2xl bg-surface-card p-6 shadow-sm">
          {activeTab === 'general' && (
            <GeneralSettings
              settings={settings.general}
              onSave={(data) => updateSettingsMutation.mutate({ general: data })}
              isSaving={updateSettingsMutation.isPending}
            />
          )}
          {activeTab === 'email' && (
            <EmailSettings
              settings={settings.email}
              onSave={(data) => updateSettingsMutation.mutate({ email: data })}
              isSaving={updateSettingsMutation.isPending}
            />
          )}
          {activeTab === 'payments' && (
            <PaymentSettings
              settings={settings.payments}
              onSave={(data) => updateSettingsMutation.mutate({ payments: data })}
              isSaving={updateSettingsMutation.isPending}
            />
          )}
          {activeTab === 'security' && (
            <SecuritySettings
              settings={settings.security}
              onSave={(data) => updateSettingsMutation.mutate({ security: data })}
              isSaving={updateSettingsMutation.isPending}
            />
          )}
          {activeTab === 'features' && (
            <FeatureSettings
              settings={settings.features}
              onSave={(data) => updateSettingsMutation.mutate({ features: data })}
              isSaving={updateSettingsMutation.isPending}
            />
          )}
        </div>
      </div>
    </AdminPageShell>
  );
}

function GeneralSettings({
  settings,
  onSave,
  isSaving,
}: {
  settings: PlatformSettingsResponse['general'];
  onSave: (data: Partial<PlatformSettingsResponse['general']>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState(settings);

  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Configuracoes Gerais</h2>

      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Nome da Plataforma" value={form.platform_name} onChange={(value) => setForm({ ...form, platform_name: value })} />
        <Input label="Moeda Padrao" value={form.default_currency} onChange={(value) => setForm({ ...form, default_currency: value })} />
        <Input label="Email de Suporte" value={form.support_email} onChange={(value) => setForm({ ...form, support_email: value })} />
        <Input label="Telefone de Suporte" value={form.support_phone} onChange={(value) => setForm({ ...form, support_phone: value })} />
        <Input label="Fuso Horario" value={form.timezone} onChange={(value) => setForm({ ...form, timezone: value })} />
      </div>

      <label className="flex items-center gap-3 cursor-pointer">
        <input
          type="checkbox"
          checked={form.maintenance_mode}
          onChange={(e) => setForm({ ...form, maintenance_mode: e.target.checked })}
          className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
        />
        <span className="text-sm text-tx-primary">Modo de manutencao</span>
      </label>

      <SaveButton isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function EmailSettings({
  settings,
  onSave,
  isSaving,
}: {
  settings: PlatformSettingsResponse['email'];
  onSave: (data: Partial<PlatformSettingsResponse['email']>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState({ ...settings, smtp_password: '' });
  const [showPassword, setShowPassword] = useState(false);

  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Configuracoes de Email</h2>

      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Nome do Remetente" value={form.from_name} onChange={(value) => setForm({ ...form, from_name: value })} />
        <Input label="Email do Remetente" value={form.from_email} onChange={(value) => setForm({ ...form, from_email: value })} />
        <Input label="Host SMTP" value={form.smtp_host} onChange={(value) => setForm({ ...form, smtp_host: value })} />
        <Input label="Porta SMTP" type="number" value={String(form.smtp_port)} onChange={(value) => setForm({ ...form, smtp_port: Number(value) || 0 })} />
        <Input label="Usuario SMTP" value={form.smtp_username} onChange={(value) => setForm({ ...form, smtp_username: value })} />

        <div>
          <label className="block text-sm font-medium text-tx-secondary mb-1">Senha SMTP</label>
          <div className="relative">
            <input
              type={showPassword ? 'text' : 'password'}
              value={form.smtp_password}
              onChange={(e) => setForm({ ...form, smtp_password: e.target.value })}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 pr-10 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-tx-muted hover:text-tx-primary"
            >
              {showPassword ? <EyeSlashIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
            </button>
          </div>
        </div>

        <Input label="Criptografia" value={form.smtp_encryption} onChange={(value) => setForm({ ...form, smtp_encryption: value })} />
      </div>

      <SaveButton isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function PaymentSettings({
  settings,
  onSave,
  isSaving,
}: {
  settings: PlatformSettingsResponse['payments'];
  onSave: (data: Partial<PlatformSettingsResponse['payments']>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState(settings);

  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Configuracoes de Pagamento</h2>

      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Maximo de Parcelas" type="number" value={String(form.max_installments)} onChange={(value) => setForm({ ...form, max_installments: Number(value) || 1 })} />
        <Input label="Valor Minimo por Parcela" type="number" value={String(form.min_installment_value)} onChange={(value) => setForm({ ...form, min_installment_value: Number(value) || 0 })} />
        <Input label="Taxa Padrao do Gateway (%)" type="number" value={String(form.default_gateway_fee)} onChange={(value) => setForm({ ...form, default_gateway_fee: Number(value) || 0 })} />
        <Input label="Expiracao PIX (min)" type="number" value={String(form.pix_expiration_minutes)} onChange={(value) => setForm({ ...form, pix_expiration_minutes: Number(value) || 0 })} />
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Toggle label="PIX habilitado" checked={form.pix_enabled} onChange={(checked) => setForm({ ...form, pix_enabled: checked })} />
        <Toggle label="Cartao habilitado" checked={form.credit_card_enabled} onChange={(checked) => setForm({ ...form, credit_card_enabled: checked })} />
        <Toggle label="Boleto habilitado" checked={form.boleto_enabled} onChange={(checked) => setForm({ ...form, boleto_enabled: checked })} />
      </div>

      <SaveButton isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function SecuritySettings({
  settings,
  onSave,
  isSaving,
}: {
  settings: PlatformSettingsResponse['security'];
  onSave: (data: Partial<PlatformSettingsResponse['security']>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState(settings);

  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Configuracoes de Seguranca</h2>

      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Timeout de Sessao (min)" type="number" value={String(form.session_timeout_minutes)} onChange={(value) => setForm({ ...form, session_timeout_minutes: Number(value) || 0 })} />
        <Input label="Maximo de Tentativas de Login" type="number" value={String(form.max_login_attempts)} onChange={(value) => setForm({ ...form, max_login_attempts: Number(value) || 0 })} />
        <Input label="Tamanho Minimo de Senha" type="number" value={String(form.password_min_length)} onChange={(value) => setForm({ ...form, password_min_length: Number(value) || 0 })} />
        <Input label="Whitelist de IP (csv)" value={form.ip_whitelist} onChange={(value) => setForm({ ...form, ip_whitelist: value })} />
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Toggle label="MFA obrigatorio" checked={form.require_mfa} onChange={(checked) => setForm({ ...form, require_mfa: checked })} />
        <Toggle label="KYC obrigatorio" checked={form.require_kyc} onChange={(checked) => setForm({ ...form, require_kyc: checked })} />
        <Toggle label="Whitelist de IP ativa" checked={form.ip_whitelist_enabled} onChange={(checked) => setForm({ ...form, ip_whitelist_enabled: checked })} />
      </div>

      <SaveButton isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function FeatureSettings({
  settings,
  onSave,
  isSaving,
}: {
  settings: PlatformSettingsResponse['features'];
  onSave: (data: Partial<PlatformSettingsResponse['features']>) => void;
  isSaving: boolean;
}) {
  const [form, setForm] = useState(settings);

  const features = [
    { key: 'affiliates_enabled', label: 'Sistema de Afiliados' },
    { key: 'marketplace_enabled', label: 'Marketplace' },
    { key: 'member_area_enabled', label: 'Area de Membros' },
    { key: 'custom_domains_enabled', label: 'Dominios Customizados' },
    { key: 'webhooks_enabled', label: 'Webhooks Globais' },
    { key: 'api_access_enabled', label: 'Acesso API' },
  ] as const;

  return (
    <div className="space-y-6">
      <h2 className="text-lg font-semibold text-tx-primary">Recursos da Plataforma</h2>

      <div className="space-y-3">
        {features.map((feature) => (
          <label key={feature.key} className="flex items-center gap-3 p-4 rounded-xl bg-surface-page cursor-pointer hover:bg-surface-hover transition-colors">
            <input
              type="checkbox"
              checked={form[feature.key]}
              onChange={(e) => setForm({ ...form, [feature.key]: e.target.checked })}
              className="h-5 w-5 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
            />
            <div className="flex-1">
              <span className="text-sm font-medium text-tx-primary">{feature.label}</span>
            </div>
            {form[feature.key] ? <CheckCircleIcon className="h-5 w-5 text-emerald-500" /> : <XMarkIcon className="h-5 w-5 text-tx-muted" />}
          </label>
        ))}
      </div>

      <SaveButton isSaving={isSaving} onClick={() => onSave(form)} />
    </div>
  );
}

function Input({
  label,
  value,
  onChange,
  type = 'text',
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: 'text' | 'number';
}) {
  return (
    <div>
      <label className="block text-sm font-medium text-tx-secondary mb-1">{label}</label>
      <input
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
      />
    </div>
  );
}

function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <label className="flex items-center gap-3 cursor-pointer rounded-lg bg-surface-page p-3">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded border-border-DEFAULT text-green-500 focus:ring-green-500"
      />
      <span className="text-sm text-tx-primary">{label}</span>
    </label>
  );
}

function SaveButton({ isSaving, onClick }: { isSaving: boolean; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      disabled={isSaving}
      className="rounded-lg bg-green-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
    >
      {isSaving ? 'Salvando...' : 'Salvar Alteracoes'}
    </button>
  );
}

