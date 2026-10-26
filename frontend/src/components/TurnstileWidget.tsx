import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

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

    useImperativeHandle(ref, () => ({
      reset: () => {
        if (widgetIdRef.current && window.turnstile) {
          window.turnstile.reset(widgetIdRef.current);
          onVerifyRef.current(null);
        }
      },
    }));

    useEffect(() => {
      let cancelled = false;

      loadTurnstileScript()
        .then(() => {
          if (cancelled || !containerRef.current || !window.turnstile) return;
          widgetIdRef.current = window.turnstile.render(containerRef.current, {
            sitekey: siteKey,
            theme,
            language: 'pt-br',
            size: 'flexible',
            callback: (token: string) => onVerifyRef.current(token),
            'expired-callback': () => onVerifyRef.current(null),
            'error-callback': () => onVerifyRef.current(null),
          });
        })
        .catch(() => onVerifyRef.current(null));

      return () => {
        cancelled = true;
        if (widgetIdRef.current && window.turnstile) {
          window.turnstile.remove(widgetIdRef.current);
        }
        widgetIdRef.current = null;
      };
    }, [siteKey, theme]);

    return <div ref={containerRef} className="min-h-[65px] w-full" />;
  },
);

TurnstileWidget.displayName = 'TurnstileWidget';

export default TurnstileWidget;
