import { useState, useEffect, useCallback } from 'react';
import { PlusIcon, TrashIcon, PencilIcon, XMarkIcon, BoltIcon, ArrowDownIcon } from '@heroicons/react/24/outline';
import { Switch } from '@headlessui/react';
import toast from 'react-hot-toast';
import { upsellsService, type UpsellOffer, type CreateUpsellData } from '@/services/api/orderBumpsUpsells.service';

interface UpsellsPanelProps {
  checkoutId: string;
  availableProducts: any[];
}

export default function UpsellsPanel({ checkoutId, availableProducts }: UpsellsPanelProps) {
  const [upsells, setUpsells] = useState<UpsellOffer[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingUpsell, setEditingUpsell] = useState<UpsellOffer | null>(null);
  const [showForm, setShowForm] = useState(false);

  const [formData, setFormData] = useState<CreateUpsellData>({
    product_id: '',
    title: '',
    description: '',
    headline: 'Espere! Temos uma oferta especial para você',
    call_to_action: 'Sim, eu quero esta oferta!',
    decline_text: 'Não, obrigado',
    price: 0,
    original_price: undefined,
    discount_percent: undefined,
    type: 'upsell',
    next_upsell_id: undefined,
    downsell_id: undefined,
    is_active: true,
    one_click_purchase: true,
    timer_seconds: 300,
    benefits: [],
  });

  const [benefitInput, setBenefitInput] = useState('');

  const loadUpsells = useCallback(async () => {
    try {
      const result = await upsellsService.list(checkoutId);
      if (result.success) {
        setUpsells(result.data);
      }
    } catch {
      // silently fail
    } finally {
      setIsLoading(false);
    }
  }, [checkoutId]);

  useEffect(() => {
    loadUpsells();
  }, [loadUpsells]);

  const resetForm = () => {
    setFormData({
      product_id: '',
      title: '',
      description: '',
      headline: 'Espere! Temos uma oferta especial para você',
      call_to_action: 'Sim, eu quero esta oferta!',
      decline_text: 'Não, obrigado',
      price: 0,
      original_price: undefined,
      discount_percent: undefined,
      type: 'upsell',
      next_upsell_id: undefined,
      downsell_id: undefined,
      is_active: true,
      one_click_purchase: true,
      timer_seconds: 300,
      benefits: [],
    });
    setBenefitInput('');
    setEditingUpsell(null);
    setShowForm(false);
  };

  const handleProductSelect = (productId: string) => {
    const product = availableProducts.find(p => p.id === productId);
    if (product) {
      setFormData(prev => ({
        ...prev,
        product_id: productId,
        title: prev.title || product.name,
        price: product.price,
        original_price: product.original_price || product.price,
      }));
    }
  };

  const addBenefit = () => {
    const text = benefitInput.trim();
    if (!text) return;
    setFormData(prev => ({
      ...prev,
      benefits: [...(prev.benefits || []), text],
    }));
    setBenefitInput('');
  };

  const removeBenefit = (index: number) => {
    setFormData(prev => ({
      ...prev,
      benefits: (prev.benefits || []).filter((_, i) => i !== index),
    }));
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
      if (editingUpsell) {
        const result = await upsellsService.update(checkoutId, editingUpsell.id, formData);
        if (result.success) {
          toast.success('Upsell atualizado!');
        }
      } else {
        const result = await upsellsService.create(checkoutId, formData);
        if (result.success) {
          toast.success('Upsell criado!');
        }
      }
      resetForm();
      loadUpsells();
    } catch {
      toast.error('Erro ao salvar Upsell');
    }
  };

  const handleEdit = (upsell: UpsellOffer) => {
    setFormData({
      product_id: upsell.product_id,
      title: upsell.title,
      description: upsell.description,
      headline: upsell.headline,
      call_to_action: upsell.call_to_action,
      decline_text: upsell.decline_text,
      price: upsell.price,
      original_price: upsell.original_price,
      discount_percent: upsell.discount_percent,
      type: upsell.type,
      next_upsell_id: upsell.next_upsell_id,
      downsell_id: upsell.downsell_id,
      is_active: upsell.is_active,
      one_click_purchase: upsell.one_click_purchase,
      timer_seconds: upsell.timer_seconds,
      benefits: upsell.benefits || [],
    });
    setEditingUpsell(upsell);
    setShowForm(true);
  };

  const handleDelete = async (upsellId: string) => {
    try {
      await upsellsService.delete(checkoutId, upsellId);
      toast.success('Upsell removido');
      loadUpsells();
    } catch {
      toast.error('Erro ao remover');
    }
  };

  const toggleActive = async (upsell: UpsellOffer) => {
    try {
      await upsellsService.update(checkoutId, upsell.id, { is_active: !upsell.is_active });
      loadUpsells();
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
          <h3 className="text-sm font-semibold text-white">Upsells / Downsells</h3>
          <p className="text-xs text-tx-muted">Ofertas pós-compra</p>
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
        <div className="rounded-lg border border-gray-700 bg-gray-800/50 p-3 space-y-3 max-h-[60vh] overflow-y-auto">
          <div className="flex items-center justify-between sticky top-0 bg-gray-800/90 -mx-3 -mt-3 px-3 pt-3 pb-2 z-10">
            <h4 className="text-xs font-semibold text-white">
              {editingUpsell ? 'Editar Upsell' : 'Novo Upsell'}
            </h4>
            <button onClick={resetForm} className="text-gray-400 hover:text-white">
              <XMarkIcon className="h-4 w-4" />
            </button>
          </div>

          {/* Type */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Tipo</label>
            <div className="flex gap-2">
              <button
                onClick={() => setFormData(prev => ({ ...prev, type: 'upsell' }))}
                className={`flex-1 rounded-lg border px-3 py-2 text-xs font-medium transition-colors ${
                  formData.type === 'upsell'
                    ? 'border-green-500 bg-green-500/20 text-green-300'
                    : 'border-gray-600 text-gray-400 hover:text-white'
                }`}
              >
                <BoltIcon className="h-4 w-4 mx-auto mb-1" />
                Upsell
              </button>
              <button
                onClick={() => setFormData(prev => ({ ...prev, type: 'downsell' }))}
                className={`flex-1 rounded-lg border px-3 py-2 text-xs font-medium transition-colors ${
                  formData.type === 'downsell'
                    ? 'border-orange-500 bg-orange-500/20 text-orange-300'
                    : 'border-gray-600 text-gray-400 hover:text-white'
                }`}
              >
                <ArrowDownIcon className="h-4 w-4 mx-auto mb-1" />
                Downsell
              </button>
            </div>
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
              placeholder="Nome do produto ou oferta"
            />
          </div>

          {/* Headline */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Headline</label>
            <input
              type="text"
              value={formData.headline || ''}
              onChange={(e) => setFormData(prev => ({ ...prev, headline: e.target.value }))}
              className="input text-sm w-full"
              placeholder="Espere! Temos uma oferta especial..."
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
              placeholder="Descreva o valor desta oferta..."
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

          {/* CTA & Decline */}
          <div className="grid grid-cols-1 gap-2">
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Botão Aceitar</label>
              <input
                type="text"
                value={formData.call_to_action || ''}
                onChange={(e) => setFormData(prev => ({ ...prev, call_to_action: e.target.value }))}
                className="input text-sm w-full"
                placeholder="Sim, eu quero esta oferta!"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Botão Recusar</label>
              <input
                type="text"
                value={formData.decline_text || ''}
                onChange={(e) => setFormData(prev => ({ ...prev, decline_text: e.target.value }))}
                className="input text-sm w-full"
                placeholder="Não, obrigado"
              />
            </div>
          </div>

          {/* Benefits */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Benefícios</label>
            <div className="flex gap-1">
              <input
                type="text"
                value={benefitInput}
                onChange={(e) => setBenefitInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addBenefit(); } }}
                className="input text-sm flex-1"
                placeholder="Adicione um benefício..."
              />
              <button onClick={addBenefit} className="rounded-lg bg-gray-700 px-2 text-xs text-white hover:bg-gray-600">
                +
              </button>
            </div>
            {(formData.benefits || []).length > 0 && (
              <div className="mt-2 space-y-1">
                {(formData.benefits || []).map((b, i) => (
                  <div key={i} className="flex items-center justify-between rounded bg-gray-700/50 px-2 py-1">
                    <span className="text-xs text-white">✓ {b}</span>
                    <button onClick={() => removeBenefit(i)} className="text-gray-400 hover:text-red-400">
                      <XMarkIcon className="h-3 w-3" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Timer */}
          <div>
            <label className="block text-xs font-medium text-tx-muted mb-1">Timer (segundos)</label>
            <input
              type="number"
              value={formData.timer_seconds || ''}
              onChange={(e) => setFormData(prev => ({ ...prev, timer_seconds: e.target.value ? Number(e.target.value) : undefined }))}
              className="input text-sm w-full"
              min={0}
              placeholder="300 = 5 minutos"
            />
          </div>

          {/* Chain */}
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Próximo Upsell</label>
              <select
                value={formData.next_upsell_id || ''}
                onChange={(e) => setFormData(prev => ({ ...prev, next_upsell_id: e.target.value || undefined }))}
                className="input text-xs w-full"
              >
                <option value="">Nenhum</option>
                {upsells.filter(u => u.id !== editingUpsell?.id).map(u => (
                  <option key={u.id} value={u.id}>{u.title} ({u.type})</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-tx-muted mb-1">Downsell</label>
              <select
                value={formData.downsell_id || ''}
                onChange={(e) => setFormData(prev => ({ ...prev, downsell_id: e.target.value || undefined }))}
                className="input text-xs w-full"
              >
                <option value="">Nenhum</option>
                {upsells.filter(u => u.id !== editingUpsell?.id).map(u => (
                  <option key={u.id} value={u.id}>{u.title} ({u.type})</option>
                ))}
              </select>
            </div>
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
              <div>
                <span className="text-xs text-tx-muted">Compra com 1 clique</span>
                <p className="text-[10px] text-gray-500">Usa dados do pagamento original</p>
              </div>
              <Switch
                checked={formData.one_click_purchase ?? true}
                onChange={(checked) => setFormData(prev => ({ ...prev, one_click_purchase: checked }))}
                className={`${formData.one_click_purchase ? 'bg-green-600' : 'bg-gray-600'} relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
              >
                <span className={`${formData.one_click_purchase ? 'translate-x-5' : 'translate-x-1'} inline-block h-3 w-3 transform rounded-full bg-white transition-transform`} />
              </Switch>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-2 pt-1">
            <button onClick={handleSave} className="flex-1 rounded-lg bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 transition-colors">
              {editingUpsell ? 'Atualizar' : 'Criar Upsell'}
            </button>
            <button onClick={resetForm} className="rounded-lg border border-gray-600 px-3 py-2 text-xs text-tx-muted hover:text-white transition-colors">
              Cancelar
            </button>
          </div>
        </div>
      )}

      {/* List */}
      {upsells.length === 0 && !showForm ? (
        <div className="rounded-lg border border-dashed border-gray-700 p-6 text-center">
          <BoltIcon className="mx-auto h-8 w-8 text-gray-600 mb-2" />
          <p className="text-sm text-tx-muted">Nenhum Upsell</p>
          <p className="text-xs text-gray-600 mt-1">Crie ofertas pós-compra para maximizar receita</p>
        </div>
      ) : (
        <div className="space-y-2">
          {upsells.map((upsell) => (
            <div
              key={upsell.id}
              className={`rounded-lg border p-3 transition-all ${
                upsell.is_active
                  ? upsell.type === 'upsell'
                    ? 'border-green-500/30 bg-green-900/10'
                    : 'border-orange-500/30 bg-orange-900/10'
                  : 'border-gray-700 bg-gray-800/30 opacity-60'
              }`}
            >
              <div className="flex items-start justify-between gap-2">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    {upsell.type === 'upsell' ? (
                      <BoltIcon className="h-3.5 w-3.5 text-green-400 shrink-0" />
                    ) : (
                      <ArrowDownIcon className="h-3.5 w-3.5 text-orange-400 shrink-0" />
                    )}
                    <p className="text-sm font-medium text-white truncate">{upsell.title}</p>
                  </div>
                  <p className="text-xs text-tx-muted mt-0.5 ml-5">
                    {upsell.product?.name} • R$ {(upsell.price / 100).toFixed(2)}
                    {upsell.original_price && upsell.original_price > upsell.price && (
                      <span className="line-through ml-1 text-gray-500">R$ {(upsell.original_price / 100).toFixed(2)}</span>
                    )}
                  </p>
                  {/* Chain info */}
                  {(upsell.next_upsell_id || upsell.downsell_id) && (
                    <div className="flex gap-2 mt-1 ml-5">
                      {upsell.next_upsell_id && (
                        <span className="rounded bg-green-500/20 px-1.5 py-0.5 text-[10px] text-green-300">
                          → próximo: {upsells.find(u => u.id === upsell.next_upsell_id)?.title || '...'}
                        </span>
                      )}
                      {upsell.downsell_id && (
                        <span className="rounded bg-orange-500/20 px-1.5 py-0.5 text-[10px] text-orange-300">
                          ↓ downsell: {upsells.find(u => u.id === upsell.downsell_id)?.title || '...'}
                        </span>
                      )}
                    </div>
                  )}
                  {upsell.conversions_count > 0 && (
                    <p className="text-[10px] text-green-400 mt-1 ml-5">
                      {upsell.conversions_count} conversões • {upsell.conversion_rate.toFixed(1)}% taxa
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-1">
                  <Switch
                    checked={upsell.is_active}
                    onChange={() => toggleActive(upsell)}
                    className={`${upsell.is_active ? 'bg-green-600' : 'bg-gray-600'} relative inline-flex h-4 w-7 items-center rounded-full transition-colors`}
                  >
                    <span className={`${upsell.is_active ? 'translate-x-4' : 'translate-x-0.5'} inline-block h-2.5 w-2.5 transform rounded-full bg-white transition-transform`} />
                  </Switch>
                  <button onClick={() => handleEdit(upsell)} className="p-1 text-gray-400 hover:text-white">
                    <PencilIcon className="h-3.5 w-3.5" />
                  </button>
                  <button onClick={() => handleDelete(upsell.id)} className="p-1 text-gray-400 hover:text-red-400">
                    <TrashIcon className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Info */}
      <div className="rounded-lg bg-blue-900/20 border border-blue-800 p-3 space-y-1">
        <p className="text-xs text-blue-300">
          ⚡ Upsells aparecem logo após a compra. Configure cadeias de ofertas para maximizar receita.
        </p>
        <p className="text-[10px] text-blue-400/70">
          Dica: Configure "Próximo Upsell" e "Downsell" para criar funis de ofertas encadeadas.
        </p>
      </div>
    </div>
  );
}


