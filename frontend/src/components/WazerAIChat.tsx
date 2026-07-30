import { useState, useRef, useEffect, useCallback } from 'react';
import DOMPurify from 'dompurify';
import { useThemeStore } from '@/stores/themeStore';
import {
  XMarkIcon, PaperAirplaneIcon, ChatBubbleLeftRightIcon, ArrowPathIcon,
  ChartBarIcon, RocketLaunchIcon, CubeIcon, CurrencyDollarIcon,
  LinkIcon, ChartPieIcon, CogIcon, ArrowTrendingUpIcon,
  ShoppingCartIcon, CreditCardIcon, TagIcon, QuestionMarkCircleIcon,
} from '@heroicons/react/24/outline';
import { ChatBubbleLeftRightIcon as ChatBubbleLeftRightIconSolid } from '@heroicons/react/24/solid';
import { wazerAIService, type WazerAIChatMessage, type WazerAISuggestion, type WazerAIActionResult } from '@/services/api/wazerAI.service';
import { useAuthStore } from '@/stores/authStore';
import { motion, AnimatePresence } from 'framer-motion';

const suggestionIcons: Record<string, React.ComponentType<React.SVGProps<SVGSVGElement>>> = {
  'chart-bar': ChartBarIcon,
  'rocket': RocketLaunchIcon,
  'cube': CubeIcon,
  'currency-dollar': CurrencyDollarIcon,
  'link': LinkIcon,
  'chart-pie': ChartPieIcon,
  'cog': CogIcon,
  'arrow-trending-up': ArrowTrendingUpIcon,
  'shopping-cart': ShoppingCartIcon,
  'credit-card': CreditCardIcon,
  'tag': TagIcon,
};

interface ChatMessage extends WazerAIChatMessage {
  id: string;
  timestamp: Date;
  isLoading?: boolean;
  actionResult?: WazerAIActionResult | null;
}

export default function WazerAIChat() {
  const platformName = useThemeStore((s) => s.platformName);
  const aiName = `${platformName} IA`;
  const { user } = useAuthStore();
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [suggestions, setSuggestions] = useState<WazerAISuggestion[]>([]);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const scrollToBottom = useCallback(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  useEffect(() => {
    scrollToBottom();
  }, [messages, scrollToBottom]);

  useEffect(() => {
    if (isOpen && messages.length === 0) {
      wazerAIService.getSuggestions().then(res => {
        if (res.success) setSuggestions(res.data);
      }).catch((error) => {
        console.error('Erro ao carregar sugestões:', error);
      });
    }
  }, [isOpen, messages.length]);

  useEffect(() => {
    if (isOpen) {
      setTimeout(() => inputRef.current?.focus(), 100);
    }
  }, [isOpen]);

  const getHistory = (): WazerAIChatMessage[] => {
    return messages
      .filter(m => !m.isLoading)
      .slice(-20)
      .map(m => ({ role: m.role, content: asText(m.content) }));
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
    setSuggestions([]);

    try {
      const history = getHistory();
      const response = await wazerAIService.chat(messageText, history);

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

  const clearChat = () => {
    setMessages([]);
    setSuggestions([]);
    wazerAIService.getSuggestions().then(res => {
      if (res.success) setSuggestions(res.data);
    }).catch((error) => {
      console.error('Erro ao recarregar sugestões:', error);
    });
  };

  const firstName = asText(user?.name).split(' ')[0] || 'Usuario';

  return (
    <>
      {/* Floating Button */}
      <AnimatePresence>
        {!isOpen && (
          <motion.button
            initial={{ scale: 0, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            exit={{ scale: 0, opacity: 0 }}
            onClick={() => setIsOpen(true)}
            className="fixed bottom-6 right-6 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg shadow-emerald-500/40 hover:bg-emerald-500 hover:shadow-xl hover:shadow-emerald-500/50 transition-all hover:scale-105 active:scale-95"
            style={{ opacity: 1 }}
            title={aiName}
          >
            <ChatBubbleLeftRightIconSolid className="h-6 w-6" />
          </motion.button>
        )}
      </AnimatePresence>

      {/* Chat Panel */}
      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.95 }}
            transition={{ duration: 0.2 }}
            className="fixed bottom-6 right-6 z-50 flex h-[600px] w-[420px] max-h-[85vh] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-border-DEFAULT/60 shadow-2xl shadow-black/20 bg-white dark:bg-[#1a1a2e]"
            style={{ opacity: 1 }}
          >
            {/* Header */}
            <div className="flex items-center justify-between border-b border-border-DEFAULT/60 bg-gradient-to-r from-emerald-600 to-emerald-700 px-4 py-3">
              <div className="flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                  <ChatBubbleLeftRightIconSolid className="h-5 w-5 text-white" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-white">{aiName}</h3>
                  <p className="text-[11px] text-white/70">Assistente inteligente</p>
                </div>
              </div>
              <div className="flex items-center gap-1">
                <button
                  onClick={clearChat}
                  className="rounded-lg p-1.5 text-white/70 hover:bg-white/10 hover:text-white transition-colors"
                  title="Nova conversa"
                >
                  <ArrowPathIcon className="h-4 w-4" />
                </button>
                <button
                  onClick={() => setIsOpen(false)}
                  className="rounded-lg p-1.5 text-white/70 hover:bg-white/10 hover:text-white transition-colors"
                >
                  <XMarkIcon className="h-5 w-5" />
                </button>
              </div>
            </div>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4 scrollbar-thin bg-white dark:bg-[#1a1a2e]">
              {messages.length === 0 && (
                <div className="flex flex-col items-center pt-6 pb-4">
                  <h4 className="text-base font-bold text-tx-primary mb-1">
                    Olá, {firstName}! 👋
                  </h4>
                  <p className="text-xs text-tx-muted text-center max-w-[280px] mb-6">
                    Sou a {aiName}, sua assistente inteligente. Posso te ajudar com vendas, métricas, estratégias e mais!
                  </p>

                  {/* Suggestions */}
                  {suggestions.length > 0 && (
                    <div className="w-full space-y-2">
                      <p className="text-[11px] font-medium text-tx-muted uppercase tracking-wider px-1">Sugestões</p>
                      <div className="grid grid-cols-1 gap-1.5">
                        {suggestions.slice(0, 10).map((s, i) => {
                          const IconComp = (s.icon && suggestionIcons[s.icon]) || QuestionMarkCircleIcon;
                          return (
                            <button
                              key={i}
                              onClick={() => sendMessage(s.text)}
                              className="flex items-center gap-2 rounded-xl border border-border-DEFAULT/60 bg-surface-hover/30 px-3 py-2 text-left text-xs text-tx-secondary hover:bg-accent-subtle/50 hover:border-accent-primary/30 transition-all"
                            >
                              <IconComp className="h-4 w-4 flex-shrink-0 text-accent-primary" />
                              <span>{s.text}</span>
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {messages.map((msg) => (
                <div
                  key={msg.id}
                  className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                  <div
                    className={`max-w-[85%] rounded-2xl px-3.5 py-2.5 text-[13px] leading-relaxed ${
                      msg.role === 'user'
                        ? 'bg-gradient-to-br from-emerald-600 to-emerald-700 text-white rounded-br-md'
                        : 'bg-surface-hover/60 text-tx-primary border border-border-DEFAULT/40 rounded-bl-md'
                    }`}
                  >
                    {msg.isLoading ? (
                      <div className="flex items-center gap-2 py-1">
                        <div className="flex gap-1">
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500" style={{ animationDelay: '0ms' }} />
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500" style={{ animationDelay: '150ms' }} />
                          <span className="h-1.5 w-1.5 animate-bounce rounded-full bg-emerald-500" style={{ animationDelay: '300ms' }} />
                        </div>
                        <span className="text-xs text-tx-muted">Pensando...</span>
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
                </div>
              ))}
              <div ref={messagesEndRef} />
            </div>

            {/* Input */}
            <div className="border-t border-border-DEFAULT/60 p-3 bg-white dark:bg-[#1a1a2e]">
              <div className="flex items-end gap-2">
                <textarea
                  ref={inputRef}
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="Digite sua mensagem..."
                  rows={1}
                  className="flex-1 resize-none rounded-xl border border-border-DEFAULT/60 bg-gray-50 dark:bg-[#12122a] px-3.5 py-2.5 text-sm text-tx-primary placeholder-tx-muted focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500/50 transition-colors"
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
                  className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-600 to-emerald-700 text-white shadow-md shadow-emerald-500/20 transition-all hover:shadow-lg hover:shadow-emerald-500/30 disabled:opacity-40 disabled:shadow-none disabled:cursor-not-allowed active:scale-95"
                >
                  <PaperAirplaneIcon className="h-4 w-4" />
                </button>
              </div>
              <p className="mt-1.5 text-center text-[10px] text-tx-muted/60">
                {platformName} — inteligência para o seu negócio.
              </p>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}

/**
 * Action result badge with quick-access links
 */
function ActionResultBadge({ actionResult }: { actionResult: WazerAIActionResult }) {
  const data = actionResult.data as Record<string, unknown> | undefined;
  if (!data) return null;

  const productId = data.product_id as string | undefined;
  const checkoutSlug = data.checkout_slug as string | undefined;
  const checkoutUrl = data.checkout_url as string | undefined;
  const previewUrl = data.preview_url as string | undefined;
  const downloadUrl = data.download_url as string | undefined;

  return (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {productId && (
        <a
          href={`/products`}
          className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/20 px-2 py-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors"
        >
          <CubeIcon className="h-3 w-3" />
          Ver Produto
        </a>
      )}
      {checkoutSlug && (
        <a
          href={`/checkouts`}
          className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/20 px-2 py-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/30 transition-colors"
        >
          <ShoppingCartIcon className="h-3 w-3" />
          Ver Checkout
        </a>
      )}
      {(checkoutUrl || checkoutSlug) && (
        <a
          href={checkoutUrl || `/checkout/${checkoutSlug}`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1 rounded-lg bg-blue-500/20 px-2 py-1 text-[11px] font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-500/30 transition-colors"
        >
          <LinkIcon className="h-3 w-3" />
          Preview
        </a>
      )}
      {previewUrl && (
        <a
          href={previewUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1 rounded-lg bg-slate-500/20 px-2 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-500/30 transition-colors"
        >
          <LinkIcon className="h-3 w-3" />
          Página
        </a>
      )}
      {downloadUrl && (
        <a
          href={downloadUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1 rounded-lg bg-amber-500/20 px-2 py-1 text-[11px] font-medium text-amber-700 hover:bg-amber-500/30 transition-colors"
        >
          <LinkIcon className="h-3 w-3" />
          Baixar
        </a>
      )}
    </div>
  );
}

/**
 * Simple markdown renderer for chat messages
 */
function MarkdownContent({ content, isUser }: { content: string; isUser: boolean }) {
  const html = simpleMarkdown(asText(content));
  return (
    <div
      className={`wazerai-markdown prose prose-sm max-w-none ${
        isUser
          ? 'prose-invert'
          : 'prose-emerald'
      }`}
      dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html) }}
    />
  );
}

function simpleMarkdown(text: string): string {
  let html = text
    // Escape HTML entities
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    // Bold
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    // Italic
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    // Inline code
    .replace(/`([^`]+)`/g, '<code class="rounded bg-black/10 px-1 py-0.5 text-[12px] font-mono">$1</code>')
    // Headers
    .replace(/^### (.+)$/gm, '<h4 class="text-sm font-bold mt-3 mb-1">$1</h4>')
    .replace(/^## (.+)$/gm, '<h3 class="text-sm font-bold mt-3 mb-1">$1</h3>')
    .replace(/^# (.+)$/gm, '<h2 class="text-base font-bold mt-3 mb-1">$1</h2>')
    // Unordered lists
    .replace(/^[-•] (.+)$/gm, '<li class="ml-3">$1</li>')
    // Ordered lists
    .replace(/^\d+\. (.+)$/gm, '<li class="ml-3 list-decimal">$1</li>')
    // Line breaks (double newline = paragraph, single = <br>)
    .replace(/\n\n/g, '</p><p class="mt-2">')
    .replace(/\n/g, '<br/>');

  // Wrap consecutive <li> groups in <ul>
  html = html.replace(/((?:<li[^>]*>.*?<\/li>\s*(?:<br\/>)?)+)/g, '<ul class="my-1 space-y-0.5">$1</ul>');

  return `<p>${html}</p>`;
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
