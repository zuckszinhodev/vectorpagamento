import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { Dialog, Transition, Switch } from '@headlessui/react';
import { Fragment } from 'react';
import { useForm } from 'react-hook-form';
import {
  PlusIcon,
  MagnifyingGlassIcon,
  ArrowPathIcon,
  XMarkIcon,
  PencilIcon,
  TrashIcon,
  TicketIcon,
  ClipboardDocumentIcon,
  CalendarIcon,
  TagIcon,
  CheckCircleIcon,
  XCircleIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Coupon {
  id: string;
  code: string;
  type: 'percentage' | 'fixed';
  value: number;
  max_uses?: number;
  used_count: number;
  valid_from?: string;
  valid_until?: string;
  min_purchase_amount?: number;
  max_discount_amount?: number;
  status: 'active' | 'inactive' | 'expired';
  created_at: string;
}

interface CouponForm {
  code: string;
  type: 'percentage' | 'fixed';
  value: number;
  max_uses?: number;
  valid_from?: string;
  valid_until?: string;
  min_purchase_amount?: number;
  max_discount_amount?: number;
  status: 'active' | 'inactive';
}

function CouponModal({
  coupon,
  isOpen,
  onClose,
}: {
  coupon: Coupon | null;
  isOpen: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const isEditing = !!coupon;

  const {
    register,
    handleSubmit,
    watch,
    reset,
    formState: { errors },
  } = useForm<CouponForm>({
    defaultValues: coupon
      ? {
          code: coupon.code,
          type: coupon.type,
          value: coupon.type === 'percentage' ? coupon.value : coupon.value / 100,
          max_uses: coupon.max_uses,
          valid_from: coupon.valid_from?.split('T')[0],
          valid_until: coupon.valid_until?.split('T')[0],
          min_purchase_amount: coupon.min_purchase_amount ? coupon.min_purchase_amount / 100 : undefined,
          max_discount_amount: coupon.max_discount_amount ? coupon.max_discount_amount / 100 : undefined,
          status: coupon.status as 'active' | 'inactive',
        }
      : {
          type: 'percentage',
          status: 'active',
        },
  });

  const mutation = useMutation({
    mutationFn: async (data: CouponForm) => {
      const payload = {
        ...data,
        value: data.type === 'percentage' ? data.value : Math.round((data.value || 0) * 100),
        min_purchase_amount: data.min_purchase_amount ? Math.round(data.min_purchase_amount * 100) : undefined,
        max_discount_amount: data.max_discount_amount ? Math.round(data.max_discount_amount * 100) : undefined,
      };

      if (isEditing) {
        return await api.put(`/coupons/${coupon!.id}`, payload);
      }
      return await api.post('/coupons', payload);
    },
    onSuccess: () => {
      toast.success(isEditing ? 'Cupom atualizado!' : 'Cupom criado!');
      queryClient.invalidateQueries({ queryKey: ['coupons'] });
      reset();
      onClose();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao salvar cupom');
    },
  });

  const onSubmit = (data: CouponForm) => {
    mutation.mutate(data);
  };

  const generateRandomCode = () => {
    const code = Math.random().toString(36).substring(2, 10).toUpperCase();
    reset({ ...watch(), code });
  };

  return (
    <Transition.Root show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50 transition-opacity" />
        </Transition.Child>

        <div className="fixed inset-0 z-10 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enterTo="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 translate-y-0 sm:scale-100"
              leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                <form onSubmit={handleSubmit(onSubmit)}>
                  <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                    <Dialog.Title className="text-lg font-semibold text-tx-primary flex items-center gap-2">
                      <TicketIcon className="h-5 w-5 text-green-500" />
                      {isEditing ? 'Editar Cupom' : 'Novo Cupom'}
                    </Dialog.Title>
                    <button
                      type="button"
                      onClick={onClose}
                      className="text-tx-muted hover:text-tx-secondary"
                    >
                      <XMarkIcon className="h-6 w-6" />
                    </button>
                  </div>

                  <div className="max-h-[60vh] space-y-4 overflow-y-auto px-4 sm:px-6 py-4">
                    {/* Código do Cupom */}
                    <div>
                      <label className="block text-sm font-medium text-tx-secondary mb-1">
                        Código do Cupom
                      </label>
                      <div className="flex gap-2">
                        <input
                          {...register('code', { 
                            required: 'Código é obrigatório',
                            pattern: {
                              value: /^[A-Z0-9]+$/,
                              message: 'Use apenas letras maiúsculas e números'
                            }
                          })}
                          type="text"
                          className="input flex-1 uppercase"
                          placeholder="DESCONTO10"
                          style={{ textTransform: 'uppercase' }}
                        />
                        <button
                          type="button"
                          onClick={generateRandomCode}
                          className="btn-secondary whitespace-nowrap"
                        >
                          Gerar Código
                        </button>
                      </div>
                      {errors.code && (
                        <p className="mt-1 text-sm text-status-error">{errors.code.message}</p>
                      )}
                    </div>

                    {/* Tipo e Valor */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-2">
                          Tipo de Desconto
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                          <label className={`flex items-center justify-center gap-2 p-3 rounded-lg border-2 cursor-pointer transition-all ${
                            watch('type') === 'percentage' 
                              ? 'border-green-500 bg-green-50 text-green-600' 
                              : 'border-border-DEFAULT hover:border-green-300'
                          }`}>
                            <input
                              {...register('type')}
                              type="radio"
                              value="percentage"
                              className="sr-only"
                            />
                            <span className="font-bold text-lg">%</span>
                          </label>
                          <label className={`flex items-center justify-center gap-2 p-3 rounded-lg border-2 cursor-pointer transition-all ${
                            watch('type') === 'fixed' 
                              ? 'border-green-500 bg-green-50 text-green-600' 
                              : 'border-border-DEFAULT hover:border-green-300'
                          }`}>
                            <input
                              {...register('type')}
                              type="radio"
                              value="fixed"
                              className="sr-only"
                            />
                            <span className="font-bold text-lg">R$</span>
                          </label>
                        </div>
                      </div>

                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          {watch('type') === 'percentage' ? 'Desconto (%)' : 'Desconto (R$)'}
                        </label>
                        <input
                          {...register('value', {
                            required: 'Valor é obrigatório',
                            min: { value: 0.01, message: 'Valor mínimo é 0,01' },
                            max: watch('type') === 'percentage' 
                              ? { value: 100, message: 'Máximo 100%' }
                              : undefined,
                          })}
                          type="number"
                          step="0.01"
                          className="input mt-1"
                          placeholder={watch('type') === 'percentage' ? '10' : '50.00'}
                        />
                        {errors.value && (
                          <p className="mt-1 text-sm text-status-error">{errors.value.message}</p>
                        )}
                      </div>
                    </div>

                    {/* Limites */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Usos Máximos <span className="text-xs text-tx-muted">(Opcional)</span>
                        </label>
                        <input
                          {...register('max_uses')}
                          type="number"
                          min="1"
                          className="input"
                          placeholder="Ilimitado"
                        />
                      </div>

                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Compra Mínima (R$) <span className="text-xs text-tx-muted">(Opcional)</span>
                        </label>
                        <input
                          {...register('min_purchase_amount')}
                          type="number"
                          step="0.01"
                          className="input"
                          placeholder="0.00"
                        />
                      </div>
                    </div>

                    {/* Desconto Máximo (apenas para percentual) */}
                    {watch('type') === 'percentage' && (
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Desconto Máximo (R$) <span className="text-xs text-tx-muted">(Opcional)</span>
                        </label>
                        <input
                          {...register('max_discount_amount')}
                          type="number"
                          step="0.01"
                          className="input"
                          placeholder="Sem limite"
                        />
                        <p className="mt-1 text-xs text-tx-muted">
                          Limite máximo de desconto em reais
                        </p>
                      </div>
                    )}

                    {/* Validade */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Válido De <span className="text-xs text-tx-muted">(Opcional)</span>
                        </label>
                        <input
                          {...register('valid_from')}
                          type="date"
                          className="input"
                        />
                      </div>

                      <div>
                        <label className="block text-sm font-medium text-tx-secondary mb-1">
                          Válido Até <span className="text-xs text-tx-muted">(Opcional)</span>
                        </label>
                        <input
                          {...register('valid_until')}
                          type="date"
                          className="input"
                        />
                      </div>
                    </div>

                    {/* Status */}
                    <div className="flex items-center justify-between p-4 rounded-lg bg-surface-hover border border-border-DEFAULT">
                      <div>
                        <p className="text-sm font-medium text-tx-primary">Status do Cupom</p>
                        <p className="text-xs text-tx-muted">Ativar ou desativar este cupom</p>
                      </div>
                      <Switch
                        {...register('status')}
                        checked={watch('status') === 'active'}
                        onChange={(checked) => {
                          reset({ ...watch(), status: checked ? 'active' : 'inactive' });
                        }}
                        className={`${
                          watch('status') === 'active' ? 'bg-green-500' : 'bg-gray-300'
                        } relative inline-flex h-7 w-12 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2`}
                      >
                        <span
                          className={`${
                            watch('status') === 'active' ? 'translate-x-6' : 'translate-x-1'
                          } inline-block h-5 w-5 transform rounded-full bg-white shadow-lg transition-transform`}
                        />
                      </Switch>
                    </div>
                  </div>

                  <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                    <button type="button" onClick={onClose} className="btn-secondary">
                      Cancelar
                    </button>
                    <button
                      type="submit"
                      disabled={mutation.isPending}
                      className="btn-primary"
                    >
                      {mutation.isPending ? 'Salvando...' : isEditing ? 'Salvar' : 'Criar Cupom'}
                    </button>
                  </div>
                </form>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

export default function CouponsPage() {
  const [search, setSearch] = useState('');
  const [selectedCoupon, setSelectedCoupon] = useState<Coupon | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const queryClient = useQueryClient();

  const { data, refetch, isLoading } = useQuery({
    queryKey: ['coupons', search],
    queryFn: async () => {
      const response = await api.get('/coupons', {
        params: { search },
      });
      return response.data;
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      await api.delete(`/coupons/${id}`);
    },
    onSuccess: () => {
      toast.success('Cupom removido!');
      queryClient.invalidateQueries({ queryKey: ['coupons'] });
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao remover cupom');
    },
  });

  const coupons: Coupon[] = data?.data || [];

  const handleCreate = () => {
    setSelectedCoupon(null);
    setIsModalOpen(true);
  };

  const handleEdit = (coupon: Coupon) => {
    setSelectedCoupon(coupon);
    setIsModalOpen(true);
  };

  const handleDelete = (coupon: Coupon) => {
    if (confirm('Tem certeza que deseja remover este cupom?')) {
      deleteMutation.mutate(coupon.id);
    }
  };

  const copyCouponCode = (code: string) => {
    navigator.clipboard.writeText(code);
    toast.success('Código copiado!');
  };

  const stats = {
    total: coupons.length,
    active: coupons.filter(c => c.status === 'active').length,
    used: coupons.reduce((acc, c) => acc + c.used_count, 0),
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Cupons de Desconto</h1>
          <p className="text-sm text-tx-muted">
            {stats.total} {stats.total === 1 ? 'cupom' : 'cupons'} • {stats.active} {stats.active === 1 ? 'ativo' : 'ativos'}
          </p>
        </div>
        <button
          onClick={handleCreate}
          className="hidden lg:flex items-center gap-2 px-4 py-2.5 rounded-lg bg-green-500 text-white font-medium hover:bg-green-600 transition-all hover:scale-105 active:scale-95 shadow-lg"
        >
          <PlusIcon className="h-5 w-5" />
          Criar Cupom
        </button>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-3 gap-3 sm:gap-4">
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-500/10">
              <TicketIcon className="h-5 w-5 text-green-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Total</p>
              <p className="text-xl font-bold text-tx-primary">{stats.total}</p>
            </div>
          </div>
        </div>
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-500/10">
              <CheckCircleIcon className="h-5 w-5 text-green-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Ativos</p>
              <p className="text-xl font-bold text-green-600">{stats.active}</p>
            </div>
          </div>
        </div>
        <div className="card p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10">
              <TagIcon className="h-5 w-5 text-blue-500" />
            </div>
            <div>
              <p className="text-xs text-tx-muted">Usos</p>
              <p className="text-xl font-bold text-blue-600">{stats.used}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Search Bar */}
      <div className="card p-3 sm:p-4">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar cupons..."
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-input pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder-tx-muted focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500/20 transition-all"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <button
            onClick={() => refetch()}
            className="btn-secondary inline-flex items-center justify-center text-sm"
          >
            <ArrowPathIcon className="mr-2 h-4 w-4" />
            Atualizar
          </button>
        </div>
      </div>

      {/* Coupons List */}
      {isLoading ? (
        <div className="card p-12 text-center">
          <div className="flex items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
          </div>
        </div>
      ) : coupons.length === 0 ? (
        <div className="card p-12 text-center">
          <TicketIcon className="mx-auto h-16 w-16 text-tx-muted" />
          <h3 className="mt-4 text-lg font-semibold text-tx-primary">
            Nenhum cupom cadastrado
          </h3>
          <p className="mt-2 text-base text-tx-muted">
            Comece criando seu primeiro cupom de desconto
          </p>
          <button onClick={handleCreate} className="btn-primary mt-4 text-base px-6 py-3">
            Criar Cupom
          </button>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {coupons.map((coupon) => (
            <div
              key={coupon.id}
              className="card p-4 hover:shadow-lg transition-all border-2 border-transparent hover:border-green-500/30"
            >
              {/* Header */}
              <div className="flex items-start justify-between mb-3">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <h3 className="text-lg font-bold text-green-600 font-mono truncate">
                      {coupon.code}
                    </h3>
                    <button
                      onClick={() => copyCouponCode(coupon.code)}
                      className="p-1 rounded hover:bg-surface-hover text-tx-muted hover:text-green-500 transition-colors"
                      title="Copiar código"
                    >
                      <ClipboardDocumentIcon className="h-4 w-4" />
                    </button>
                  </div>
                  <p className="text-2xl font-bold text-tx-primary">
                    {coupon.type === 'percentage' 
                      ? `${coupon.value}%` 
                      : formatCurrency(coupon.value)
                    }
                  </p>
                </div>
                <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                  coupon.status === 'active' 
                    ? 'bg-green-100 text-green-700' 
                    : coupon.status === 'expired'
                    ? 'bg-red-100 text-red-700'
                    : 'bg-gray-100 text-gray-700'
                }`}>
                  {coupon.status === 'active' ? 'Ativo' : coupon.status === 'expired' ? 'Expirado' : 'Inativo'}
                </span>
              </div>

              {/* Info */}
              <div className="space-y-2 mb-4">
                {coupon.max_uses && (
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-tx-muted">Usos:</span>
                    <span className="font-medium text-tx-primary">
                      {coupon.used_count} / {coupon.max_uses}
                    </span>
                  </div>
                )}
                {coupon.min_purchase_amount && (
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-tx-muted">Compra mínima:</span>
                    <span className="font-medium text-tx-primary">
                      {formatCurrency(coupon.min_purchase_amount)}
                    </span>
                  </div>
                )}
                {coupon.valid_until && (
                  <div className="flex items-center gap-2 text-sm text-tx-muted">
                    <CalendarIcon className="h-4 w-4" />
                    <span>Válido até {formatDate(coupon.valid_until)}</span>
                  </div>
                )}
              </div>

              {/* Actions */}
              <div className="flex gap-2 pt-3 border-t border-border-DEFAULT">
                <button
                  onClick={() => handleEdit(coupon)}
                  className="flex-1 btn-secondary text-sm py-2"
                >
                  <PencilIcon className="h-4 w-4 mr-1 inline" />
                  Editar
                </button>
                <button
                  onClick={() => handleDelete(coupon)}
                  className="px-3 py-2 rounded-lg border border-border-DEFAULT text-status-error hover:bg-red-50 transition-colors"
                  title="Remover"
                >
                  <TrashIcon className="h-4 w-4" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Floating Action Button */}
      <button
        onClick={handleCreate}
        className="fixed bottom-6 right-6 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-green-500 text-white shadow-lg hover:bg-green-600 transition-all hover:scale-110 active:scale-95 lg:hidden"
        title="Criar Cupom"
      >
        <PlusIcon className="h-6 w-6" />
      </button>

      {/* Modal */}
      <CouponModal
        coupon={selectedCoupon}
        isOpen={isModalOpen}
        onClose={() => {
          setIsModalOpen(false);
          setSelectedCoupon(null);
        }}
      />
    </div>
  );
}


