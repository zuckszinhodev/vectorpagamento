import { Fragment, useEffect, useState } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import {
  ArrowPathIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  MagnifyingGlassIcon,
  InboxIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline';
import {
  supportService,
  type SupportTicket,
  type SupportTicketAwaiting,
  type SupportTicketCategory,
  type SupportTicketPriority,
  type SupportTicketStatus,
  statusLabels,
  priorityLabels,
  categoryLabels,
} from '@/services/api/support.service';

function formatDateTime(iso?: string | null) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return '';
  }
}

// ─── Detalhes / thread para admin ───────────────────────────

function AdminTicketDetail({ ticketId, onClose }: { ticketId: string | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [reply, setReply] = useState('');
  const [isInternalNote, setIsInternalNote] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-support-ticket', ticketId],
    queryFn: () => supportService.adminGet(ticketId!),
    enabled: !!ticketId,
    refetchInterval: 15000,
  });

  const ticket = data?.data;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-support-ticket', ticketId] });
    queryClient.invalidateQueries({ queryKey: ['admin-support-tickets'] });
  };

  const replyMutation = useMutation({
    mutationFn: (body: string) => supportService.adminReply(ticketId!, body, isInternalNote),
    onSuccess: () => {
      setReply('');
      setIsInternalNote(false);
      invalidate();
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message || 'Erro ao responder'),
  });

  const updateMutation = useMutation({
    mutationFn: (updates: Partial<Pick<SupportTicket, 'status' | 'priority' | 'category'>>) =>
      supportService.adminUpdate(ticketId!, updates),
    onSuccess: () => {
      toast.success('Chamado atualizado');
      invalidate();
    },
    onError: (err: any) => toast.error(err?.response?.data?.error?.message || 'Erro ao atualizar'),
  });

  const submitReply = (e: React.FormEvent) => {
    e.preventDefault();
    if (!reply.trim()) return;
    replyMutation.mutate(reply.trim());
  };

  return (
    <Transition appear show={!!ticketId} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child as={Fragment} enter="ease-out duration-200" enterFrom="opacity-0" enterTo="opacity-100" leave="ease-in duration-150" leaveFrom="opacity-100" leaveTo="opacity-0">
          <div className="fixed inset-0 bg-black/50" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child as={Fragment} enter="ease-out duration-200" enterFrom="opacity-0 scale-95" enterTo="opacity-100 scale-100" leave="ease-in duration-150" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95">
              <Dialog.Panel className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-2xl bg-surface-card shadow-xl">
                {isLoading || !ticket ? (
                  <div className="flex h-64 items-center justify-center">
                    <ArrowPathIcon className="h-6 w-6 animate-spin text-accent-primary" />
                  </div>
                ) : (
                  <>
                    {/* Header */}
                    <div className="border-b border-border-DEFAULT p-5">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0 flex-1">
                          <Dialog.Title className="truncate text-lg font-semibold text-tx-primary">
                            {ticket.subject}
                          </Dialog.Title>
                          <p className="mt-1 text-xs text-tx-muted">
                            <span className="font-medium text-tx-secondary">{ticket.user?.name || 'Vendedor'}</span>
                            {' '}• {ticket.user?.email}
                            {' '}• Aberto em {formatDateTime(ticket.created_at)}
                          </p>
                        </div>
                        <button type="button" onClick={onClose} className="text-tx-muted hover:text-tx-primary">
                          <XMarkIcon className="h-5 w-5" />
                        </button>
                      </div>

                      {/* Controles do admin */}
                      <div className="mt-3 grid grid-cols-3 gap-2">
                        <select
                          value={ticket.status}
                          onChange={(e) => updateMutation.mutate({ status: e.target.value as SupportTicketStatus })}
                          className="input text-xs"
                        >
                          {Object.entries(statusLabels).map(([v, { label }]) => (
                            <option key={v} value={v}>{label}</option>
                          ))}
                        </select>
                        <select
                          value={ticket.priority}
                          onChange={(e) => updateMutation.mutate({ priority: e.target.value as SupportTicketPriority })}
                          className="input text-xs"
                        >
                          {Object.entries(priorityLabels).map(([v, { label }]) => (
                            <option key={v} value={v}>{label}</option>
                          ))}
                        </select>
                        <select
                          value={ticket.category}
                          onChange={(e) => updateMutation.mutate({ category: e.target.value as SupportTicketCategory })}
                          className="input text-xs"
                        >
                          {Object.entries(categoryLabels).map(([v, label]) => (
                            <option key={v} value={v}>{label}</option>
                          ))}
                        </select>
                      </div>
                    </div>

                    {/* Thread */}
                    <div className="flex-1 space-y-3 overflow-y-auto p-5">
                      {(ticket.messages || []).map((msg) => {
                        const isAdmin = msg.author_type === 'admin';
                        return (
                          <div key={msg.id} className={clsx('flex', isAdmin ? 'justify-end' : 'justify-start')}>
                            <div
                              className={clsx(
                                'max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm',
                                msg.is_internal_note
                                  ? 'border border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                                  : isAdmin
                                  ? 'bg-accent-primary text-white'
                                  : 'border border-border-DEFAULT bg-surface-elevated text-tx-primary'
                              )}
                            >
                              <div
                                className={clsx(
                                  'mb-1 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider',
                                  msg.is_internal_note
                                    ? 'text-amber-700 dark:text-amber-300'
                                    : isAdmin
                                    ? 'text-white/70'
                                    : 'text-tx-muted'
                                )}
                              >
                                {msg.is_internal_note
                                  ? 'Chat Interno'
                                  : isAdmin
                                  ? `Suporte • ${msg.author?.name || ''}`
                                  : `${ticket.user?.name || 'Vendedor'}`}
                                {' • '}{formatDateTime(msg.created_at)}
                              </div>
                              <p className="whitespace-pre-wrap leading-relaxed">{msg.body}</p>
                            </div>
                          </div>
                        );
                      })}
                    </div>

                    {/* Reply form */}
                    <form onSubmit={submitReply} className="border-t border-border-DEFAULT p-4">
                      <textarea
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                        rows={3}
                        className="input w-full resize-none"
                        placeholder={isInternalNote ? 'Nota interna (só o time vê)...' : 'Escreva sua resposta...'}
                      />
                      <div className="mt-2 flex items-center justify-between">
                        <label className="flex cursor-pointer items-center gap-2 text-xs text-tx-secondary">
                          <input
                            type="checkbox"
                            checked={isInternalNote}
                            onChange={(e) => setIsInternalNote(e.target.checked)}
                            className="rounded border-border-DEFAULT"
                          />
                          Nota interna (não visível para o vendedor)
                        </label>
                        <button
                          type="submit"
                          disabled={!reply.trim() || replyMutation.isPending}
                          className="btn-primary flex items-center gap-1 px-4 py-2 disabled:opacity-50"
                        >
                          <PaperAirplaneIcon className="h-4 w-4" />
                          Enviar
                        </button>
                      </div>
                    </form>
                  </>
                )}
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}

// ─── Página ─────────────────────────────────────────────────

export default function AdminSupport() {
  const [statusFilter, setStatusFilter] = useState<SupportTicketStatus | ''>('');
  const [priorityFilter, setPriorityFilter] = useState<SupportTicketPriority | ''>('');
  const [awaitingFilter, setAwaitingFilter] = useState<SupportTicketAwaiting | ''>('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [detailId, setDetailId] = useState<string | null>(null);

  // Debounce simples da busca (300ms).
  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-support-tickets', statusFilter, priorityFilter, awaitingFilter, debouncedSearch],
    queryFn: () =>
      supportService.adminList({
        status: statusFilter || undefined,
        priority: priorityFilter || undefined,
        awaiting: awaitingFilter || undefined,
        search: debouncedSearch || undefined,
        per_page: 30,
      }),
    refetchInterval: 20000,
  });

  const tickets = data?.data || [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-tx-primary">Suporte</h1>
        <p className="mt-1 text-sm text-tx-muted">Todos os chamados dos vendedores</p>
      </div>

      {/* Filtros */}
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <div className="relative">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-tx-muted" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por assunto, nome ou e-mail..."
            className="input pl-9"
          />
        </div>
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as any)} className="input">
          <option value="">Todos os status</option>
          {Object.entries(statusLabels).map(([v, { label }]) => (
            <option key={v} value={v}>{label}</option>
          ))}
        </select>
        <select value={priorityFilter} onChange={(e) => setPriorityFilter(e.target.value as any)} className="input">
          <option value="">Todas as prioridades</option>
          {Object.entries(priorityLabels).map(([v, { label }]) => (
            <option key={v} value={v}>{label}</option>
          ))}
        </select>
        <select value={awaitingFilter} onChange={(e) => setAwaitingFilter(e.target.value as any)} className="input">
          <option value="">Todos (aguardando)</option>
          <option value="admin">Aguardando suporte</option>
          <option value="seller">Aguardando vendedor</option>
        </select>
      </div>

      {/* Lista */}
      {isLoading ? (
        <div className="flex justify-center py-12">
          <ArrowPathIcon className="h-6 w-6 animate-spin text-accent-primary" />
        </div>
      ) : tickets.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-border-DEFAULT bg-surface-card py-12 text-center">
          <InboxIcon className="mx-auto h-10 w-10 text-tx-muted" />
          <p className="mt-2 text-sm font-medium text-tx-primary">Nenhum chamado encontrado</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card">
          <table className="w-full">
            <thead className="bg-surface-hover text-xs uppercase tracking-wider text-tx-muted">
              <tr>
                <th className="px-4 py-3 text-left">Assunto</th>
                <th className="px-4 py-3 text-left">Vendedor</th>
                <th className="px-4 py-3 text-left">Categoria</th>
                <th className="px-4 py-3 text-left">Prioridade</th>
                <th className="px-4 py-3 text-left">Status</th>
                <th className="px-4 py-3 text-left">Atualizado</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT">
              {tickets.map((t) => (
                <tr
                  key={t.id}
                  onClick={() => setDetailId(t.id)}
                  className="cursor-pointer transition hover:bg-surface-hover"
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <p className="truncate text-sm font-medium text-tx-primary">{t.subject}</p>
                      {t.awaiting === 'admin' && t.status !== 'closed' && t.status !== 'resolved' && (
                        <span className="inline-flex shrink-0 items-center rounded-full bg-red-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:text-red-300">
                          Aguarda resposta
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <p className="text-sm text-tx-primary">{t.user?.name || '—'}</p>
                    <p className="text-xs text-tx-muted">{t.user?.email}</p>
                  </td>
                  <td className="px-4 py-3 text-sm text-tx-secondary">{categoryLabels[t.category]}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${priorityLabels[t.priority].cls}`}>
                      {priorityLabels[t.priority].label}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${statusLabels[t.status].cls}`}>
                      {statusLabels[t.status].label}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-xs text-tx-muted">
                    {formatDateTime(t.last_reply_at || t.created_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <AdminTicketDetail ticketId={detailId} onClose={() => setDetailId(null)} />
    </div>
  );
}
