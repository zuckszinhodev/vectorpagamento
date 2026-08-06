import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import SEO from '@/components/SEO';
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

const inputClass =
  'h-12 w-full rounded-xl border border-neutral-200 bg-white px-11 text-sm text-neutral-900 outline-none transition-all placeholder:text-neutral-400 hover:border-neutral-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10';

const primaryButtonClass =
  'group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-neutral-900 px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-neutral-800 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-neutral-900/15 disabled:cursor-not-allowed disabled:opacity-60';

export default function RegisterPage() {
  const platformName = useThemeStore((s) => s.platformName);
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);
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
    setIsLoading(true);
    try {
      await registerUser(data);
      toast.success('Conta criada com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      toast.error(getApiErrorMessage(error));
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
          <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">
            Comece agora
          </p>
          <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">
            Crie sua conta
          </h1>
          <p className="mt-3 text-sm leading-6 text-neutral-600">
            Configure seu acesso para operar pagamentos, acompanhar vendas e gerenciar retiradas.
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <label className="text-sm font-medium text-neutral-800">Nome completo</label>
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
            {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium text-neutral-800">Senha</label>
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
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 transition-colors hover:text-neutral-700"
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-neutral-800">Confirmar</label>
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
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 transition-colors hover:text-neutral-700"
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

          <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-neutral-200 bg-neutral-50/60 p-4 text-sm leading-6 text-neutral-600 transition-colors hover:border-neutral-300 hover:bg-neutral-50">
            <input
              type="checkbox"
              required
              className="mt-1 h-4 w-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900"
            />
            <span>
              Concordo com os{' '}
              <a href="/terms" target="_blank" rel="noreferrer" className="font-medium text-emerald-600 hover:text-emerald-700">
                Termos de Uso
              </a>{' '}
              e a{' '}
              <a href="/privacy" target="_blank" rel="noreferrer" className="font-medium text-emerald-600 hover:text-emerald-700">
                Politica de Privacidade
              </a>
              .
            </span>
          </label>

          <button type="submit" disabled={isLoading} className={primaryButtonClass}>
            {isLoading ? (
              <>
                <Spinner />
                Criando conta
              </>
            ) : (
              <>
                Criar conta
                <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
              </>
            )}
          </button>
        </form>

        <div className="mt-7 border-t border-neutral-200 pt-6">
          <p className="text-sm text-neutral-600">
            Ja tem uma conta?{' '}
            <Link
              to="/login"
              className="font-semibold text-neutral-900 transition-colors hover:text-emerald-700"
            >
              Fazer login
            </Link>
          </p>
        </div>
      </section>
    </>
  );
}
