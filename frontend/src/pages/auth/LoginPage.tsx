import { useState, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import SEO from '@/components/SEO';
import toast from 'react-hot-toast';
import { 
  EyeIcon, 
  EyeSlashIcon,
  ArrowLeftIcon,
} from '@heroicons/react/24/outline';

interface LoginForm {
  email: string;
  password: string;
}

export default function LoginPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [mfaCode, setMfaCode] = useState(['', '', '', '', '', '']);
  const [mfaLoading, setMfaLoading] = useState(false);
  const mfaInputRefs = useRef<(HTMLInputElement | null)[]>([]);
  const { login, verifyMfa, cancelMfa, mfaPending } = useAuthStore();
  const navigate = useNavigate();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginForm>();

  const onSubmit = async (data: LoginForm) => {
    setIsLoading(true);
    try {
      await login(data.email, data.password);
      // If MFA is required, the store will set mfaPending and we stay on page
      const state = useAuthStore.getState();
      if (state.mfaPending) {
        // MFA step will show automatically
        return;
      }
      toast.success('Login realizado com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      const errorCode = error.response?.data?.error?.code;
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao fazer login. Verifique suas credenciais.';
      
      if (errorCode === 'account_blocked') {
        const blockReason = error.response?.data?.error?.block_reason;
        navigate('/account-blocked', { state: { blockReason } });
        return;
      }
      
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  const handleMfaChange = (index: number, value: string) => {
    if (!/^\d*$/.test(value)) return;
    const newCode = [...mfaCode];
    newCode[index] = value.slice(-1);
    setMfaCode(newCode);
    if (value && index < 5) {
      mfaInputRefs.current[index + 1]?.focus();
    }
  };

  const handleMfaKeyDown = (index: number, e: React.KeyboardEvent) => {
    if (e.key === 'Backspace' && !mfaCode[index] && index > 0) {
      mfaInputRefs.current[index - 1]?.focus();
    }
  };

  const handleMfaPaste = (e: React.ClipboardEvent) => {
    e.preventDefault();
    const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
    if (pasted.length === 6) {
      const newCode = pasted.split('');
      setMfaCode(newCode);
      mfaInputRefs.current[5]?.focus();
    }
  };

  const handleMfaSubmit = async () => {
    const code = mfaCode.join('');
    if (code.length !== 6) {
      toast.error('Digite o código de 6 dígitos');
      return;
    }
    setMfaLoading(true);
    try {
      await verifyMfa(code);
      toast.success('Login realizado com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Código inválido. Tente novamente.';
      toast.error(message);
      setMfaCode(['', '', '', '', '', '']);
      mfaInputRefs.current[0]?.focus();
    } finally {
      setMfaLoading(false);
    }
  };

  const handleBackToLogin = () => {
    cancelMfa();
    setMfaCode(['', '', '', '', '', '']);
  };

  return (
    <>
      <SEO
        title="Login"
        description="Acesse sua conta VectorPagamentos e gerencie seus pagamentos, produtos e afiliados"
        url="/login"
      />
      
      <div className="w-full">
        {mfaPending ? (
          /* MFA Verification Step */
          <div>
            <div className="mb-8">
              <button
                onClick={handleBackToLogin}
                className="flex items-center gap-1 text-sm text-emerald-400 hover:text-emerald-300 mb-4 transition-colors"
              >
                <ArrowLeftIcon className="h-4 w-4" />
                Voltar
              </button>
              <div className="flex items-center gap-3 mb-2">
                <h1 className="text-2xl font-semibold text-white">
                  Verificação 2FA
                </h1>
              </div>
              <p className="text-sm text-gray-400">
                Digite o código de 6 dígitos gerado pelo Google Authenticator.
              </p>
            </div>

            <div className="space-y-6">
              <div className="flex justify-center gap-3" onPaste={handleMfaPaste}>
                {mfaCode.map((digit, i) => (
                  <input
                    key={i}
                    ref={(el) => { mfaInputRefs.current[i] = el; }}
                    type="text"
                    inputMode="numeric"
                    maxLength={1}
                    value={digit}
                    onChange={(e) => handleMfaChange(i, e.target.value)}
                    onKeyDown={(e) => handleMfaKeyDown(i, e)}
                    autoFocus={i === 0}
                    className="w-12 h-14 text-center text-xl font-bold bg-[#121212] border border-[#202020] rounded-element text-white focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
                  />
                ))}
              </div>

              <button
                onClick={handleMfaSubmit}
                disabled={mfaLoading || mfaCode.join('').length !== 6}
                className="w-full py-3 px-6 rounded-full font-semibold text-[#101010] bg-emerald-300 hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-[#101010] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {mfaLoading ? (
                  <span className="flex items-center justify-center gap-2">
                    <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                    Verificando...
                  </span>
                ) : (
                  'Verificar'
                )}
              </button>

              <p className="text-center text-xs text-gray-500">
                O código expira em 5 minutos. Abra o Google Authenticator para obtê-lo.
              </p>
              <p className="text-center text-xs text-gray-500">
                Em caso de problemas, contate o suporte: <a href="https://wa.me/5511940968290?text=Ol%C3%A1%2C%20preciso%20de%20ajuda%20com%20a%20autentica%C3%A7%C3%A3o%20de%202%20fatores" className="text-emerald-400 hover:text-emerald-300 transition-colors">
                  11 94096-8290
                </a>
              </p>
            </div>
          </div>
        ) : (
        /* Normal Login Form */
        <div>
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-lg font-semibold text-white mb-1">
            Acesso ao Gateway
          </h1>
        </div>

      {/* Form */}
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        {/* Email Field */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-white">
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
            className="w-full px-4 py-3 bg-[#121212] border border-[#202020] rounded-element text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
            placeholder="seu@email.com"
          />
          {errors.email && (
            <p className="text-sm text-red-400">
              {errors.email.message}
            </p>
          )}
        </div>

        {/* Password Field */}
        <div className="space-y-2">
          <label className="text-sm font-medium text-white">
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
              autoComplete="current-password"
              className="w-full px-4 pr-12 py-3 bg-[#121212] border border-[#202020] rounded-element text-white placeholder-gray-500 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 transition-colors"
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

        {/* Forgot Password Link */}
        <div className="flex justify-start">
          <Link
            to="/forgot-password"
            className="text-sm text-emerald-400 hover:text-emerald-300 hover:underline transition-colors"
          >
            Esqueceu sua senha?
          </Link>
        </div>

        {/* Submit Button */}
        <button
          type="submit"
          disabled={isLoading}
          className="w-full py-3 px-6 rounded-full font-semibold text-[#052e1a] bg-emerald-300 hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-[#101010] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {isLoading ? (
            <span className="flex items-center justify-center gap-2">
              <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Entrando...
            </span>
          ) : (
            'Entrar'
          )}
        </button>
      </form>

      {/* Register Link */}
      <p className="text-center mt-8 text-tx-muted">
        Não tem uma conta?{' '}
        <Link
          to="/register"
          className="text-emerald-400 hover:text-emerald-300 hover:underline transition-colors"
        >
          Cadastre-se
        </Link>
      </p>
    </div>
        )}
    </div>
    </>
  );
}
