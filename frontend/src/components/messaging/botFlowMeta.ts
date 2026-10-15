import {
  ChatBubbleLeftRightIcon,
  QuestionMarkCircleIcon,
  ArrowsRightLeftIcon,
  WrenchScrewdriverIcon,
  CpuChipIcon,
  UserIcon,
  FlagIcon,
  VariableIcon,
  GlobeAltIcon,
  CircleStackIcon,
  ClockIcon,
} from '@heroicons/react/24/outline';
import type { BotNode, BotNodeType, BotAiAction, BotConditionOperator } from '@/services/api/bot.service';

// ============================================================
//  METADADOS DOS TIPOS DE NÓ
// ============================================================
export interface NodeMeta {
  label: string;
  icon: any;
  /** Cor crua, para SVG e estilos inline do canvas. */
  hex: string;
  ring: string;
  chip: string;
  hint: string;
}

export const NODE_META: Record<BotNodeType, NodeMeta> = {
  message: {
    label: 'Mensagem',
    icon: ChatBubbleLeftRightIcon,
    hex: '#3b82f6',
    ring: 'border-blue-500/40',
    chip: 'bg-blue-500/10 text-blue-500',
    hint: 'Envia um texto e segue adiante',
  },
  question: {
    label: 'Pergunta',
    icon: QuestionMarkCircleIcon,
    hex: '#f59e0b',
    ring: 'border-amber-500/40',
    chip: 'bg-amber-500/10 text-amber-500',
    hint: 'Pergunta e espera a resposta do cliente',
  },
  condition: {
    label: 'Condição',
    icon: ArrowsRightLeftIcon,
    hex: '#a855f7',
    ring: 'border-purple-500/40',
    chip: 'bg-purple-500/10 text-purple-400',
    hint: 'Compara uma variável e desvia o caminho',
  },
  delay: {
    label: 'Esperar',
    icon: ClockIcon,
    hex: '#8b5cf6',
    ring: 'border-violet-500/40',
    chip: 'bg-violet-500/10 text-violet-400',
    hint: 'Pausa o fluxo e retoma sozinho depois',
  },
  set: {
    label: 'Definir variável',
    icon: VariableIcon,
    hex: '#eab308',
    ring: 'border-yellow-500/40',
    chip: 'bg-yellow-500/10 text-yellow-500',
    hint: 'Guarda um valor para usar depois',
  },
  http: {
    label: 'Chamar API',
    icon: GlobeAltIcon,
    hex: '#ec4899',
    ring: 'border-pink-500/40',
    chip: 'bg-pink-500/10 text-pink-400',
    hint: 'Requisição HTTP para qualquer serviço externo',
  },
  system_data: {
    label: 'Dados do sistema',
    icon: CircleStackIcon,
    hex: '#14b8a6',
    ring: 'border-teal-500/40',
    chip: 'bg-teal-500/10 text-teal-400',
    hint: 'Carrega os pedidos do contato como variáveis',
  },
  action: {
    label: 'Ação',
    icon: WrenchScrewdriverIcon,
    hex: '#22c55e',
    ring: 'border-green-500/40',
    chip: 'bg-green-500/10 text-green-500',
    hint: 'Executa algo no gateway',
  },
  ai: {
    label: 'IA responde',
    icon: CpuChipIcon,
    hex: '#06b6d4',
    ring: 'border-cyan-500/40',
    chip: 'bg-cyan-500/10 text-cyan-400',
    hint: 'Entrega a resposta para a IA',
  },
  handoff: {
    label: 'Transferir',
    icon: UserIcon,
    hex: '#f97316',
    ring: 'border-orange-500/40',
    chip: 'bg-orange-500/10 text-orange-400',
    hint: 'Passa a conversa para um humano',
  },
  end: {
    label: 'Encerrar',
    icon: FlagIcon,
    hex: '#94a3b8',
    ring: 'border-slate-500/40',
    chip: 'bg-slate-500/10 text-slate-400',
    hint: 'Fecha o atendimento',
  },
};

export const AI_ACTIONS: { value: BotAiAction; label: string; description: string; readOnly?: boolean }[] = [
  {
    value: 'lookup_order',
    label: 'Consultar pedidos do cliente',
    description: 'A IA enxerga status, valor e produto dos pedidos daquele telefone. Só leitura.',
    readOnly: true,
  },
  {
    value: 'resend_pix',
    label: 'Reenviar código PIX',
    description: 'Devolve o copia e cola do pedido pendente do próprio cliente.',
  },
  {
    value: 'resend_access',
    label: 'Reenviar acesso da área de membros',
    description: 'Só para quem tem compra aprovada naquele telefone.',
  },
  {
    value: 'create_payment_link',
    label: 'Gerar link de pagamento',
    description: 'A IA monta o link de checkout de um produto ativo.',
  },
];

/** Verificações que consultam o gateway direto, sem precisar de variável. */
export const CONDITION_SUBJECTS = [
  { value: 'has_order', label: 'Cliente tem algum pedido' },
  { value: 'has_paid_order', label: 'Cliente tem pedido pago' },
  { value: 'has_pending_order', label: 'Cliente tem pedido aguardando pagamento' },
];

export const CONDITION_OPERATORS: { value: BotConditionOperator; label: string; needsValue: boolean }[] = [
  { value: 'equals', label: 'é igual a', needsValue: true },
  { value: 'not_equals', label: 'é diferente de', needsValue: true },
  { value: 'contains', label: 'contém', needsValue: true },
  { value: 'not_contains', label: 'não contém', needsValue: true },
  { value: 'starts_with', label: 'começa com', needsValue: true },
  { value: 'is_empty', label: 'está vazia', needsValue: false },
  { value: 'is_not_empty', label: 'está preenchida', needsValue: false },
  { value: 'greater_than', label: 'é maior que', needsValue: true },
  { value: 'less_than', label: 'é menor que', needsValue: true },
];

/** Sempre disponíveis, sem precisar de nenhum nó. */
export const BUILTIN_VARIABLES = [
  { name: 'nome', description: 'Nome do contato no WhatsApp' },
  { name: 'telefone', description: 'Telefone de quem escreveu' },
  { name: 'http_status', description: 'Código HTTP da última chamada de API' },
];

/** Aparecem depois que um nó "Dados do sistema" roda. */
export const SYSTEM_VARIABLES = [
  { name: 'tem_pedido', description: 'sim / nao' },
  { name: 'tem_pedido_pago', description: 'sim / nao' },
  { name: 'pedidos_total', description: 'Quantidade de pedidos encontrados' },
  { name: 'pedido_id', description: 'ID do pedido mais recente' },
  { name: 'pedido_status', description: 'Status do pedido mais recente' },
  { name: 'pedido_valor', description: 'Valor formatado, ex: R$ 97,00' },
  { name: 'pedido_produto', description: 'Nome do produto' },
  { name: 'pedido_criado_em', description: 'Data de criação' },
];

/** Variáveis que o próprio fluxo cria, para o autocomplete do inspetor. */
export function variablesDefinedBy(nodes: BotNode[]): string[] {
  const names = new Set<string>();

  for (const node of nodes) {
    if (node.save_as?.trim()) names.add(node.save_as.trim());
    for (const assignment of node.assignments ?? []) {
      if (assignment.key?.trim()) names.add(assignment.key.trim());
    }
    if (node.type === 'system_data') SYSTEM_VARIABLES.forEach(v => names.add(v.name));
  }

  return [...names].sort();
}

export const newId = () => 'n_' + Math.random().toString(36).slice(2, 10);

// ============================================================
//  LIGAÇÕES
// ============================================================
export interface NodeOutlet {
  /** Campo do BotNode que guarda o destino, ou `opt:{índice}` para opções. */
  handle: string;
  label: string;
  hex: string;
}

/**
 * Saídas de um nó. É a fonte da verdade para os pontos de conexão do canvas,
 * para as arestas desenhadas e para o que `onConnect` grava.
 */
export function outletsOf(node: BotNode): NodeOutlet[] {
  switch (node.type) {
    case 'question': {
      const options = node.options ?? [];
      if (options.length === 0) {
        return [{ handle: 'next', label: 'resposta', hex: '#f59e0b' }];
      }
      return options.map((opt, i) => ({
        handle: `opt:${i}`,
        label: opt.label || `Opção ${i + 1}`,
        hex: '#f59e0b',
      }));
    }
    case 'condition':
      return [
        { handle: 'next_true', label: 'sim', hex: '#22c55e' },
        { handle: 'next_false', label: 'não', hex: '#ef4444' },
      ];
    case 'action':
    case 'http':
      return [
        { handle: 'next', label: 'ok', hex: '#22c55e' },
        { handle: 'next_error', label: 'erro', hex: '#ef4444' },
      ];
    case 'handoff':
    case 'end':
      return [];
    default:
      return [{ handle: 'next', label: '', hex: '#64748b' }];
  }
}

/** Lê o destino de uma saída. */
export function getTarget(node: BotNode, handle: string): string | null {
  if (handle.startsWith('opt:')) {
    const index = Number(handle.slice(4));
    return node.options?.[index]?.next ?? null;
  }
  return (node[handle as 'next' | 'next_true' | 'next_false' | 'next_error'] as string | null) ?? null;
}

/** Grava o destino de uma saída, devolvendo um nó novo. */
export function setTarget(node: BotNode, handle: string, target: string | null): BotNode {
  if (handle.startsWith('opt:')) {
    const index = Number(handle.slice(4));
    const options = [...(node.options ?? [])];
    if (!options[index]) return node;
    options[index] = { ...options[index], next: target };
    return { ...node, options };
  }
  return { ...node, [handle]: target };
}

/** Remove todas as ligações que apontam para um nó apagado. */
export function detachReferencesTo(nodes: BotNode[], removedId: string): BotNode[] {
  return nodes.map(n => ({
    ...n,
    next: n.next === removedId ? null : n.next,
    next_true: n.next_true === removedId ? null : n.next_true,
    next_false: n.next_false === removedId ? null : n.next_false,
    next_error: n.next_error === removedId ? null : n.next_error,
    options: n.options?.map(o => (o.next === removedId ? { ...o, next: null } : o)),
  }));
}

// ============================================================
//  LAYOUT
// ============================================================
const COL_WIDTH = 320;
const ROW_HEIGHT = 190;

/**
 * Posiciona por níveis a partir do primeiro nó, seguindo as ligações.
 * Usado em fluxos antigos (salvos sem posição) e no botão "Reorganizar".
 */
export function autoLayout(nodes: BotNode[]): BotNode[] {
  if (nodes.length === 0) return nodes;

  const byId = new Map(nodes.map(n => [n.id, n]));
  const level = new Map<string, number>();
  const queue: string[] = [nodes[0].id];
  level.set(nodes[0].id, 0);

  while (queue.length) {
    const id = queue.shift()!;
    const node = byId.get(id);
    if (!node) continue;

    for (const outlet of outletsOf(node)) {
      const target = getTarget(node, outlet.handle);
      if (target && byId.has(target) && !level.has(target)) {
        level.set(target, (level.get(id) ?? 0) + 1);
        queue.push(target);
      }
    }
  }

  // Nós que ninguém alcança ainda precisam de um lugar: vão para o fim.
  const maxLevel = Math.max(0, ...level.values());
  nodes.forEach(n => {
    if (!level.has(n.id)) level.set(n.id, maxLevel + 1);
  });

  const perLevel = new Map<number, number>();

  return nodes.map(n => {
    const lvl = level.get(n.id) ?? 0;
    const index = perLevel.get(lvl) ?? 0;
    perLevel.set(lvl, index + 1);

    return { ...n, position: { x: index * COL_WIDTH, y: lvl * ROW_HEIGHT } };
  });
}

/** Garante posição em todo nó, sem mexer nas que já existem. */
export function ensurePositions(nodes: BotNode[]): BotNode[] {
  if (nodes.every(n => n.position)) return nodes;
  if (nodes.every(n => !n.position)) return autoLayout(nodes);

  let fallback = 0;
  return nodes.map(n =>
    n.position ? n : { ...n, position: { x: (fallback++ % 3) * COL_WIDTH, y: 9999 } },
  );
}

/** Fluxo inicial de um bot novo: já sai funcionando e serve de exemplo. */
export function starterFlow(): BotNode[] {
  const menu = newId();
  const pedido = newId();
  const humano = newId();
  const fim = newId();

  return autoLayout([
    {
      id: menu,
      type: 'question',
      text: 'Olá {{nome}}! Sou o atendimento automático. Como posso ajudar?',
      invalid_message: 'Não entendi. Responda com o número da opção.',
      options: [
        { label: 'Consultar meu pedido', next: pedido },
        { label: 'Falar com atendente', next: humano },
      ],
    },
    { id: pedido, type: 'condition', subject: 'has_order', next_true: fim, next_false: humano },
    { id: humano, type: 'handoff', text: 'Certo! Vou chamar um atendente para você.' },
    { id: fim, type: 'end', text: 'Encontrei seu pedido. Um atendente segue com os detalhes.' },
  ]);
}
