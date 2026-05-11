import { useState, useRef, useEffect } from 'react';
import DOMPurify from 'dompurify';
import { wazerAIService, type WazerAIChatMessage, type WazerAIActionResult } from '@/services/api/wazerAI.service';
import { useAuthStore } from '@/stores/authStore';

interface ChatMessage extends WazerAIChatMessage {
  id: string;
  timestamp: Date;
  isLoading?: boolean;
  actionResult?: WazerAIActionResult | null;
}

const suggestions = [
  'Resumo de vendas do mês',
  'Como criar um produto?',
  'Estratégias para vender mais',
  'Como configurar um cupom?',
  'Analise meu site e gere um checkout pronto para baixar',
];

export default function VectorIAPage() {
  const { user } = useAuthStore();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  useEffect(() => { inputRef.current?.focus(); }, []);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const getHistory = (): WazerAIChatMessage[] => {
    return messages.filter(m => !m.isLoading).slice(-20)
      .map(m => ({ role: m.role, content: m.content }));
  };

  const copyMessage = (id: string, content: string) => {
    navigator.clipboard.writeText(content);
    setCopiedId(id);
    setTimeout(() => setCopiedId(null), 2000);
  };

  const sendMessage = async (text?: string) => {
    const messageText = (text || input).trim();
    if (!messageText || isLoading) return;

    const userMessage: ChatMessage = {
      id: crypto.randomUUID(),
      role: 'user',
      content: messageText,
      timestamp: new Date(),
    };

    const loadingMessage: ChatMessage = {
      id: crypto.randomUUID(),
      role: 'assistant',
      content: '',
      timestamp: new Date(),
      isLoading: true,
    };

    setMessages(prev => [...prev, userMessage, loadingMessage]);
    setInput('');
    setIsLoading(true);

    try {
      const response = await wazerAIService.chat(messageText, getHistory());

      if (response.success) {
        setMessages(prev =>
          prev.map(m =>
            m.id === loadingMessage.id
              ? { ...m, content: response.data.message, isLoading: false, actionResult: response.data.action_result }
              : m
          )
        );
      } else {
        setMessages(prev =>
          prev.map(m =>
            m.id === loadingMessage.id
              ? { ...m, content: 'Desculpe, ocorreu um erro. Tente novamente.', isLoading: false }
              : m
          )
        );
      }
    } catch (err: any) {
      const errorMsg = err?.response?.data?.error || 'Erro de conexão. Verifique sua internet e tente novamente.';
      setMessages(prev =>
        prev.map(m =>
          m.id === loadingMessage.id
            ? { ...m, content: errorMsg, isLoading: false }
            : m
        )
      );
    } finally {
      setIsLoading(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  const firstName = user?.name?.split(' ')[0] || 'Usuário';
  const userInitial = firstName.charAt(0).toUpperCase() || 'U';
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Bom dia' : hour < 18 ? 'Boa tarde' : 'Boa noite';

  return (
    <div className="flex h-[calc(100vh-80px)] flex-col overflow-hidden">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-border-DEFAULT px-6 py-3">
        <div>
          <div>
            <h1 className="text-base font-semibold text-tx-primary">VectorIA</h1>
            <p className="text-[11px] text-emerald-600 dark:text-emerald-400">Online</p>
          </div>
        </div>
        {messages.length > 0 && (
          <button
            onClick={() => setMessages([])}
            className="rounded-lg px-2.5 py-1.5 text-xs text-tx-muted hover:bg-surface-hover transition-colors"
          >
            Limpar
          </button>
        )}
      </div>

      {/* Messages */}
      <div className="flex-1 min-h-0 overflow-y-auto px-4 py-4">
        <div className="mx-auto max-w-2xl">
          {messages.length === 0 && (
            <div className="flex flex-col items-center pt-16 pb-8">
              <h2 className="text-xl font-semibold text-tx-primary mb-1">
                {greeting}, {firstName}!
              </h2>
              <p className="text-sm text-tx-muted mb-6">Como posso te ajudar?</p>

              <div className="flex flex-wrap justify-center gap-2">
                {suggestions.map((s, i) => (
                  <button
                    key={i}
                    onClick={() => sendMessage(s)}
                    className="rounded-full border border-border-DEFAULT bg-surface-card px-4 py-2 text-xs text-tx-secondary hover:bg-surface-hover hover:border-emerald-500/40 transition-all"
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="space-y-1">
            {messages.map((msg) => (
              <div key={msg.id} className={`group flex gap-2.5 py-2 ${msg.role === 'user' ? 'flex-row-reverse' : ''}`}>
                <div className="flex-shrink-0 pt-0.5">
                  {msg.role === 'assistant' ? (
                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-600 to-emerald-700 text-[11px] font-semibold text-white">
                      IA
                    </div>
                  ) : (
                    <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-surface-hover text-[11px] font-semibold text-tx-muted">
                      {userInitial}
                    </div>
                  )}
                </div>

                <div className={`flex flex-col ${msg.role === 'user' ? 'items-end' : 'items-start'} max-w-[75%]`}>
                  <div
                    className={`rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed ${
                      msg.role === 'user'
                        ? 'bg-gradient-to-br from-emerald-600 to-emerald-700 text-white rounded-tr-md'
                        : 'bg-surface-card text-tx-primary border border-border-DEFAULT rounded-tl-md'
                    }`}
                  >
                    {msg.isLoading ? (
                      <div className="flex items-center gap-2 py-0.5">
                        <div className="flex gap-1">
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-400" style={{ animationDelay: '0ms' }} />
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-400" style={{ animationDelay: '150ms' }} />
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-400" style={{ animationDelay: '300ms' }} />
                        </div>
                      </div>
                    ) : (
                      <>
                        <MarkdownContent content={msg.content} isUser={msg.role === 'user'} />
                        {msg.actionResult?.success && msg.actionResult.data && (
                          <ActionResultBadge actionResult={msg.actionResult} />
                        )}
                      </>
                    )}
                  </div>

                  {!msg.isLoading && msg.role === 'assistant' && (
                    <button
                      onClick={() => copyMessage(msg.id, msg.content)}
                      className="mt-0.5 rounded px-1.5 py-0.5 text-[10px] text-tx-muted opacity-0 group-hover:opacity-100 hover:bg-surface-hover transition-all"
                    >
                      {copiedId === msg.id ? 'Copiado' : 'Copiar'}
                    </button>
                  )}
                </div>
              </div>
            ))}
            <div ref={messagesEndRef} />
          </div>
        </div>
      </div>

      {/* Input */}
      <div className="border-t border-border-DEFAULT px-4 py-3">
        <div className="mx-auto max-w-2xl">
          <div className="flex items-end gap-2 rounded-xl border border-border-DEFAULT bg-surface-card p-1.5 focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-500/20 transition-all">
            <textarea
              ref={inputRef}
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Pergunte algo..."
              rows={1}
              className="flex-1 resize-none bg-transparent px-3 py-2 text-sm text-tx-primary placeholder-tx-muted focus:outline-none"
              style={{ maxHeight: '100px' }}
              onInput={(e) => {
                const target = e.target as HTMLTextAreaElement;
                target.style.height = 'auto';
                target.style.height = Math.min(target.scrollHeight, 100) + 'px';
              }}
              disabled={isLoading}
            />
            <button
              onClick={() => sendMessage()}
              disabled={!input.trim() || isLoading}
              className="rounded-lg bg-gradient-to-br from-emerald-600 to-emerald-700 px-3 py-2 text-xs font-medium text-white transition-all disabled:opacity-40 active:scale-95"
            >
              Enviar
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function ActionResultBadge({ actionResult }: { actionResult: WazerAIActionResult }) {
  const data = actionResult.data as Record<string, unknown> | undefined;
  if (!data) return null;

  const productId = data.product_id as string | undefined;
  const checkoutSlug = data.checkout_slug as string | undefined;
  const checkoutUrl = data.checkout_url as string | undefined;
  const previewUrl = data.preview_url as string | undefined;
  const downloadUrl = data.download_url as string | undefined;

  return (
    <div className="mt-3 flex flex-wrap gap-2">
      {productId && (
        <a href="/products" className="inline-flex items-center rounded-lg bg-emerald-500/20 px-3 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors">
          Ver Produto
        </a>
      )}
      {checkoutSlug && (
        <a href="/checkouts" className="inline-flex items-center rounded-lg bg-emerald-500/20 px-3 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors">
          Ver Checkout
        </a>
      )}
      {(checkoutUrl || checkoutSlug) && (
        <a href={checkoutUrl || `/checkout/${checkoutSlug}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center rounded-lg bg-blue-500/20 px-3 py-1.5 text-xs font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-500/30 transition-colors">
          Preview
        </a>
      )}
      {previewUrl && (
        <a href={previewUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center rounded-lg bg-slate-500/20 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-500/30 transition-colors">
          Ver página gerada
        </a>
      )}
      {downloadUrl && (
        <a href={downloadUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center rounded-lg bg-amber-500/20 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-500/30 transition-colors">
          Baixar pacote
        </a>
      )}
    </div>
  );
}

function MarkdownContent({ content, isUser }: { content: string; isUser: boolean }) {
  const html = simpleMarkdown(content);
  return (
    <div
      className={`wazerai-markdown prose prose-sm max-w-none ${isUser ? 'prose-invert' : 'prose-emerald'}`}
      dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html) }}
    />
  );
}

function simpleMarkdown(text: string): string {
  // Handle code blocks first (```)
  const parts = text.split(/(```[\s\S]*?```)/g);
  const processed = parts.map(part => {
    if (part.startsWith('```') && part.endsWith('```')) {
      const inner = part.slice(3, -3);
      const newlineIdx = inner.indexOf('\n');
      const code = newlineIdx >= 0 ? inner.slice(newlineIdx + 1) : inner;
      return `<pre class="my-2 rounded-lg bg-black/10 dark:bg-white/5 p-3 overflow-x-auto"><code class="text-xs font-mono whitespace-pre">${code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code></pre>`;
    }

    let html = part
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code class="rounded bg-black/10 dark:bg-white/10 px-1.5 py-0.5 text-[12px] font-mono">$1</code>')
      .replace(/^### (.+)$/gm, '<h4 class="text-sm font-bold mt-3 mb-1">$1</h4>')
      .replace(/^## (.+)$/gm, '<h3 class="text-sm font-bold mt-3 mb-1">$1</h3>')
      .replace(/^# (.+)$/gm, '<h2 class="text-base font-bold mt-3 mb-1">$1</h2>')
      .replace(/^[-•] (.+)$/gm, '<li class="ml-3">$1</li>')
      .replace(/^\d+\. (.+)$/gm, '<li class="ml-3 list-decimal">$1</li>')
      .replace(/\n\n/g, '</p><p class="mt-2">')
      .replace(/\n/g, '<br/>');

    html = html.replace(/((?:<li[^>]*>.*?<\/li>\s*(?:<br\/>)?)+)/g, '<ul class="my-1 space-y-0.5">$1</ul>');
    return `<p>${html}</p>`;
  });

  return processed.join('');
}
