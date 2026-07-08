import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import SEO from '@/components/SEO';
import toast from 'react-hot-toast';
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  EnvelopeIcon,
  EyeIcon,
  EyeSlashIcon,
  LockClosedIcon,
  ShieldCheckIcon,
} from '@heroicons/react/24/outline';

interface LoginForm {
  email: string;
  password: string;
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

export default function LoginPage() {
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [mfaCode, setMfaCode] = useState(['', '', '', '', '', '']);
  const [rememberDevice, setRememberDevice] = useState(true);
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
      const state = useAuthStore.getState();

      if (state.mfaPending) {
        return;
      }

      toast.success('Login realizado com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      const errorCode = error.response?.data?.error?.code;
      const message =
        error.response?.data?.error?.message ||
        error.response?.data?.message ||
        'Erro ao fazer login. Verifique suas credenciais.';

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
      setMfaCode(pasted.split(''));
      mfaInputRefs.current[5]?.focus();
    }
  };

  const handleMfaSubmit = async () => {
    const code = mfaCode.join('');

    if (code.length !== 6) {
      toast.error('Digite o codigo de 6 digitos');
      return;
    }

    setMfaLoading(true);
    try {
      await verifyMfa(code, rememberDevice);
      toast.success('Login realizado com sucesso!');
      navigate('/dashboard');
    } catch (error: any) {
      const message =
        error.response?.data?.error?.message ||
        error.response?.data?.message ||
        'Codigo invalido. Tente novamente.';
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

      {mfaPending ? (
        <section className="w-full">
          <div className="mb-10 md:hidden">
            <img
              src="/img/logo.png"
              alt="Vector"
              className="h-10 w-auto object-contain"
            />
          </div>

          <button
            onClick={handleBackToLogin}
            className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-emerald-300 transition-colors hover:text-emerald-200"
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar
          </button>

          <div className="mb-8 flex h-12 w-12 items-center justify-center rounded-lg border border-emerald-300/25 bg-emerald-300/10 text-emerald-200">
            <ShieldCheckIcon className="h-6 w-6" />
          </div>

          <div className="mb-8">
            <p className="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-200/70">
              Verificacao adicional
            </p>
            <h1 className="mt-3 text-3xl font-semibold tracking-normal text-white">
              Confirme seu acesso
            </h1>
            <p className="mt-3 text-sm leading-6 text-slate-400">
              Digite o codigo de 6 digitos gerado pelo Google Authenticator.
            </p>
          </div>

          <div className="space-y-6">
            <div className="grid grid-cols-6 gap-2 sm:gap-3" onPaste={handleMfaPaste}>
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
                  className="h-14 w-full rounded-lg border border-white/10 bg-white/[0.035] text-center text-xl font-semibold text-white outline-none transition-colors focus:border-emerald-300 focus:ring-2 focus:ring-emerald-300/15"
                />
              ))}
            </div>

            <label className="flex items-center gap-3 text-sm text-slate-400">
              <input
                type="checkbox"
                checked={rememberDevice}
                onChange={(e) => setRememberDevice(e.target.checked)}
                className="h-4 w-4 rounded border-white/15 bg-[#101312] text-emerald-300 focus:ring-emerald-300"
              />
              Lembrar este dispositivo por 30 dias
            </label>

            <button
              onClick={handleMfaSubmit}
              disabled={mfaLoading || mfaCode.join('').length !== 6}
              className="flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-emerald-300 px-5 text-sm font-semibold text-[#052e1a] transition-colors hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2 focus:ring-offset-[#0b0d0c] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {mfaLoading ? (
                <>
                  <Spinner />
                  Verificando
                </>
              ) : (
                'Verificar acesso'
              )}
            </button>

            <p className="text-sm leading-6 text-slate-500">
              Em caso de problemas, contate o suporte pelo WhatsApp{' '}
              <a
                href="https://wa.me/5511940968290?text=Ola%2C%20preciso%20de%20ajuda%20com%20a%20autenticacao%20de%202%20fatores"
                className="font-medium text-emerald-300 transition-colors hover:text-emerald-200"
              >
                11 94096-8290
              </a>
              .
            </p>
          </div>
        </section>
      ) : (
        <section className="w-full">
          <div className="mb-8">
            <div className="mb-10 md:hidden">
              <img
                src="/img/logo.png"
                alt="Vector"
                className="h-10 w-auto object-contain"
              />
            </div>

            <h1 className="text-3xl font-semibold tracking-normal text-white">
              Acesse sua conta
            </h1>
            <p className="mt-3 text-sm leading-6 text-slate-400">
              Entre para acompanhar suas vendas
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
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

            <div className="space-y-2">
              <div className="flex items-center justify-between gap-4">
                <label className="text-sm font-medium text-slate-200">Senha</label>
                <Link
                  to="/forgot-password"
                  className="text-sm font-medium text-emerald-300 transition-colors hover:text-emerald-200"
                >
                  Esqueci minha senha
                </Link>
              </div>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-500" />
                <input
                  {...register('password', {
                    required: 'Senha e obrigatoria',
                    minLength: {
                      value: 8,
                      message: 'Senha deve ter no minimo 8 caracteres',
                    },
                  })}
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  className="h-12 w-full rounded-lg border border-white/10 bg-white/[0.035] px-11 pr-12 text-sm text-white outline-none transition-colors placeholder:text-slate-600 focus:border-emerald-300 focus:bg-white/[0.055] focus:ring-2 focus:ring-emerald-300/15"
                  placeholder="Sua senha"
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

            <button
              type="submit"
              disabled={isLoading}
              className="mt-2 flex h-12 w-full items-center justify-center gap-2 rounded-lg bg-emerald-300 px-5 text-sm font-semibold text-[#052e1a] transition-colors hover:bg-emerald-200 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2 focus:ring-offset-[#0b0d0c] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isLoading ? (
                <>
                  <Spinner />
                  Entrando
                </>
              ) : (
                <>
                  Entrar na plataforma
                  <ArrowRightIcon className="h-4 w-4" />
                </>
              )}
            </button>
          </form>

          <div className="mt-8 border-t border-white/10 pt-6">
            <p className="text-sm text-slate-500">
              Nao tem uma conta?{' '}
              <Link
                to="/register"
                className="font-medium text-emerald-300 transition-colors hover:text-emerald-200"
              >
                Cadastre-se
              </Link>
            </p>
          </div>
        </section>
      )}
    </>
  );
}
