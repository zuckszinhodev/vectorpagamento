import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: Record<string, unknown>) => string;
      reset: (widgetId: string) => void;
      remove: (widgetId: string) => void;
    };
  }
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise: Promise<void> | null = null;

function loadTurnstileScript(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => {
      scriptPromise = null;
      reject(new Error('Falha ao carregar o Cloudflare Turnstile'));
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}

export interface TurnstileWidgetHandle {
  reset: () => void;
}

interface TurnstileWidgetProps {
  siteKey: string;
  theme?: 'light' | 'dark' | 'auto';
  onVerify: (token: string | null) => void;
}

const TurnstileWidget = forwardRef<TurnstileWidgetHandle, TurnstileWidgetProps>(
  ({ siteKey, theme = 'auto', onVerify }, ref) => {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetIdRef = useRef<string | null>(null);
    const onVerifyRef = useRef(onVerify);
    onVerifyRef.current = onVerify;
    // O tema só vale na criação: recriar o widget ao trocar de tema descartava a
    // verificação em andamento (no celular ficava girando sem concluir)
    const themeRef = useRef(theme);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error' | 'unavailable'>('loading');
    const [attempt, setAttempt] = useState(0);

    const reset = () => {
      onVerifyRef.current(null);
      setStatus('loading');
      if (widgetIdRef.current && window.turnstile) {
        window.turnstile.reset(widgetIdRef.current);
      } else {
        setAttempt((n) => n + 1);
      }
    };

    useImperativeHandle(ref, () => ({ reset }));

    useEffect(() => {
      let cancelled = false;

      loadTurnstileScript()
        .then(() => {
          if (cancelled || !containerRef.current || !window.turnstile) return;
          widgetIdRef.current = window.turnstile.render(containerRef.current, {
            sitekey: siteKey,
            theme: themeRef.current,
            language: 'pt-br',
            size: 'flexible',
            retry: 'auto',
            'refresh-expired': 'auto',
            callback: (token: string) => {
              setStatus('ready');
              onVerifyRef.current(token);
            },
            'expired-callback': () => onVerifyRef.current(null),
            'error-callback': () => {
              setStatus('error');
              onVerifyRef.current(null);
            },
          });
        })
        .catch(() => {
          if (cancelled) return;
          setStatus('unavailable');
          onVerifyRef.current(null);
        });

      return () => {
        cancelled = true;
        if (widgetIdRef.current && window.turnstile) {
          window.turnstile.remove(widgetIdRef.current);
        }
        widgetIdRef.current = null;
      };
    }, [siteKey, attempt]);

    return (
      <div className="w-full">
        <div ref={containerRef} className="min-h-[65px] w-full" />
        {(status === 'error' || status === 'unavailable') && (
          <p className="mt-2 text-xs text-red-500">
            {status === 'unavailable'
              ? 'Não foi possível carregar a verificação. Confira sua conexão ou desative bloqueadores de anúncio.'
              : 'A verificação falhou.'}{' '}
            <button type="button" onClick={reset} className="font-semibold underline">
              Tentar novamente
            </button>
          </p>
        )}
      </div>
    );
  },
);

TurnstileWidget.displayName = 'TurnstileWidget';

export default TurnstileWidget;
