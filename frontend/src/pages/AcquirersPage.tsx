import { useEffect, useState } from 'react';
import { DragDropContext, Draggable, Droppable, type DropResult } from '@hello-pangea/dnd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import {
  ArrowsUpDownIcon,
  Bars3Icon,
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

  const { data, isLoading } = useQuery<AcquirerPriorityItem[]>({
    queryKey: ['account-acquirers'],
    queryFn: async () => {
      const response = await api.get('/acquirers');
      return response.data?.data || [];
    },
  });

  const acquirers = data || [];

  useEffect(() => {
    setItems([...acquirers].sort((a, b) => a.priority - b.priority));
  }, [data]);

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
      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-6 shadow-sm sm:p-7">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div className="max-w-3xl">
            <h1 className="text-2xl font-bold tracking-tight text-tx-primary sm:text-3xl">Prioridade de nominais</h1>
            <p className="mt-2 text-sm leading-6 text-tx-secondary">
              Defina a ordem de prioridade das nominais para tentativas de processamento. A primeira da lista sera usada primeiro e, em caso de indisponibilidade, o sistema tenta automaticamente a proxima.
            </p>
          </div>

          <div className="rounded-xl border border-border-DEFAULT bg-surface-hover px-4 py-3 text-sm text-tx-secondary">
            <span className="font-medium text-tx-primary">Prioridade atual:</span> {primaryNominal}
          </div>
        </div>
      </section>

      <section className="overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card shadow-sm">
        <div className="flex flex-col gap-3 border-b border-border-DEFAULT px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-base font-semibold text-tx-primary">Ordem de prioridade</h2>
            <p className="mt-1 text-sm text-tx-muted">A lista abaixo mostra apenas as nominais disponiveis para sua conta.</p>
          </div>
          <div className="inline-flex w-fit items-center gap-2 rounded-md border border-border-DEFAULT bg-surface-hover px-3 py-1.5 text-xs font-medium text-tx-secondary">
            <ArrowsUpDownIcon className="h-4 w-4" />
            Arraste para ordenar
          </div>
        </div>

        {isLoading ? (
          <div className="space-y-3 p-5">
            {[1, 2, 3].map((item) => (
              <div key={item} className="h-20 animate-pulse rounded-2xl bg-surface-hover" />
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
                          className={`group rounded-xl border bg-surface-card p-4 transition-all ${
                            snapshot.isDragging ? 'border-accent-primary shadow-lg' : 'border-border-DEFAULT shadow-sm hover:shadow-md'
                          }`}
                        >
                          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex min-w-0 items-start gap-3">
                              <button
                                type="button"
                                {...dragProvided.dragHandleProps}
                                className="mt-1 rounded-lg border border-border-DEFAULT bg-surface-hover p-2.5 text-tx-muted transition group-hover:bg-surface-elevated"
                                aria-label="Arrastar nominal"
                              >
                                <Bars3Icon className="h-5 w-5" />
                              </button>

                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-surface-hover px-2 text-xs font-semibold text-tx-secondary">
                                    {index + 1}
                                  </span>
                                  <h3 className="truncate text-base font-semibold text-tx-primary sm:text-lg">
                                    {getNominalName(item)}
                                  </h3>
                                </div>

                                <p className="mt-1 text-xs text-tx-muted">Adquirente: {item.name}</p>

                                <div className="mt-3 flex flex-wrap gap-2">
                                  {item.supported_methods.map((method) => (
                                    <span key={method} className="rounded-full border border-border-DEFAULT bg-surface-hover px-2.5 py-1 text-xs font-semibold text-tx-secondary">
                                      {methodLabels[method] || method}
                                    </span>
                                  ))}
                                </div>
                              </div>
                            </div>

                            <div className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${index === 0 ? 'bg-accent-subtle text-accent-primary' : 'bg-surface-hover text-tx-secondary'}`}>
                              Prioridade {index + 1}
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
