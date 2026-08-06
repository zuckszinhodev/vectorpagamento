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

const inputClass =
  'h-12 w-full rounded-xl border border-neutral-200 bg-white px-11 text-sm text-neutral-900 outline-none transition-all placeholder:text-neutral-400 hover:border-neutral-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10';

const primaryButtonClass =
  'group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-neutral-900 px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-neutral-800 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-neutral-900/15 disabled:cursor-not-allowed disabled:opacity-60';

export default function ForgotPasswordPage() {
  const platformName = useThemeStore((s) => s.platformName);
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
            className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-neutral-600 transition-colors hover:text-neutral-900"
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar para login
          </Link>

          <div className="mb-6 flex h-12 w-12 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600">
            <EnvelopeIcon className="h-6 w-6" />
          </div>

          <div className="mb-8">
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">
              Recuperacao enviada
            </p>
            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">
              Verifique seu e-mail
            </h1>
            <p className="mt-3 text-sm leading-6 text-neutral-600">
              Se existir uma conta vinculada a este endereco, voce recebera um link seguro para redefinir sua senha.
            </p>
          </div>

          <div className="rounded-xl border border-neutral-200 bg-neutral-50/60 p-4">
            <div className="flex items-start gap-3">
              <div className="mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-white text-neutral-700 shadow-sm ring-1 ring-neutral-200">
                <EnvelopeIcon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-neutral-900">E-mail de recuperacao</p>
                <p className="mt-1 truncate text-sm text-neutral-600">{submittedEmail}</p>
              </div>
            </div>
          </div>

          <Link to="/login" className={`${primaryButtonClass} mt-6`}>
            Ir para login
            <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
          </Link>
        </section>
      ) : (
        <section className="w-full">
          <Link
            to="/login"
            className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-neutral-600 transition-colors hover:text-neutral-900"
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar
          </Link>

          <div className="mb-8">
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">
              Recuperar acesso
            </p>
            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">
              Esqueceu sua senha?
            </h1>
            <p className="mt-3 text-sm leading-6 text-neutral-600">
              Informe seu e-mail para receber um link seguro de redefinicao.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
            <div className="space-y-2">
              <label className="text-sm font-medium text-neutral-800">E-mail</label>
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
                  Enviando
                </>
              ) : (
                <>
                  Enviar instrucoes
                  <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                </>
              )}
            </button>

            <p className="text-center text-sm text-neutral-600">
              Lembrou a senha?{' '}
              <Link to="/login" className="font-semibold text-neutral-900 transition-colors hover:text-emerald-700">
                Voltar para login
              </Link>
            </p>
          </form>
        </section>
      )}
    </>
  );
}
