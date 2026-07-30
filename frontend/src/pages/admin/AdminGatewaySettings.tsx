import { useEffect, useState } from 'react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { AdminPageShell, AdminPageHeader } from '@/components/admin/AdminPageShell';
import { useThemeStore } from '@/stores/themeStore';
import { BuildingStorefrontIcon, MagnifyingGlassIcon, SwatchIcon } from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

export default function AdminGatewaySettings() {
  const { setPlatformName, setPlatformDescription, setPrimaryColor, setLogoUrl: applyLogoUrl, platformName } = useThemeStore();

  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [primaryColor, setPrimaryColorLocal] = useState('#16a34a');
  const [logoUrl, setLogoUrl] = useState('');
  const [supportEmail, setSupportEmail] = useState('');
  const [seoTitle, setSeoTitle] = useState('');
  const [seoDescription, setSeoDescription] = useState('');
  const [seoKeywords, setSeoKeywords] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-system-config'],
    queryFn: async () => (await api.get('/admin/system-config')).data?.data,
  });

  useEffect(() => {
    const a = data?.appearance;
    if (!a) return;
    setName(a.platform_name || '');
    setDescription(a.platform_description || '');
    setPrimaryColorLocal(a.primary_color || '#16a34a');
    setLogoUrl(a.logo_url || '');
    setSupportEmail(a.support_email || '');
    setSeoTitle(a.seo_title || '');
    setSeoDescription(a.seo_description || '');
    setSeoKeywords(a.seo_keywords || '');
  }, [data]);

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        platform_name: name.trim(),
        platform_description: description,
        primary_color: primaryColor,
        logo_url: logoUrl,
        support_email: supportEmail,
        seo_title: seoTitle,
        seo_description: seoDescription,
        seo_keywords: seoKeywords,
      };
      return (await api.put('/admin/system-config', payload)).data;
    },
    onSuccess: () => {
      // Reflect immediately across the app
      if (name.trim()) setPlatformName(name.trim());
      setPlatformDescription(description);
      applyLogoUrl(logoUrl || '');
      if (primaryColor) setPrimaryColor(primaryColor);
      const title = seoTitle || name.trim();
      if (title) document.title = title;
      toast.success('Configurações salvas — a marca foi atualizada em todo o gateway');
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'Erro ao salvar configurações'),
  });

  const uploadLogo = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData();
      fd.append('logo', file);
      return (await api.post('/admin/system-config/logo', fd, { headers: { 'Content-Type': undefined } })).data;
    },
    onSuccess: (data: any) => {
      const url = data?.data?.logo_url;
      if (url) {
        setLogoUrl(url);
        applyLogoUrl(url);
        toast.success('Logo enviada e aplicada');
      }
    },
    onError: (e: any) => toast.error(e?.response?.data?.message || 'Erro ao enviar logo'),
  });

  const input = 'w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none';
  const labelCls = 'block text-xs font-medium text-gray-500 mb-1';
  const card = 'rounded-xl border border-gray-200 bg-white p-5 shadow-sm';
  const sectionTitle = 'mb-4 flex items-center gap-2 border-b border-gray-100 pb-3 text-sm font-semibold text-gray-900';

  if (isLoading) {
    return (
      <AdminPageShell>
        <div className="flex items-center justify-center py-24">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
        </div>
      </AdminPageShell>
    );
  }

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Configurações do Gateway"
        subtitle="Nome, identidade e SEO da plataforma. Ao salvar, a marca muda em todo o sistema."
        actions={
          <button
            onClick={() => save.mutate()}
            disabled={save.isPending || !name.trim()}
            className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-50"
          >
            {save.isPending ? 'Salvando...' : 'Salvar alterações'}
          </button>
        }
      />

      <div className="grid gap-6 lg:grid-cols-2">
        {/* Identity */}
        <div className={card}>
          <h3 className={sectionTitle}><BuildingStorefrontIcon className="h-4 w-4 text-emerald-600" /> Identidade</h3>
          <div className="space-y-4">
            <div>
              <label className={labelCls}>Nome da plataforma</label>
              <input className={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex: Minha Pay" />
              <p className="mt-1 text-xs text-gray-400">Substitui "Vector" em todo o painel, títulos e telas.</p>
            </div>
            <div>
              <label className={labelCls}>Descrição</label>
              <input className={input} value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Gateway de pagamentos" />
            </div>
            <div>
              <label className={labelCls}>E-mail de suporte</label>
              <input className={input} value={supportEmail} onChange={(e) => setSupportEmail(e.target.value)} placeholder="suporte@seudominio.com" />
            </div>
          </div>
        </div>

        {/* Appearance */}
        <div className={card}>
          <h3 className={sectionTitle}><SwatchIcon className="h-4 w-4 text-amber-600" /> Aparência</h3>
          <div className="space-y-4">
            <div>
              <label className={labelCls}>Cor primária</label>
              <div className="flex items-center gap-3">
                <input type="color" value={primaryColor} onChange={(e) => setPrimaryColorLocal(e.target.value)} className="h-10 w-14 cursor-pointer rounded-lg border border-gray-200" />
                <input className={input} value={primaryColor} onChange={(e) => setPrimaryColorLocal(e.target.value)} placeholder="#16a34a" />
              </div>
            </div>
            <div>
              <label className={labelCls}>Logo</label>
              {logoUrl ? (
                <div className="mb-2 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-2">
                  <img src={logoUrl} alt="logo" className="h-10 w-auto object-contain" />
                  <button type="button" onClick={() => { setLogoUrl(''); applyLogoUrl(''); }} className="text-xs text-red-600 hover:underline">Remover</button>
                </div>
              ) : null}
              <div className="flex flex-wrap items-center gap-2">
                <label className="cursor-pointer rounded-lg bg-gray-900 px-3 py-2 text-xs font-medium text-white hover:bg-gray-800">
                  {uploadLogo.isPending ? 'Enviando...' : 'Enviar imagem'}
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif"
                    className="hidden"
                    onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadLogo.mutate(f); e.currentTarget.value = ''; }}
                  />
                </label>
                <span className="text-xs text-gray-400">PNG, JPG, WEBP ou SVG · até 2MB</span>
              </div>
              <input className={`${input} mt-2`} value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} placeholder="ou cole uma URL do logo" />
            </div>
          </div>
        </div>

        {/* SEO */}
        <div className={`${card} lg:col-span-2`}>
          <h3 className={sectionTitle}><MagnifyingGlassIcon className="h-4 w-4 text-blue-600" /> SEO</h3>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className={labelCls}>Título (SEO)</label>
              <input className={input} value={seoTitle} onChange={(e) => setSeoTitle(e.target.value)} placeholder={name || 'Título da aba do navegador'} />
              <p className="mt-1 text-xs text-gray-400">Se vazio, usa o nome da plataforma.</p>
            </div>
            <div>
              <label className={labelCls}>Palavras-chave</label>
              <input className={input} value={seoKeywords} onChange={(e) => setSeoKeywords(e.target.value)} placeholder="pagamentos, pix, gateway" />
            </div>
            <div className="sm:col-span-2">
              <label className={labelCls}>Descrição (meta description)</label>
              <textarea className={`${input} h-20 resize-none`} value={seoDescription} onChange={(e) => setSeoDescription(e.target.value)} placeholder="Descrição exibida nos resultados de busca" />
            </div>
          </div>
        </div>
      </div>

      <div className="mt-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800">
        Prévia: o painel exibirá <span className="font-semibold">{name || platformName}</span> no lugar de "Vector" após salvar e recarregar.
      </div>
    </AdminPageShell>
  );
}
