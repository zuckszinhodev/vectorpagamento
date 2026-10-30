import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import { CubeIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';

interface AdminProduct {
  id: string;
  name: string;
  slug: string;
  type: string | null;
  price: number;
  image_url: string | null;
  is_active: boolean;
  blocked_at: string | null;
  block_reason: string | null;
  is_showcase: boolean;
  showcase_status: string | null;
  seller_id: string | null;
  seller_name: string | null;
  seller_email: string | null;
  sales_count: number;
  sales_amount: number;
  chargebacks: number;
  created_at: string | null;
}

interface ProductDetail extends AdminProduct {
  description: string | null;
  original_price: number | null;
  category: string | null;
  stock_enabled: boolean;
  stock_quantity: number | null;
  affiliate_enabled: boolean;
  commission_percent: number;
  checkouts: Array<{ id: string; name: string | null; slug: string }>;
  recent_charges: Array<{ id: string; amount: number; status: string; payment_method: string; created_at: string }>;
}

interface ProductsResponse {
  data: AdminProduct[];
  meta: { current_page: number; last_page: number; total: number };
  stats: { total: number; active: number; inactive: number; blocked: number; showcase_pending: number };
  sellers: Array<{ account_id: string; name: string; email: string | null; products: number }>;
}

const STATUS_CHIPS = [
  { value: '', label: 'Todos', stat: 'total' },
  { value: 'active', label: 'Ativos', stat: 'active' },
  { value: 'inactive', label: 'Inativos', stat: 'inactive' },
  { value: 'blocked', label: 'Bloqueados', stat: 'blocked' },
  { value: 'showcase_pending', label: 'Vitrine pendente', stat: 'showcase_pending' },
] as const;

const TYPE_LABELS: Record<string, string> = { digital: 'Digital', physical: 'Físico', service: 'Serviço' };

const cardClass = 'rounded-2xl border border-white/[0.06] bg-[#0b0b0d]';
const fieldClass = 'h-10 rounded-lg border border-white/10 bg-[#0e0e11] px-3 text-sm text-white focus:border-red-500/60 focus:outline-none';
const labelClass = 'mb-1 block text-xs font-medium text-gray-400';

function StatusBadge({ product }: { product: AdminProduct }) {
  const [label, className] = product.blocked_at
    ? ['Bloqueado', 'bg-red-500/15 text-red-400']
    : product.is_active
      ? ['Ativo', 'bg-emerald-500/10 text-emerald-400']
      : ['Inativo', 'bg-white/5 text-gray-400'];

  return <span className={clsx('rounded-full px-2.5 py-0.5 text-xs font-medium', className)}>{label}</span>;
}

export default function AdminProducts() {
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [sellerId, setSellerId] = useState('');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  const { data, isLoading, isError } = useQuery<ProductsResponse>({
    queryKey: ['admin-products', search, status, type, sellerId, page],
    queryFn: async () => {
      const params = {
        page,
        per_page: 20,
        search: search || undefined,
        status: status || undefined,
        type: type || undefined,
        account_id: sellerId || undefined,
      };
      return (await api.get('/admin/products/manage', { params })).data;
    },
    placeholderData: (previous) => previous,
  });

  const products = data?.data ?? [];
  const lastPage = data?.meta?.last_page ?? 1;

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Produtos"
        subtitle="Todos os produtos criados pelos sellers"
        icon={CubeIcon}
      />

      <div className="flex gap-2 overflow-x-auto pb-1">
        {STATUS_CHIPS.map((chip) => (
          <button
            key={chip.value}
            type="button"
            aria-pressed={status === chip.value}
            onClick={() => { setStatus(chip.value); setPage(1); }}
            className={clsx(
              'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
              status === chip.value ? 'border-red-500 bg-red-500/10 text-white' : 'border-white/10 text-gray-400 hover:text-white'
            )}
          >
            {chip.label}
            <span className="ml-1.5 tabular-nums text-gray-500">{(data?.stats?.[chip.stat] ?? 0).toLocaleString('pt-BR')}</span>
          </button>
        ))}
      </div>

      <div className="flex flex-col gap-2 sm:flex-row">
        <div className="relative min-w-0 flex-1">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" />
          <input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Buscar por produto, seller ou e-mail"
            aria-label="Buscar produtos"
            className={clsx(fieldClass, 'w-full pl-9')}
          />
        </div>
        <select value={sellerId} onChange={(e) => { setSellerId(e.target.value); setPage(1); }} aria-label="Seller" className={clsx(fieldClass, 'sm:max-w-[260px]')}>
          <option value="">Todos os sellers</option>
          {(data?.sellers ?? []).map((seller) => (
            <option key={seller.account_id} value={seller.account_id}>
              {seller.name}{seller.email ? ` · ${seller.email}` : ''} ({seller.products})
            </option>
          ))}
        </select>
        <select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} aria-label="Tipo" className={fieldClass}>
          <option value="">Todos os tipos</option>
          {Object.entries(TYPE_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
      </div>

      <div className={clsx(cardClass, 'overflow-x-auto')}>
        <table className="w-full min-w-[820px] text-sm">
          <thead>
            <tr className="border-b border-white/[0.06] text-left text-[11px] uppercase tracking-wider text-gray-500">
              <th className="px-4 py-3 font-semibold">Produto</th>
              <th className="px-4 py-3 font-semibold">Seller</th>
              <th className="px-4 py-3 text-right font-semibold">Preço</th>
              <th className="px-4 py-3 text-right font-semibold">Vendas</th>
              <th className="px-4 py-3 text-right font-semibold">Volume</th>
              <th className="px-4 py-3 font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr
                key={product.id}
                onClick={() => setSelectedId(product.id)}
                className="cursor-pointer border-b border-white/[0.04] text-gray-300 transition-colors last:border-0 hover:bg-white/[0.03]"
              >
                <td className="px-4 py-3">
                  <div className="flex items-center gap-3">
                    {product.image_url ? (
                      <img src={product.image_url} alt="" className="h-9 w-9 shrink-0 rounded-lg object-cover" />
                    ) : (
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/5">
                        <CubeIcon className="h-4 w-4 text-gray-500" />
                      </span>
                    )}
                    <div className="min-w-0">
                      <p className="max-w-[240px] truncate font-medium text-white">{product.name}</p>
                      <p className="text-xs text-gray-500">{TYPE_LABELS[product.type ?? ''] ?? product.type ?? '—'}</p>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <p className="max-w-[200px] truncate">{product.seller_name || '—'}</p>
                  <p className="max-w-[200px] truncate text-xs text-gray-500">{product.seller_email}</p>
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{formatCurrency(product.price)}</td>
                <td className="px-4 py-3 text-right tabular-nums">{product.sales_count.toLocaleString('pt-BR')}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums">{formatCurrency(product.sales_amount)}</td>
                <td className="px-4 py-3"><StatusBadge product={product} /></td>
              </tr>
            ))}
            {products.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-12 text-center text-gray-500">
                  {isLoading ? 'Carregando...' : isError ? 'Não foi possível carregar os produtos.' : 'Nenhum produto encontrado.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {lastPage > 1 && (
        <div className="flex items-center justify-end gap-3 text-sm text-gray-400">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Anterior</button>
          <span>Página {page} de {lastPage}</span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage(page + 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Próxima</button>
        </div>
      )}

      {selectedId && <ProductDialog productId={selectedId} onClose={() => setSelectedId(null)} />}
    </AdminPageShell>
  );
}

function ProductEditForm({ product, onSaved }: { product: ProductDetail; onSaved: () => void }) {
  const [form, setForm] = useState({
    name: product.name,
    description: product.description ?? '',
    type: product.type ?? 'digital',
    price: (product.price / 100).toFixed(2),
    original_price: product.original_price ? (product.original_price / 100).toFixed(2) : '',
    category: product.category ?? '',
    stock_enabled: product.stock_enabled,
    stock_quantity: product.stock_quantity === null ? '' : String(product.stock_quantity),
    affiliate_enabled: product.affiliate_enabled,
    commission_percent: String(product.commission_percent),
  });

  const update = (patch: Partial<typeof form>) => setForm((current) => ({ ...current, ...patch }));

  const saveMutation = useMutation({
    mutationFn: () =>
      api.put(`/admin/products/manage/${product.id}`, {
        name: form.name.trim(),
        description: form.description,
        type: form.type,
        price: Math.round(Number(form.price) * 100),
        original_price: form.original_price ? Math.round(Number(form.original_price) * 100) : null,
        category: form.category.trim() || null,
        stock_enabled: form.stock_enabled,
        stock_quantity: form.stock_enabled && form.stock_quantity !== '' ? Number(form.stock_quantity) : null,
        affiliate_enabled: form.affiliate_enabled,
        commission_percent: form.affiliate_enabled ? Number(form.commission_percent) : product.commission_percent,
      }),
    onSuccess: () => {
      toast.success('Produto atualizado');
      onSaved();
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Não foi possível salvar o produto');
    },
  });

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        saveMutation.mutate();
      }}
    >
      <div>
        <label htmlFor="product-name" className={labelClass}>Nome</label>
        <input id="product-name" required maxLength={255} value={form.name} onChange={(e) => update({ name: e.target.value })} className={clsx(fieldClass, 'w-full')} />
      </div>

      <div>
        <label htmlFor="product-description" className={labelClass}>Descrição</label>
        <textarea
          id="product-description"
          rows={4}
          value={form.description}
          onChange={(e) => update({ description: e.target.value })}
          className="w-full rounded-lg border border-white/10 bg-[#0e0e11] p-3 text-sm text-white focus:border-red-500/60 focus:outline-none"
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <div>
          <label htmlFor="product-price" className={labelClass}>Preço (R$)</label>
          <input id="product-price" type="number" required min="1" step="0.01" value={form.price} onChange={(e) => update({ price: e.target.value })} className={clsx(fieldClass, 'w-full')} />
        </div>
        <div>
          <label htmlFor="product-original-price" className={labelClass}>Preço original (R$)</label>
          <input id="product-original-price" type="number" min="0" step="0.01" value={form.original_price} onChange={(e) => update({ original_price: e.target.value })} className={clsx(fieldClass, 'w-full')} />
        </div>
        <div>
          <label htmlFor="product-type" className={labelClass}>Tipo</label>
          <select id="product-type" value={form.type} onChange={(e) => update({ type: e.target.value })} className={clsx(fieldClass, 'w-full')}>
            {Object.entries(TYPE_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </div>
      </div>

      <div>
        <label htmlFor="product-category" className={labelClass}>Categoria</label>
        <input id="product-category" maxLength={100} value={form.category} onChange={(e) => update({ category: e.target.value })} className={clsx(fieldClass, 'w-full')} />
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <div className="rounded-lg border border-white/[0.06] p-3">
          <label className="flex items-center gap-2 text-sm text-gray-200">
            <input type="checkbox" checked={form.stock_enabled} onChange={(e) => update({ stock_enabled: e.target.checked })} className="h-4 w-4" />
            Controlar estoque
          </label>
          {form.stock_enabled && (
            <div className="mt-2">
              <label htmlFor="product-stock" className={labelClass}>Quantidade</label>
              <input id="product-stock" type="number" min="0" step="1" value={form.stock_quantity} onChange={(e) => update({ stock_quantity: e.target.value })} className={clsx(fieldClass, 'w-full')} />
            </div>
          )}
        </div>
        <div className="rounded-lg border border-white/[0.06] p-3">
          <label className="flex items-center gap-2 text-sm text-gray-200">
            <input type="checkbox" checked={form.affiliate_enabled} onChange={(e) => update({ affiliate_enabled: e.target.checked })} className="h-4 w-4" />
            Programa de afiliados
          </label>
          {form.affiliate_enabled && (
            <div className="mt-2">
              <label htmlFor="product-commission" className={labelClass}>Comissão (%)</label>
              <input id="product-commission" type="number" min="0" max="100" step="0.01" value={form.commission_percent} onChange={(e) => update({ commission_percent: e.target.value })} className={clsx(fieldClass, 'w-full')} />
            </div>
          )}
        </div>
      </div>

      <div className="flex items-center justify-between gap-3">
        <p className="text-xs text-gray-500">O preço novo vale para as próximas vendas. O link do checkout não muda.</p>
        <button
          type="submit"
          disabled={saveMutation.isPending}
          className="h-10 shrink-0 rounded-lg bg-red-600 px-5 text-sm font-semibold text-white transition-colors hover:bg-red-500 disabled:opacity-50"
        >
          {saveMutation.isPending ? 'Salvando...' : 'Salvar produto'}
        </button>
      </div>
    </form>
  );
}

function ProductDialog({ productId, onClose }: { productId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [tab, setTab] = useState<'overview' | 'edit'>('overview');

  const { data: product, isLoading } = useQuery<ProductDetail>({
    queryKey: ['admin-product', productId],
    queryFn: async () => (await api.get(`/admin/products/manage/${productId}`)).data?.data,
  });

  const actionMutation = useMutation({
    mutationFn: async (action: 'block' | 'unblock' | 'delete' | 'approve' | 'reject') => {
      if (action === 'delete') return api.delete(`/admin/products/manage/${productId}`, { data: { reason: reason.trim() } });
      if (action === 'approve') return api.post(`/admin/showcase/products/${productId}/approve`);
      if (action === 'reject') return api.post(`/admin/showcase/products/${productId}/reject`, { reason: reason.trim() });
      return api.post(`/admin/products/manage/${productId}/${action}`, action === 'block' ? { reason: reason.trim() } : {});
    },
    onSuccess: (_response, action) => {
      toast.success({ block: 'Produto bloqueado', unblock: 'Produto desbloqueado', delete: 'Produto excluído', approve: 'Aprovado na vitrine', reject: 'Rejeitado na vitrine' }[action]);
      setReason('');
      queryClient.invalidateQueries({ queryKey: ['admin-products'] });
      queryClient.invalidateQueries({ queryKey: ['admin-product', productId] });
      if (action === 'delete') onClose();
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message || 'Não foi possível concluir a ação');
    },
  });

  const needsReason = reason.trim().length < 3;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Detalhes do produto"
        className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-white/10 bg-[#0e0e11] p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-red-400">Produto</p>
            <p className="mt-1 break-words text-xl font-bold text-white">{product?.name ?? (isLoading ? 'Carregando...' : '—')}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Fechar" className="rounded-lg p-1.5 text-gray-400 hover:bg-white/5 hover:text-white">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        {product && (
          <div className="mt-4 flex gap-1 border-b border-white/[0.06]">
            {[
              { id: 'overview' as const, label: 'Visão geral' },
              { id: 'edit' as const, label: 'Editar produto' },
            ].map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => setTab(item.id)}
                className={clsx(
                  '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                  tab === item.id ? 'border-red-500 text-white' : 'border-transparent text-gray-400 hover:text-white'
                )}
              >
                {item.label}
              </button>
            ))}
          </div>
        )}

        {product && tab === 'edit' && (
          <div className="mt-5">
            <ProductEditForm
              key={product.id}
              product={product}
              onSaved={() => {
                queryClient.invalidateQueries({ queryKey: ['admin-products'] });
                queryClient.invalidateQueries({ queryKey: ['admin-product', productId] });
                setTab('overview');
              }}
            />
          </div>
        )}

        {product && tab === 'overview' && (
          <>
            <div className="mt-3"><StatusBadge product={product} /></div>

            {product.blocked_at && (
              <p className="mt-4 rounded-lg border border-red-500/30 bg-red-500/[0.06] p-3 text-sm text-red-300">
                Bloqueado em {formatDate(product.blocked_at)}: {product.block_reason}
              </p>
            )}

            <dl className="mt-5 space-y-3 text-sm">
              {[
                ['Preço', formatCurrency(product.price)],
                ['Vendas aprovadas', product.sales_count.toLocaleString('pt-BR')],
                ['Volume', formatCurrency(product.sales_amount)],
                ['Chargebacks', product.chargebacks.toLocaleString('pt-BR')],
                ['Criado em', product.created_at ? formatDate(product.created_at) : '—'],
              ].map(([label, value]) => (
                <div key={label} className="flex justify-between gap-4">
                  <dt className="text-gray-500">{label}</dt>
                  <dd className="text-right text-gray-200">{value}</dd>
                </div>
              ))}
              <div className="flex justify-between gap-4">
                <dt className="text-gray-500">Seller</dt>
                <dd className="min-w-0 truncate text-right">
                  {product.seller_id ? (
                    <Link to={`/admin/sellers/${product.seller_id}`} className="text-red-400 hover:text-red-300">{product.seller_name}</Link>
                  ) : (
                    <span className="text-gray-200">{product.seller_name || '—'}</span>
                  )}
                </dd>
              </div>
            </dl>

            {product.description && (
              <p className="mt-4 whitespace-pre-line text-sm text-gray-400">{product.description.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()}</p>
            )}

            <h3 className="mt-6 text-sm font-semibold text-white">Checkouts</h3>
            <ul className="mt-2 space-y-1 text-sm text-gray-300">
              {product.checkouts.map((checkout) => (
                <li key={checkout.id} className="truncate">{checkout.name || checkout.slug}</li>
              ))}
              {product.checkouts.length === 0 && <li className="text-gray-500">Nenhum checkout vinculado.</li>}
            </ul>

            <h3 className="mt-6 text-sm font-semibold text-white">Últimas cobranças</h3>
            <ul className="mt-2 space-y-1.5 text-sm">
              {product.recent_charges.map((charge) => (
                <li key={charge.id} className="flex justify-between gap-3 text-gray-300">
                  <span className="text-gray-500">{formatDate(charge.created_at)}</span>
                  <span className="tabular-nums">{formatCurrency(charge.amount)} · {charge.status}</span>
                </li>
              ))}
              {product.recent_charges.length === 0 && <li className="text-gray-500">Sem cobranças.</li>}
            </ul>

            <h3 className="mt-6 text-sm font-semibold text-white">Gerenciar</h3>
            <label htmlFor="product-action-reason" className="mt-2 block text-xs text-gray-400">Motivo (obrigatório para bloquear e excluir)</label>
            <textarea
              id="product-action-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              maxLength={500}
              rows={2}
              className="mt-1 w-full rounded-lg border border-white/10 bg-[#0b0b0d] p-2 text-sm text-white focus:border-red-500/60 focus:outline-none"
            />
            <div className="mt-2 flex flex-wrap gap-2">
              {product.blocked_at ? (
                <button
                  type="button"
                  disabled={actionMutation.isPending}
                  onClick={() => actionMutation.mutate('unblock')}
                  className="rounded-lg border border-white/10 px-3 py-2 text-sm font-medium text-gray-200 hover:border-emerald-500/50 disabled:opacity-50"
                >
                  Desbloquear
                </button>
              ) : (
                <button
                  type="button"
                  disabled={actionMutation.isPending || needsReason}
                  onClick={() => actionMutation.mutate('block')}
                  className="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500 disabled:opacity-50"
                >
                  Bloquear vendas
                </button>
              )}
              {product.is_showcase && product.showcase_status === 'pending' && (
                <>
                  <button
                    type="button"
                    disabled={actionMutation.isPending}
                    onClick={() => actionMutation.mutate('approve')}
                    className="rounded-lg border border-white/10 px-3 py-2 text-sm font-medium text-gray-200 hover:border-emerald-500/50 disabled:opacity-50"
                  >
                    Aprovar na vitrine
                  </button>
                  <button
                    type="button"
                    disabled={actionMutation.isPending}
                    onClick={() => actionMutation.mutate('reject')}
                    className="rounded-lg border border-white/10 px-3 py-2 text-sm font-medium text-gray-200 hover:border-red-500/50 disabled:opacity-50"
                  >
                    Rejeitar na vitrine
                  </button>
                </>
              )}
              <button
                type="button"
                disabled={actionMutation.isPending || needsReason}
                onClick={() => {
                  if (window.confirm(`Excluir "${product.name}" e os checkouts dele? O seller deixa de ver o produto.`)) {
                    actionMutation.mutate('delete');
                  }
                }}
                className="rounded-lg border border-red-500/40 px-3 py-2 text-sm font-medium text-red-400 hover:bg-red-500/10 disabled:opacity-50"
              >
                Excluir
              </button>
            </div>
            <p className="mt-2 text-xs text-gray-500">Bloquear tira o produto de venda e o seller não consegue reativar.</p>
          </>
        )}
      </div>
    </div>
  );
}
