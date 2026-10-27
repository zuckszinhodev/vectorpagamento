import { useState } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';
import {
  ArrowRightIcon,
  CheckIcon,
  ExclamationTriangleIcon,
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline';

interface ResetPasswordForm {
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

const inputClass =
  'h-12 w-full rounded-xl border border-neutral-200 bg-white px-11 pr-12 text-sm text-neutral-900 outline-none transition-all placeholder:text-neutral-400 hover:border-neutral-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10';

const primaryButtonClass =
  'group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-neutral-900 px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-neutral-800 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-neutral-900/15 disabled:cursor-not-allowed disabled:opacity-60';

export default function ResetPasswordPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmation, setShowConfirmation] = useState(false);
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get('token');

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = useForm<ResetPasswordForm>();

  const onSubmit = async (data: ResetPasswordForm) => {
    if (!token) {
      toast.error('Token invalido');
      return;
    }

    setIsLoading(true);
    try {
      await api.post('/auth/reset-password', {
        token,
        password: data.password,
        password_confirmation: data.password_confirmation,
      });
      setIsSuccess(true);
      toast.success('Senha alterada com sucesso!');
      setTimeout(() => navigate('/login'), 3000);
    } catch (error: any) {
      const message =
        error?.response?.data?.error?.message || 'Erro ao redefinir senha. Tente novamente.';
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  if (!token) {
    return (
      <section className="w-full text-center">
        <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl border border-red-200 bg-red-50 text-red-600">
          <ExclamationTriangleIcon className="h-6 w-6" />
        </div>
        <h1 className="text-3xl font-semibold tracking-tight text-neutral-900">Link invalido</h1>
        <p className="mt-3 text-sm leading-6 text-neutral-600">
          Este link de redefinicao de senha e invalido ou expirou.
        </p>
        <Link to="/forgot-password" className={`${primaryButtonClass} mt-8`}>
          Solicitar novo link
          <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
        </Link>
      </section>
    );
  }

  if (isSuccess) {
    return (
      <section className="w-full text-center">
        <div className="mx-auto mb-6 flex h-12 w-12 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600">
          <CheckIcon className="h-6 w-6" />
        </div>
        <h1 className="text-3xl font-semibold tracking-tight text-neutral-900">Senha alterada!</h1>
        <p className="mt-3 text-sm leading-6 text-neutral-600">
          Sua senha foi redefinida com sucesso. Redirecionando para o login...
        </p>
        <Link to="/login" className={`${primaryButtonClass} mt-8`}>
          Ir para login
          <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
        </Link>
      </section>
    );
  }

  return (
    <section className="w-full">
      <div className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">
          Nova senha
        </p>
        <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">
          Redefinir senha
        </h1>
        <p className="mt-3 text-sm leading-6 text-neutral-600">
          Escolha uma nova senha segura para acessar sua conta.
        </p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
        <div className="space-y-2">
          <label className="text-sm font-medium text-neutral-800">Nova senha</label>
          <div className="relative">
            <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
            <input
              {...register('password', {
                required: 'Senha e obrigatoria',
                minLength: { value: 8, message: 'Minimo 8 caracteres' },
              })}
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              className={inputClass}
              placeholder="Sua nova senha"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-2 text-neutral-400 transition-colors hover:text-neutral-700"
              aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
            >
              {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
            </button>
          </div>
          {errors.password && (
            <p className="text-sm text-red-600">{errors.password.message}</p>
          )}
        </div>

        <div className="space-y-2">
          <label className="text-sm font-medium text-neutral-800">Confirmar nova senha</label>
          <div className="relative">
            <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
            <input
              {...register('password_confirmation', {
                required: 'Confirmacao e obrigatoria',
                validate: (val) => val === watch('password') || 'As senhas nao conferem',
              })}
              type={showConfirmation ? 'text' : 'password'}
              autoComplete="new-password"
              className={inputClass}
              placeholder="Repita a nova senha"
            />
            <button
              type="button"
              onClick={() => setShowConfirmation(!showConfirmation)}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-2 text-neutral-400 transition-colors hover:text-neutral-700"
              aria-label={showConfirmation ? 'Ocultar confirmacao' : 'Mostrar confirmacao'}
            >
              {showConfirmation ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
            </button>
          </div>
          {errors.password_confirmation && (
            <p className="text-sm text-red-600">{errors.password_confirmation.message}</p>
          )}
        </div>

        <button type="submit" disabled={isLoading} className={primaryButtonClass}>
          {isLoading ? (
            <>
              <Spinner />
              Redefinindo
            </>
          ) : (
            <>
              Redefinir senha
              <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
            </>
          )}
        </button>

        <p className="text-center text-sm text-neutral-600">
          <Link to="/login" className="font-semibold text-neutral-900 transition-colors hover:text-emerald-700">
            Voltar para login
          </Link>
        </p>
      </form>
    </section>
  );
}
