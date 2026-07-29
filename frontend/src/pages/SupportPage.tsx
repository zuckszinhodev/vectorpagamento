import { Fragment, useState } from 'react';
import { Tab, Dialog, Transition } from '@headlessui/react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import toast from 'react-hot-toast';
import {
  ChatBubbleLeftRightIcon,
  BookOpenIcon,
  QuestionMarkCircleIcon,
  ChevronDownIcon,
  ArrowTopRightOnSquareIcon,
  PlusIcon,
  ArrowPathIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  InboxIcon,
} from '@heroicons/react/24/outline';
import clsx from 'clsx';
import {
  supportService,
  type SupportTicket,
  type SupportTicketCategory,
  type SupportTicketPriority,
  statusLabels,
  priorityLabels,
  categoryLabels,
} from '@/services/api/support.service';

const faqItems = [
  {
    question: 'Como criar minha primeira cobrança?',
    answer:
      'Acesse o menu "Cobranças" no painel e clique em "Nova Cobrança". Preencha o valor, método de pagamento (Pix, Boleto ou Cartão), dados do cliente e clique em "Criar Cobrança". Você receberá o link de pagamento instantaneamente.',
  },
  {
    question: 'Quanto tempo leva para receber meus saques?',
    answer:
      'Saques via Pix são processados em até 1 dia útil. Saques via transferência bancária podem levar de 1 a 3 dias úteis dependendo do banco.',
  },
  {
    question: 'Como configurar webhooks?',
    answer:
      'Vá em Configurações > Webhooks e adicione a URL do seu servidor. Você pode configurar webhooks para N8N, Zapier ou qualquer endpoint HTTP.',
  },
  {
    question: 'O que é o processo de KYC?',
    answer:
      'KYC (Conheça Seu Cliente) é um processo obrigatório de verificação de identidade. Envie seus documentos em Configurações > Verificação. A aprovação leva até 48 horas.',
  },
  {
    question: 'Como funciona o checkout personalizado?',
    answer:
      'Você pode criar checkouts personalizados com sua marca em Checkouts > Novo Checkout.',
  },
];

function formatDateTime(iso?: string | null) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
  } catch {
    return '';
  }
}

// ─── Novo ticket (modal) ────────────────────────────────────

interface NewTicketForm {
  subject: string;
  category: SupportTicketCategory;
  priority: SupportTicketPriority;
  body: string;
}

function NewTicketModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const { register, handleSubmit, reset, formState: { errors } } = useForm<NewTicketForm>({
    defaultValues: { category: 'other', priority: 'normal' },
  });

  const createMutation = useMutation({
    mutationFn: (payload: NewTicketForm) => supportService.create(payload),
    onSuccess: () => {
      toast.success('Chamado aberto com sucesso');
      queryClient.invalidateQueries({ queryKey: ['support-tickets'] });
      reset();
      onClose();
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.error?.message || 'Erro ao abrir chamado');
    },
  });

  return (
    <Transition appear show={open} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-200"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-150"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-200"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-150"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-lg rounded-2xl bg-surface-card p-6 shadow-xl">
                <div className="flex items-start justify-between">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary">
                    Abrir novo chamado
                  </Dialog.Title>
                  <button type="button" onClick={onClose} className="text-tx-muted hover:text-tx-primary">
                    <XMarkIcon className="h-5 w-5" />
                  </button>
                </div>

                <form onSubmit={handleSubmit((data) => createMutation.mutate(data))} className="mt-4 space-y-4">
                  <div>
                    <label className="mb-1 block text-sm font-medium text-tx-secondary">Assunto</label>
                    <input
                      type="text"
                      {...register('subject', { required: 'Assunto é obrigatório', maxLength: 200 })}
                      className="input"
                      placeholder="Ex.: Cobrança não confirmada"
                    />
                    {errors.subject && <p className="mt-1 text-xs text-status-error">{errors.subject.message}</p>}
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="mb-1 block text-sm font-medium text-tx-secondary">Categoria</label>
                      <select {...register('category')} className="input">
                        {Object.entries(categoryLabels).map(([value, label]) => (
                          <option key={value} value={value}>{label}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="mb-1 block text-sm font-medium text-tx-secondary">Prioridade</label>
                      <select {...register('priority')} className="input">
                        {Object.entries(priorityLabels).map(([value, { label }]) => (
                          <option key={value} value={value}>{label}</option>
                        ))}
                      </select>
                    </div>
                  </div>

                  <div>
                    <label className="mb-1 block text-sm font-medium text-tx-secondary">Descrição</label>
                    <textarea
                      {...register('body', { required: 'Descrição é obrigatória', maxLength: 10000 })}
                      rows={5}
                      className="input resize-none"
                      placeholder="Descreva o problema, o que você tentou, IDs relacionados..."
                    />
                    {errors.body && <p className="mt-1 text-xs text-status-error">{errors.body.message}</p>}
                  </div>

                  <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onClose} className="rounded-lg border border-border-DEFAULT px-4 py-2 text-sm font-medium text-tx-secondary hover:bg-surface-hover">
                      Cancelar
                    </button>
                    <button type="submit" disabled={createMutation.isPending} className="btn-primary px-4 py-2">
                      {createMutation.isPending ? 'Enviando...' : 'Abrir chamado'}
                    </button>
                  </div>
                </form>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}

// ─── Detalhes / thread (modal) ──────────────────────────────

function TicketDetailModal({
  ticketId,
  onClose,
}: {
  ticketId: string | null;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [reply, setReply] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['support-ticket', ticketId],
    queryFn: () => supportService.get(ticketId!),
    enabled: !!ticketId,
    refetchInterval: 15000,
  });

  const ticket = data?.data;

  const replyMutation = useMutation({
    mutationFn: (body: string) => supportService.reply(ticketId!, body),
    onSuccess: () => {
      setReply('');
      queryClient.invalidateQueries({ queryKey: ['support-ticket', ticketId] });
      queryClient.invalidateQueries({ queryKey: ['support-tickets'] });
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.error?.message || 'Erro ao enviar resposta');
    },
  });

  const closeMutation = useMutation({
    mutationFn: () => supportService.close(ticketId!),
    onSuccess: () => {
      toast.success('Chamado fechado');
      queryClient.invalidateQueries({ queryKey: ['support-tickets'] });
      queryClient.invalidateQueries({ queryKey: ['support-ticket', ticketId] });
    },
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
              <Dialog.Panel className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-surface-card shadow-xl">
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
                          <div className="mt-2 flex flex-wrap items-center gap-2">
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${statusLabels[ticket.status].cls}`}>
                              {statusLabels[ticket.status].label}
                            </span>
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${priorityLabels[ticket.priority].cls}`}>
                              {priorityLabels[ticket.priority].label}
                            </span>
                            <span className="inline-flex items-center rounded-full bg-surface-hover px-2 py-0.5 text-xs font-medium text-tx-secondary">
                              {categoryLabels[ticket.category]}
                            </span>
                            <span className="text-xs text-tx-muted">
                              Aberto em {formatDateTime(ticket.created_at)}
                            </span>
                          </div>
                        </div>
                        <button type="button" onClick={onClose} className="text-tx-muted hover:text-tx-primary">
                          <XMarkIcon className="h-5 w-5" />
                        </button>
                      </div>
                    </div>

                    {/* Thread */}
                    <div className="flex-1 space-y-3 overflow-y-auto p-5">
                      {(ticket.messages || []).map((msg) => {
                        const isSeller = msg.author_type === 'seller';
                        return (
                          <div key={msg.id} className={clsx('flex', isSeller ? 'justify-end' : 'justify-start')}>
                            <div
                              className={clsx(
                                'max-w-[85%] rounded-2xl px-4 py-3 text-sm shadow-sm',
                                isSeller
                                  ? 'bg-accent-primary text-white'
                                  : 'border border-border-DEFAULT bg-surface-elevated text-tx-primary'
                              )}
                            >
                              <div className={clsx('mb-1 text-[10px] font-semibold uppercase tracking-wider', isSeller ? 'text-white/70' : 'text-tx-muted')}>
                                {isSeller ? 'Você' : 'Suporte Vector'} • {formatDateTime(msg.created_at)}
                              </div>
                              <p className="whitespace-pre-wrap leading-relaxed">{msg.body}</p>
                            </div>
                          </div>
                        );
                      })}
                    </div>

                    {/* Reply form */}
                    {ticket.status === 'closed' ? (
                      <div className="border-t border-border-DEFAULT bg-surface-page p-4 text-center text-sm text-tx-muted">
                        Este chamado está fechado. Abra um novo se precisar de mais ajuda.
                      </div>
                    ) : (
                      <form onSubmit={submitReply} className="border-t border-border-DEFAULT p-4">
                        <div className="flex items-end gap-2">
                          <textarea
                            value={reply}
                            onChange={(e) => setReply(e.target.value)}
                            rows={2}
                            className="input flex-1 resize-none"
                            placeholder="Escreva sua resposta..."
                          />
                          <button
                            type="submit"
                            disabled={!reply.trim() || replyMutation.isPending}
                            className="btn-primary flex h-10 items-center gap-1 px-4 disabled:opacity-50"
                          >
                            <PaperAirplaneIcon className="h-4 w-4" />
                            Enviar
                          </button>
                        </div>
                        <div className="mt-2 flex justify-end">
                          <button
                            type="button"
                            onClick={() => closeMutation.mutate()}
                            disabled={closeMutation.isPending}
                            className="text-xs text-tx-muted hover:text-status-error"
                          >
                            Marcar como resolvido e fechar
                          </button>
                        </div>
                      </form>
                    )}
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

// ─── Lista de chamados ──────────────────────────────────────

function TicketsList({ onOpen }: { onOpen: (id: string) => void }) {
  const [statusFilter, setStatusFilter] = useState<SupportTicket['status'] | 'all'>('all');

  const { data, isLoading } = useQuery({
    queryKey: ['support-tickets', statusFilter],
    queryFn: () => supportService.listMine(statusFilter === 'all' ? undefined : { status: statusFilter }),
    refetchInterval: 30000,
  });

  const tickets = data?.data || [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        {(['all', 'open', 'pending', 'resolved', 'closed'] as const).map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => setStatusFilter(s)}
            className={clsx(
              'rounded-full border px-3 py-1 text-xs font-medium transition',
              statusFilter === s
                ? 'border-accent-primary bg-accent-primary text-white'
                : 'border-border-DEFAULT text-tx-secondary hover:bg-surface-hover'
            )}
          >
            {s === 'all' ? 'Todos' : statusLabels[s].label}
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <ArrowPathIcon className="h-6 w-6 animate-spin text-accent-primary" />
        </div>
      ) : tickets.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-border-DEFAULT bg-surface-card py-12 text-center">
          <InboxIcon className="mx-auto h-10 w-10 text-tx-muted" />
          <p className="mt-2 text-sm font-medium text-tx-primary">Nenhum chamado por aqui</p>
          <p className="text-xs text-tx-muted">Clique em "Novo chamado" para falar com o suporte.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {tickets.map((t) => (
            <button
              key={t.id}
              type="button"
              onClick={() => onOpen(t.id)}
              className="w-full rounded-xl border border-border-DEFAULT bg-surface-card p-4 text-left transition hover:border-accent-primary hover:shadow-sm"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-tx-primary">{t.subject}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-tx-muted">
                    <span>{categoryLabels[t.category]}</span>
                    <span>•</span>
                    <span>Atualizado {formatDateTime(t.last_reply_at || t.created_at)}</span>
                    {t.awaiting === 'seller' && t.status !== 'closed' && (
                      <span className="ml-auto inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                        Aguarda sua resposta
                      </span>
                    )}
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${statusLabels[t.status].cls}`}>
                    {statusLabels[t.status].label}
                  </span>
                  <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${priorityLabels[t.priority].cls}`}>
                    {priorityLabels[t.priority].label}
                  </span>
                </div>
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Página ─────────────────────────────────────────────────

export default function SupportPage() {
  const [openFaq, setOpenFaq] = useState<number | null>(null);
  const [newTicketOpen, setNewTicketOpen] = useState(false);
  const [detailTicketId, setDetailTicketId] = useState<string | null>(null);

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Suporte</h1>
          <p className="mt-1 text-sm text-tx-muted">
            Abra um chamado ou consulte nossa base de conhecimento
          </p>
        </div>
        <button
          type="button"
          onClick={() => setNewTicketOpen(true)}
          className="btn-primary inline-flex items-center gap-2 px-4 py-2"
        >
          <PlusIcon className="h-4 w-4" />
          Novo chamado
        </button>
      </div>

      {/* Quick contact cards */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <a
          href="https://wa.me/5511940968290"
          target="_blank"
          rel="noopener noreferrer"
          className="group rounded-2xl border border-border-DEFAULT bg-surface-card p-5 transition-all hover:border-green-300 hover:shadow-lg"
        >
          <div className="mb-2 flex items-center gap-3">
            <div className="rounded-xl bg-status-success-subtle p-2.5 transition-transform group-hover:scale-110">
              <ChatBubbleLeftRightIcon className="h-5 w-5 text-status-success" />
            </div>
            <h3 className="text-sm font-medium text-tx-primary">WhatsApp</h3>
          </div>
          <p className="text-xs text-tx-muted">Atendimento rápido via chat</p>
        </a>

        <a
          href="https://vectorpagamentos.readme.io/reference"
          target="_blank"
          rel="noopener noreferrer"
          className="group rounded-2xl border border-border-DEFAULT bg-surface-card p-5 transition-all hover:border-blue-300 hover:shadow-lg"
        >
          <div className="mb-2 flex items-center gap-3">
            <div className="rounded-xl bg-accent-subtle p-2.5 transition-transform group-hover:scale-110">
              <BookOpenIcon className="h-5 w-5 text-accent-primary" />
            </div>
            <h3 className="text-sm font-medium text-tx-primary">Documentação</h3>
          </div>
          <p className="flex items-center gap-1 text-xs text-tx-muted">
            Guias e tutoriais completos
            <ArrowTopRightOnSquareIcon className="h-3 w-3" />
          </p>
        </a>
      </div>

      {/* Tabs: Meus Chamados / FAQ */}
      <Tab.Group>
        <Tab.List className="flex gap-2 rounded-xl bg-surface-hover p-1">
          <Tab as={Fragment}>
            {({ selected }) => (
              <button
                type="button"
                className={clsx(
                  'flex-1 rounded-lg py-2.5 text-sm font-medium transition',
                  selected ? 'bg-surface-card text-accent-primary shadow-sm' : 'text-tx-secondary hover:text-tx-primary'
                )}
              >
                Meus chamados
              </button>
            )}
          </Tab>
          <Tab as={Fragment}>
            {({ selected }) => (
              <button
                type="button"
                className={clsx(
                  'flex-1 rounded-lg py-2.5 text-sm font-medium transition',
                  selected ? 'bg-surface-card text-accent-primary shadow-sm' : 'text-tx-secondary hover:text-tx-primary'
                )}
              >
                Perguntas frequentes
              </button>
            )}
          </Tab>
        </Tab.List>

        <Tab.Panels className="mt-4">
          <Tab.Panel>
            <TicketsList onOpen={(id) => setDetailTicketId(id)} />
          </Tab.Panel>
          <Tab.Panel>
            <div className="mb-3 flex items-center gap-2">
              <QuestionMarkCircleIcon className="h-5 w-5 text-accent-primary" />
              <h2 className="text-lg font-semibold text-tx-primary">Perguntas Frequentes</h2>
            </div>
            <div className="divide-y divide-border-DEFAULT overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card">
              {faqItems.map((item, idx) => (
                <div key={idx}>
                  <button
                    onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
                    className="flex w-full items-center justify-between px-5 py-4 text-left transition-colors hover:bg-surface-page/50"
                  >
                    <span className="pr-4 text-sm font-medium text-tx-primary">{item.question}</span>
                    <ChevronDownIcon
                      className={`h-4 w-4 shrink-0 text-tx-muted transition-transform duration-200 ${
                        openFaq === idx ? 'rotate-180' : ''
                      }`}
                    />
                  </button>
                  {openFaq === idx && (
                    <div className="-mt-1 px-5 pb-4">
                      <p className="text-sm leading-relaxed text-tx-secondary">{item.answer}</p>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </Tab.Panel>
        </Tab.Panels>
      </Tab.Group>

      <NewTicketModal open={newTicketOpen} onClose={() => setNewTicketOpen(false)} />
      <TicketDetailModal ticketId={detailTicketId} onClose={() => setDetailTicketId(null)} />
    </div>
  );
}
