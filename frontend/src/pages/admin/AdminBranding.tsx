import { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
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
    onError: () => toast.error('Erro ao salvar'),
  });

  const [form, setForm] = useState<BrandingSettings>({
    platform_name: '',
    logo_url: '',
    favicon_url: '',
    primary_color: '#16a34a',
    secondary_color: '#4F46E5',
    custom_css: '',
  });

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
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
      </div>
    );
  }

  return (
    <AdminPageShell>
      <AdminPageHeader title="Branding" subtitle="Personalize a aparência da plataforma" />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Identity */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h2 className="text-sm font-semibold text-gray-900 mb-4">Identidade</h2>
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Nome da Plataforma</label>
              <input type="text" value={form.platform_name} onChange={(e) => setForm({ ...form, platform_name: e.target.value })} className="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:outline-none" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">URL do Logo</label>
              <div className="flex gap-2 items-center">
                <input type="url" value={form.logo_url} onChange={(e) => setForm({ ...form, logo_url: e.target.value })} placeholder="https://..." className="flex-1 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none" />
                {form.logo_url && <img src={form.logo_url} alt="Logo" className="h-10 w-10 rounded border border-gray-200 object-contain p-1" />}
              </div>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">URL do Favicon</label>
              <div className="flex gap-2 items-center">
                <input type="url" value={form.favicon_url} onChange={(e) => setForm({ ...form, favicon_url: e.target.value })} placeholder="https://..." className="flex-1 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none" />
                {form.favicon_url && <img src={form.favicon_url} alt="Favicon" className="h-8 w-8 rounded border border-gray-200 object-contain p-0.5" />}
              </div>
            </div>
          </div>
        </div>

        {/* Colors */}
        <div className="rounded-lg border border-gray-200 bg-white p-5">
          <h2 className="text-sm font-semibold text-gray-900 mb-4">Cores</h2>
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Cor Primária</label>
              <div className="flex items-center gap-3">
                <input type="color" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="h-10 w-14 cursor-pointer rounded border border-gray-200" />
                <input type="text" value={form.primary_color} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="w-24 rounded-md border border-gray-200 px-3 py-2 text-sm font-mono text-gray-900 focus:border-gray-400 focus:outline-none" />
                <div className="h-10 flex-1 rounded-md" style={{ backgroundColor: form.primary_color }} />
              </div>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Cor Secundária</label>
              <div className="flex items-center gap-3">
                <input type="color" value={form.secondary_color} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })} className="h-10 w-14 cursor-pointer rounded border border-gray-200" />
                <input type="text" value={form.secondary_color} onChange={(e) => setForm({ ...form, secondary_color: e.target.value })} className="w-24 rounded-md border border-gray-200 px-3 py-2 text-sm font-mono text-gray-900 focus:border-gray-400 focus:outline-none" />
                <div className="h-10 flex-1 rounded-md" style={{ backgroundColor: form.secondary_color }} />
              </div>
            </div>
            <div className="rounded-md bg-gray-50 p-3">
              <p className="text-xs text-gray-500 mb-2">Preview</p>
              <div className="flex gap-2">
                <button className="rounded-md px-3 py-1.5 text-sm font-medium text-white" style={{ backgroundColor: form.primary_color }}>Primário</button>
                <button className="rounded-md px-3 py-1.5 text-sm font-medium text-white" style={{ backgroundColor: form.secondary_color }}>Secundário</button>
              </div>
            </div>
          </div>
        </div>

        {/* Custom CSS */}
        <div className="rounded-lg border border-gray-200 bg-white p-5 lg:col-span-2">
          <h2 className="text-sm font-semibold text-gray-900 mb-3">CSS Personalizado</h2>
          <textarea
            value={form.custom_css}
            onChange={(e) => setForm({ ...form, custom_css: e.target.value })}
            placeholder="/* CSS customizado */"
            rows={6}
            className="w-full rounded-md border border-gray-200 bg-white px-3 py-2 text-sm font-mono text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none"
          />
        </div>
      </div>

      <div className="flex justify-end">
        <button
          onClick={() => updateMutation.mutate(form)}
          disabled={updateMutation.isPending}
          className="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
        >
          {updateMutation.isPending ? 'Salvando...' : 'Salvar'}
        </button>
      </div>
    </AdminPageShell>
  );
}
