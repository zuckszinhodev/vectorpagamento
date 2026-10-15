import { useCallback, useMemo, useState, useEffect } from 'react';
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  BackgroundVariant,
  Controls,
  MiniMap,
  Handle,
  Position,
  useReactFlow,
  useViewport,
  applyNodeChanges,
  type Node,
  type Edge,
  type Connection,
  type NodeChange,
  type NodeProps,
  type EdgeChange,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import {
  PlusIcon,
  TrashIcon,
  XMarkIcon,
  Squares2X2Icon,
  LockClosedIcon,
  ArrowsPointingOutIcon,
  MapIcon,
} from '@heroicons/react/24/outline';
import clsx from 'clsx';
import type { BotNode, BotNodeType, BotAiAction } from '@/services/api/bot.service';
import {
  NODE_META,
  AI_ACTIONS,
  CONDITION_SUBJECTS,
  CONDITION_OPERATORS,
  BUILTIN_VARIABLES,
  SYSTEM_VARIABLES,
  variablesDefinedBy,
  newId,
  outletsOf,
  getTarget,
  setTarget,
  detachReferencesTo,
  autoLayout,
  ensurePositions,
} from './botFlowMeta';

type RFNode = Node<{ node: BotNode; isStart: boolean }>;

// ============================================================
//  NÓ DESENHADO NO CANVAS
// ============================================================
function BotFlowNode({ data, selected }: NodeProps<RFNode>) {
  const node = data.node;
  const meta = NODE_META[node.type];
  const outlets = outletsOf(node);

  const subtitle = (() => {
    switch (node.type) {
      case 'condition': {
        const system = CONDITION_SUBJECTS.find(s => s.value === node.subject);
        if (system) return system.label;
        if (!node.subject) return 'Condição sem variável definida';
        const op = CONDITION_OPERATORS.find(o => o.value === (node.operator ?? 'equals'));
        return `${node.subject} ${op?.label ?? ''} ${op?.needsValue ? node.value ?? '' : ''}`.trim();
      }
      case 'action':
        return AI_ACTIONS.find(a => a.value === node.action)?.label ?? 'Ação';
      case 'http':
        return node.url ? `${node.method ?? 'GET'} ${node.url}` : 'Chamada sem URL definida';
      case 'set': {
        const keys = (node.assignments ?? []).map(a => a.key).filter(Boolean);
        return keys.length ? keys.join(', ') : 'Nenhuma variável definida';
      }
      case 'system_data':
        return 'Carrega pedidos do contato';
      case 'delay': {
        const units: Record<string, string> = { seconds: 'segundos', minutes: 'minutos', hours: 'horas', days: 'dias' };
        return `Espera ${node.duration ?? 5} ${units[node.unit ?? 'minutes']}`;
      }
      default:
        return node.text || meta.hint;
    }
  })();

  return (
    <div
      className={clsx(
        'w-60 rounded-xl border-2 bg-surface-card shadow-lg transition-all',
        selected ? 'border-green-500 ring-2 ring-green-500/30' : meta.ring,
      )}
      style={{ borderTopColor: meta.hex, borderTopWidth: 3 }}
    >
      {/* Entrada */}
      <Handle
        type="target"
        position={Position.Top}
        id="in"
        style={{ width: 10, height: 10, background: '#64748b', border: '2px solid var(--rf-bg, #0b0f14)' }}
      />

      <div className="p-3">
        <div className="flex items-center gap-2 mb-1.5">
          <span className={clsx('p-1 rounded-md', meta.chip)}>
            <meta.icon className="h-3.5 w-3.5" />
          </span>
          <span className="text-[10px] uppercase tracking-wide text-tx-muted font-medium">{meta.label}</span>
          {data.isStart && (
            <span className="ml-auto text-[9px] px-1.5 py-0.5 rounded bg-green-500/15 text-green-500 font-medium">
              INÍCIO
            </span>
          )}
        </div>

        <p className="text-xs text-tx-primary leading-snug line-clamp-3">{subtitle}</p>

        {node.type === 'question' && (node.options?.length ?? 0) > 0 && (
          <div className="mt-2 space-y-1">
            {node.options!.map((opt, i) => (
              <div key={i} className="text-[10px] text-tx-muted truncate px-1.5 py-0.5 rounded bg-surface-page">
                {i + 1}. {opt.label || '(sem texto)'}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Saídas: uma bolinha por caminho possível */}
      {outlets.map((outlet, i) => {
        const left = outlets.length === 1 ? 50 : ((i + 1) / (outlets.length + 1)) * 100;
        return (
          <div key={outlet.handle}>
            <Handle
              type="source"
              position={Position.Bottom}
              id={outlet.handle}
              style={{
                left: `${left}%`,
                width: 11,
                height: 11,
                background: outlet.hex,
                border: '2px solid rgba(0,0,0,0.35)',
              }}
            />
            {outlet.label && (
              <span
                className="absolute text-[9px] text-tx-muted whitespace-nowrap pointer-events-none"
                style={{ left: `${left}%`, bottom: -18, transform: 'translateX(-50%)' }}
              >
                {outlet.label.length > 14 ? outlet.label.slice(0, 14) + '…' : outlet.label}
              </span>
            )}
          </div>
        );
      })}
    </div>
  );
}

const nodeTypes = { bot: BotFlowNode };

/**
 * Converte os nós do bot em nós do canvas, carregando adiante o que o React
 * Flow anexou aos anteriores (medidas, arraste) para não perder a medição.
 */
function toRfNodes(nodes: BotNode[], selectedId: string | null, previous?: RFNode[]): RFNode[] {
  const previousById = new Map((previous ?? []).map(n => [n.id, n]));

  return nodes.map((node, index) => {
    const existing = previousById.get(node.id);

    return {
      ...existing,
      id: node.id,
      type: 'bot',
      position: node.position ?? existing?.position ?? { x: (index % 3) * 320, y: Math.floor(index / 3) * 190 },
      data: { node, isStart: index === 0 },
      selected: node.id === selectedId,
    } as RFNode;
  });
}

// ============================================================
//  CANVAS
// ============================================================
export default function FlowCanvas(props: { nodes: BotNode[]; onChange: (nodes: BotNode[]) => void }) {
  return (
    <ReactFlowProvider>
      <FlowCanvasInner {...props} />
    </ReactFlowProvider>
  );
}

function FlowCanvasInner({ nodes, onChange }: { nodes: BotNode[]; onChange: (nodes: BotNode[]) => void }) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [showMiniMap, setShowMiniMap] = useState(true);
  const { fitView } = useReactFlow();

  // Fluxos salvos antes do canvas não têm posição: calcula uma vez.
  useEffect(() => {
    if (nodes.length > 0 && nodes.some(n => !n.position)) {
      onChange(ensurePositions(nodes));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [nodes.length]);

  /**
   * O React Flow devolve as medições dos nós como mudanças `dimensions`. Num
   * fluxo controlado elas precisam ser aplicadas de volta, senão os nós ficam
   * sem tamanho — e aí nem as arestas nem o minimapa conseguem se desenhar.
   * Por isso os nós do canvas vivem em estado local, e `preserve` mantém o que
   * o React Flow anotou quando os dados do bot mudam.
   */
  const [rfNodes, setRfNodes] = useState<RFNode[]>(() => toRfNodes(nodes, null));

  useEffect(() => {
    setRfNodes(previous => toRfNodes(nodes, selectedId, previous));
  }, [nodes, selectedId]);

  const rfEdges: Edge[] = useMemo(() => {
    const edges: Edge[] = [];
    const ids = new Set(nodes.map(n => n.id));

    for (const node of nodes) {
      for (const outlet of outletsOf(node)) {
        const target = getTarget(node, outlet.handle);
        if (!target || !ids.has(target)) continue;

        edges.push({
          id: `${node.id}:${outlet.handle}`,
          source: node.id,
          sourceHandle: outlet.handle,
          target,
          targetHandle: 'in',
          type: 'smoothstep',
          animated: true,
          style: { stroke: outlet.hex, strokeWidth: 2 },
          label: outlet.label || undefined,
          labelStyle: { fill: '#94a3b8', fontSize: 10 },
          labelBgStyle: { fill: '#0f172a', fillOpacity: 0.85 },
          labelBgPadding: [4, 2] as [number, number],
          labelBgBorderRadius: 4,
        });
      }
    }

    return edges;
  }, [nodes]);

  const updateNode = useCallback(
    (id: string, changes: Partial<BotNode>) => onChange(nodes.map(n => (n.id === id ? { ...n, ...changes } : n))),
    [nodes, onChange],
  );

  const removeNode = useCallback(
    (id: string) => {
      onChange(detachReferencesTo(nodes, id).filter(n => n.id !== id));
      setSelectedId(current => (current === id ? null : current));
    },
    [nodes, onChange],
  );

  const onNodesChange = useCallback(
    (changes: NodeChange<RFNode>[]) => {
      // Aplica tudo — inclusive `dimensions`, que é a medição dos nós.
      setRfNodes(previous => applyNodeChanges(changes, previous));

      const removals = changes.filter(c => c.type === 'remove').map(c => c.id);
      if (removals.length) {
        let next = nodes;
        for (const id of removals) next = detachReferencesTo(next, id);
        onChange(next.filter(n => !removals.includes(n.id)));
        if (selectedId && removals.includes(selectedId)) setSelectedId(null);
        return;
      }

      // Só persiste a posição quando o arraste termina, para não marcar o bot
      // como alterado a cada pixel do movimento.
      const moved = new Map<string, { x: number; y: number }>();
      for (const change of changes) {
        if (change.type === 'position' && change.dragging === false && change.position) {
          moved.set(change.id, change.position);
        }
      }
      if (moved.size === 0) return;

      onChange(nodes.map(n => (moved.has(n.id) ? { ...n, position: moved.get(n.id)! } : n)));
    },
    [nodes, onChange, selectedId],
  );

  /** Apagar uma aresta = limpar o destino daquela saída. */
  const onEdgesChange = useCallback(
    (changes: EdgeChange[]) => {
      const removed = changes.filter(c => c.type === 'remove').map(c => c.id);
      if (removed.length === 0) return;

      let next = nodes;
      for (const edgeId of removed) {
        const [sourceId, handle] = edgeId.split(/:(.+)/);
        next = next.map(n => (n.id === sourceId ? setTarget(n, handle, null) : n));
      }
      onChange(next);
    },
    [nodes, onChange],
  );

  const onConnect = useCallback(
    (connection: Connection) => {
      const handle = connection.sourceHandle;
      if (!connection.source || !connection.target || !handle) return;
      if (connection.source === connection.target) return; // laço direto trava o motor

      onChange(nodes.map(n => (n.id === connection.source ? setTarget(n, handle, connection.target) : n)));
    },
    [nodes, onChange],
  );

  const addNode = useCallback(
    (type: BotNodeType) => {
      const node: BotNode = { id: newId(), type, text: '', next: null };
      if (type === 'question') {
        node.text = 'O que você precisa?';
        node.options = [{ label: 'Opção 1', next: null }];
        node.invalid_message = 'Não entendi. Responda com o número da opção.';
      }
      if (type === 'condition') {
        node.subject = '';
        node.operator = 'equals';
        node.next_true = null;
        node.next_false = null;
      }
      if (type === 'action') node.action = 'resend_pix';
      if (type === 'set') node.assignments = [{ key: '', value: '' }];
      if (type === 'delay') {
        node.duration = 5;
        node.unit = 'minutes';
      }
      if (type === 'http') {
        node.method = 'GET';
        node.url = '';
        node.headers = [];
        node.save_as = 'resposta';
        node.next_error = null;
      }

      // Nasce abaixo de tudo que já existe, para nunca cair em cima de outro nó.
      const lowest = nodes.reduce((max, n) => Math.max(max, n.position?.y ?? 0), 0);
      node.position = { x: (nodes.length % 3) * 320, y: nodes.length === 0 ? 0 : lowest + 190 };

      onChange([...nodes, node]);
      setSelectedId(node.id);
      window.setTimeout(() => fitView({ padding: 0.2, duration: 300 }), 50);
    },
    [nodes, onChange, fitView],
  );

  const selected = nodes.find(n => n.id === selectedId) ?? null;

  return (
    <div className="rounded-2xl bg-surface-card border border-border-DEFAULT/50 overflow-hidden">
      {/* Barra de ferramentas */}
      <div className="p-3 border-b border-border-DEFAULT/50 flex items-center gap-2 flex-wrap">
        <span className="text-xs text-tx-muted mr-1">Adicionar:</span>
        {(Object.keys(NODE_META) as BotNodeType[]).map(type => {
          const meta = NODE_META[type];
          return (
            <button
              key={type}
              onClick={() => addNode(type)}
              title={meta.hint}
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-surface-hover text-tx-secondary text-xs font-medium hover:bg-surface-page transition-all"
            >
              <meta.icon className="h-3.5 w-3.5" style={{ color: meta.hex }} />
              {meta.label}
            </button>
          );
        })}

        <div className="ml-auto flex items-center gap-2">
          <ZoomBadge />
          <button
            onClick={() => setShowMiniMap(v => !v)}
            title={showMiniMap ? 'Ocultar minimapa' : 'Mostrar minimapa'}
            className={clsx(
              'p-1.5 rounded-lg text-xs',
              showMiniMap ? 'bg-green-500/10 text-green-500' : 'bg-surface-hover text-tx-muted hover:bg-surface-page',
            )}
          >
            <MapIcon className="h-3.5 w-3.5" />
          </button>
          <button
            onClick={() => onChange(autoLayout(nodes))}
            title="Reorganizar automaticamente"
            className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-surface-hover text-tx-secondary text-xs font-medium hover:bg-surface-page"
          >
            <Squares2X2Icon className="h-3.5 w-3.5" />Reorganizar
          </button>
          <button
            onClick={() => fitView({ padding: 0.2, duration: 300 })}
            title="Enquadrar tudo"
            className="p-1.5 rounded-lg bg-surface-hover text-tx-secondary hover:bg-surface-page"
          >
            <ArrowsPointingOutIcon className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      <div className="flex flex-col lg:flex-row">
        {/* Canvas */}
        <div className="h-[22rem] lg:h-[27rem] flex-1 min-w-0 bg-surface-page relative">
          {nodes.length === 0 ? (
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center px-6">
              <PlusIcon className="h-8 w-8 text-tx-muted mb-2" />
              <p className="text-sm text-tx-muted">
                Nenhum passo ainda. Use a barra acima para adicionar o primeiro.
              </p>
            </div>
          ) : (
            <ReactFlow
              nodes={rfNodes}
              edges={rfEdges}
              nodeTypes={nodeTypes}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              onConnect={onConnect}
              onNodeClick={(_, n) => setSelectedId(n.id)}
              onPaneClick={() => setSelectedId(null)}
              colorMode="dark"
              fitView
              minZoom={0.2}
              maxZoom={1.6}
              defaultEdgeOptions={{ type: 'smoothstep' }}
              proOptions={{ hideAttribution: true }}
            >
              <Background variant={BackgroundVariant.Dots} gap={18} size={1} color="#334155" />
              <Controls showInteractive={false} position="bottom-left" />
              {showMiniMap && <MiniMap
                pannable
                zoomable
                position="bottom-right"
                /* Tamanho fixo: sem isso o minimapa cresce e vira um bloco vazio. */
                style={{
                  width: 168,
                  height: 112,
                  background: 'rgba(15, 23, 42, 0.92)',
                  border: '1px solid rgba(148, 163, 184, 0.25)',
                  borderRadius: 8,
                }}
                nodeColor={n => {
                  const type = (n.data as any)?.node?.type as BotNodeType | undefined;
                  return (type && NODE_META[type]?.hex) || '#64748b';
                }}
                nodeStrokeColor="rgba(226, 232, 240, 0.55)"
                nodeStrokeWidth={2}
                nodeBorderRadius={3}
                /* Máscara clara + contorno: é o que mostra onde o zoom está. */
                maskColor="rgba(2, 6, 23, 0.55)"
                maskStrokeColor="#22c55e"
                maskStrokeWidth={2}
              />}
            </ReactFlow>
          )}
        </div>

        {/* Inspetor */}
        <div className="lg:w-80 shrink-0 border-t lg:border-t-0 lg:border-l border-border-DEFAULT/50 max-h-[22rem] lg:max-h-[27rem] overflow-y-auto">
          {selected ? (
            <NodeInspector
              node={selected}
              nodes={nodes}
              onChange={changes => updateNode(selected.id, changes)}
              onRemove={() => removeNode(selected.id)}
              onClose={() => setSelectedId(null)}
            />
          ) : (
            <div className="p-6 text-center">
              <p className="text-sm text-tx-muted">Clique em um passo para editar.</p>
              <p className="text-[11px] text-tx-muted mt-3 leading-relaxed">
                Arraste de uma bolinha colorida até o topo de outro passo para ligar.
                Clique numa linha e aperte <kbd className="px-1 rounded bg-surface-hover">Delete</kbd> para desfazer.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

/** Nível de zoom atual, para não ficar perdido depois de dar scroll. */
function ZoomBadge() {
  const { zoom } = useViewport();
  const { zoomTo } = useReactFlow();

  return (
    <button
      onClick={() => zoomTo(1, { duration: 200 })}
      title="Voltar para 100%"
      className="px-2 py-1.5 rounded-lg bg-surface-hover text-tx-muted text-xs font-mono tabular-nums hover:bg-surface-page"
    >
      {Math.round(zoom * 100)}%
    </button>
  );
}

// ============================================================
//  CONDIÇÃO: variável livre ou verificação do gateway
// ============================================================
function ConditionFields({
  node,
  nodes,
  onChange,
  inputClass,
}: {
  node: BotNode;
  nodes: BotNode[];
  onChange: (changes: Partial<BotNode>) => void;
  inputClass: string;
}) {
  const isSystem = CONDITION_SUBJECTS.some(s => s.value === node.subject);
  const operator = CONDITION_OPERATORS.find(o => o.value === (node.operator ?? 'equals'));
  const available = [...BUILTIN_VARIABLES.map(v => v.name), ...variablesDefinedBy(nodes)];

  return (
    <div className="space-y-2">
      <div className="flex gap-1.5">
        <button
          onClick={() => onChange({ subject: '', operator: 'equals' })}
          className={clsx(
            'flex-1 px-2 py-1.5 rounded-lg text-[11px] font-medium transition-all',
            !isSystem ? 'bg-purple-500/15 text-purple-400' : 'bg-surface-hover text-tx-muted',
          )}
        >
          Comparar variável
        </button>
        <button
          onClick={() => onChange({ subject: 'has_order' })}
          className={clsx(
            'flex-1 px-2 py-1.5 rounded-lg text-[11px] font-medium transition-all',
            isSystem ? 'bg-purple-500/15 text-purple-400' : 'bg-surface-hover text-tx-muted',
          )}
        >
          Checar no gateway
        </button>
      </div>

      {isSystem ? (
        <select value={node.subject} onChange={e => onChange({ subject: e.target.value })} className={inputClass}>
          {CONDITION_SUBJECTS.map(s => (
            <option key={s.value} value={s.value}>{s.label}</option>
          ))}
        </select>
      ) : (
        <>
          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Variável</label>
            <input
              list="bot-vars"
              value={node.subject ?? ''}
              onChange={e => onChange({ subject: e.target.value })}
              placeholder="ex: assunto"
              className={inputClass}
            />
            <datalist id="bot-vars">
              {available.map(v => <option key={v} value={v} />)}
            </datalist>
          </div>

          <select
            value={node.operator ?? 'equals'}
            onChange={e => onChange({ operator: e.target.value as BotNode['operator'] })}
            className={inputClass}
          >
            {CONDITION_OPERATORS.map(o => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>

          {operator?.needsValue && (
            <input
              value={node.value ?? ''}
              onChange={e => onChange({ value: e.target.value })}
              placeholder="valor de comparação"
              className={inputClass}
            />
          )}
        </>
      )}
    </div>
  );
}

// ============================================================
//  INSPETOR DO NÓ SELECIONADO
// ============================================================
function NodeInspector({
  node,
  nodes,
  onChange,
  onRemove,
  onClose,
}: {
  node: BotNode;
  nodes: BotNode[];
  onChange: (changes: Partial<BotNode>) => void;
  onRemove: () => void;
  onClose: () => void;
}) {
  const meta = NODE_META[node.type];
  const others = nodes.filter(n => n.id !== node.id);
  const inputClass = 'w-full px-3 py-2 rounded-lg bg-surface-page border border-border-DEFAULT/50 text-sm text-tx-primary';

  const nodeLabel = (id: string) => {
    const target = nodes.find(n => n.id === id);
    if (!target) return 'Removido';
    const preview = (target.text ?? '').slice(0, 22);
    return `${NODE_META[target.type].label}${preview ? ': ' + preview : ''}`;
  };

  const TargetSelect = ({ handle, label }: { handle: string; label: string }) => (
    <div>
      <label className="block text-[11px] text-tx-muted mb-1">{label}</label>
      <select
        value={getTarget(node, handle) ?? ''}
        onChange={e => {
          const updated = setTarget(node, handle, e.target.value || null);
          onChange(handle.startsWith('opt:') ? { options: updated.options } : { [handle]: e.target.value || null });
        }}
        className={inputClass}
      >
        <option value="">Encerrar atendimento</option>
        {others.map(n => (
          <option key={n.id} value={n.id}>{nodeLabel(n.id)}</option>
        ))}
      </select>
    </div>
  );

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center gap-2">
        <span className={clsx('p-1.5 rounded-lg', meta.chip)}>
          <meta.icon className="h-4 w-4" />
        </span>
        <h4 className="text-sm font-medium text-tx-primary flex-1">{meta.label}</h4>
        <button onClick={onClose} className="p-1 rounded-lg hover:bg-surface-hover text-tx-muted">
          <XMarkIcon className="h-4 w-4" />
        </button>
      </div>

      {['message', 'question', 'handoff', 'end'].includes(node.type) && (
        <div>
          <label className="block text-[11px] text-tx-muted mb-1">
            Texto enviado {node.type === 'end' && '(opcional)'}
          </label>
          <textarea
            value={node.text ?? ''}
            onChange={e => onChange({ text: e.target.value })}
            rows={3}
            placeholder="Use {{nome}} para o nome do cliente"
            className={clsx(inputClass, 'resize-y')}
          />
        </div>
      )}

      {node.type === 'question' && (
        <>
          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Opções do menu</label>
            <div className="space-y-1.5">
              {(node.options ?? []).map((opt, i) => (
                <div key={i} className="flex gap-1.5">
                  <input
                    value={opt.label}
                    onChange={e => {
                      const options = [...(node.options ?? [])];
                      options[i] = { ...opt, label: e.target.value };
                      onChange({ options });
                    }}
                    className={clsx(inputClass, 'flex-1')}
                  />
                  <button
                    onClick={() => onChange({ options: (node.options ?? []).filter((_, j) => j !== i) })}
                    className="p-2 rounded-lg bg-red-500/10 text-red-400 shrink-0"
                  >
                    <XMarkIcon className="h-4 w-4" />
                  </button>
                </div>
              ))}
            </div>
            <button
              onClick={() => onChange({ options: [...(node.options ?? []), { label: '', next: null }] })}
              className="mt-2 flex items-center gap-1.5 text-xs text-green-500 hover:text-green-400"
            >
              <PlusIcon className="h-3.5 w-3.5" />Adicionar opção
            </button>
            <p className="text-[11px] text-tx-muted mt-2">
              Cada opção vira uma bolinha no nó. Ligue no canvas ou pelos campos abaixo.
            </p>
          </div>

          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Guardar resposta como</label>
            <input
              value={node.save_as ?? ''}
              onChange={e => onChange({ save_as: e.target.value })}
              placeholder="ex: assunto"
              className={inputClass}
            />
          </div>

          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Se não entender</label>
            <input
              value={node.invalid_message ?? ''}
              onChange={e => onChange({ invalid_message: e.target.value })}
              className={inputClass}
            />
          </div>
        </>
      )}

      {node.type === 'condition' && <ConditionFields node={node} nodes={nodes} onChange={onChange} inputClass={inputClass} />}

      {node.type === 'set' && (
        <div>
          <label className="block text-[11px] text-tx-muted mb-1">Variáveis a definir</label>
          <div className="space-y-1.5">
            {(node.assignments ?? []).map((item, i) => (
              <div key={i} className="flex gap-1.5">
                <input
                  value={item.key}
                  onChange={e => {
                    const assignments = [...(node.assignments ?? [])];
                    assignments[i] = { ...item, key: e.target.value };
                    onChange({ assignments });
                  }}
                  placeholder="nome"
                  className={clsx(inputClass, 'w-24 shrink-0')}
                />
                <input
                  value={item.value}
                  onChange={e => {
                    const assignments = [...(node.assignments ?? [])];
                    assignments[i] = { ...item, value: e.target.value };
                    onChange({ assignments });
                  }}
                  placeholder="valor ou {{outra}}"
                  className={clsx(inputClass, 'flex-1')}
                />
                <button
                  onClick={() => onChange({ assignments: (node.assignments ?? []).filter((_, j) => j !== i) })}
                  className="p-2 rounded-lg bg-red-500/10 text-red-400 shrink-0"
                >
                  <XMarkIcon className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>
          <button
            onClick={() => onChange({ assignments: [...(node.assignments ?? []), { key: '', value: '' }] })}
            className="mt-2 flex items-center gap-1.5 text-xs text-green-500 hover:text-green-400"
          >
            <PlusIcon className="h-3.5 w-3.5" />Adicionar variável
          </button>
        </div>
      )}

      {node.type === 'delay' && (
        <>
          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Esperar</label>
            <div className="flex gap-1.5">
              <input
                type="number"
                min={1}
                value={node.duration ?? 5}
                onChange={e => onChange({ duration: Math.max(1, Number(e.target.value)) })}
                className={clsx(inputClass, 'w-20 shrink-0')}
              />
              <select
                value={node.unit ?? 'minutes'}
                onChange={e => onChange({ unit: e.target.value as BotNode['unit'] })}
                className={clsx(inputClass, 'flex-1')}
              >
                <option value="seconds">segundos</option>
                <option value="minutes">minutos</option>
                <option value="hours">horas</option>
                <option value="days">dias</option>
              </select>
            </div>
          </div>
          <p className="text-[11px] text-tx-muted leading-relaxed">
            O fluxo para aqui e continua sozinho depois. Se o cliente escrever antes do prazo, a espera é
            cancelada e a mensagem dele é atendida na hora. Máximo de 7 dias.
          </p>
        </>
      )}

      {node.type === 'system_data' && (
        <div className="rounded-lg bg-surface-page p-3">
          <p className="text-[11px] text-tx-secondary leading-relaxed">
            Busca os pedidos do telefone de quem escreveu e preenche estas variáveis:
          </p>
          <div className="mt-2 space-y-0.5">
            {SYSTEM_VARIABLES.map(v => (
              <p key={v.name} className="text-[10px] text-tx-muted">
                <code className="text-teal-400">{`{{${v.name}}}`}</code> — {v.description}
              </p>
            ))}
          </div>
        </div>
      )}

      {node.type === 'http' && (
        <>
          <div className="flex gap-1.5">
            <select
              value={node.method ?? 'GET'}
              onChange={e => onChange({ method: e.target.value as BotNode['method'] })}
              className={clsx(inputClass, 'w-24 shrink-0')}
            >
              {['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map(m => (
                <option key={m} value={m}>{m}</option>
              ))}
            </select>
            <input
              value={node.url ?? ''}
              onChange={e => onChange({ url: e.target.value })}
              placeholder="https://api.exemplo.com/rota"
              className={clsx(inputClass, 'flex-1')}
            />
          </div>

          <div>
            <label className="block text-[11px] text-tx-muted mb-1">Cabeçalhos</label>
            <div className="space-y-1.5">
              {(node.headers ?? []).map((header, i) => (
                <div key={i} className="flex gap-1.5">
                  <input
                    value={header.key}
                    onChange={e => {
                      const headers = [...(node.headers ?? [])];
                      headers[i] = { ...header, key: e.target.value };
                      onChange({ headers });
                    }}
                    placeholder="Authorization"
                    className={clsx(inputClass, 'w-28 shrink-0')}
                  />
                  <input
                    value={header.value}
                    onChange={e => {
                      const headers = [...(node.headers ?? [])];
                      headers[i] = { ...header, value: e.target.value };
                      onChange({ headers });
                    }}
                    placeholder="Bearer ..."
                    className={clsx(inputClass, 'flex-1')}
                  />
                  <button
                    onClick={() => onChange({ headers: (node.headers ?? []).filter((_, j) => j !== i) })}
                    className="p-2 rounded-lg bg-red-500/10 text-red-400 shrink-0"
                  >
                    <XMarkIcon className="h-4 w-4" />
                  </button>
                </div>
              ))}
            </div>
            <button
              onClick={() => onChange({ headers: [...(node.headers ?? []), { key: '', value: '' }] })}
              className="mt-2 flex items-center gap-1.5 text-xs text-green-500 hover:text-green-400"
            >
              <PlusIcon className="h-3.5 w-3.5" />Adicionar cabeçalho
            </button>
          </div>

          {node.method !== 'GET' && node.method !== 'DELETE' && (
            <div>
              <label className="block text-[11px] text-tx-muted mb-1">Corpo da requisição</label>
              <textarea
                value={node.body ?? ''}
                onChange={e => onChange({ body: e.target.value })}
                rows={3}
                placeholder={'{"telefone": "{{telefone}}"}'}
                className={clsx(inputClass, 'resize-y font-mono text-xs')}
              />
            </div>
          )}

          <div className="grid grid-cols-2 gap-1.5">
            <div>
              <label className="block text-[11px] text-tx-muted mb-1">Salvar em</label>
              <input
                value={node.save_as ?? ''}
                onChange={e => onChange({ save_as: e.target.value })}
                placeholder="resposta"
                className={inputClass}
              />
            </div>
            <div>
              <label className="block text-[11px] text-tx-muted mb-1">Caminho no JSON</label>
              <input
                value={node.save_path ?? ''}
                onChange={e => onChange({ save_path: e.target.value })}
                placeholder="data.nome"
                className={inputClass}
              />
            </div>
          </div>

          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={!!node.reply_with_message}
              onChange={e => onChange({ reply_with_message: e.target.checked })}
              className="accent-green-500"
            />
            <span className="text-[11px] text-tx-secondary">Avisar o cliente do resultado</span>
          </label>

          <p className="text-[11px] text-amber-500 flex items-start gap-1">
            <LockClosedIcon className="h-3 w-3 mt-0.5 shrink-0" />
            Endereços de rede interna são bloqueados. Em produção só https.
          </p>
        </>
      )}

      {node.type === 'action' && (
        <div>
          <label className="block text-[11px] text-tx-muted mb-1">Ação no gateway</label>
          <select
            value={node.action ?? 'resend_pix'}
            onChange={e => onChange({ action: e.target.value as BotAiAction })}
            className={inputClass}
          >
            {AI_ACTIONS.filter(a => !a.readOnly).map(a => (
              <option key={a.value} value={a.value}>{a.label}</option>
            ))}
          </select>
          <p className="text-[11px] text-amber-500 mt-1.5 flex items-start gap-1">
            <LockClosedIcon className="h-3 w-3 mt-0.5 shrink-0" />
            Só roda se a permissão estiver ligada na configuração da IA deste bot.
          </p>
        </div>
      )}

      {/* Ligações do nó */}
      {outletsOf(node).length > 0 && (
        <div className="space-y-2 pt-2 border-t border-border-DEFAULT/50">
          <p className="text-[11px] text-tx-muted">Para onde vai</p>
          {outletsOf(node).map(outlet => (
            <TargetSelect
              key={outlet.handle}
              handle={outlet.handle}
              label={
                outlet.handle.startsWith('opt:')
                  ? `Opção "${outlet.label}"`
                  : outlet.handle === 'next_true'
                    ? 'Se SIM'
                    : outlet.handle === 'next_false'
                      ? 'Se NÃO'
                      : outlet.handle === 'next_error'
                        ? 'Se falhar'
                        : 'Depois'
              }
            />
          ))}
        </div>
      )}

      <button
        onClick={onRemove}
        className="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20"
      >
        <TrashIcon className="h-3.5 w-3.5" />Remover passo
      </button>
    </div>
  );
}
