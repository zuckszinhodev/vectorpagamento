import { useState, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import { useGoogleLogin } from '@react-oauth/google';
import SEO from '@/components/SEO';
import toast from 'react-hot-toast';
import { 
  EyeIcon, 
  EyeSlashIcon,
  ShieldCheckIcon,
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
  const { googleLogin } = useAuthStore();
  const [googleLoading, setGoogleLoading] = useState(false);
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

  const handleGoogleLogin = useGoogleLogin({
    onSuccess: async (tokenResponse) => {
      setGoogleLoading(true);
      try {
        await googleLogin(tokenResponse.access_token);
        const state = useAuthStore.getState();

        // If MFA is required, stay on page — MFA step will show automatically
        if (state.mfaPending) {
          return;
        }

        toast.success('Login realizado com sucesso!');
        navigate('/dashboard');
      } catch (error: any) {
        const message = error.response?.data?.error?.message || 'Erro ao fazer login com Google.';
        toast.error(message);
      } finally {
        setGoogleLoading(false);
      }
    },
    onError: () => {
      toast.error('Erro ao fazer login com Google. Tente novamente.');
    },
  });

  return (
    <>
      <SEO
        title="Login"
        description="Acesse sua conta WazerPay e gerencie seus pagamentos, produtos e afiliados"
        url="/login"
      />
      
      <div className="w-full">
        {mfaPending ? (
          /* MFA Verification Step */
          <div>
            <div className="mb-8">
              <button
                onClick={handleBackToLogin}
                className="flex items-center gap-1 text-sm text-purple-400 hover:text-purple-300 mb-4 transition-colors"
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
                    className="w-12 h-14 text-center text-xl font-bold bg-[#121212] border border-[#202020] rounded-element text-white focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 transition-colors"
                  />
                ))}
              </div>

              <button
                onClick={handleMfaSubmit}
                disabled={mfaLoading || mfaCode.join('').length !== 6}
                className="w-full py-3 px-6 rounded-full font-semibold text-[#101010] bg-purple-300 hover:bg-purple-200 focus:outline-none focus:ring-2 focus:ring-purple-400 focus:ring-offset-2 focus:ring-offset-[#101010] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
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
                Em caso de problemas, contate o suporte: <a href="https://wa.me/5511940968290?text=Ol%C3%A1%2C%20preciso%20de%20ajuda%20com%20a%20autentica%C3%A7%C3%A3o%20de%202%20fatores" className="text-purple-400 hover:text-purple-300 transition-colors">
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
          <h1 className="text-lg font-semibold text-white mb-2">
            Entrar na sua conta
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
            className="w-full px-4 py-3 bg-[#121212] border border-[#202020] rounded-element text-white placeholder-gray-500 focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 transition-colors"
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
              className="w-full px-4 pr-12 py-3 bg-[#121212] border border-[#202020] rounded-element text-white placeholder-gray-500 focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 transition-colors"
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
            className="text-sm text-purple-400 hover:text-purple-300 hover:underline transition-colors"
          >
            Esqueceu sua senha?
          </Link>
        </div>

        {/* Submit Button */}
        <button
          type="submit"
          disabled={isLoading}
          className="w-full py-3 px-6 rounded-full font-semibold text-[#101010] bg-purple-300 hover:bg-purple-200 focus:outline-none focus:ring-2 focus:ring-purple-400 focus:ring-offset-2 focus:ring-offset-[#101010] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
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

      {/* Divider */}
      <div className="relative my-6">
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
        onClick={() => handleGoogleLogin()}
        className="w-full flex items-center justify-center gap-3 py-3 px-6 rounded-full font-semibold text-gray-300 bg-transparent border border-[#2a2a2a] hover:border-[#404040] hover:bg-white/[0.03] focus:outline-none focus:ring-2 focus:ring-purple-500/40 focus:ring-offset-2 focus:ring-offset-[#101010] transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
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
        Continuar com Google
      </button>

      {/* Register Link */}
      <p className="text-center mt-8 text-tx-muted">
        Não tem uma conta?{' '}
        <Link
          to="/register"
          className="text-purple-400 hover:text-purple-300 hover:underline transition-colors"
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
