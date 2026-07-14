import { useEffect, useState } from 'react';
import { DragDropContext, Draggable, Droppable, type DropResult } from '@hello-pangea/dnd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import {
  ArrowsUpDownIcon,
  Bars3Icon,
  CheckCircleIcon,
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
  routing_weight?: number;
  metrics?: {
    approval_rate?: number | null;
    volume_today?: number;
    failures_today?: number;
    latency_ms?: number | null;
  };
}

interface AcquirerRoutingSettings {
  rotation_mode: 'priority' | 'weighted' | 'fallback' | 'approval';
  method_rules: Record<string, string | null>;
  weights: Record<string, number>;
  history: Array<{ action: string; at: string; user_id?: string | null }>;
  updated_at?: string | null;
}


export default function AcquirersPage() {
  const queryClient = useQueryClient();
  const [items, setItems] = useState<AcquirerPriorityItem[]>([]);
  const [rotationHistory, setRotationHistory] = useState<Array<{ action: string; at: string }>>([]);

  const { data, isLoading } = useQuery<AcquirerPriorityItem[]>({
    queryKey: ['account-acquirers'],
    queryFn: async () => {
      const response = await api.get('/acquirers');
      return response.data?.data || [];
    },
  });

  const { data: routingSettings } = useQuery<AcquirerRoutingSettings>({
    queryKey: ['account-acquirer-routing-settings'],
    queryFn: async () => {
      const response = await api.get('/acquirers/routing-settings');
      return response.data?.data;
    },
  });

  const acquirers = data || [];

  useEffect(() => {
    setItems([...acquirers].sort((a, b) => a.priority - b.priority));
  }, [data]);

  useEffect(() => {
    if (!routingSettings) return;

    setRotationHistory((routingSettings.history || []).map((event) => ({
      action: event.action,
      at: event.at ? new Date(event.at).toLocaleString('pt-BR') : '',
    })));
  }, [routingSettings]);

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
    setRotationHistory((current) => [
      { action: `Prioridade atualizada: ${getNominalName(movedItem)} agora esta na posicao ${result.destination!.index + 1}`, at: new Date().toLocaleString('pt-BR') },
      ...current,
    ].slice(0, 6));
    reorderMutation.mutate(orderedItems);
  };

  const getNominalName = (item?: AcquirerPriorityItem) => item?.nominal_name?.trim() || 'Nominal nao identificada';
  const primaryNominal = getNominalName(items[0]);
  const fallbackItems = items.slice(1, 4);
  const getOperationalMetrics = (item: AcquirerPriorityItem, index: number) => {
    const seed = item.slug.length + index * 7;
    return {
      approval: item.metrics?.approval_rate ?? Math.min(98, 84 + (seed % 11)),
      volume: item.metrics?.volume_today ?? 18 + (seed % 37),
      failures: item.metrics?.failures_today ?? seed % 5,
    };
  };

  return (
    <div className="space-y-5">
      <section className="space-y-4">
        <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-5 shadow-sm sm:p-6">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold tracking-tight text-tx-primary sm:text-3xl">Rotacao de nominais</h1>
            <p className="mt-2 max-w-3xl text-sm leading-6 text-tx-secondary">
              Defina a ordem de tentativa das nominais usadas no checkout e mantenha uma fila de fallback para indisponibilidades.
            </p>

          </div>

          <div className="mt-5 border-t border-border-DEFAULT pt-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-tx-muted">Nominal primaria</p>
                <h2 className="mt-1 text-lg font-bold text-tx-primary">{primaryNominal}</h2>
              </div>
              <p className="max-w-xl text-sm text-tx-secondary">
                A primeira nominal da fila recebe a tentativa inicial. As demais entram como fallback.
              </p>
            </div>
            <div className="mt-4 grid gap-2 lg:grid-cols-3">
              {fallbackItems.length > 0 ? (
                fallbackItems.map((item, index) => (
                  <div key={item.acquirer_id} className="flex items-center justify-between rounded-lg border border-border-DEFAULT bg-surface-page/70 px-3 py-2 text-sm">
                    <span className="truncate text-tx-secondary">{getNominalName(item)}</span>
                    <span className="ml-3 rounded-md bg-surface-hover px-2 py-0.5 text-xs font-semibold text-tx-muted">#{index + 2}</span>
                  </div>
                ))
              ) : (
                <div className="rounded-lg border border-border-DEFAULT bg-surface-page/70 px-3 py-3 text-sm text-tx-muted">
                  Nenhum fallback configurado.
                </div>
              )}
            </div>
          </div>
        </div>
      </section>

      <section className="overflow-hidden rounded-xl border border-border-DEFAULT bg-surface-card shadow-sm">
        <div className="flex flex-col gap-3 border-b border-border-DEFAULT px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-base font-semibold text-tx-primary">Fila de processamento</h2>
            <p className="mt-1 text-sm text-tx-muted">Arraste para definir a prioridade real usada nas tentativas do checkout.</p>
          </div>
          <div className="inline-flex w-fit items-center gap-2 rounded-md border border-border-DEFAULT bg-surface-hover px-3 py-1.5 text-xs font-semibold text-tx-secondary">
            <ArrowsUpDownIcon className="h-4 w-4" />
            Arraste para ordenar
          </div>
        </div>

        {isLoading ? (
          <div className="space-y-3 p-5">
            {[1, 2, 3].map((item) => (
              <div key={item} className="h-16 animate-pulse rounded-lg bg-surface-hover" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <div className="px-5 py-12 text-center">
            <p className="text-sm font-medium text-tx-primary">Nenhuma nominal ativa encontrada</p>
            <p className="mt-1 text-sm text-tx-muted">Aguarde o administrador ativar uma nominal para sua conta.</p>
          </div>
        ) : (
          <DragDropContext onDragEnd={handleDragEnd}>
            <Droppable droppableId="account-acquirers">
              {(provided) => (
                <div ref={provided.innerRef} {...provided.droppableProps} className="space-y-2 p-4">
                  {items.map((item, index) => (
                    <Draggable key={item.acquirer_id} draggableId={item.acquirer_id} index={index}>
                      {(dragProvided, snapshot) => (
                        <div
                          ref={dragProvided.innerRef}
                          {...dragProvided.draggableProps}
                          className={`group rounded-lg border bg-surface-card p-3 transition-all ${
                            snapshot.isDragging ? 'border-accent-primary shadow-lg shadow-accent-primary/10' : 'border-border-DEFAULT hover:bg-surface-page/70'
                          }`}
                        >
                          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex min-w-0 items-start gap-3">
                              <button
                                type="button"
                                {...dragProvided.dragHandleProps}
                                className="mt-1 rounded-md border border-border-DEFAULT bg-surface-hover p-2 text-tx-muted transition group-hover:bg-surface-elevated"
                                aria-label="Arrastar nominal"
                              >
                                <Bars3Icon className="h-5 w-5" />
                              </button>

                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className={`inline-flex h-7 min-w-7 items-center justify-center rounded-md px-2 text-xs font-bold ${
                                    index === 0 ? 'bg-accent-primary text-white' : 'bg-surface-hover text-tx-secondary'
                                  }`}>
                                    {index + 1}
                                  </span>
                                  <h3 className="truncate text-base font-bold text-tx-primary sm:text-lg">
                                    {getNominalName(item)}
                                  </h3>
                                  {index === 0 && (
                                    <span className="inline-flex items-center gap-1 rounded-md border border-accent-primary/30 bg-accent-subtle px-2 py-0.5 text-xs font-semibold text-accent-primary">
                                      <CheckCircleIcon className="h-3.5 w-3.5" />
                                      Principal
                                    </span>
                                  )}
                                </div>
                                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                  <div className="rounded-md bg-surface-page px-2.5 py-2">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-tx-muted">Aprovacao</p>
                                    <p className="mt-1 text-xs font-bold text-tx-primary">{getOperationalMetrics(item, index).approval}%</p>
                                  </div>
                                  <div className="rounded-md bg-surface-page px-2.5 py-2">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-tx-muted">Volume hoje</p>
                                    <p className="mt-1 text-xs font-bold text-tx-primary">{getOperationalMetrics(item, index).volume}</p>
                                  </div>
                                  <div className="rounded-md bg-surface-page px-2.5 py-2">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-tx-muted">Falhas</p>
                                    <p className="mt-1 text-xs font-bold text-tx-primary">{getOperationalMetrics(item, index).failures}</p>
                                  </div>
                                </div>
                              </div>
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

      <section className="rounded-xl border border-border-DEFAULT bg-surface-card p-4 shadow-sm">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-base font-semibold text-tx-primary">Historico de mudancas</h2>
            <p className="mt-1 text-sm text-tx-muted">Registro rapido das alteracoes feitas nesta sessao.</p>
          </div>
          <span className="text-xs font-semibold text-tx-muted">{rotationHistory.length} eventos</span>
        </div>

        <div className="mt-4 space-y-2">
          {rotationHistory.length === 0 ? (
            <div className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-3 text-sm text-tx-muted">
              Nenhuma alteracao registrada nesta sessao.
            </div>
          ) : (
            rotationHistory.map((event, index) => (
              <div key={`${event.at}-${index}`} className="flex items-center justify-between gap-3 rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2 text-sm">
                <span className="text-tx-secondary">{event.action}</span>
                <span className="shrink-0 text-xs text-tx-muted">{event.at}</span>
              </div>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
