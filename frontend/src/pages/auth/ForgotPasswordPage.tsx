import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { api } from '@/lib/api';
import SEO from '@/components/SEO';
import { useThemeStore } from '@/stores/themeStore';
import toast from 'react-hot-toast';
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  EnvelopeIcon,
} from '@heroicons/react/24/outline';

interface ForgotPasswordForm {
  email: string;
}

function Spinner() {
  return (
    <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24" aria-hidden="true">
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
      <path
        className="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>
  );
}

export default function ForgotPasswordPage() {
  const { platformName, themeVariant, theme } = useThemeStore();
  const isModern = themeVariant === 'modern';
  const isDark = isModern || theme === 'dark';
  const headingClass = isDark ? 'text-white' : 'text-neutral-900';
  const bodyClass = isDark ? 'text-neutral-400' : 'text-neutral-600';
  const labelClass = isDark ? 'text-neutral-200' : 'text-neutral-800';
  const accentClass = isModern ? 'text-red-500' : 'text-emerald-600';
  const accentLink = isModern ? 'text-red-500 hover:text-red-400' : 'text-emerald-600 hover:text-emerald-700';
  const inputClass = `h-12 w-full rounded-xl border px-11 text-sm outline-none transition-all duration-200 focus:ring-4 ${isDark
    ? `border-white/10 bg-white/[0.045] text-white placeholder:text-neutral-600 ${isModern ? 'hover:border-red-500/35 focus:border-red-500 focus:ring-red-500/10' : 'hover:border-emerald-500/35 focus:border-emerald-500 focus:ring-emerald-500/10'}`
    : 'border-neutral-200 bg-white text-neutral-900 placeholder:text-neutral-400 hover:border-neutral-300 focus:border-emerald-500 focus:ring-emerald-500/10'}`;
  const primaryButtonClass = `group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl px-5 text-sm font-semibold text-white transition-all duration-300 before:pointer-events-none before:absolute before:inset-0 before:-translate-x-[120%] before:skew-x-[-20deg] before:bg-gradient-to-r before:from-transparent before:via-white/25 before:to-transparent before:transition-transform before:duration-700 hover:-translate-y-0.5 hover:before:translate-x-[120%] active:scale-[0.985] disabled:cursor-not-allowed disabled:transform-none disabled:opacity-60 ${isModern
    ? 'bg-gradient-to-r from-red-600 to-red-500 shadow-[0_12px_35px_rgba(239,68,68,0.25)] hover:shadow-[0_16px_42px_rgba(239,68,68,0.38)]'
    : 'bg-gradient-to-r from-emerald-600 to-emerald-500 shadow-[0_12px_35px_rgba(16,185,129,0.22)] hover:shadow-[0_16px_42px_rgba(16,185,129,0.34)]'}`;
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [submittedEmail, setSubmittedEmail] = useState('');

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ForgotPasswordForm>();

  const onSubmit = async (data: ForgotPasswordForm) => {
    setIsLoading(true);
    setSubmittedEmail(data.email);

    try {
      await api.post('/auth/forgot-password', data);
      toast.success('Instrucoes enviadas com sucesso!');
    } catch {
      toast.success('Se o e-mail existir, enviaremos as instrucoes.');
    } finally {
      setIsSubmitted(true);
      setIsLoading(false);
    }
  };

  return (
    <>
      <SEO
        title="Recuperar senha"
        description={`Recupere o acesso a sua conta ${platformName}`}
        url="/forgot-password"
      />

      {isSubmitted ? (
        <section className="w-full">
          <Link
            to="/login"
            className={`mb-8 inline-flex items-center gap-2 text-sm font-medium transition-colors ${bodyClass} ${isModern ? 'hover:text-red-500' : 'hover:text-emerald-600'}`}
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar para login
          </Link>

          <div className={`mb-6 flex h-12 w-12 items-center justify-center rounded-xl border ${isModern ? 'border-red-500/25 bg-red-500/10 text-red-500' : 'border-emerald-200 bg-emerald-50 text-emerald-600'}`}>
            <EnvelopeIcon className="h-6 w-6" />
          </div>

          <div className="mb-8">
            <p className={`text-xs font-semibold uppercase tracking-[0.24em] ${accentClass}`}>
              Recuperacao enviada
            </p>
            <h1 className={`mt-3 text-3xl font-semibold tracking-tight sm:text-4xl ${headingClass}`}>
              Verifique seu e-mail
            </h1>
            <p className={`mt-3 text-sm leading-6 ${bodyClass}`}>
              Se existir uma conta vinculada a este endereco, voce recebera um link seguro para redefinir sua senha.
            </p>
          </div>

          <div className={`rounded-xl border p-4 ${isDark ? 'border-white/10 bg-white/[0.04]' : 'border-neutral-200 bg-neutral-50/60'}`}>
            <div className="flex items-start gap-3">
              <div className={`mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg shadow-sm ring-1 ${isDark ? 'bg-white/[0.06] text-neutral-300 ring-white/10' : 'bg-white text-neutral-700 ring-neutral-200'}`}>
                <EnvelopeIcon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <p className={`text-sm font-medium ${headingClass}`}>E-mail de recuperacao</p>
                <p className={`mt-1 truncate text-sm ${bodyClass}`}>{submittedEmail}</p>
              </div>
            </div>
          </div>

          <Link to="/login" className={`${primaryButtonClass} mt-6`}>
            <span className="relative z-10">Ir para login</span>
            <ArrowRightIcon className="relative z-10 h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" />
          </Link>
        </section>
      ) : (
        <section className="w-full">
          <Link
            to="/login"
            className={`mb-8 inline-flex items-center gap-2 text-sm font-medium transition-colors ${bodyClass} ${isModern ? 'hover:text-red-500' : 'hover:text-emerald-600'}`}
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar
          </Link>

          <div className="mb-8">
            <p className={`text-xs font-semibold uppercase tracking-[0.24em] ${accentClass}`}>
              Recuperar acesso
            </p>
            <h1 className={`mt-3 text-3xl font-semibold tracking-tight sm:text-4xl ${headingClass}`}>
              Esqueceu sua senha?
            </h1>
            <p className={`mt-3 text-sm leading-6 ${bodyClass}`}>
              Informe seu e-mail para receber um link seguro de redefinicao.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
            <div className="space-y-2">
              <label className={`text-sm font-medium ${labelClass}`}>E-mail</label>
              <div className="relative">
                <EnvelopeIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
                <input
                  {...register('email', {
                    required: 'E-mail e obrigatorio',
                    pattern: {
                      value: /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i,
                      message: 'E-mail invalido',
                    },
                  })}
                  type="email"
                  autoComplete="email"
                  className={inputClass}
                  placeholder="seu@email.com"
                />
              </div>
              {errors.email && (
                <p className="text-sm text-red-600">{errors.email.message}</p>
              )}
            </div>

            <button type="submit" disabled={isLoading} className={primaryButtonClass}>
              {isLoading ? (
                <>
                  <Spinner />
                  <span className="relative z-10">Enviando</span>
                </>
              ) : (
                <>
                  <span className="relative z-10">Enviar instrucoes</span>
                  <ArrowRightIcon className="relative z-10 h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" />
                </>
              )}
            </button>

            <p className={`text-center text-sm ${bodyClass}`}>
              Lembrou a senha?{' '}
              <Link to="/login" className={`font-semibold transition-colors ${isDark ? 'text-white' : 'text-neutral-900'} ${accentLink}`}>
                Voltar para login
              </Link>
            </p>
          </form>
        </section>
      )}
    </>
  );
}
