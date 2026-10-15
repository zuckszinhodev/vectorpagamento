import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  BoltIcon,
  CpuChipIcon,
  PlusIcon,
  TrashIcon,
  PlayIcon,
  ArrowLeftIcon,
  XMarkIcon,
  ArrowPathIcon,
  ClipboardDocumentIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import clsx from 'clsx';
import { Spinner } from '@/components/ui/Spinner';
import { Modal, ModalHeader } from '@/components/ui/Modal';
import {
  botService,
  type Bot,
  type BotAiAction,
  type BotAiConfig,
} from '@/services/api/bot.service';
import FlowCanvas from './FlowCanvas';
import { AI_ACTIONS, starterFlow } from './botFlowMeta';

const WEEK = [
  { key: 'mon', label: 'Seg' },
  { key: 'tue', label: 'Ter' },
  { key: 'wed', label: 'Qua' },
  { key: 'thu', label: 'Qui' },
  { key: 'fri', label: 'Sex' },
  { key: 'sat', label: 'Sáb' },
  { key: 'sun', label: 'Dom' },
];

// ============================================================
//  ABA PRINCIPAL
// ============================================================
export default function BotTab() {
  const queryClient = useQueryClient();
  const [editingId, setEditingId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['bots'],
    queryFn: () => botService.list(),
  });

  const bots = data?.data ?? [];
  const editing = bots.find(b => b.id === editingId) ?? null;

  const createMutation = useMutation({
    mutationFn: (mode: 'flow' | 'ai') =>
      botService.create({
        name: mode === 'ai' ? 'Atendimento com IA' : 'Atendimento por fluxo',
        mode,
        is_active: false,
        flow_data: mode === 'flow' ? { nodes: starterFlow() } : { nodes: [] },
        ai_config: mode === 'ai' ? { allowed_actions: ['lookup_order'], tone: 'profissional e cordial' } : {},
      }),
    onSuccess: bot => {
      toast.success('Bot criado!');
      queryClient.invalidateQueries({ queryKey: ['bots'] });
      setEditingId(bot.id);
    },
    onError: () => toast.error('Não consegui criar o bot'),
  });

  if (isLoading) return <div className="py-20"><Spinner size="md" text="Carregando bots..." /></div>;

  if (editing) {
    return (
      <BotEditor
        bot={editing}
        aiAvailable={data?.ai_available ?? false}
        inboundUrl={data?.inbound_url ?? null}
        onBack={() => setEditingId(null)}
      />
    );
  }

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-tx-primary">Bot de atendimento</h2>
          <p className="text-sm text-tx-muted mt-1">
            Responde automaticamente quem manda mensagem no seu WhatsApp, com acesso aos pedidos do gateway.
          </p>
        </div>
      </div>

      {bots.length === 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <button
            onClick={() => createMutation.mutate('flow')}
            disabled={createMutation.isPending}
            className="text-left rounded-2xl border border-border-DEFAULT/50 bg-surface-card p-6 hover:border-green-500/40 transition-all disabled:opacity-50"
          >
            <div className="p-2.5 rounded-xl bg-blue-500/10 w-fit mb-3">
              <BoltIcon className="h-6 w-6 text-blue-500" />
            </div>
            <h3 className="font-semibold text-tx-primary">Bot por fluxograma</h3>
            <p className="text-sm text-tx-muted mt-1">
              Você desenha o caminho: menu de opções, condições sobre o pedido e transferência. Resposta previsível,
              sem custo de IA.
            </p>
          </button>

          <button
            onClick={() => createMutation.mutate('ai')}
            disabled={createMutation.isPending}
            className="text-left rounded-2xl border border-border-DEFAULT/50 bg-surface-card p-6 hover:border-green-500/40 transition-all disabled:opacity-50"
          >
            <div className="p-2.5 rounded-xl bg-cyan-500/10 w-fit mb-3">
              <CpuChipIcon className="h-6 w-6 text-cyan-400" />
            </div>
            <h3 className="font-semibold text-tx-primary">Bot com IA</h3>
            <p className="text-sm text-tx-muted mt-1">
              Você descreve o negócio e o que ela deve atender. A IA responde em texto livre, já sabendo o status
              real do pedido de quem escreveu.
            </p>
          </button>
        </div>
      ) : (
        <>
          <div className="space-y-3">
            {bots.map(bot => (
              <BotCard key={bot.id} bot={bot} onEdit={() => setEditingId(bot.id)} />
            ))}
          </div>

          <div className="flex gap-2">
            <button
              onClick={() => createMutation.mutate('flow')}
              disabled={createMutation.isPending}
              className="flex items-center gap-2 px-4 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all disabled:opacity-50"
            >
              <PlusIcon className="h-4 w-4" />Novo bot por fluxo
            </button>
            <button
              onClick={() => createMutation.mutate('ai')}
              disabled={createMutation.isPending}
              className="flex items-center gap-2 px-4 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all disabled:opacity-50"
            >
              <PlusIcon className="h-4 w-4" />Novo bot com IA
            </button>
          </div>
        </>
      )}

      <ConversationsList />
    </div>
  );
}

function BotCard({ bot, onEdit }: { bot: Bot; onEdit: () => void }) {
  const isAi = bot.mode === 'ai';
  return (
    <button
      onClick={onEdit}
      className="w-full text-left rounded-2xl bg-surface-card border border-border-DEFAULT/50 p-5 flex items-center justify-between gap-3 hover:border-green-500/30 transition-all"
    >
      <div className="flex items-center gap-4 min-w-0">
        <div className={clsx('p-2.5 rounded-xl shrink-0', isAi ? 'bg-cyan-500/10' : 'bg-blue-500/10')}>
          {isAi ? <CpuChipIcon className="h-5 w-5 text-cyan-400" /> : <BoltIcon className="h-5 w-5 text-blue-500" />}
        </div>
        <div className="min-w-0">
          <h4 className="font-medium text-tx-primary truncate">{bot.name}</h4>
          <p className="text-xs text-tx-muted mt-0.5">
            {isAi ? 'IA' : `Fluxograma · ${bot.flow_data?.nodes?.length ?? 0} passos`} · {bot.conversations_count} conversas
          </p>
        </div>
      </div>
      <span
        className={clsx(
          'px-2.5 py-1 rounded-full text-xs font-medium shrink-0',
          bot.is_active ? 'bg-green-500/10 text-green-500' : 'bg-gray-500/10 text-gray-400',
        )}
      >
        {bot.is_active ? 'Ativo' : 'Inativo'}
      </span>
    </button>
  );
}

// ============================================================
//  EDITOR
// ============================================================
function BotEditor({
  bot,
  aiAvailable,
  inboundUrl,
  onBack,
}: {
  bot: Bot;
  aiAvailable: boolean;
  inboundUrl: string | null;
  onBack: () => void;
}) {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Bot>(bot);
  const [dirty, setDirty] = useState(false);
  const [testing, setTesting] = useState(false);

  const patch = (changes: Partial<Bot>) => {
    setDraft(d => ({ ...d, ...changes }));
    setDirty(true);
  };

  const saveMutation = useMutation({
    mutationFn: () =>
      botService.update(bot.id, {
        name: draft.name,
        is_active: draft.is_active,
        flow_data: draft.flow_data,
        ai_config: draft.ai_config,
        trigger_keywords: draft.trigger_keywords,
        only_business_hours: draft.only_business_hours,
        business_hours: draft.business_hours,
      }),
    onSuccess: () => {
      toast.success('Bot salvo!');
      setDirty(false);
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
    onError: () => toast.error('Não consegui salvar'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => botService.remove(bot.id),
    onSuccess: () => {
      toast.success('Bot removido');
      queryClient.invalidateQueries({ queryKey: ['bots'] });
      onBack();
    },
  });

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Barra do editor */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-3 min-w-0">
          <button onClick={onBack} className="p-2 rounded-lg hover:bg-surface-hover text-tx-muted shrink-0">
            <ArrowLeftIcon className="h-5 w-5" />
          </button>
          <input
            value={draft.name}
            onChange={e => patch({ name: e.target.value })}
            className="bg-transparent text-lg font-semibold text-tx-primary outline-none border-b border-transparent focus:border-green-500/40 min-w-0"
          />
          <span className={clsx('px-2 py-0.5 rounded-full text-[10px] font-medium shrink-0', draft.mode === 'ai' ? 'bg-cyan-500/10 text-cyan-400' : 'bg-blue-500/10 text-blue-500')}>
            {draft.mode === 'ai' ? 'IA' : 'FLUXO'}
          </span>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setTesting(true)}
            className="flex items-center gap-2 px-3 py-2 rounded-xl bg-surface-hover text-tx-secondary text-sm font-medium hover:bg-surface-page transition-all"
          >
            <PlayIcon className="h-4 w-4 text-green-500" />
            Testar bot
          </button>
          <label className="flex items-center gap-2 px-3 py-2 rounded-xl bg-surface-hover cursor-pointer">
            <input
              type="checkbox"
              checked={draft.is_active}
              onChange={e => patch({ is_active: e.target.checked })}
              className="accent-green-500"
            />
            <span className="text-sm text-tx-secondary">Ativo</span>
          </label>
          <button
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending || !dirty}
            className="px-4 py-2 rounded-xl bg-green-500/10 text-green-500 text-sm font-medium hover:bg-green-500/20 disabled:opacity-40 transition-all"
          >
            {saveMutation.isPending ? 'Salvando...' : dirty ? 'Salvar' : 'Salvo'}
          </button>
          <button
            onClick={() => { if (confirm('Remover este bot?')) deleteMutation.mutate(); }}
            className="p-2 rounded-xl bg-red-500/10 text-red-400 hover:bg-red-500/20 transition-all"
          >
            <TrashIcon className="h-4 w-4" />
          </button>
        </div>
      </div>

      {draft.is_active && !inboundUrl && (
        <div className="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3">
          <ExclamationTriangleIcon className="h-4 w-4 text-amber-500 shrink-0 mt-0.5" />
          <p className="text-xs text-amber-500">
            O bot está ativo mas o WhatsApp ainda não entrega mensagens recebidas. Gere a URL de recebimento em
            <strong> Recebimento de mensagens</strong>, abaixo, e cole no seu provedor.
          </p>
        </div>
      )}

      {/* Coluna única: o fluxograma precisa da largura toda. */}
      <div className="space-y-4 sm:space-y-6 min-w-0">
        <TriggerSection draft={draft} patch={patch} />

        {draft.mode === 'flow' ? (
          <FlowCanvas
            nodes={draft.flow_data?.nodes ?? []}
            onChange={nodes => patch({ flow_data: { ...draft.flow_data, nodes } })}
          />
        ) : (
          <AiConfigForm
            config={draft.ai_config ?? {}}
            aiAvailable={aiAvailable}
            onChange={ai_config => patch({ ai_config })}
          />
        )}

        <InboundSection inboundUrl={inboundUrl} />
      </div>

      <Modal isOpen={testing} onClose={() => setTesting(false)} size="full">
        <ModalHeader onClose={() => setTesting(false)}>
          <div className="flex items-center gap-2">
            <PlayIcon className="h-4 w-4 text-green-500" />
            <span>Testar bot</span>
          </div>
        </ModalHeader>
        <Simulator botId={bot.id} dirty={dirty} />
      </Modal>
    </div>
  );
}

// ============================================================
//  GATILHOS E HORÁRIO
// ============================================================
function TriggerSection({ draft, patch }: { draft: Bot; patch: (c: Partial<Bot>) => void }) {
  const hours = draft.business_hours ?? {};

  const setDay = (key: string, changes: Partial<{ enabled: boolean; start: string; end: string }>) => {
    const current = hours[key] ?? { enabled: true, start: '09:00', end: '18:00' };
    patch({ business_hours: { ...hours, [key]: { ...current, ...changes } } });
  };

  return (
    <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
      <h3 className="font-medium text-tx-primary">Quando o bot atende</h3>

      <div>
        <label className="block text-xs text-tx-muted mb-1.5">Palavras-chave que acionam o bot</label>
        <TagInput
          values={draft.trigger_keywords ?? []}
          placeholder="Deixe vazio para atender todas as mensagens"
          onChange={trigger_keywords => patch({ trigger_keywords })}
        />
        <p className="text-[11px] text-tx-muted mt-1.5">
          Sem palavra-chave, o bot responde qualquer mensagem recebida.
        </p>
      </div>

      <label className="flex items-center gap-2 cursor-pointer">
        <input
          type="checkbox"
          checked={draft.only_business_hours}
          onChange={e => patch({ only_business_hours: e.target.checked })}
          className="accent-green-500"
        />
        <span className="text-sm text-tx-secondary">Atender só em horário comercial</span>
      </label>

      {draft.only_business_hours && (
        <div className="space-y-2">
          {WEEK.map(day => {
            const cfg = hours[day.key] ?? { enabled: false, start: '09:00', end: '18:00' };
            return (
              <div key={day.key} className="flex items-center gap-3">
                <label className="flex items-center gap-2 w-20 shrink-0 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={cfg.enabled}
                    onChange={e => setDay(day.key, { enabled: e.target.checked })}
                    className="accent-green-500"
                  />
                  <span className="text-sm text-tx-secondary">{day.label}</span>
                </label>
                <input
                  type="time"
                  value={cfg.start}
                  disabled={!cfg.enabled}
                  onChange={e => setDay(day.key, { start: e.target.value })}
                  className="px-2 py-1 rounded-lg bg-surface-page border border-border-DEFAULT/50 text-sm text-tx-primary disabled:opacity-40"
                />
                <span className="text-tx-muted text-sm">até</span>
                <input
                  type="time"
                  value={cfg.end}
                  disabled={!cfg.enabled}
                  onChange={e => setDay(day.key, { end: e.target.value })}
                  className="px-2 py-1 rounded-lg bg-surface-page border border-border-DEFAULT/50 text-sm text-tx-primary disabled:opacity-40"
                />
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ============================================================
//  CONFIGURAÇÃO DA IA
// ============================================================
function AiConfigForm({
  config,
  aiAvailable,
  onChange,
}: {
  config: BotAiConfig;
  aiAvailable: boolean;
  onChange: (c: BotAiConfig) => void;
}) {
  const set = (changes: Partial<BotAiConfig>) => onChange({ ...config, ...changes });
  const allowed = config.allowed_actions ?? ['lookup_order'];

  const toggleAction = (action: BotAiAction) => {
    const next = allowed.includes(action) ? allowed.filter(a => a !== action) : [...allowed, action];
    set({ allowed_actions: next });
  };

  const Field = ({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) => (
    <div>
      <label className="block text-xs font-medium text-tx-secondary mb-1.5">{label}</label>
      {children}
      {hint && <p className="text-[11px] text-tx-muted mt-1.5">{hint}</p>}
    </div>
  );

  const inputClass = 'w-full px-3 py-2 rounded-lg bg-surface-page border border-border-DEFAULT/50 text-sm text-tx-primary';

  return (
    <div className="space-y-4 sm:space-y-6">
      {!aiAvailable && (
        <div className="flex items-start gap-2 rounded-xl border border-red-500/30 bg-red-500/5 p-3">
          <ExclamationTriangleIcon className="h-4 w-4 text-red-400 shrink-0 mt-0.5" />
          <p className="text-xs text-red-400">
            Falta a variável <code>OPENROUTER_API_KEY</code> no servidor. Você pode configurar tudo aqui, mas o bot só
            responderá depois que a chave for adicionada.
          </p>
        </div>
      )}

      {/* Negócio */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
        <div>
          <h3 className="font-medium text-tx-primary">O que a IA precisa saber do seu negócio</h3>
          <p className="text-xs text-tx-muted mt-1">
            É daqui que ela tira as respostas. O que não estiver escrito, ela não inventa — transfere para humano.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Nome do atendente" hint="Como a IA se apresenta ao cliente.">
            <input value={config.agent_name ?? ''} onChange={e => set({ agent_name: e.target.value })} placeholder="ex: Ana" className={inputClass} />
          </Field>
          <Field label="Nome da empresa">
            <input value={config.business_name ?? ''} onChange={e => set({ business_name: e.target.value })} className={inputClass} />
          </Field>
        </div>

        <Field label="O que a empresa faz" hint="Uma descrição curta, como você explicaria para um cliente novo.">
          <textarea value={config.business_description ?? ''} onChange={e => set({ business_description: e.target.value })} rows={2} className={inputClass} />
        </Field>

        <Field label="Produtos e serviços" hint="Nomes, o que cada um entrega e faixa de preço, se quiser que ela informe.">
          <textarea value={config.products_description ?? ''} onChange={e => set({ products_description: e.target.value })} rows={3} className={inputClass} />
        </Field>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Horário de atendimento humano">
            <input value={config.support_hours ?? ''} onChange={e => set({ support_hours: e.target.value })} placeholder="Seg a sex, 9h às 18h" className={inputClass} />
          </Field>
          <Field label="Política de reembolso">
            <input value={config.refund_policy ?? ''} onChange={e => set({ refund_policy: e.target.value })} placeholder="7 dias de garantia" className={inputClass} />
          </Field>
        </div>
      </div>

      {/* Escopo */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
        <h3 className="font-medium text-tx-primary">O que ela deve atender</h3>

        <Field label="Assuntos que ela resolve" hint="Enter para adicionar cada item.">
          <TagInput values={config.topics ?? []} onChange={topics => set({ topics })} placeholder="ex: status do pedido" />
        </Field>

        <Field label="O que ela NUNCA deve fazer" hint="Limites explícitos. Ela transfere para humano nesses casos.">
          <TagInput values={config.restrictions ?? []} onChange={restrictions => set({ restrictions })} placeholder="ex: prometer desconto" />
        </Field>

        <Field label="Tom de voz">
          <select value={config.tone ?? 'profissional e cordial'} onChange={e => set({ tone: e.target.value })} className={inputClass}>
            <option value="profissional e cordial">Profissional e cordial</option>
            <option value="amigavel e proximo, com linguagem simples">Amigável e próximo</option>
            <option value="descontraido, pode usar emojis com moderacao">Descontraído, com emojis</option>
            <option value="formal e objetivo, sem girias">Formal e objetivo</option>
          </select>
        </Field>

        <FaqEditor faq={config.faq ?? []} onChange={faq => set({ faq })} />

        <Field label="Instruções adicionais" hint="Qualquer regra específica sua que não coube acima.">
          <textarea value={config.custom_instructions ?? ''} onChange={e => set({ custom_instructions: e.target.value })} rows={3} className={inputClass} />
        </Field>
      </div>

      {/* Permissões */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
        <div>
          <h3 className="font-medium text-tx-primary">O que ela pode fazer no gateway</h3>
          <p className="text-xs text-tx-muted mt-1">
            Cada permissão é uma chave separada. O que estiver desligado, a IA não consegue executar nem se o cliente pedir.
          </p>
        </div>

        <div className="space-y-2">
          {AI_ACTIONS.map(action => {
            const on = allowed.includes(action.value);
            return (
              <label
                key={action.value}
                className={clsx(
                  'flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-colors',
                  on ? 'border-green-500/30 bg-green-500/5' : 'border-border-DEFAULT/50 bg-surface-page',
                  action.readOnly && 'cursor-default opacity-90',
                )}
              >
                <input
                  type="checkbox"
                  checked={on}
                  disabled={action.readOnly}
                  onChange={() => toggleAction(action.value)}
                  className="accent-green-500 mt-0.5"
                />
                <div className="min-w-0">
                  <p className="text-sm font-medium text-tx-primary">
                    {action.label}
                    {action.readOnly && <span className="ml-2 text-[10px] text-tx-muted">sempre ligado</span>}
                  </p>
                  <p className="text-[11px] text-tx-muted mt-0.5">{action.description}</p>
                </div>
              </label>
            );
          })}
        </div>
      </div>

      {/* Saídas */}
      <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-4">
        <h3 className="font-medium text-tx-primary">Quando ela não der conta</h3>

        <Field label="Mensagem ao transferir para humano">
          <input
            value={config.fallback_message ?? ''}
            onChange={e => set({ fallback_message: e.target.value })}
            placeholder="Vou chamar um atendente para te ajudar, só um momento."
            className={inputClass}
          />
        </Field>

        <Field label="Mensagem fora do horário">
          <input
            value={config.off_hours_message ?? ''}
            onChange={e => set({ off_hours_message: e.target.value })}
            placeholder="Nosso atendimento está fora do horário. Retornamos assim que abrirmos."
            className={inputClass}
          />
        </Field>

        <Field
          label={`Criatividade das respostas: ${(config.temperature ?? 0.5).toFixed(1)}`}
          hint="Mais baixo responde sempre parecido e gruda no que você escreveu. Mais alto varia o texto."
        >
          <input
            type="range"
            min={0}
            max={1}
            step={0.1}
            value={config.temperature ?? 0.5}
            onChange={e => set({ temperature: Number(e.target.value) })}
            className="w-full accent-green-500"
          />
        </Field>
      </div>
    </div>
  );
}

function FaqEditor({ faq, onChange }: { faq: { question: string; answer: string }[]; onChange: (f: { question: string; answer: string }[]) => void }) {
  return (
    <div>
      <label className="block text-xs font-medium text-tx-secondary mb-1.5">Perguntas frequentes</label>
      <p className="text-[11px] text-tx-muted mb-2">
        A IA trata estas respostas como verdade absoluta. É o jeito mais confiável de ensiná-la.
      </p>
      <div className="space-y-2">
        {faq.map((item, i) => (
          <div key={i} className="rounded-xl border border-border-DEFAULT/50 bg-surface-page p-3 space-y-2">
            <div className="flex gap-2">
              <input
                value={item.question}
                onChange={e => {
                  const next = [...faq];
                  next[i] = { ...item, question: e.target.value };
                  onChange(next);
                }}
                placeholder="Pergunta do cliente"
                className="flex-1 px-3 py-2 rounded-lg bg-surface-card border border-border-DEFAULT/50 text-sm text-tx-primary"
              />
              <button onClick={() => onChange(faq.filter((_, j) => j !== i))} className="p-2 rounded-lg bg-red-500/10 text-red-400 shrink-0">
                <XMarkIcon className="h-4 w-4" />
              </button>
            </div>
            <textarea
              value={item.answer}
              onChange={e => {
                const next = [...faq];
                next[i] = { ...item, answer: e.target.value };
                onChange(next);
              }}
              rows={2}
              placeholder="Resposta que a IA deve dar"
              className="w-full px-3 py-2 rounded-lg bg-surface-card border border-border-DEFAULT/50 text-sm text-tx-primary resize-y"
            />
          </div>
        ))}
      </div>
      <button
        onClick={() => onChange([...faq, { question: '', answer: '' }])}
        className="mt-2 flex items-center gap-1.5 text-xs text-green-500 hover:text-green-400"
      >
        <PlusIcon className="h-3.5 w-3.5" />Adicionar pergunta
      </button>
    </div>
  );
}

// ============================================================
//  SIMULADOR
// ============================================================
function Simulator({ botId, dirty }: { botId: string; dirty: boolean }) {
  const [messages, setMessages] = useState<{ from: 'you' | 'bot'; text: string }[]>([]);
  const [input, setInput] = useState('');

  const sendMutation = useMutation({
    mutationFn: ({ message, reset }: { message: string; reset: boolean }) => botService.simulate(botId, message, reset),
    onSuccess: (data, variables) => {
      setMessages(prev => [
        ...prev,
        { from: 'you' as const, text: variables.message },
        ...data.replies.map(r => ({ from: 'bot' as const, text: r })),
        ...(data.handoff ? [{ from: 'bot' as const, text: '— conversa transferida para atendente —' }] : []),
      ]);
      setInput('');
    },
    onError: (e: any) => toast.error(e?.response?.data?.error?.message ?? 'Falha no teste'),
  });

  const send = (reset = false) => {
    const message = input.trim();
    if (!message) return;
    if (reset) setMessages([]);
    sendMutation.mutate({ message, reset });
  };

  return (
    <div className="overflow-hidden">
      <div className="px-4 py-2.5 border-b border-border-DEFAULT/50 flex items-center justify-between gap-3">
        <span className="text-xs text-tx-muted truncate">
          Escreva como se fosse o cliente. Nada é enviado para fora.
        </span>
        <button
          onClick={() => { setMessages([]); setInput(''); }}
          title="Reiniciar conversa de teste"
          className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg hover:bg-surface-hover text-tx-muted text-xs shrink-0"
        >
          <ArrowPathIcon className="h-4 w-4" />
          <span className="hidden sm:inline">Reiniciar</span>
        </button>
      </div>

      {dirty && (
        <p className="px-4 py-2 text-[11px] text-amber-500 bg-amber-500/5 border-b border-amber-500/20">
          Você tem alterações não salvas — o teste roda a última versão salva.
        </p>
      )}

      {/* Conversa centralizada: em tela cheia o balão não deve atravessar a página. */}
      <div className="p-4 h-[22rem] overflow-y-auto">
        <div className="max-w-2xl mx-auto space-y-2">
          {messages.length === 0 ? (
            <p className="text-xs text-tx-muted text-center py-12">
              Mande a primeira mensagem para percorrer o fluxo do começo.
            </p>
          ) : (
            messages.map((m, i) => (
              <div key={i} className={clsx('flex', m.from === 'you' ? 'justify-end' : 'justify-start')}>
                <div
                  className={clsx(
                    'max-w-[80%] px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words',
                    m.from === 'you' ? 'bg-green-500/15 text-tx-primary' : 'bg-surface-page text-tx-secondary',
                  )}
                >
                  {m.text}
                </div>
              </div>
            ))
          )}
          {sendMutation.isPending && <p className="text-xs text-tx-muted">digitando...</p>}
        </div>
      </div>

      <div className="p-3 border-t border-border-DEFAULT/50">
        <div className="max-w-2xl mx-auto flex gap-2">
          <input
            value={input}
            onChange={e => setInput(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') send(messages.length === 0); }}
            placeholder="Mensagem do cliente..."
            className="flex-1 px-3 py-2 rounded-xl bg-surface-page border border-border-DEFAULT/50 text-sm text-tx-primary"
          />
          <button
            onClick={() => send(messages.length === 0)}
            disabled={sendMutation.isPending || !input.trim()}
            className="px-4 py-2 rounded-xl bg-green-500/10 text-green-500 text-sm font-medium hover:bg-green-500/20 disabled:opacity-40"
          >
            Enviar
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================
//  RECEBIMENTO + CONVERSAS
// ============================================================
function InboundSection({ inboundUrl }: { inboundUrl: string | null }) {
  const queryClient = useQueryClient();
  const [url, setUrl] = useState(inboundUrl);

  const mutation = useMutation({
    mutationFn: () => botService.regenerateInboundToken(),
    onSuccess: newUrl => {
      setUrl(newUrl);
      toast.success('URL gerada!');
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
    onError: (e: any) => toast.error(e?.response?.data?.error?.message ?? 'Falha ao gerar URL'),
  });

  return (
    <div className="rounded-2xl bg-surface-card p-4 sm:p-6 border border-border-DEFAULT/50 space-y-3">
      <h3 className="font-medium text-tx-primary">Recebimento de mensagens</h3>
      <p className="text-xs text-tx-muted">
        Cole esta URL no webhook do seu provedor de WhatsApp, no evento de mensagem recebida. Sem isso o bot nunca é
        acionado, porque nenhuma mensagem chega até ele.
      </p>

      {url ? (
        <div className="flex gap-2">
          <input readOnly value={url} className="flex-1 px-3 py-2 rounded-lg bg-surface-page border border-border-DEFAULT/50 text-xs text-tx-secondary font-mono" />
          <button
            onClick={() => { navigator.clipboard.writeText(url); toast.success('Copiado!'); }}
            className="p-2 rounded-lg bg-surface-hover text-tx-secondary shrink-0"
          >
            <ClipboardDocumentIcon className="h-4 w-4" />
          </button>
        </div>
      ) : (
        <p className="text-xs text-tx-muted">Nenhuma URL gerada ainda.</p>
      )}

      <button
        onClick={() => mutation.mutate()}
        disabled={mutation.isPending}
        className="flex items-center gap-2 px-3 py-2 rounded-xl bg-surface-hover text-tx-secondary text-xs font-medium hover:bg-surface-page disabled:opacity-50"
      >
        <ArrowPathIcon className={clsx('h-3.5 w-3.5', mutation.isPending && 'animate-spin')} />
        {url ? 'Gerar nova URL' : 'Gerar URL'}
      </button>
      {url && (
        <p className="text-[11px] text-amber-500">Gerar uma nova invalida a anterior — atualize no provedor depois.</p>
      )}
    </div>
  );
}

function ConversationsList() {
  const { data: conversations = [], isLoading } = useQuery({
    queryKey: ['bot-conversations'],
    queryFn: () => botService.conversations(),
    refetchInterval: 30000,
  });

  if (isLoading || conversations.length === 0) return null;

  const statusStyle: Record<string, string> = {
    active: 'bg-green-500/10 text-green-500',
    handoff: 'bg-amber-500/10 text-amber-500',
    ended: 'bg-gray-500/10 text-gray-400',
  };
  const statusLabel: Record<string, string> = {
    active: 'Com o bot',
    handoff: 'Aguardando humano',
    ended: 'Encerrada',
  };

  return (
    <div>
      <h3 className="font-medium text-tx-primary mb-3">Conversas recentes</h3>
      <div className="space-y-2">
        {conversations.map(c => (
          <div key={c.id} className="rounded-xl bg-surface-card border border-border-DEFAULT/50 p-3 flex items-center justify-between gap-3">
            <div className="min-w-0">
              <p className="text-sm font-medium text-tx-primary truncate">{c.contact_name || c.contact_phone}</p>
              <p className="text-[11px] text-tx-muted">
                {c.message_count} mensagens{c.bot ? ` · ${c.bot.name}` : ''}
              </p>
            </div>
            <span className={clsx('px-2.5 py-1 rounded-full text-[11px] font-medium shrink-0', statusStyle[c.status])}>
              {statusLabel[c.status]}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

// ============================================================
//  ENTRADA DE TAGS
// ============================================================
function TagInput({ values, onChange, placeholder }: { values: string[]; onChange: (v: string[]) => void; placeholder?: string }) {
  const [text, setText] = useState('');

  const add = () => {
    const value = text.trim();
    if (!value || values.includes(value)) { setText(''); return; }
    onChange([...values, value]);
    setText('');
  };

  return (
    <div className="rounded-lg bg-surface-page border border-border-DEFAULT/50 p-2 flex flex-wrap gap-1.5">
      {values.map(v => (
        <span key={v} className="flex items-center gap-1 px-2 py-1 rounded-md bg-green-500/10 text-green-500 text-xs">
          {v}
          <button onClick={() => onChange(values.filter(x => x !== v))}>
            <XMarkIcon className="h-3 w-3" />
          </button>
        </span>
      ))}
      <input
        value={text}
        onChange={e => setText(e.target.value)}
        onKeyDown={e => {
          if (e.key === 'Enter') { e.preventDefault(); add(); }
          if (e.key === 'Backspace' && !text && values.length) onChange(values.slice(0, -1));
        }}
        onBlur={add}
        placeholder={values.length === 0 ? placeholder : ''}
        className="flex-1 min-w-[10rem] bg-transparent text-sm text-tx-primary outline-none px-1"
      />
    </div>
  );
}
