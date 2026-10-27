import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import SEO from '@/components/SEO';
import TurnstileWidget, { TurnstileWidgetHandle } from '@/components/TurnstileWidget';
import SocialLoginButtons from '@/components/auth/SocialLoginButtons';
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

const turnstileSiteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY;

const inputBaseClass =
  'h-12 w-full rounded-xl border px-11 text-sm outline-none transition-all duration-200 focus:ring-4';

export default function LoginPage() {
  const { platformName, themeVariant, theme } = useThemeStore();
  const isModern = themeVariant === 'modern';
  const isDark = isModern || theme === 'dark';
  const focusCls = isModern
    ? 'focus:border-red-500 focus:ring-red-500/10'
    : 'focus:border-emerald-500 focus:ring-emerald-500/10';
  // No tema modern o fundo é quase preto: red-600 não passa no contraste mínimo (4.5:1), red-400 passa.
  const accentLink = isModern ? 'text-red-400 hover:text-red-300' : 'text-emerald-600 hover:text-emerald-700';
  const accentLabel = isModern ? 'text-red-400' : 'text-emerald-600';
  const accentHover = isModern ? 'hover:text-red-300' : 'hover:text-emerald-700';
  const headingClass = isDark ? 'text-white' : 'text-neutral-900';
  const bodyClass = isDark ? 'text-neutral-400' : 'text-neutral-600';
  const labelClass = isDark ? 'text-neutral-200' : 'text-neutral-800';
  const inputClass = `${inputBaseClass} ${
    isDark
      ? 'border-white/10 bg-white/[0.045] text-white placeholder:text-neutral-600 hover:border-red-500/35 hover:bg-white/[0.065]'
      : 'border-neutral-200 bg-white text-neutral-900 placeholder:text-neutral-400 hover:border-neutral-300'
  }`;
  const primaryButtonClass = `group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl px-5 text-sm font-semibold text-white transition-all duration-300 before:pointer-events-none before:absolute before:inset-0 before:-translate-x-[120%] before:skew-x-[-20deg] before:bg-gradient-to-r before:from-transparent before:via-white/25 before:to-transparent before:transition-transform before:duration-700 hover:-translate-y-0.5 hover:before:translate-x-[120%] active:translate-y-0 active:scale-[0.985] focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:transform-none disabled:opacity-60 ${
    isModern
      ? 'bg-gradient-to-r from-red-600 to-red-500 shadow-[0_12px_35px_rgba(239,68,68,0.25)] hover:shadow-[0_16px_42px_rgba(239,68,68,0.38)] focus:ring-red-500/20'
      : 'bg-gradient-to-r from-emerald-600 to-emerald-500 shadow-[0_12px_35px_rgba(16,185,129,0.22)] hover:shadow-[0_16px_42px_rgba(16,185,129,0.34)] focus:ring-emerald-500/20'
  }`;
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [mfaCode, setMfaCode] = useState(['', '', '', '', '', '']);
  const [rememberDevice, setRememberDevice] = useState(true);
  const [mfaLoading, setMfaLoading] = useState(false);
  const mfaInputRefs = useRef<(HTMLInputElement | null)[]>([]);
  const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
  const turnstileRef = useRef<TurnstileWidgetHandle>(null);
  const { login, verifyMfa, cancelMfa, mfaPending } = useAuthStore();
  const navigate = useNavigate();

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginForm>();

  const onSubmit = async (data: LoginForm) => {
    if (turnstileSiteKey && !turnstileToken) {
      toast.error('Confirme a verificacao anti-robo.');
      return;
    }

    setIsLoading(true);
    try {
      await login(data.email, data.password, undefined, turnstileToken);
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
      // O token do Turnstile é de uso único; gera um novo para a próxima tentativa
      turnstileRef.current?.reset();
    } finally {
      setIsLoading(false);
    }
  };

  const handleSocialDone = () => {
    // Com MFA ligado o store fica em mfaPending e esta página troca para o código
    if (useAuthStore.getState().mfaPending) return;
    toast.success('Login realizado com sucesso!');
    navigate('/dashboard');
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
        description={`Acesse sua conta ${platformName} e gerencie seus pagamentos, produtos e afiliados`}
        url="/login"
      />

      {mfaPending ? (
        <section className="w-full">
          <button
            onClick={handleBackToLogin}
            className="mb-8 inline-flex items-center gap-2 text-sm font-medium text-neutral-600 transition-colors hover:text-neutral-900"
          >
            <ArrowLeftIcon className="h-4 w-4" />
            Voltar
          </button>


          <div className="mb-8">
            <h1 className={`mt-3 text-3xl font-semibold tracking-tight ${headingClass}`}>
              Confirme seu acesso
            </h1>
            <p className={`mt-3 text-sm leading-6 ${bodyClass}`}>
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
                  className={`h-14 w-full rounded-xl border text-center text-xl font-semibold outline-none transition-all focus:ring-4 ${isDark ? 'border-white/10 bg-white/[0.045] text-white hover:border-white/25' : 'border-neutral-200 bg-white text-neutral-900 hover:border-neutral-300'} ${focusCls}`}
                />
              ))}
            </div>

            <label className={`flex cursor-pointer items-center gap-3 text-sm ${bodyClass}`}>
              <input
                type="checkbox"
                checked={rememberDevice}
                onChange={(e) => setRememberDevice(e.target.checked)}
                className="h-4 w-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900"
              />
              Lembrar este dispositivo por 30 dias
            </label>

            <button
              onClick={handleMfaSubmit}
              disabled={mfaLoading || mfaCode.join('').length !== 6}
              className={primaryButtonClass}
            >
              {mfaLoading ? (
                <>
                  <Spinner />
                  <span className="relative z-10">Verificando</span>
                </>
              ) : (
                <span className="relative z-10">Verificar acesso</span>
              )}
            </button>

            <p className={`text-sm leading-6 ${bodyClass}`}>
              Em caso de problemas, contate o suporte pelo WhatsApp{' '}
              <a
                href="https://wa.me/5511940968290?text=Ola%2C%20preciso%20de%20ajuda%20com%20a%20autenticacao%20de%202%20fatores"
                className={`font-medium transition-colors ${accentLink}`}
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
            <p className={`text-xs font-semibold uppercase tracking-[0.24em] ${accentLabel}`}>
              Bem-vindo de volta
            </p>
            <h1 className={`mt-3 text-3xl font-semibold tracking-tight sm:text-4xl ${headingClass}`}>
              Acesse sua conta
            </h1>
            <p className={`mt-3 text-sm leading-6 ${bodyClass}`}>
              Entre para acompanhar suas vendas e movimentacoes.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
            <div className="space-y-2">
              <label className={`text-sm font-medium ${labelClass}`}>E-mail</label>
              <div className="relative">
                <EnvelopeIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
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
                  className={`${inputClass} ${focusCls}`}
                  placeholder="seu@email.com"
                />
              </div>
              {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between gap-4">
                <label className={`text-sm font-medium ${labelClass}`}>Senha</label>
                <Link
                  to="/forgot-password"
                  className={`text-sm font-medium transition-colors ${accentLink}`}
                >
                  Esqueci minha senha
                </Link>
              </div>
              <div className="relative">
                <LockClosedIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-neutral-400" />
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
                  className={`${inputClass} ${focusCls} pr-12`}
                  placeholder="Sua senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className={`absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-2 text-neutral-400 transition-colors ${isDark ? 'hover:text-white' : 'hover:text-neutral-700'}`}
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
            </div>

            {turnstileSiteKey && (
              <TurnstileWidget
                ref={turnstileRef}
                siteKey={turnstileSiteKey}
                theme={isDark ? 'dark' : 'light'}
                onVerify={setTurnstileToken}
              />
            )}

            <button type="submit" disabled={isLoading || (!!turnstileSiteKey && !turnstileToken)} className={`${primaryButtonClass} mt-2`}>
              {isLoading ? (
                <>
                  <Spinner />
                  <span className="relative z-10">Entrando</span>
                </>
              ) : (
                <>
                  <span className="relative z-10">Entrar na plataforma</span>
                  <ArrowRightIcon className="relative z-10 h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" />
                </>
              )}
            </button>
          </form>

          <SocialLoginButtons
            isDark={isDark}
            onDone={handleSocialDone}
            onBlocked={(blockReason) => navigate('/account-blocked', { state: { blockReason } })}
          />

          <div className={`mt-8 border-t pt-6 ${isDark ? 'border-white/10' : 'border-neutral-200'}`}>
            <p className={`text-sm ${bodyClass}`}>
              Nao tem uma conta?{' '}
              <Link
                to="/register"
                className={`font-semibold transition-colors ${isDark ? 'text-white' : 'text-neutral-900'} ${accentHover}`}
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
