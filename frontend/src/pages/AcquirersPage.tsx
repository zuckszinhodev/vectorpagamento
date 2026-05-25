import { type ComponentType, useEffect, useState } from 'react';
import { DragDropContext, Draggable, Droppable, type DropResult } from '@hello-pangea/dnd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import {
  ArrowsUpDownIcon,
  Bars3Icon,
  BanknotesIcon,
  CheckCircleIcon,
  ShieldCheckIcon,
} from '@heroicons/react/24/outline';

interface AcquirerPriorityItem {
  acquirer_id: string;
  slug: string;
  name: string;
  nominal_name?: string | null;
  priority: number;
  is_active: boolean;
  supported_methods: string[];
  supports_pix: boolean;
  supports_pix_out: boolean;
  supports_credit_card: boolean;
  supports_boleto: boolean;
}

const methodLabels: Record<string, string> = {
  pix: 'PIX',
  pix_out: 'Saque PIX',
  credit_card: 'Cartao',
  card: 'Cartao',
  boleto: 'Boleto',
};

export default function AcquirersPage() {
  const queryClient = useQueryClient();
  const [items, setItems] = useState<AcquirerPriorityItem[]>([]);

  const { data: acquirers = [], isLoading } = useQuery<AcquirerPriorityItem[]>({
    queryKey: ['account-acquirers'],
    queryFn: async () => {
      const response = await api.get('/acquirers');
      return response.data?.data || [];
    },
  });

  useEffect(() => {
    setItems([...acquirers].sort((a, b) => a.priority - b.priority));
  }, [acquirers]);

  const reorderMutation = useMutation({
    mutationFn: async (orderedItems: AcquirerPriorityItem[]) => {
      await api.put('/acquirers/reorder', {
        acquirers: orderedItems.map((item, index) => ({
          acquirer_id: item.acquirer_id,
          priority: index,
        })),
      });
    },
    onSuccess: () => {
      toast.success('Prioridade das nominais salva');
      queryClient.invalidateQueries({ queryKey: ['account-acquirers'] });
    },
    onError: () => {
      toast.error('Erro ao salvar prioridade das nominais');
      setItems([...acquirers].sort((a, b) => a.priority - b.priority));
    },
  });

  const handleDragEnd = (result: DropResult) => {
    if (!result.destination || result.destination.index === result.source.index) {
      return;
    }

    const nextItems = [...items];
    const [movedItem] = nextItems.splice(result.source.index, 1);
    nextItems.splice(result.destination.index, 0, movedItem);

    const orderedItems = nextItems.map((item, index) => ({ ...item, priority: index }));
    setItems(orderedItems);
    reorderMutation.mutate(orderedItems);
  };

  const getNominalName = (item?: AcquirerPriorityItem) => item?.nominal_name?.trim() || 'Nominal nao identificada';
  const primaryNominal = getNominalName(items[0]);

  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-[2rem] border border-border-DEFAULT bg-surface-card p-6 shadow-sm sm:p-8">
        <div className="relative flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
          <div className="max-w-3xl">
            <div className="inline-flex items-center gap-2 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">
              <ShieldCheckIcon className="h-4 w-4" />
              Roteamento inteligente
            </div>
            <h1 className="mt-5 text-2xl font-bold tracking-tight text-tx-primary sm:text-3xl">Prioridade de nominais</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-tx-secondary">
              Organize a ordem das nominais usadas nas suas cobrancas. Arraste para cima a nominal que deve ter prioridade; se ela nao puder processar, a proxima da lista sera tentada automaticamente.
            </p>
          </div>

          <div className="min-w-0 rounded-3xl border border-border-DEFAULT bg-gray-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-tx-muted">Nominal principal</p>
            <p className="mt-2 max-w-xs truncate text-lg font-bold text-tx-primary">{primaryNominal}</p>
            <p className="mt-1 text-xs text-tx-muted">Primeira tentativa nas cobrancas elegiveis</p>
          </div>
        </div>
      </section>

      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard label="Nominais ativas" value={String(items.length)} icon={ShieldCheckIcon} />
        <SummaryCard label="Nominal prioridade" value={primaryNominal} icon={BanknotesIcon} />
        <SummaryCard label="Salvamento" value={reorderMutation.isPending ? 'Salvando...' : 'Automatico'} icon={CheckCircleIcon} />
      </div>

      <section className="overflow-hidden rounded-[1.75rem] border border-border-DEFAULT bg-surface-card shadow-sm">
        <div className="flex flex-col gap-3 border-b border-border-DEFAULT bg-gradient-to-r from-gray-50 to-white px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-base font-semibold text-tx-primary">Ordem de prioridade</h2>
            <p className="mt-1 text-sm text-tx-muted">A lista abaixo mostra apenas as nominais disponiveis para sua conta.</p>
          </div>
          <div className="inline-flex w-fit items-center gap-2 rounded-full border border-border-DEFAULT bg-white px-3 py-1.5 text-xs font-semibold text-tx-secondary shadow-sm">
            <ArrowsUpDownIcon className="h-4 w-4" />
            Arraste para ordenar
          </div>
        </div>

        {isLoading ? (
          <div className="space-y-3 p-5">
            {[1, 2, 3].map((item) => (
              <div key={item} className="h-20 animate-pulse rounded-2xl bg-gray-100" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <div className="px-5 py-12 text-center">
            <p className="text-sm font-medium text-tx-primary">Nenhuma nominal ativa encontrada</p>
            <p className="mt-1 text-sm text-tx-muted">Aguarde o administrador ativar uma adquirente para sua conta.</p>
          </div>
        ) : (
          <DragDropContext onDragEnd={handleDragEnd}>
            <Droppable droppableId="account-acquirers">
              {(provided) => (
                <div ref={provided.innerRef} {...provided.droppableProps} className="space-y-4 p-5">
                  {items.map((item, index) => (
                    <Draggable key={item.acquirer_id} draggableId={item.acquirer_id} index={index}>
                      {(dragProvided, snapshot) => (
                        <div
                          ref={dragProvided.innerRef}
                          {...dragProvided.draggableProps}
                          className={`group rounded-3xl border bg-white p-4 transition-all ${
                            snapshot.isDragging ? 'scale-[1.01] border-emerald-400 shadow-2xl shadow-emerald-950/10' : 'border-border-DEFAULT shadow-sm hover:-translate-y-0.5 hover:shadow-md'
                          }`}
                        >
                          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex min-w-0 items-start gap-3">
                              <button
                                type="button"
                                {...dragProvided.dragHandleProps}
                                className="mt-1 rounded-2xl border border-border-DEFAULT bg-gray-50 p-3 text-tx-muted transition group-hover:border-emerald-200 group-hover:bg-emerald-50 group-hover:text-emerald-700"
                                aria-label="Arrastar nominal"
                              >
                                <Bars3Icon className="h-5 w-5" />
                              </button>

                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className="inline-flex h-8 min-w-8 items-center justify-center rounded-full bg-slate-950 px-2 text-xs font-bold text-white">
                                    {index + 1}
                                  </span>
                                  <h3 className="truncate text-base font-bold text-tx-primary sm:text-lg">
                                    {getNominalName(item)}
                                  </h3>
                                </div>

                                <div className="mt-3 flex flex-wrap gap-2">
                                  {item.supported_methods.map((method) => (
                                    <span key={method} className="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-tx-secondary">
                                      {methodLabels[method] || method}
                                    </span>
                                  ))}
                                </div>
                              </div>
                            </div>

                            <div className={`rounded-2xl px-4 py-2 text-xs font-bold ${index === 0 ? 'bg-emerald-500/10 text-emerald-700' : 'bg-gray-50 text-tx-muted'}`}>
                              {index === 0 ? 'Prioridade maxima' : `Alternativa ${index}`}
                            </div>
                          </div>
                        </div>
                      )}
                    </Draggable>
                  ))}
                  {provided.placeholder}
                </div>
              )}
            </Droppable>
          </DragDropContext>
        )}
      </section>
    </div>
  );
}

function SummaryCard({ label, value, icon: Icon }: { label: string; value: string; icon: ComponentType<{ className?: string }> }) {
  return (
    <div className="rounded-2xl border border-border-DEFAULT bg-surface-card p-4">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
          <Icon className="h-5 w-5" />
        </div>
        <div className="min-w-0">
          <p className="text-xs font-medium uppercase tracking-wide text-tx-muted">{label}</p>
          <p className="truncate text-sm font-semibold text-tx-primary">{value}</p>
        </div>
      </div>
    </div>
  );
}
