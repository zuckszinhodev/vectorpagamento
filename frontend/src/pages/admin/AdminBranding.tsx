import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  SwatchIcon,
  PhotoIcon,
  CheckCircleIcon,
  ArrowPathIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface BrandingSettings {
  platform_name: string;
  logo_url?: string;
  favicon_url?: string;
  primary_color: string;
  secondary_color?: string;
  custom_css?: string;
}

export default function AdminBranding() {
  const queryClient = useQueryClient();

  const { data: settings, isLoading } = useQuery<BrandingSettings>({
    queryKey: ['platform-settings'],
    queryFn: async () => {
      const response = await api.get('/admin/settings');
      return response.data?.data?.branding || response.data?.data;
    },
  });

  const updateMutation = useMutation({
    mutationFn: async (data: Partial<BrandingSettings>) => {
      await api.put('/admin/settings', { branding: data });
    },
    onSuccess: () => {
      toast.success('Branding atualizado');
      queryClient.invalidateQueries({ queryKey: ['platform-settings'] });
    },
    onError: () => {
      toast.error('Erro ao salvar');
    },
  });

  const [form, setForm] = useState<BrandingSettings>({
    platform_name: '',
    logo_url: '',
    favicon_url: '',
    primary_color: '#16a34a',
    secondary_color: '#4F46E5',
    custom_css: '',
  });

  // Update form when settings load
  useEffect(() => {
    if (!settings) return;

    setForm({
      platform_name: settings.platform_name || '',
      logo_url: settings.logo_url || '',
      favicon_url: settings.favicon_url || '',
      primary_color: settings.primary_color || '#16a34a',
      secondary_color: settings.secondary_color || '#4F46E5',
      custom_css: settings.custom_css || '',
    });
  }, [settings]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
      </div>
    );
  }

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={SwatchIcon}
        title="Branding da Plataforma"
        subtitle="Personalize a aparencia visual da plataforma"
      />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Logo & Identity */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-green-500/10 p-2.5">
              <PhotoIcon className="h-6 w-6 text-green-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Identidade Visual</h2>
              <p className="text-sm text-tx-muted">Logo e ícones da plataforma</p>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">Nome da Plataforma</label>
              <input
                type="text"
                value={form.platform_name}
                onChange={(e) => setForm({ ...form, platform_name: e.target.value })}
                placeholder="GatePro"
                className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Logo</label>
              <div className="flex gap-3 items-center">
                <input
                  type="url"
                  value={form.logo_url}
                  onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
                  placeholder="https://example.com/logo.png"
                  className="flex-1 rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
                />
                {form.logo_url && (
                  <div className="h-12 w-12 rounded-lg border border-border-DEFAULT p-2 bg-white">
                    <img src={form.logo_url} alt="Logo" className="h-full w-full object-contain" />
                  </div>
                )}
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Favicon</label>
              <div className="flex gap-3 items-center">
                <input
                  type="url"
                  value={form.favicon_url}
                  onChange={(e) => setForm({ ...form, favicon_url: e.target.value })}
                  placeholder="https://example.com/favicon.ico"
                  className="flex-1 rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
                />
                {form.favicon_url && (
                  <div className="h-10 w-10 rounded-lg border border-border-DEFAULT p-1.5 bg-white">
                    <img src={form.favicon_url} alt="Favicon" className="h-full w-full object-contain" />
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Colors */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-green-500/10 p-2.5">
              <SwatchIcon className="h-6 w-6 text-green-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Esquema de Cores</h2>
              <p className="text-sm text-tx-muted">Cores principais da interface</p>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-2">Cor Primária</label>
              <div className="flex items-center gap-4">
                <input
                  type="color"
                  value={form.primary_color}
                  onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                  className="h-12 w-20 rounded-lg border border-border-DEFAULT cursor-pointer"
                />
                <input
                  type="text"
                  value={form.primary_color}
                  onChange={(e) => setForm({ ...form, primary_color: e.target.value })}
                  className="w-28 rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary font-mono focus:border-green-500 focus:outline-none"
                />
                <div 
                  className="h-12 flex-1 rounded-lg" 
                  style={{ backgroundColor: form.primary_color }}
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-secondary mb-2">Cor Secundária</label>
              <div className="flex items-center gap-4">
                <input
                  type="color"
                  value={form.secondary_color}
                  onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
                  className="h-12 w-20 rounded-lg border border-border-DEFAULT cursor-pointer"
                />
                <input
                  type="text"
                  value={form.secondary_color}
                  onChange={(e) => setForm({ ...form, secondary_color: e.target.value })}
                  className="w-28 rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm text-tx-primary font-mono focus:border-green-500 focus:outline-none"
                />
                <div 
                  className="h-12 flex-1 rounded-lg" 
                  style={{ backgroundColor: form.secondary_color }}
                />
              </div>
            </div>

            {/* Preview */}
            <div className="mt-6 p-4 rounded-xl bg-surface-page">
              <p className="text-xs text-tx-muted mb-3">Preview</p>
              <div className="flex gap-3">
                <button 
                  className="rounded-lg px-4 py-2 text-sm font-medium text-white"
                  style={{ backgroundColor: form.primary_color }}
                >
                  Botão Primário
                </button>
                <button 
                  className="rounded-lg px-4 py-2 text-sm font-medium text-white"
                  style={{ backgroundColor: form.secondary_color }}
                >
                  Botão Secundário
                </button>
              </div>
            </div>
          </div>
        </div>

        {/* Custom CSS */}
        <div className="rounded-2xl bg-surface-card p-6 shadow-sm lg:col-span-2">
          <div className="flex items-center gap-3 mb-6">
            <div className="rounded-xl bg-green-500/10 p-2.5">
              <SwatchIcon className="h-6 w-6 text-green-500" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">CSS Personalizado</h2>
              <p className="text-sm text-tx-muted">Adicione estilos CSS customizados (avançado)</p>
            </div>
          </div>

          <textarea
            value={form.custom_css}
            onChange={(e) => setForm({ ...form, custom_css: e.target.value })}
            placeholder="/* Adicione seu CSS personalizado aqui */"
            rows={8}
            className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-3 text-sm font-mono text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none"
          />
        </div>
      </div>

      {/* Save Button */}
      <div className="flex justify-end">
        <button
          onClick={() => updateMutation.mutate(form)}
          disabled={updateMutation.isPending}
          className="flex items-center gap-2 rounded-lg bg-green-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
        >
          {updateMutation.isPending ? (
            <>
              <ArrowPathIcon className="h-4 w-4 animate-spin" />
              Salvando...
            </>
          ) : (
            <>
              <CheckCircleIcon className="h-4 w-4" />
              Salvar Alterações
            </>
          )}
        </button>
      </div>
    </AdminPageShell>
  );
}


