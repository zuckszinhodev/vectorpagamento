import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { api } from '@/lib/api';
import toast from 'react-hot-toast';

interface ForgotPasswordForm {
  email: string;
}

export default function ForgotPasswordPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<ForgotPasswordForm>();

  const onSubmit = async (data: ForgotPasswordForm) => {
    setIsLoading(true);
    try {
      await api.post('/auth/forgot-password', data);
      setIsSubmitted(true);
      toast.success('E-mail enviado com sucesso!');
    } catch (error: any) {
      // Always show success to prevent email enumeration
      setIsSubmitted(true);
    } finally {
      setIsLoading(false);
    }
  };

  if (isSubmitted) {
    return (
      <div className="p-8 text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-status-success-subtle">
          <svg
            className="h-6 w-6 text-status-success"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M5 13l4 4L19 7"
            />
          </svg>
        </div>
        <h2 className="text-xl font-bold text-white">
          Verifique seu e-mail
        </h2>
        <p className="mt-2 text-sm text-gray-400">
          Se o e-mail existir em nossa base, você receberá instruções para redefinir sua senha.
        </p>
        <Link to="/login" className="btn-primary mt-6 inline-block">
          Voltar para login
        </Link>
      </div>
    );
  }

  return (
    <div className="p-8">
      <div className="mb-8 text-center">
        <h2 className="text-2xl font-bold text-white">
          Esqueceu sua senha?
        </h2>
        <p className="mt-2 text-sm text-gray-400">
          Digite seu e-mail e enviaremos instruções para redefinir sua senha.
        </p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <div>
          <label htmlFor="email" className="block text-sm font-medium text-gray-300">
            E-mail
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
            className="mt-1 w-full rounded-lg border border-gray-700 bg-gray-900 px-4 py-3 text-white placeholder-gray-500 focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
            placeholder="seu@email.com"
          />
          {errors.email && (
            <p className="mt-1 text-sm text-status-error">{errors.email.message}</p>
          )}
        </div>

        <button
          type="submit"
          disabled={isLoading}
          className="btn-primary w-full"
        >
          {isLoading ? (
            <span className="flex items-center justify-center">
              <svg className="mr-2 h-4 w-4 animate-spin" viewBox="0 0 24 24">
                <circle
                  className="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  strokeWidth="4"
                  fill="none"
                />
                <path
                  className="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                />
              </svg>
              Enviando...
            </span>
          ) : (
            'Enviar instruções'
          )}
        </button>

        <p className="text-center text-sm text-gray-400">
          Lembrou a senha?{' '}
          <Link to="/login" className="text-green-500 hover:text-green-400">
            Voltar para login
          </Link>
        </p>
      </form>
    </div>
  );
}


