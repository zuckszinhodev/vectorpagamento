import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import { useGoogleLogin } from '@react-oauth/google';
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
  const [googleLoading, setGoogleLoading] = useState(false);
  const { register: registerUser, googleLogin } = useAuthStore();
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

  const handleGoogleRegister = useGoogleLogin({
    onSuccess: async (tokenResponse) => {
      setGoogleLoading(true);
      try {
        await googleLogin(tokenResponse.access_token);
        toast.success('Conta criada com sucesso!');
        navigate('/dashboard');
      } catch (error: any) {
        const message = error.response?.data?.error?.message || 'Erro ao criar conta com Google.';
        toast.error(message);
      } finally {
        setGoogleLoading(false);
      }
    },
    onError: () => {
      toast.error('Erro ao criar conta com Google. Tente novamente.');
    },
  });

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

      {/* Divider */}
      <div className="relative my-3">
        <div className="absolute inset-0 flex items-center">
          <div className="w-full border-t border-[#202020]" />
        </div>
        <div className="relative flex justify-center text-sm">
          <span className="bg-[#101010] px-4 text-gray-500">ou</span>
        </div>
      </div>

      {/* Google Button */}
      <button
        type="button"
        disabled={googleLoading}
        onClick={() => handleGoogleRegister()}
        className="w-full flex items-center justify-center gap-3 py-2.5 px-6 rounded-full font-semibold text-gray-300 bg-transparent border border-[#2a2a2a] hover:border-[#404040] hover:bg-white/[0.03] focus:outline-none focus:ring-2 focus:ring-emerald-500/40 focus:ring-offset-2 focus:ring-offset-[#101010] transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {googleLoading ? (
          <svg className="h-5 w-5 animate-spin text-gray-400" viewBox="0 0 24 24">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
          </svg>
        ) : (
          <svg className="h-5 w-5" viewBox="0 0 24 24">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4" />
            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853" />
            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05" />
            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335" />
          </svg>
        )}
        Cadastrar com Google
      </button>

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
