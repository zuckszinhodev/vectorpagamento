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

// O Turnstile baixa ~800 KB e disputa rede e CPU com a abertura do login.
// Ele só começa na primeira interação (ou após este tempo, se ninguém tocar
// na página). O envio continua bloqueado até haver token: a proteção não muda.
const ACTIVATION_FALLBACK_MS = 5000;
const ACTIVATION_EVENTS = ['pointerdown', 'touchstart', 'keydown', 'focusin'] as const;

function useActivation(): [boolean, () => void] {
  const [active, setActive] = useState(false);

  useEffect(() => {
    if (active) return;
    const activate = () => setActive(true);
    const timer = window.setTimeout(activate, ACTIVATION_FALLBACK_MS);
    ACTIVATION_EVENTS.forEach((event) =>
      window.addEventListener(event, activate, { once: true, passive: true, capture: true }),
    );
    return () => {
      window.clearTimeout(timer);
      ACTIVATION_EVENTS.forEach((event) => window.removeEventListener(event, activate, { capture: true }));
    };
  }, [active]);

  return [active, () => setActive(true)];
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
    const [active, activate] = useActivation();

    const reset = () => {
      onVerifyRef.current(null);
      setStatus('loading');
      activate();
      if (widgetIdRef.current && window.turnstile) {
        window.turnstile.reset(widgetIdRef.current);
      } else {
        setAttempt((n) => n + 1);
      }
    };

    useImperativeHandle(ref, () => ({ reset }));

    useEffect(() => {
      if (!active) return;
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
    }, [siteKey, attempt, active]);

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
