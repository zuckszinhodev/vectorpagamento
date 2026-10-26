import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import SEO from '@/components/SEO';
import TurnstileWidget, { TurnstileWidgetHandle } from '@/components/TurnstileWidget';
import { useThemeStore } from '@/stores/themeStore';
import toast from 'react-hot-toast';
import {
  ArrowRightIcon,
  EnvelopeIcon,
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
  UserIcon,
} from '@heroicons/react/24/outline';

interface RegisterForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

const turnstileSiteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY;

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

function getApiErrorMessage(error: any): string {
  const errors = error.response?.data?.errors;

  if (errors && typeof errors === 'object') {
    const firstField = Object.keys(errors)[0];
    const firstError = firstField ? errors[firstField]?.[0] : null;

    if (firstError) {
      return firstError;
    }
  }

  return (
    error.response?.data?.error?.message ||
    error.response?.data?.message ||
    'Erro ao criar conta'
  );
}

export default function RegisterPage() {
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
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
  const turnstileRef = useRef<TurnstileWidgetHandle>(null);
  const { register: registerUser } = useAuthStore();
  const navigate = useNavigate();

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<RegisterForm>();

  const password = watch('password');

  const onSubmit = async (data: RegisterForm) => {
    if (turnstileSiteKey && !turnstileToken) {
      toast.error('Confirme a verificacao anti-robo.');
      return;
    }

    setIsLoading(true);
    try {
      await registerUser({ ...data, cf_turnstile_token: turnstileToken });
      toast.success('Conta criada com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      toast.error(getApiErrorMessage(error));
      // O token do Turnstile é de uso único; gera um novo para a próxima tentativa
      turnstileRef.current?.reset();
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <>
      <SEO
        title="Criar conta"
        description={`Crie sua conta ${platformName} para gerenciar pagamentos, produtos e afiliados`}
        url="/register"
      />

      <section className="w-full">
        <div className="mb-7">
          <p className={`text-xs font-semibold uppercase tracking-[0.24em] ${accentClass}`}>
            Comece agora
          </p>
          <h1 className={`mt-3 text-3xl font-semibold tracking-tight sm:text-4xl ${headingClass}`}>
            Crie sua conta
          </h1>
          <p className={`mt-3 text-sm leading-6 ${bodyClass}`}>
            Configure seu acesso para operar pagamentos, acompanhar vendas e gerenciar retiradas.
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <label className={`text-sm font-medium ${labelClass}`}>Nome completo</label>
            <div className="relative">
              <UserIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
              <input
                {...register('name', {
                  required: 'Nome e obrigatorio',
                  minLength: {
                    value: 3,
                    message: 'Nome deve ter no minimo 3 caracteres',
                  },
                })}
                type="text"
                autoComplete="name"
                className={inputClass}
                placeholder="Seu nome"
              />
            </div>
            {errors.name && <p className="text-sm text-red-600">{errors.name.message}</p>}
          </div>

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
            {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <label className={`text-sm font-medium ${labelClass}`}>Senha</label>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
                <input
                  {...register('password', {
                    required: 'Senha e obrigatoria',
                    minLength: {
                      value: 8,
                      message: 'Minimo de 8 caracteres',
                    },
                  })}
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="new-password"
                  className={`${inputClass} pr-12`}
                  placeholder="Senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className={`absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 transition-colors ${isDark ? 'hover:text-white' : 'hover:text-neutral-700'}`}
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
            </div>

            <div className="space-y-2">
              <label className={`text-sm font-medium ${labelClass}`}>Confirmar</label>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
                <input
                  {...register('password_confirmation', {
                    required: 'Confirme sua senha',
                    validate: (value) => value === password || 'As senhas nao conferem',
                  })}
                  type={showPasswordConfirmation ? 'text' : 'password'}
                  autoComplete="new-password"
                  className={`${inputClass} pr-12`}
                  placeholder="Repetir senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                  className={`absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 transition-colors ${isDark ? 'hover:text-white' : 'hover:text-neutral-700'}`}
                  aria-label={showPasswordConfirmation ? 'Ocultar confirmacao' : 'Mostrar confirmacao'}
                >
                  {showPasswordConfirmation ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password_confirmation && (
                <p className="text-sm text-red-600">{errors.password_confirmation.message}</p>
              )}
            </div>
          </div>

          <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 text-sm leading-6 transition-colors ${isDark ? `border-white/10 bg-white/[0.04] text-neutral-400 hover:bg-white/[0.06] ${isModern ? 'hover:border-red-500/30' : 'hover:border-emerald-500/30'}` : 'border-neutral-200 bg-neutral-50/60 text-neutral-600 hover:border-neutral-300 hover:bg-neutral-50'}`}>
            <input
              type="checkbox"
              required
              className={`mt-1 h-4 w-4 rounded ${isModern ? 'border-white/20 text-red-500 focus:ring-red-500' : 'border-neutral-300 text-emerald-600 focus:ring-emerald-500'}`}
            />
            <span>
              Concordo com os{' '}
              <a href="/terms" target="_blank" rel="noreferrer" className={`font-medium ${accentLink}`}>
                Termos de Uso
              </a>{' '}
              e a{' '}
              <a href="/privacy" target="_blank" rel="noreferrer" className={`font-medium ${accentLink}`}>
                Politica de Privacidade
              </a>
              .
            </span>
          </label>

          {turnstileSiteKey && (
            <TurnstileWidget
              ref={turnstileRef}
              siteKey={turnstileSiteKey}
              theme={isDark ? 'dark' : 'light'}
              onVerify={setTurnstileToken}
            />
          )}

          <button
            type="submit"
            disabled={isLoading || (!!turnstileSiteKey && !turnstileToken)}
            className={primaryButtonClass}
          >
            {isLoading ? (
              <>
                <Spinner />
                <span className="relative z-10">Criando conta</span>
              </>
            ) : (
              <>
                <span className="relative z-10">Criar conta</span>
                <ArrowRightIcon className="relative z-10 h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" />
              </>
            )}
          </button>
        </form>

        <div className={`mt-7 border-t pt-6 ${isDark ? 'border-white/10' : 'border-neutral-200'}`}>
          <p className={`text-sm ${bodyClass}`}>
            Ja tem uma conta?{' '}
            <Link
              to="/login"
              className={`font-semibold transition-colors ${isDark ? 'text-white' : 'text-neutral-900'} ${accentLink}`}
            >
              Fazer login
            </Link>
          </p>
        </div>
      </section>
    </>
  );
}
