import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import {
  ArrowLeftIcon,
  ArrowRightOnRectangleIcon,
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
  EnvelopeIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import SEO from '@/components/SEO';
import { useAuthStore } from '@/stores/authStore';

interface LoginForm {
  email: string;
  password: string;
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

interface GoogleAuthButtonProps {
  loading: boolean;
  setLoading: (value: boolean) => void;
  onToken: (accessToken: string) => Promise<void>;
  onAuthError: () => void;
}

function GoogleAuthButton({ loading, setLoading, onToken, onAuthError }: GoogleAuthButtonProps) {
  const handleGoogleLogin = useGoogleLogin({
    onSuccess: async (tokenResponse) => {
      setLoading(true);
      try {
        await onToken(tokenResponse.access_token);
      } catch {
        // Errors are handled by the caller.
      } finally {
        setLoading(false);
      }
    },
    onError: onAuthError,
  });

  return (
    <button
      type="button"
      disabled={loading}
      onClick={() => handleGoogleLogin()}
      className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/[0.03] text-base font-semibold text-zinc-100 transition-colors hover:bg-white/[0.08] disabled:cursor-not-allowed disabled:opacity-50"
    >
      <span className="text-xl">G</span>
      Continuar com Google
    </button>
  );
}

export default function LoginPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [mfaCode, setMfaCode] = useState(['', '', '', '', '', '']);
  const [mfaLoading, setMfaLoading] = useState(false);
<<<<<<< HEAD
=======
  const [googleLoading, setGoogleLoading] = useState(false);
  const googleClientId = (import.meta.env.VITE_GOOGLE_CLIENT_ID || '').trim();
  const isGoogleEnabled = Boolean(googleClientId);
>>>>>>> 93dd7220d812b8a5f7177d7b771c8dcb25b2eb1c
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
      const state = useAuthStore.getState();
      if (state.mfaPending) {
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

<<<<<<< HEAD
=======
  const handleGoogleToken = async (accessToken: string) => {
    try {
      await googleLogin(accessToken);
      const state = useAuthStore.getState();

      if (state.mfaPending) {
        return;
      }

      toast.success('Login realizado com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      const message = error.response?.data?.error?.message || 'Erro ao fazer login com Google.';
      toast.error(message);
    }
  };

  const handleGoogleAuthError = () => {
    toast.error('Erro ao fazer login com Google. Tente novamente.');
  };

>>>>>>> 93dd7220d812b8a5f7177d7b771c8dcb25b2eb1c
  return (
    <>
      <SEO title="Login" description="Acesse sua conta Vector Pagamentos e gerencie seus pagamentos, produtos e afiliados" url="/login" />

      <div className="w-full">
        <AuthTabs active="login" />

        {mfaPending ? (
          <div>
            <button
              onClick={handleBackToLogin}
              className="mb-5 inline-flex items-center gap-1 text-sm font-medium text-green-300 hover:text-green-200"
            >
              <ArrowLeftIcon className="h-4 w-4" />
              Voltar
            </button>

            <h1 className="text-4xl font-bold tracking-tight text-white">Verificação 2FA</h1>
            <p className="mt-2 text-lg text-zinc-400">Digite o código de 6 dígitos gerado pelo Google Authenticator.</p>

            <div className="mt-7 space-y-6">
              <div className="flex justify-center gap-2" onPaste={handleMfaPaste}>
                {mfaCode.map((digit, i) => (
                  <input
                    key={i}
                    ref={(el) => {
                      mfaInputRefs.current[i] = el;
                    }}
                    type="text"
                    inputMode="numeric"
                    maxLength={1}
                    value={digit}
                    onChange={(e) => handleMfaChange(i, e.target.value)}
                    onKeyDown={(e) => handleMfaKeyDown(i, e)}
                    autoFocus={i === 0}
                    className="h-14 w-12 rounded-xl border border-white/15 bg-white/[0.05] text-center text-2xl font-bold text-white outline-none transition-colors focus:border-green-400"
                  />
                ))}
              </div>

              <button
                onClick={handleMfaSubmit}
                disabled={mfaLoading || mfaCode.join('').length !== 6}
                className="inline-flex h-12 w-full items-center justify-center rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-lg font-semibold text-white transition-opacity hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Verificar
              </button>
            </div>
          </div>
        ) : (
          <div>
            <h1 className="text-3xl font-bold tracking-tight text-white">Bem-vindo de volta</h1>
<<<<<<< HEAD
            <p className="mt-1 text-base text-zinc-400">Faça login com seu e-mail e senha</p>

            <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-3">
=======
            <p className="mt-1 text-base text-zinc-400">
              {isGoogleEnabled ? 'Continue com Google ou use e-mail e senha' : 'Use e-mail e senha para continuar'}
            </p>

            {isGoogleEnabled && (
              <>
                <div className="mt-4 space-y-2.5">
                  <GoogleAuthButton
                    loading={googleLoading}
                    setLoading={setGoogleLoading}
                    onToken={handleGoogleToken}
                    onAuthError={handleGoogleAuthError}
                  />
                </div>

                <div className="my-4 flex items-center gap-3">
                  <span className="h-px flex-1 bg-white/15" />
                  <span className="text-sm text-zinc-500">ou continue com e-mail</span>
                  <span className="h-px flex-1 bg-white/15" />
                </div>
              </>
            )}

            <form onSubmit={handleSubmit(onSubmit)} className={isGoogleEnabled ? 'space-y-3' : 'mt-4 space-y-3'}>
>>>>>>> 93dd7220d812b8a5f7177d7b771c8dcb25b2eb1c
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
                    autoComplete="current-password"
                    className="h-11 w-full rounded-xl border border-white/15 bg-white/[0.03] pl-10 pr-12 text-base text-white placeholder-zinc-500 outline-none transition-colors focus:border-green-400"
                    placeholder="Insira sua senha"
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

              <button
                type="submit"
                disabled={isLoading}
                className="mt-1 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-green-500 to-green-600 text-base font-semibold text-white transition-opacity hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <ArrowRightOnRectangleIcon className="h-5 w-5" />
                Entrar
              </button>
            </form>

            <p className="mt-4 text-center text-sm text-zinc-400">
              Não tem uma conta?{' '}
              <Link to="/register" className="font-semibold text-green-300 hover:text-green-200">
                Cadastre-se
              </Link>
            </p>
          </div>
        )}
      </div>
    </>
  );
}

