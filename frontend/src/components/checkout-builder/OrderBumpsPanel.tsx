import { useState, useEffect, useCallback } from 'react';
import { PlusIcon, TrashIcon, PencilIcon, XMarkIcon, GiftIcon } from '@heroicons/react/24/outline';
import { Switch } from '@headlessui/react';
import toast from 'react-hot-toast';
import { orderBumpsService, type OrderBump, type CreateOrderBumpData } from '@/services/api/orderBumpsUpsells.service';

interface OrderBumpsPanelProps {
  checkoutId: string;
  availableProducts: any[];
}

export default function OrderBumpsPanel({ checkoutId, availableProducts }: OrderBumpsPanelProps) {
  const [bumps, setBumps] = useState<OrderBump[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingBump, setEditingBump] = useState<OrderBump | null>(null);
  const [showForm, setShowForm] = useState(false);

  // Form state
  const [formData, setFormData] = useState<CreateOrderBumpData>({
    product_id: '',
    title: '',
    description: '',
    call_to_action: 'Sim, eu quero!',
    price: 0,
    original_price: undefined,
    discount_percent: undefined,
    display_position: 'before_payment',
    is_active: true,
    is_pre_selected: false,
  });

  const loadBumps = useCallback(async () => {
    try {
      const result = await orderBumpsService.list(checkoutId);
      if (result.success) {
        setBumps(result.data);
      }
    } catch {
      // silently fail
    } finally {
      setIsLoading(false);
    }
  }, [checkoutId]);

  useEffect(() => {
    loadBumps();
  }, [loadBumps]);

  const resetForm = () => {
    setFormData({
      product_id: '',
      title: '',
      description: '',
      call_to_action: 'Sim, eu quero!',
      price: 0,
      original_price: undefined,
      discount_percent: undefined,
      display_position: 'before_payment',
      is_active: true,
      is_pre_selected: false,
    });
    setEditingBump(null);
    setShowForm(false);
  };

  const handleProductSelect = (productId: string) => {
    const product = availableProducts.find(p => p.id === productId);
    if (product) {
      setFormData(prev => ({
        ...prev,
        product_id: productId,
        title: prev.title || `Adicione ${product.name}`,
        price: product.price,
        original_price: product.original_price || product.price,
      }));
    }
  };

  const handleSave = async () => {
    if (!formData.product_id) {
      toast.error('Selecione um produto');
      return;
    }
    if (!formData.title) {
      toast.error('Preencha o título');
      return;
    }

    try {
      if (editingBump) {
        const result = await orderBumpsService.update(checkoutId, editingBump.id, formData);
        if (result.success) {
          toast.success('Order Bump atualizado!');
        }
      } else {
        const result = await orderBumpsService.create(checkoutId, formData);
        if (result.success) {
          toast.success('Order Bump criado!');
        }
      }
      resetForm();
      loadBumps();
    } catch {
      toast.error('Erro ao salvar Order Bump');
    }
  };

  const handleEdit = (bump: OrderBump) => {
    setFormData({
      product_id: bump.product_id,
      title: bump.title,
      description: bump.description,
      call_to_action: bump.call_to_action,
      price: bump.price,
      original_price: bump.original_price,
      discount_percent: bump.discount_percent,
      display_position: bump.display_position,
      is_active: bump.is_active,
      is_pre_selected: bump.is_pre_selected,
    });
    setEditingBump(bump);
    setShowForm(true);
  };

  const handleDelete = async (bumpId: string) => {
    try {
      await orderBumpsService.delete(checkoutId, bumpId);
      toast.success('Order Bump removido');
      loadBumps();
    } catch {
      toast.error('Erro ao remover');
    }
  };

  const toggleActive = async (bump: OrderBump) => {
    try {
      await orderBumpsService.update(checkoutId, bump.id, { is_active: !bump.is_active });
      loadBumps();
    } catch {
      toast.error('Erro ao atualizar');
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-green-500 border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-sm font-semibold text-white">Order Bumps</h3>
          <p className="text-xs text-tx-muted">Ofertas adicionais no checkout</p>
        </div>
        {!showForm && (
          <button
            onClick={() => { resetForm(); setShowForm(true); }}
            className="inline-flex items-center gap-1 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700 transition-colors"
          >
            <PlusIcon className="h-3.5 w-3.5" />
            Novo
          </button>
        )}
      </div>

      {/* Form */}
      {showForm && (
        <div className="rounded-lg border border-gray-700 bg-gray-800/50 p-3 space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="text-xs font-semibold text-white">
              {editingBump ? 'Editar Order Bump' : 'Novo Order Bump'}
            </h4>
            <button onClick={resetForm} className="text-gray-400 hover:text-white">
              <XMarkIcon className="h-4 w-4" />
            </button>
          </div>

          {/* Product */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Produto *</label>
            <select
              value={formData.product_id}
              onChange={(e) => handleProductSelect(e.target.value)}
              className="input text-sm w-full"
            >
              <option value="">Selecione...</option>
              {availableProducts.map(p => (
                <option key={p.id} value={p.id}>{p.name} - R$ {(p.price / 100).toFixed(2)}</option>
              ))}
            </select>
          </div>

          {/* Title */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Título *</label>
            <input
              type="text"
              value={formData.title}
              onChange={(e) => setFormData(prev => ({ ...prev, title: e.target.value }))}
              className="input text-sm w-full"
              placeholder="Adicione este produto incrível!"
            />
          </div>

          {/* Description */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Descrição</label>
            <textarea
              value={formData.description || ''}
              onChange={(e) => setFormData(prev => ({ ...prev, description: e.target.value }))}
              className="input text-sm w-full"
              rows={2}
              placeholder="Aproveite esta oferta exclusiva..."
            />
          </div>

          {/* Price */}
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Preço (centavos)</label>
              <input
                type="number"
                value={formData.price}
                onChange={(e) => setFormData(prev => ({ ...prev, price: Number(e.target.value) }))}
                className="input text-sm w-full"
                min={0}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Preço Original</label>
              <input
                type="number"
                value={formData.original_price || ''}
                onChange={(e) => setFormData(prev => ({ ...prev, original_price: e.target.value ? Number(e.target.value) : undefined }))}
                className="input text-sm w-full"
                min={0}
                placeholder="Opcional"
              />
            </div>
          </div>

          {/* Call to Action */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Botão de Ação</label>
            <input
              type="text"
              value={formData.call_to_action || ''}
              onChange={(e) => setFormData(prev => ({ ...prev, call_to_action: e.target.value }))}
              className="input text-sm w-full"
              placeholder="Sim, eu quero!"
            />
          </div>

          {/* Position */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Posição</label>
            <select
              value={formData.display_position}
              onChange={(e) => setFormData(prev => ({ ...prev, display_position: e.target.value as any }))}
              className="input text-sm w-full"
            >
              <option value="before_payment">Antes do pagamento</option>
              <option value="after_product">Depois do produto</option>
              <option value="sidebar">Barra lateral</option>
            </select>
          </div>

          {/* Toggles */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-xs text-tx-muted">Ativo</span>
              <Switch
                checked={formData.is_active ?? true}
                onChange={(checked) => setFormData(prev => ({ ...prev, is_active: checked }))}
                className={`${formData.is_active ? 'bg-green-600' : 'bg-gray-600'} relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
              >
                <span className={`${formData.is_active ? 'translate-x-5' : 'translate-x-1'} inline-block h-3 w-3 transform rounded-full bg-white transition-transform`} />
              </Switch>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-xs text-tx-muted">Pré-selecionado</span>
              <Switch
                checked={formData.is_pre_selected ?? false}
                onChange={(checked) => setFormData(prev => ({ ...prev, is_pre_selected: checked }))}
                className={`${formData.is_pre_selected ? 'bg-green-600' : 'bg-gray-600'} relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
              >
                <span className={`${formData.is_pre_selected ? 'translate-x-5' : 'translate-x-1'} inline-block h-3 w-3 transform rounded-full bg-white transition-transform`} />
              </Switch>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-2 pt-1">
            <button onClick={handleSave} className="flex-1 rounded-lg bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 transition-colors">
              {editingBump ? 'Atualizar' : 'Criar Order Bump'}
            </button>
            <button onClick={resetForm} className="rounded-lg border border-gray-600 px-3 py-2 text-xs text-tx-muted hover:text-white transition-colors">
              Cancelar
            </button>
          </div>
        </div>
      )}

      {/* List */}
      {bumps.length === 0 && !showForm ? (
        <div className="rounded-lg border border-dashed border-gray-700 p-6 text-center">
          <GiftIcon className="mx-auto h-8 w-8 text-gray-600 mb-2" />
          <p className="text-sm text-tx-muted">Nenhum Order Bump</p>
          <p className="text-xs text-gray-600 mt-1">Crie ofertas para aumentar o ticket médio</p>
        </div>
      ) : (
        <div className="space-y-2">
          {bumps.map((bump) => (
            <div
              key={bump.id}
              className={`rounded-lg border p-3 transition-all ${
                bump.is_active
                  ? 'border-green-500/30 bg-green-900/10'
                  : 'border-gray-700 bg-gray-800/30 opacity-60'
              }`}
            >
              <div className="flex items-start justify-between gap-2">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-medium text-white truncate">{bump.title}</p>
                    {bump.is_pre_selected && (
                      <span className="shrink-0 rounded bg-orange-500/20 px-1.5 py-0.5 text-[10px] text-orange-400">
                        pré-selecionado
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-tx-muted mt-0.5">
                    {bump.product?.name} • R$ {(bump.price / 100).toFixed(2)}
                    {bump.original_price && bump.original_price > bump.price && (
                      <span className="line-through ml-1 text-gray-500">R$ {(bump.original_price / 100).toFixed(2)}</span>
                    )}
                  </p>
                  {bump.conversions_count > 0 && (
                    <p className="text-[10px] text-green-400 mt-1">
                      {bump.conversions_count} conversões • {bump.conversion_rate.toFixed(1)}% taxa
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-1">
                  <Switch
                    checked={bump.is_active}
                    onChange={() => toggleActive(bump)}
                    className={`${bump.is_active ? 'bg-green-600' : 'bg-gray-600'} relative inline-flex h-4 w-7 items-center rounded-full transition-colors`}
                  >
                    <span className={`${bump.is_active ? 'translate-x-4' : 'translate-x-0.5'} inline-block h-2.5 w-2.5 transform rounded-full bg-white transition-transform`} />
                  </Switch>
                  <button onClick={() => handleEdit(bump)} className="p-1 text-gray-400 hover:text-white">
                    <PencilIcon className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={() => handleDelete(bump.id)} className="p-1 text-gray-400 hover:text-red-400">
                    <TrashIcon className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Info */}
      <div className="rounded-lg bg-blue-900/20 border border-blue-800 p-3">
        <p className="text-xs text-blue-300">
          💡 Order Bumps aparecem na página de checkout como ofertas adicionais. O comprador pode adicioná-los ao pedido com um clique.
        </p>
      </div>
    </div>
  );
}


