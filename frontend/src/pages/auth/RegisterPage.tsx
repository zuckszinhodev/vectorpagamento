import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import toast from 'react-hot-toast';
import { 
  EyeIcon, 
  EyeSlashIcon
} from '@heroicons/react/24/outline';

interface RegisterForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
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
      {/* Header */}
      <div className="mb-3">
        <h1 className="text-lg font-semibold text-white">
          Criar sua conta
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-2.5">
        {/* Name Field */}
        <div className="space-y-1">
          <label className="text-xs font-medium text-white">
            Nome completo
          </label>
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
            className="w-full px-3 py-2 bg-[#121212] border border-[#202020] rounded-element text-white text-sm placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
            placeholder="João Silva"
          />
          {errors.name && (
            <p className="text-sm text-red-400">
              {errors.name.message}
            </p>
          )}
        </div>

        {/* Email Field */}
        <div className="space-y-1">
          <label className="text-xs font-medium text-white">
            Email
          </label>
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
            className="w-full px-3 py-2 bg-[#121212] border border-[#202020] rounded-element text-white text-sm placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
            placeholder="seu@email.com"
          />
          {errors.email && (
            <p className="text-sm text-red-400">
              {errors.email.message}
            </p>
          )}
        </div>

        {/* Password Field */}
        <div className="space-y-1">
          <label className="text-xs font-medium text-white">
            Senha
          </label>
          <div className="relative">
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
              className="w-full px-3 pr-10 py-2 bg-[#121212] border border-[#202020] rounded-element text-white text-sm placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
              placeholder="Sua senha"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-4 top-1/2 -translate-y-1/2 text-tx-muted hover:text-white transition-colors"
            >
              {showPassword ? (
                <EyeSlashIcon className="h-5 w-5" />
              ) : (
                <EyeIcon className="h-5 w-5" />
              )}
            </button>
          </div>
          {errors.password && (
            <p className="text-sm text-red-400">
              {errors.password.message}
            </p>
          )}
        </div>

        {/* Password Confirmation Field */}
        <div className="space-y-1">
          <label className="text-xs font-medium text-white">
            Confirmar senha
          </label>
          <div className="relative">
            <input
              {...register('password_confirmation', {
                required: 'Confirmação de senha é obrigatória',
                validate: (value) =>
                  value === password || 'As senhas não conferem',
              })}
              type={showPasswordConfirmation ? 'text' : 'password'}
              autoComplete="new-password"
              className="w-full px-3 pr-10 py-2 bg-[#121212] border border-[#202020] rounded-element text-white text-sm placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
              placeholder="Confirme sua senha"
            />
            <button
              type="button"
              onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
              className="absolute right-4 top-1/2 -translate-y-1/2 text-tx-muted hover:text-white transition-colors"
            >
              {showPasswordConfirmation ? (
                <EyeSlashIcon className="h-5 w-5" />
              ) : (
                <EyeIcon className="h-5 w-5" />
              )}
            </button>
          </div>
          {errors.password_confirmation && (
            <p className="text-sm text-red-400">
              {errors.password_confirmation.message}
            </p>
          )}
        </div>

        {/* Terms */}
        <label className="flex items-start gap-3 cursor-pointer group/terms">
          <input
            type="checkbox"
            required
            className="mt-1 w-4 h-4 rounded bg-[#121212] border border-[#202020] text-accent-primary focus:ring-2 focus:ring-emerald-500/50 focus:ring-offset-0 transition-colors"
          />
          <span className="text-sm text-tx-muted group-hover/terms:text-tx-muted transition-colors">
            Concordo com os{' '}
            <a href="/terms" target="_blank" className="text-emerald-400 hover:text-emerald-300 hover:underline">
              Termos de Uso
            </a>
            {' '}e{' '}
            <a href="/privacy" target="_blank" className="text-emerald-400 hover:text-emerald-300 hover:underline">
              Política de Privacidade
            </a>
          </span>
        </label>

        {/* Submit Button */}
        <button
          type="submit"
          disabled={isLoading}
          className="w-full py-2.5 px-6 rounded-full font-semibold text-[#101010] bg-emerald-300 hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-[#101010] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {isLoading ? (
            <span className="flex items-center justify-center gap-2">
              <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Criando conta...
            </span>
          ) : (
            'Criar conta'
          )}
        </button>
      </form>

      {/* Login Link */}
      <p className="text-center mt-3 text-tx-muted">
        Já tem uma conta?{' '}
        <Link
          to="/login"
          className="text-emerald-400 hover:text-emerald-300 hover:underline transition-colors"
        >
          Faça login
        </Link>
      </p>
    </div>
  );
}
