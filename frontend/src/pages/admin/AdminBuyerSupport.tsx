import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import {
  LifebuoyIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
  PaperAirplaneIcon,
} from '@heroicons/react/24/outline';
import { SectionSpinner } from '@/components/ui/Spinner';

type TicketStatus = 'open' | 'pending' | 'resolved' | 'closed';

interface BuyerTicket {
  id: string;
  protocol: string;
  name: string;
  email: string;
  subject: string;
  category: string;
  category_label: string;
  status: TicketStatus;
  message: string;
  order_reference: string | null;
  attachments?: string[];
  admin_reply: string | null;
  replied_at: string | null;
  created_at: string;
  ip_address?: string;
}

const STATUS: Record<TicketStatus, { label: string; chip: string }> = {
  open: { label: 'Aberto', chip: 'bg-amber-500/10 text-amber-600' },
  pending: { label: 'Aguardando cliente', chip: 'bg-blue-500/10 text-blue-600' },
  resolved: { label: 'Resolvido', chip: 'bg-emerald-500/10 text-emerald-600' },
  closed: { label: 'Fechado', chip: 'bg-gray-500/10 text-gray-500' },
};

export default function AdminBuyerSupport() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<TicketStatus | ''>('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<BuyerTicket | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-buyer-tickets', status, search],
    queryFn: async () => {
      const response = await api.get('/admin/buyer-support/tickets', {
        params: { status: status || undefined, search: search || undefined },
      });
      return response.data;
    },
    // Chamado de comprador é fila de atendimento: vale atualizar sozinho.
    refetchInterval: 60000,
  });

  const tickets: BuyerTicket[] = data?.data ?? [];
  const counts = data?.counts ?? { open: 0, pending: 0, resolved: 0, closed: 0 };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-tx-primary">
          <LifebuoyIcon className="h-6 w-6 text-accent-primary" />
          Chamados de compradores
        </h1>
        <p className="mt-1 text-sm text-tx-muted">
          Abertos pela central de ajuda pública, por quem comprou em um checkout da plataforma.
        </p>
      </div>

      {/* Contadores servem como filtro rápido */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {(Object.keys(STATUS) as TicketStatus[]).map((key) => (
          <button
            key={key}
            onClick={() => setStatus(status === key ? '' : key)}
            className={clsx(
              'rounded-xl border p-4 text-left transition-colors',
              status === key
                ? 'border-accent-primary/50 bg-accent-subtle'
                : 'border-border-DEFAULT bg-surface-card hover:bg-surface-hover',
            )}
          >
            <p className="text-2xl font-bold text-tx-primary">{counts[key] ?? 0}</p>
            <p className="mt-0.5 text-xs font-medium text-tx-muted">{STATUS[key].label}</p>
          </button>
        ))}
      </div>

      <div className="relative">
        <MagnifyingGlassIcon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar por protocolo, e-mail, nome ou assunto..."
          className="w-full rounded-xl border border-border-DEFAULT bg-surface-input py-2.5 pl-10 pr-4 text-sm text-tx-primary"
        />
      </div>

      {isLoading ? (
        <SectionSpinner />
      ) : tickets.length === 0 ? (
        <div className="rounded-xl border border-dashed border-border-DEFAULT bg-surface-card py-16 text-center">
          <LifebuoyIcon className="mx-auto h-10 w-10 text-tx-muted" />
          <p className="mt-3 text-sm text-tx-muted">
            {search || status ? 'Nenhum chamado com esse filtro.' : 'Nenhum chamado aberto até agora.'}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {tickets.map((ticket) => (
            <button
              key={ticket.id}
              onClick={() => setSelected(ticket)}
              className="flex w-full items-center gap-4 rounded-xl border border-border-DEFAULT bg-surface-card p-4 text-left transition-colors hover:border-accent-primary/30"
            >
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono text-xs font-bold text-accent-primary">{ticket.protocol}</span>
                  <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-semibold', STATUS[ticket.status].chip)}>
                    {STATUS[ticket.status].label}
                  </span>
                  <span className="rounded-md bg-surface-hover px-2 py-0.5 text-[10px] text-tx-muted">
                    {ticket.category_label}
                  </span>
                </div>
                <p className="mt-1 truncate text-sm font-medium text-tx-primary">{ticket.subject}</p>
                <p className="mt-0.5 truncate text-xs text-tx-muted">
                  {ticket.name} · {ticket.email}
                </p>
              </div>
              <span className="shrink-0 text-xs text-tx-muted">
                {new Date(ticket.created_at).toLocaleDateString('pt-BR')}
              </span>
            </button>
          ))}
        </div>
      )}

      {selected && (
        <TicketDetail
          ticket={selected}
          onClose={() => setSelected(null)}
          onSaved={() => {
            queryClient.invalidateQueries({ queryKey: ['admin-buyer-tickets'] });
            setSelected(null);
          }}
        />
      )}
    </div>
  );
}

function TicketDetail({
  ticket,
  onClose,
  onSaved,
}: {
  ticket: BuyerTicket;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [reply, setReply] = useState(ticket.admin_reply ?? '');
  const [status, setStatus] = useState<TicketStatus>(ticket.status);

  const mutation = useMutation({
    mutationFn: () =>
      api.patch(`/admin/buyer-support/tickets/${ticket.id}`, {
        admin_reply: reply || undefined,
        status,
      }),
    onSuccess: () => {
      toast.success('Chamado atualizado');
      onSaved();
    },
    onError: () => toast.error('Não consegui salvar'),
  });

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/60 p-4 backdrop-blur-sm">
      <div className="flex min-h-full items-start justify-center sm:items-center">
        <div className="w-full max-w-2xl rounded-2xl border border-border-DEFAULT bg-surface-elevated shadow-float">
          <div className="flex items-center justify-between border-b border-border-DEFAULT px-6 py-4">
            <div className="min-w-0">
              <p className="font-mono text-xs font-bold text-accent-primary">{ticket.protocol}</p>
              <h2 className="truncate text-base font-semibold text-tx-primary">{ticket.subject}</h2>
            </div>
            <button onClick={onClose} className="text-tx-muted hover:text-tx-secondary">
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>

          <div className="space-y-4 px-6 py-5">
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-tx-muted">Cliente</p>
                <p className="mt-0.5 text-tx-primary">{ticket.name}</p>
                <p className="text-xs text-tx-secondary">{ticket.email}</p>
              </div>
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-tx-muted">Referência do pedido</p>
                <p className="mt-0.5 text-tx-primary">{ticket.order_reference || '—'}</p>
              </div>
            </div>

            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-tx-muted">Mensagem</p>
              <p className="mt-1 whitespace-pre-wrap rounded-xl bg-surface-page p-4 text-sm leading-relaxed text-tx-secondary">
                {ticket.message}
              </p>
            </div>

            {ticket.attachments && ticket.attachments.length > 0 && (
              <div>
                <p className="text-[11px] font-medium uppercase tracking-wide text-tx-muted">
                  Anexos ({ticket.attachments.length})
                </p>
                <div className="mt-2 flex flex-wrap gap-2">
                  {ticket.attachments.map((url) => (
                    <a
                      key={url}
                      href={url}
                      target="_blank"
                      rel="noopener noreferrer"
                      title="Abrir em tamanho real"
                      className="block"
                    >
                      <img
                        src={url}
                        alt="Anexo do chamado"
                        className="h-24 w-24 rounded-lg border border-border-DEFAULT object-cover transition-opacity hover:opacity-80"
                      />
                    </a>
                  ))}
                </div>
              </div>
            )}

            <div>
              <label className="mb-1.5 block text-[11px] font-medium uppercase tracking-wide text-tx-muted">
                Resposta
              </label>
              <textarea
                value={reply}
                onChange={(e) => setReply(e.target.value)}
                rows={5}
                placeholder="Escreva a resposta que será enviada ao cliente..."
                className="w-full resize-y rounded-xl border border-border-DEFAULT bg-surface-input p-3.5 text-sm text-tx-primary"
              />
              {ticket.replied_at && (
                <p className="mt-1 text-[11px] text-tx-muted">
                  Última resposta em {new Date(ticket.replied_at).toLocaleString('pt-BR')}
                </p>
              )}
            </div>

            <div>
              <label className="mb-1.5 block text-[11px] font-medium uppercase tracking-wide text-tx-muted">
                Situação
              </label>
              <div className="flex flex-wrap gap-2">
                {(Object.keys(STATUS) as TicketStatus[]).map((key) => (
                  <button
                    key={key}
                    onClick={() => setStatus(key)}
                    className={clsx(
                      'rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors',
                      status === key
                        ? 'border-accent-primary/50 bg-accent-subtle text-tx-primary'
                        : 'border-border-DEFAULT text-tx-muted hover:bg-surface-hover',
                    )}
                  >
                    {STATUS[key].label}
                  </button>
                ))}
              </div>
            </div>
          </div>

          <div className="flex gap-3 border-t border-border-DEFAULT px-6 py-4">
            <button
              onClick={onClose}
              className="flex-1 rounded-xl border border-border-DEFAULT px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-surface-hover"
            >
              Cancelar
            </button>
            <button
              onClick={() => mutation.mutate()}
              disabled={mutation.isPending}
              className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
            >
              <PaperAirplaneIcon className="h-4 w-4" />
              {mutation.isPending ? 'Salvando...' : 'Salvar'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
