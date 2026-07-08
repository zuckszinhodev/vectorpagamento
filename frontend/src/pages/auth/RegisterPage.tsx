import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import SEO from '@/components/SEO';
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

export default function RegisterPage() {
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
        description="Crie sua conta VectorPagamentos para gerenciar pagamentos, produtos e afiliados"
        url="/register"
      />

      <section className="w-full">
        <div className="mb-7">
          <h1 className="text-3xl font-semibold tracking-normal text-white">
            Crie sua conta
          </h1>
          <p className="mt-3 text-sm leading-6 text-slate-400">
            Configure seu acesso para operar pagamentos, acompanhar vendas e gerenciar retiradas.
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
          <div className="space-y-2">
            <label className="text-sm font-medium text-slate-200">Nome completo</label>
            <div className="relative">
              <UserIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
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
                className="h-12 w-full rounded-lg border border-white/10 bg-white/[0.035] px-11 text-sm text-white outline-none transition-colors placeholder:text-slate-600 focus:border-emerald-300 focus:bg-white/[0.055] focus:ring-2 focus:ring-emerald-300/15"
                placeholder="Seu nome"
              />
            </div>
            {errors.name && <p className="text-sm text-red-300">{errors.name.message}</p>}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium text-slate-200">E-mail</label>
            <div className="relative">
              <EnvelopeIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
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
                className="h-12 w-full rounded-lg border border-white/10 bg-white/[0.035] px-11 text-sm text-white outline-none transition-colors placeholder:text-slate-600 focus:border-emerald-300 focus:bg-white/[0.055] focus:ring-2 focus:ring-emerald-300/15"
                placeholder="seu@email.com"
              />
            </div>
            {errors.email && <p className="text-sm text-red-300">{errors.email.message}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-200">Senha</label>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
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
                  className="h-12 w-full rounded-lg border border-white/10 bg-white/[0.035] px-11 pr-12 text-sm text-white outline-none transition-colors placeholder:text-slate-600 focus:border-emerald-300 focus:bg-white/[0.055] focus:ring-2 focus:ring-emerald-300/15"
                  placeholder="Senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 transition-colors hover:text-slate-200"
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password && <p className="text-sm text-red-300">{errors.password.message}</p>}
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium text-slate-200">Confirmar</label>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
                <input
                  {...register('password_confirmation', {
                    required: 'Confirme sua senha',
                    validate: (value) => value === password || 'As senhas nao conferem',
                  })}
                  type={showPasswordConfirmation ? 'text' : 'password'}
                  autoComplete="new-password"
                  className="h-12 w-full rounded-lg border border-white/10 bg-white/[0.035] px-11 pr-12 text-sm text-white outline-none transition-colors placeholder:text-slate-600 focus:border-emerald-300 focus:bg-white/[0.055] focus:ring-2 focus:ring-emerald-300/15"
                  placeholder="Repetir senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 transition-colors hover:text-slate-200"
                  aria-label={showPasswordConfirmation ? 'Ocultar confirmacao' : 'Mostrar confirmacao'}
                >
                  {showPasswordConfirmation ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password_confirmation && (
                <p className="text-sm text-red-300">{errors.password_confirmation.message}</p>
              )}
            </div>
          </div>

          <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-white/10 bg-white/[0.025] p-4 text-sm leading-6 text-slate-400 transition-colors hover:border-white/15 hover:bg-white/[0.04]">
            <input
              type="checkbox"
              required
              className="mt-1 h-4 w-4 rounded border-white/15 bg-[#101312] text-emerald-300 focus:ring-emerald-300"
            />
            <span>
              Concordo com os{' '}
              <a href="/terms" target="_blank" rel="noreferrer" className="font-medium text-emerald-300 hover:text-emerald-200">
                Termos de Uso
              </a>{' '}
              e a{' '}
              <a href="/privacy" target="_blank" rel="noreferrer" className="font-medium text-emerald-300 hover:text-emerald-200">
                Politica de Privacidade
              </a>
              .
            </span>
          </label>

          <button
            type="submit"
            disabled={isLoading}
            className="flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-emerald-300 px-5 text-sm font-semibold text-[#052e1a] transition-colors hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2 focus:ring-offset-[#0b0d0c] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {isLoading ? (
              <>
                <Spinner />
                Criando conta
              </>
            ) : (
              <>
                Criar conta
                <ArrowRightIcon className="h-4 w-4" />
              </>
            )}
          </button>
        </form>

        <div className="mt-7 border-t border-white/10 pt-6">
          <p className="text-sm text-slate-500">
            Ja tem uma conta?{' '}
            <Link
              to="/login"
              className="font-medium text-emerald-300 transition-colors hover:text-emerald-200"
            >
              Fazer login
            </Link>
          </p>
        </div>
      </section>
    </>
  );
}
