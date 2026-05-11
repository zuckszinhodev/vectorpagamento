import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import {
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
  EnvelopeIcon,
  UserIcon,
  ArrowRightOnRectangleIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { useAuthStore } from '@/stores/authStore';

interface RegisterForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

function AuthTabs({ active }: { active: 'login' | 'register' }) {
  return (
    <div className="mb-5 rounded-2xl border border-white/15 bg-white/[0.05] p-1">
      <div className="grid grid-cols-2 gap-1">
        <Link
          to="/login"
          className={
            active === 'login'
              ? 'rounded-xl bg-[#06080d] px-4 py-2.5 text-center text-base font-semibold text-white'
              : 'rounded-xl px-4 py-2.5 text-center text-base font-medium text-zinc-400 hover:text-zinc-200'
          }
        >
          Entrar
        </Link>
        <Link
          to="/register"
          className={
            active === 'register'
              ? 'rounded-xl bg-[#06080d] px-4 py-2.5 text-center text-base font-semibold text-white'
              : 'rounded-xl px-4 py-2.5 text-center text-base font-medium text-zinc-400 hover:text-zinc-200'
          }
        >
          Cadastrar
        </Link>
      </div>
    </div>
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
      const message = error.response?.data?.message || 'Erro ao criar conta';
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="w-full">
      <AuthTabs active="register" />

      <h1 className="text-3xl font-bold tracking-tight text-white">Crie sua conta</h1>
      <p className="mt-1 text-base text-zinc-400">
        Use e-mail e senha para criar sua conta
      </p>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-4 space-y-3">
        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-200">Nome completo</label>
          <div className="relative">
            <UserIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-500" />
            <input
              {...register('name', {
                required: 'Nome é obrigatório',
                minLength: {
                  value: 3,
                  message: 'Nome deve ter no mínimo 3 caracteres',
                },
              })}
              type="text"
              autoComplete="name"
              className="h-11 w-full rounded-xl border border-white/15 bg-white/[0.03] pl-10 pr-4 text-base text-white placeholder-zinc-500 outline-none transition-colors focus:border-green-400"
              placeholder="Seu nome"
            />
          </div>
          {errors.name && <p className="mt-1 text-sm text-red-400">{errors.name.message}</p>}
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-200">E-mail</label>
          <div className="relative">
            <EnvelopeIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-500" />
            <input
              {...register('email', {
                required: 'E-mail é obrigatório',
                pattern: {
                  value: /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i,
                  message: 'E-mail inválido',
                },
              })}
              type="email"
              autoComplete="email"
              className="h-11 w-full rounded-xl border border-white/15 bg-white/[0.03] pl-10 pr-4 text-base text-white placeholder-zinc-500 outline-none transition-colors focus:border-green-400"
              placeholder="nome@exemplo.com"
            />
          </div>
          {errors.email && <p className="mt-1 text-sm text-red-400">{errors.email.message}</p>}
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-200">Senha</label>
          <div className="relative">
            <LockClosedIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-500" />
            <input
              {...register('password', {
                required: 'Senha é obrigatória',
                minLength: {
                  value: 8,
                  message: 'Senha deve ter no mínimo 8 caracteres',
                },
              })}
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              className="h-11 w-full rounded-xl border border-white/15 bg-white/[0.03] pl-10 pr-12 text-base text-white placeholder-zinc-500 outline-none transition-colors focus:border-green-400"
              placeholder="Crie uma senha"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
            >
              {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
            </button>
          </div>
          {errors.password && <p className="mt-1 text-sm text-red-400">{errors.password.message}</p>}
        </div>

        <div>
          <label className="mb-1 block text-sm font-medium text-zinc-200">Confirmar senha</label>
          <div className="relative">
            <LockClosedIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-zinc-500" />
            <input
              {...register('password_confirmation', {
                required: 'Confirmação de senha é obrigatória',
                validate: (value) => value === password || 'As senhas não conferem',
              })}
              type={showPasswordConfirmation ? 'text' : 'password'}
              autoComplete="new-password"
              className="h-11 w-full rounded-xl border border-white/15 bg-white/[0.03] pl-10 pr-12 text-base text-white placeholder-zinc-500 outline-none transition-colors focus:border-green-400"
              placeholder="Repita sua senha"
            />
            <button
              type="button"
              onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
            >
              {showPasswordConfirmation ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
            </button>
          </div>
          {errors.password_confirmation && <p className="mt-1 text-sm text-red-400">{errors.password_confirmation.message}</p>}
        </div>

        <label className="flex items-start gap-2 text-xs text-zinc-400">
          <input type="checkbox" required className="mt-1 h-4 w-4 rounded border border-white/20 bg-white/5" />
          <span>
            Concordo com os{' '}
            <a href="/terms" target="_blank" className="font-medium text-green-300 hover:text-green-200" rel="noreferrer">
              Termos de Uso
            </a>
            {' '}e{' '}
            <a href="/privacy" target="_blank" className="font-medium text-green-300 hover:text-green-200" rel="noreferrer">
              Política de Privacidade
            </a>
          </span>
        </label>

        <button
          type="submit"
          disabled={isLoading}
          className="mt-1 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-base font-semibold text-white transition-opacity hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <ArrowRightOnRectangleIcon className="h-5 w-5" />
          Criar conta
        </button>
      </form>
    </div>
  );
}

