import { useState, useRef, useEffect } from 'react';
import DOMPurify from 'dompurify';
import { wazerAIService, type WazerAIChatMessage, type WazerAIActionResult } from '@/services/api/wazerAI.service';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';

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

export default function WazerIAPage() {
  const platformName = useThemeStore((s) => s.platformName);
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
      .map(m => ({ role: m.role, content: asText(m.content) }));
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
              ? { ...m, content: asText(response.data.message), isLoading: false, actionResult: response.data.action_result }
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
      const errorMsg = asErrorMessage(err?.response?.data?.error) || 'Erro de conexão. Verifique sua internet e tente novamente.';
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

  const firstName = asText(user?.name).split(' ')[0] || 'Usuario';
  const userInitial = firstName.charAt(0).toUpperCase() || 'U';
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Bom dia' : hour < 18 ? 'Boa tarde' : 'Boa noite';

  return (
    <div className="flex min-h-[calc(100vh-5rem)] flex-col overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card/70 shadow-sm backdrop-blur-sm">
      {/* Header */}
      <div className="flex flex-col gap-3 border-b border-border-DEFAULT px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-tx-primary sm:text-xl">{platformName} IA</h1>
            <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
              Online
            </span>
          </div>
          <p className="mt-1 text-sm text-tx-muted">
            Assistente para operacao, vendas e criacao de fluxos dentro da plataforma.
          </p>
        </div>
        {messages.length > 0 && (
          <button
            onClick={() => setMessages([])}
            className="rounded-lg px-3 py-2 text-sm text-tx-muted transition-colors hover:bg-surface-hover sm:text-xs"
          >
            Limpar
          </button>
        )}
      </div>

      {/* Messages */}
      <div className="flex-1 min-h-0 overflow-y-auto px-3 py-4 sm:px-4 sm:py-5">
        <div className="mx-auto w-full max-w-4xl">
          {messages.length === 0 && (
            <div className="flex flex-col items-center px-1 pb-8 pt-8 text-center sm:pt-14">
              <h2 className="mb-1 text-2xl font-semibold text-tx-primary sm:text-3xl">
                {greeting}, {firstName}!
              </h2>
              <p className="mb-6 max-w-xl text-sm text-tx-muted sm:text-base">Como posso te ajudar?</p>

              <div className="grid w-full gap-2 sm:flex sm:flex-wrap sm:justify-center">
                {suggestions.map((s, i) => (
                  <button
                    key={i}
                    onClick={() => sendMessage(s)}
                    className="rounded-2xl border border-border-DEFAULT bg-surface-card px-4 py-3 text-left text-sm text-tx-secondary transition-all hover:border-emerald-500/40 hover:bg-surface-hover sm:rounded-full sm:px-4 sm:py-2 sm:text-center sm:text-xs"
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="space-y-2 sm:space-y-1">
            {messages.map((msg) => (
              <div key={msg.id} className={`group flex gap-2 py-1.5 sm:gap-2.5 sm:py-2 ${msg.role === 'user' ? 'flex-row-reverse' : ''}`}>
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

                <div className={`flex max-w-[88%] flex-col sm:max-w-[78%] ${msg.role === 'user' ? 'items-end' : 'items-start'}`}>
                  <div
                    className={`w-full rounded-2xl px-3.5 py-3 text-sm leading-relaxed sm:px-4 sm:py-3 ${
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
                      className="mt-1 rounded px-1.5 py-0.5 text-[10px] text-tx-muted transition-all hover:bg-surface-hover sm:opacity-0 sm:group-hover:opacity-100"
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
      <div className="border-t border-border-DEFAULT px-3 py-3 sm:px-4 sm:py-4">
        <div className="mx-auto w-full max-w-4xl">
          <div className="flex items-end gap-2 rounded-2xl border border-border-DEFAULT bg-surface-card p-2 transition-all focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-500/20 sm:gap-3">
            <textarea
              ref={inputRef}
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Pergunte algo..."
              rows={1}
              className="min-h-[44px] flex-1 resize-none bg-transparent px-2 py-2 text-sm text-tx-primary placeholder-tx-muted focus:outline-none sm:px-3"
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
              className="rounded-xl bg-gradient-to-br from-emerald-600 to-emerald-700 px-4 py-3 text-sm font-medium text-white transition-all active:scale-95 disabled:opacity-40 sm:px-5 sm:text-xs"
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
        <a href="/products" className="inline-flex items-center rounded-lg bg-emerald-500/20 px-3 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors">
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
  const html = simpleMarkdown(asText(content));
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

function asText(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asErrorMessage(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value && typeof value === 'object' && 'message' in value && typeof (value as { message?: unknown }).message === 'string') {
    return (value as { message: string }).message;
  }

  return '';
}
