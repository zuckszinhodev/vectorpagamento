import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  CreditCardIcon,
  PlusIcon,
  TrashIcon,
  ShieldCheckIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  StarIcon,
  XMarkIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline';
import { StarIcon as StarSolid } from '@heroicons/react/24/solid';
import toast from 'react-hot-toast';

interface SavedCard {
  id: string;
  brand: string;
  last_four: string;
  exp_month: number;
  exp_year: number;
  holder_name: string;
  is_default: boolean;
  is_expired: boolean;
  created_at: string;
}

const CARD_BRANDS: Record<string, { color: string; gradient: string }> = {
  visa: { color: '#1A1F71', gradient: 'from-blue-800 to-blue-600' },
  mastercard: { color: '#EB001B', gradient: 'from-red-600 to-orange-500' },
  elo: { color: '#00A4E0', gradient: 'from-cyan-600 to-blue-500' },
  amex: { color: '#006FCF', gradient: 'from-blue-600 to-indigo-600' },
  hipercard: { color: '#822124', gradient: 'from-red-800 to-red-600' },
  default: { color: '#059669', gradient: 'from-emerald-700 to-emerald-500' },
};

export default function OneClickBuyPage() {
  const queryClient = useQueryClient();
  const [showAddCard, setShowAddCard] = useState(false);
  const [cardForm, setCardForm] = useState({
    number: '',
    holder_name: '',
    exp_month: '',
    exp_year: '',
    cvv: '',
  });
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null);

  const { data: cardsData, isLoading } = useQuery({
    queryKey: ['saved-cards'],
    queryFn: async () => {
      const response = await api.get('/saved-cards');
      return response.data;
    },
  });

  const cards: SavedCard[] = cardsData?.data || [];

  const addCardMutation = useMutation({
    mutationFn: async () => {
      await api.post('/saved-cards', {
        card_number: cardForm.number.replace(/\s/g, ''),
        holder_name: cardForm.holder_name,
        exp_month: parseInt(cardForm.exp_month),
        exp_year: parseInt(cardForm.exp_year),
        cvv: cardForm.cvv,
      });
    },
    onSuccess: () => {
      toast.success('Cartão salvo com sucesso!');
      queryClient.invalidateQueries({ queryKey: ['saved-cards'] });
      setShowAddCard(false);
      setCardForm({ number: '', holder_name: '', exp_month: '', exp_year: '', cvv: '' });
    },
    onError: () => toast.error('Erro ao salvar cartão'),
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/saved-cards/${id}`);
    },
    onSuccess: () => {
      toast.success('Cartão removido');
      queryClient.invalidateQueries({ queryKey: ['saved-cards'] });
      setDeleteConfirm(null);
    },
    onError: () => toast.error('Erro ao remover cartão'),
  });

  const setDefaultMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.put(`/saved-cards/${id}/default`);
    },
    onSuccess: () => {
      toast.success('Cartão padrão atualizado');
      queryClient.invalidateQueries({ queryKey: ['saved-cards'] });
    },
  });

  const formatCardNumber = (value: string) => {
    const digits = value.replace(/\D/g, '').substring(0, 16);
    return digits.replace(/(\d{4})(?=\d)/g, '$1 ');
  };

  const detectBrand = (number: string): string => {
    const n = number.replace(/\D/g, '');
    if (/^4/.test(n)) return 'visa';
    if (/^5[1-5]/.test(n) || /^2[2-7]/.test(n)) return 'mastercard';
    if (/^(636368|438935|504175|451416|636297)/.test(n)) return 'elo';
    if (/^3[47]/.test(n)) return 'amex';
    if (/^(606282|3841)/.test(n)) return 'hipercard';
    return 'default';
  };

  const getBrand = (brand: string) => CARD_BRANDS[brand.toLowerCase()] || CARD_BRANDS.default;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-tx-primary">Compra com 1 Clique</h1>
          <p className="text-sm text-tx-muted">Gerencie seus cartões salvos para compras rápidas</p>
        </div>
        <button
          onClick={() => setShowAddCard(true)}
          className="flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 transition-colors shadow-sm"
        >
          <PlusIcon className="h-4 w-4" /> Adicionar Cartão
        </button>
      </div>

      {/* Security Notice */}
      <div className="flex items-start gap-3 rounded-2xl bg-emerald-500/5 border border-emerald-500/20 p-4">
        <ShieldCheckIcon className="h-6 w-6 text-emerald-500 flex-shrink-0 mt-0.5" />
        <div>
          <p className="text-sm font-medium text-emerald-600">Seus dados estão protegidos</p>
          <p className="text-xs text-tx-muted mt-0.5">
            Os dados do cartão são tokenizados e armazenados de forma segura. Nunca guardamos o número completo ou CVV.
          </p>
        </div>
      </div>

      {/* How it Works */}
      <div className="rounded-2xl bg-surface-card p-6 shadow-sm">
        <h2 className="text-base font-semibold text-tx-primary mb-4">Como funciona</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="flex flex-col items-center text-center gap-3 p-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10">
              <CreditCardIcon className="h-6 w-6 text-emerald-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-tx-primary">1. Salve seu cartão</p>
              <p className="text-xs text-tx-muted mt-1">Adicione um cartão de crédito de forma segura</p>
            </div>
          </div>
          <div className="flex flex-col items-center text-center gap-3 p-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10">
              <LockClosedIcon className="h-6 w-6 text-emerald-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-tx-primary">2. Tokenização</p>
              <p className="text-xs text-tx-muted mt-1">Dados criptografados e tokenizados pelo gateway</p>
            </div>
          </div>
          <div className="flex flex-col items-center text-center gap-3 p-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10">
              <CheckCircleIcon className="h-6 w-6 text-emerald-600" />
            </div>
            <div>
              <p className="text-sm font-medium text-tx-primary">3. Compra rápida</p>
              <p className="text-xs text-tx-muted mt-1">No checkout, pague com 1 clique sem preencher dados</p>
            </div>
          </div>
        </div>
      </div>

      {/* Cards Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <div className="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
        </div>
      ) : cards.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 rounded-2xl bg-surface-card">
          <CreditCardIcon className="h-14 w-14 text-tx-muted" />
          <p className="mt-4 text-base font-medium text-tx-secondary">Nenhum cartão salvo</p>
          <p className="text-sm text-tx-muted mt-1">Adicione um cartão para comprar com 1 clique</p>
          <button
            onClick={() => setShowAddCard(true)}
            className="mt-6 flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 transition-colors"
          >
            <PlusIcon className="h-4 w-4" /> Adicionar Cartão
          </button>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {cards.map((card) => {
            const brand = getBrand(card.brand);
            return (
              <div key={card.id} className="relative group">
                {/* Card Visual */}
                <div className={`rounded-2xl bg-gradient-to-br ${brand.gradient} p-6 text-white shadow-lg h-48 flex flex-col justify-between ${card.is_expired ? 'opacity-60' : ''}`}>
                  <div className="flex items-start justify-between">
                    <span className="text-lg font-bold uppercase tracking-wider">{card.brand}</span>
                    <div className="flex items-center gap-1">
                      {card.is_default && (
                        <span className="rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-semibold backdrop-blur-sm">PADRÃO</span>
                      )}
                      {card.is_expired && (
                        <span className="rounded-full bg-red-500/80 px-2 py-0.5 text-[10px] font-semibold">EXPIRADO</span>
                      )}
                    </div>
                  </div>
                  <div>
                    <p className="text-xl font-mono tracking-[0.2em]">•••• •••• •••• {card.last_four}</p>
                    <div className="flex items-center justify-between mt-3">
                      <div>
                        <p className="text-[10px] uppercase opacity-60">Titular</p>
                        <p className="text-sm font-medium truncate max-w-[180px]">{card.holder_name}</p>
                      </div>
                      <div className="text-right">
                        <p className="text-[10px] uppercase opacity-60">Validade</p>
                        <p className="text-sm font-medium">{String(card.exp_month).padStart(2, '0')}/{card.exp_year}</p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Actions overlay */}
                <div className="absolute inset-0 rounded-2xl bg-black/0 group-hover:bg-black/20 transition-all flex items-center justify-center gap-3 opacity-0 group-hover:opacity-100">
                  {!card.is_default && !card.is_expired && (
                    <button
                      onClick={() => setDefaultMutation.mutate(card.id)}
                      className="rounded-xl bg-white/90 px-3 py-2 text-xs font-medium text-gray-800 hover:bg-white transition-colors shadow-lg flex items-center gap-1.5"
                    >
                      <StarIcon className="h-4 w-4" /> Tornar Padrão
                    </button>
                  )}
                  <button
                    onClick={() => setDeleteConfirm(card.id)}
                    className="rounded-xl bg-red-500/90 px-3 py-2 text-xs font-medium text-white hover:bg-red-500 transition-colors shadow-lg flex items-center gap-1.5"
                  >
                    <TrashIcon className="h-4 w-4" /> Remover
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Add Card Modal */}
      {showAddCard && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-2xl bg-surface-card shadow-xl">
            <div className="flex items-center justify-between p-6 border-b border-border-DEFAULT">
              <h2 className="text-lg font-bold text-tx-primary">Adicionar Cartão</h2>
              <button onClick={() => setShowAddCard(false)} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>
            <div className="p-6 space-y-4">
              {/* Card Preview */}
              <div className={`rounded-xl bg-gradient-to-br ${getBrand(detectBrand(cardForm.number)).gradient} p-5 text-white shadow-lg`}>
                <p className="text-xs uppercase opacity-60 mb-1">{detectBrand(cardForm.number).toUpperCase() || 'CARTÃO'}</p>
                <p className="text-lg font-mono tracking-wider mb-4">
                  {cardForm.number || '•••• •••• •••• ••••'}
                </p>
                <div className="flex justify-between text-sm">
                  <span>{cardForm.holder_name || 'NOME DO TITULAR'}</span>
                  <span>{cardForm.exp_month && cardForm.exp_year ? `${cardForm.exp_month}/${cardForm.exp_year}` : 'MM/AA'}</span>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Número do Cartão</label>
                <input
                  type="text"
                  value={cardForm.number}
                  onChange={(e) => setCardForm({ ...cardForm, number: formatCardNumber(e.target.value) })}
                  placeholder="0000 0000 0000 0000"
                  maxLength={19}
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary font-mono placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-tx-secondary mb-1">Nome do Titular</label>
                <input
                  type="text"
                  value={cardForm.holder_name}
                  onChange={(e) => setCardForm({ ...cardForm, holder_name: e.target.value.toUpperCase() })}
                  placeholder="NOME COMO NO CARTÃO"
                  className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block text-sm font-medium text-tx-secondary mb-1">Mês</label>
                  <select
                    value={cardForm.exp_month}
                    onChange={(e) => setCardForm({ ...cardForm, exp_month: e.target.value })}
                    className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="">MM</option>
                    {Array.from({ length: 12 }, (_, i) => (
                      <option key={i + 1} value={String(i + 1).padStart(2, '0')}>{String(i + 1).padStart(2, '0')}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-tx-secondary mb-1">Ano</label>
                  <select
                    value={cardForm.exp_year}
                    onChange={(e) => setCardForm({ ...cardForm, exp_year: e.target.value })}
                    className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
                  >
                    <option value="">AA</option>
                    {Array.from({ length: 10 }, (_, i) => {
                      const year = new Date().getFullYear() + i;
                      return <option key={year} value={String(year).slice(-2)}>{year}</option>;
                    })}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-tx-secondary mb-1">CVV</label>
                  <input
                    type="text"
                    value={cardForm.cvv}
                    onChange={(e) => setCardForm({ ...cardForm, cvv: e.target.value.replace(/\D/g, '').substring(0, 4) })}
                    placeholder="•••"
                    maxLength={4}
                    className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary font-mono placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
                  />
                </div>
              </div>

              <div className="flex items-start gap-2 rounded-lg bg-amber-500/5 border border-amber-500/20 p-3">
                <LockClosedIcon className="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" />
                <p className="text-xs text-tx-muted">
                  Os dados serão tokenizados e criptografados. O CVV não é armazenado.
                </p>
              </div>
            </div>
            <div className="flex gap-3 p-6 border-t border-border-DEFAULT">
              <button onClick={() => setShowAddCard(false)} className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors">
                Cancelar
              </button>
              <button
                onClick={() => addCardMutation.mutate()}
                disabled={
                  !cardForm.number || !cardForm.holder_name || !cardForm.exp_month || !cardForm.exp_year || !cardForm.cvv || addCardMutation.isPending
                }
                className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 transition-colors"
              >
                {addCardMutation.isPending ? 'Salvando...' : 'Salvar Cartão'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirmation */}
      {deleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-sm rounded-2xl bg-surface-card p-6 shadow-xl text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-500/10 mb-4">
              <ExclamationTriangleIcon className="h-7 w-7 text-red-500" />
            </div>
            <h3 className="text-lg font-bold text-tx-primary">Remover Cartão</h3>
            <p className="text-sm text-tx-muted mt-2">Tem certeza que deseja remover este cartão? Esta ação não pode ser desfeita.</p>
            <div className="flex gap-3 mt-6">
              <button onClick={() => setDeleteConfirm(null)} className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors">
                Cancelar
              </button>
              <button
                onClick={() => deleteMutation.mutate(deleteConfirm)}
                className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 transition-colors"
              >
                {deleteMutation.isPending ? 'Removendo...' : 'Sim, Remover'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
