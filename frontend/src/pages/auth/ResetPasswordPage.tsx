import { useState } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface ResetPasswordForm {
  password: string;
  password_confirmation: string;
}

export default function ResetPasswordPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
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
      toast.error('Token inválido');
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
      <div className="p-8 text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-status-error-subtle">
          <svg className="h-6 w-6 text-status-error" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </div>
        <h2 className="text-xl font-bold text-white">Link inválido</h2>
        <p className="mt-2 text-sm text-gray-400">
          Este link de redefinição de senha é inválido ou expirou.
        </p>
        <Link to="/forgot-password" className="btn-primary mt-6 inline-block">
          Solicitar novo link
        </Link>
      </div>
    );
  }

  if (isSuccess) {
    return (
      <div className="p-8 text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-status-success-subtle">
          <svg className="h-6 w-6 text-status-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-xl font-bold text-white">Senha alterada!</h2>
        <p className="mt-2 text-sm text-gray-400">
          Sua senha foi redefinida com sucesso. Redirecionando para o login...
        </p>
        <Link to="/login" className="btn-primary mt-6 inline-block">
          Ir para login
        </Link>
      </div>
    );
  }

  return (
    <div className="p-8">
      <div className="mb-8 text-center">
        <h2 className="text-2xl font-bold text-white">Redefinir senha</h2>
        <p className="mt-2 text-sm text-gray-400">
          Digite sua nova senha abaixo.
        </p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <div>
          <label htmlFor="password" className="block text-sm font-medium text-gray-300">
            Nova senha
          </label>
          <input
            {...register('password', {
              required: 'Senha é obrigatória',
              minLength: { value: 8, message: 'Mínimo 8 caracteres' },
            })}
            type="password"
            autoComplete="new-password"
            className="mt-1 w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-3 text-white placeholder-gray-500 focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
            placeholder="••••••••"
          />
          {errors.password && (
            <p className="mt-1 text-sm text-status-error">{errors.password.message}</p>
          )}
        </div>

        <div>
          <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-300">
            Confirmar nova senha
          </label>
          <input
            {...register('password_confirmation', {
              required: 'Confirmação é obrigatória',
              validate: (val) => val === watch('password') || 'As senhas não conferem',
            })}
            type="password"
            autoComplete="new-password"
            className="mt-1 w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-3 text-white placeholder-gray-500 focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
            placeholder="••••••••"
          />
          {errors.password_confirmation && (
            <p className="mt-1 text-sm text-status-error">{errors.password_confirmation.message}</p>
          )}
        </div>

        <button
          type="submit"
          disabled={isLoading}
          className="btn-primary w-full"
        >
          {isLoading ? (
            <span className="flex items-center justify-center gap-2">
              <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Redefinindo...
            </span>
          ) : (
            'Redefinir senha'
          )}
        </button>

        <div className="text-center">
          <Link to="/login" className="text-sm text-green-500 hover:text-green-400">
            Voltar para login
          </Link>
        </div>
      </form>
    </div>
  );
}


