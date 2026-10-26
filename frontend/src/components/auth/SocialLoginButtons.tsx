import { useEffect, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { useAuthStore } from '@/stores/authStore';

declare global {
  interface Window {
    AppleID?: {
      auth: {
        init: (config: Record<string, unknown>) => void;
        signIn: () => Promise<{
          authorization: { id_token: string };
          user?: { name?: { firstName?: string; lastName?: string } };
        }>;
      };
    };
  }
}

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;
const APPLE_CLIENT_ID = import.meta.env.VITE_APPLE_CLIENT_ID;

const GOOGLE_SCRIPT = 'https://accounts.google.com/gsi/client';
const APPLE_SCRIPT = 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/pt_BR/appleid.auth.js';

const scriptCache = new Map<string, Promise<void>>();

// O Google só aceita um initialize() por página; o botão montado no momento recebe a credencial
let googleInitialized = false;
let googleCredentialHandler: ((credential: string) => void) | null = null;

function loadScript(src: string): Promise<void> {
  const cached = scriptCache.get(src);
  if (cached) return cached;

  const promise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => {
      scriptCache.delete(src);
      reject(new Error(`Falha ao carregar ${src}`));
    };
    document.head.appendChild(script);
  });
  scriptCache.set(src, promise);
  return promise;
}

function errorMessage(error: any, fallback: string): string {
  return error?.response?.data?.error?.message || error?.response?.data?.message || fallback;
}

interface SocialLoginButtonsProps {
  isDark: boolean;
  /** Chamado depois que o backend respondeu (login concluído ou MFA pendente). */
  onDone: () => void;
  /** Chamado quando a conta está bloqueada. */
  onBlocked?: (blockReason?: string) => void;
}

export function isSocialLoginEnabled(): boolean {
  return !!GOOGLE_CLIENT_ID || !!APPLE_CLIENT_ID;
}

export default function SocialLoginButtons({ isDark, onDone, onBlocked }: SocialLoginButtonsProps) {
  const googleRef = useRef<HTMLDivElement>(null);
  const [appleLoading, setAppleLoading] = useState(false);
  const { googleLogin, appleLogin } = useAuthStore();

  // Os callbacks mudam a cada render; o botão do Google é inicializado uma vez só
  const handlersRef = useRef({ onDone, onBlocked });
  handlersRef.current = { onDone, onBlocked };

  const handleError = (error: any, fallback: string) => {
    if (error?.response?.data?.error?.code === 'account_blocked') {
      handlersRef.current.onBlocked?.(error.response.data.error.block_reason);
      return;
    }
    toast.error(errorMessage(error, fallback));
  };

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID) return;
    let cancelled = false;

    googleCredentialHandler = async (credential: string) => {
      try {
        await googleLogin(credential);
        handlersRef.current.onDone();
      } catch (error) {
        handleError(error, 'Não foi possível entrar com o Google.');
      }
    };

    loadScript(GOOGLE_SCRIPT)
      .then(() => {
        if (cancelled || !googleRef.current || !window.google) return;
        if (!googleInitialized) {
          window.google.accounts.id.initialize({
            client_id: GOOGLE_CLIENT_ID,
            callback: ({ credential }) => googleCredentialHandler?.(credential),
          });
          googleInitialized = true;
        }
        googleRef.current.innerHTML = '';
        window.google.accounts.id.renderButton(googleRef.current, {
          type: 'icon',
          shape: 'circle',
          theme: isDark ? 'filled_black' : 'outline',
          size: 'large',
        });
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isDark]);

  useEffect(() => {
    if (!APPLE_CLIENT_ID) return;
    loadScript(APPLE_SCRIPT)
      .then(() => {
        window.AppleID?.auth.init({
          clientId: APPLE_CLIENT_ID,
          scope: 'name email',
          redirectURI: window.location.origin,
          usePopup: true,
        });
      })
      .catch(() => undefined);
  }, []);

  const handleApple = async () => {
    if (!window.AppleID) {
      toast.error('Login com Apple indisponível no momento.');
      return;
    }
    setAppleLoading(true);
    try {
      const result = await window.AppleID.auth.signIn();
      // A Apple só envia o nome no primeiro acesso
      const name = [result.user?.name?.firstName, result.user?.name?.lastName].filter(Boolean).join(' ');
      await appleLogin(result.authorization.id_token, name || null);
      onDone();
    } catch (error: any) {
      // Usuário fechou o popup: não é erro
      if (error?.error === 'popup_closed_by_user' || error?.error === 'user_cancelled_authorize') return;
      handleError(error, 'Não foi possível entrar com a Apple.');
    } finally {
      setAppleLoading(false);
    }
  };

  if (!isSocialLoginEnabled()) return null;

  return (
    <div className="mt-6">
      <div className="flex items-center gap-3">
        <span className={`h-px flex-1 ${isDark ? 'bg-white/10' : 'bg-neutral-200'}`} />
        <span className={`text-xs ${isDark ? 'text-neutral-500' : 'text-neutral-400'}`}>ou continue com</span>
        <span className={`h-px flex-1 ${isDark ? 'bg-white/10' : 'bg-neutral-200'}`} />
      </div>

      <div className="mt-4 flex items-center justify-center gap-4">
        {GOOGLE_CLIENT_ID && (
          <div ref={googleRef} className="h-10 w-10 overflow-hidden rounded-full" title="Continuar com Google" />
        )}

        {APPLE_CLIENT_ID && (
          <button
            type="button"
            onClick={handleApple}
            disabled={appleLoading}
            aria-label="Continuar com Apple"
            title="Continuar com Apple"
            className={`flex h-10 w-10 items-center justify-center rounded-full transition-all hover:-translate-y-0.5 disabled:opacity-60 ${
              isDark
                ? 'bg-white text-black hover:bg-neutral-200'
                : 'bg-black text-white hover:bg-neutral-800'
            }`}
          >
            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden="true">
              <path d="M16.37 12.62c-.02-2.3 1.88-3.4 1.96-3.46-1.07-1.56-2.73-1.78-3.32-1.8-1.41-.14-2.76.83-3.47.83-.72 0-1.82-.81-2.99-.79-1.54.02-2.96.9-3.75 2.27-1.6 2.78-.41 6.88 1.15 9.13.76 1.1 1.67 2.33 2.86 2.29 1.15-.05 1.58-.74 2.97-.74 1.38 0 1.77.74 2.98.72 1.24-.02 2.02-1.12 2.77-2.22.87-1.28 1.23-2.52 1.25-2.58-.03-.01-2.39-.92-2.41-3.65zM14.1 5.86c.63-.77 1.06-1.83.94-2.89-.91.04-2.02.61-2.67 1.37-.58.67-1.1 1.76-.96 2.8 1.02.08 2.06-.52 2.69-1.28z" />
            </svg>
          </button>
        )}
      </div>
    </div>
  );
}
