import { Link, useLocation } from 'react-router-dom';
import { ShieldExclamationIcon } from '@heroicons/react/24/outline';
import { useThemeStore } from '@/stores/themeStore';

export default function AccountBlockedPage() {
  const location = useLocation();
  const { platformName } = useThemeStore();
  const blockReason = (location.state as any)?.blockReason 
    || (() => { try { return sessionStorage.getItem('block_reason'); } catch { return null; } })();

  return (
    <div className="flex min-h-screen items-center justify-center bg-[#101010] px-4">
      <div className="w-full max-w-lg text-center">
        {/* Logo */}
        <div className="mb-8">
          <span className="text-3xl font-bold text-white">{platformName}</span>
        </div>

        {/* Icon */}
        <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-status-error-subtle0/10 ring-1 ring-red-500/20">
          <ShieldExclamationIcon className="h-10 w-10 text-red-400" />
        </div>

        {/* Title */}
        <h1 className="mb-3 text-2xl font-bold text-white">
          Conta Bloqueada
        </h1>

        {/* Message */}
        <p className="mb-6 text-tx-muted leading-relaxed">
          Seu login foi bloqueado pela administração. Para revisar seu bloqueio, entre em contato com o suporte.
        </p>

        {/* Block Reason */}
        {blockReason && (
          <div className="mb-6 rounded-xl bg-status-error-subtle0/5 border border-red-500/20 p-5 text-left">
            <p className="text-xs font-semibold uppercase tracking-wider text-red-400 mb-2">
              Motivo do bloqueio
            </p>
            <p className="text-sm text-red-300 leading-relaxed">
              {blockReason}
            </p>
          </div>
        )}

        {/* Contact Support */}
        <div className="mb-8 rounded-xl bg-[#121212] border border-[#202020] p-5">
          <p className="text-sm text-tx-muted mb-3">
            Se você acredita que isso foi um erro, entre em contato com nossa equipe de suporte:
          </p>
          <a
            href="mailto:suporte@vectorpagamentos.com"
            className="inline-flex items-center gap-2 text-emerald-400 hover:text-emerald-300 font-medium text-sm transition-colors"
          >
            suporte@vectorpagamentos.com
          </a>
        </div>

        {/* Back to Login */}
        <Link
          to="/login"
          className="inline-flex items-center gap-2 text-sm text-tx-muted hover:text-white transition-colors"
        >
          &larr; Voltar para o login
        </Link>
      </div>
    </div>
  );
}
