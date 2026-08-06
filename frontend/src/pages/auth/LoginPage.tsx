import { useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
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

const inputClass =
  'h-12 w-full rounded-xl border border-neutral-200 bg-white px-11 text-sm text-neutral-900 outline-none transition-all placeholder:text-neutral-400 hover:border-neutral-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10';

const primaryButtonClass =
  'group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-neutral-900 px-5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-neutral-800 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-neutral-900/15 disabled:cursor-not-allowed disabled:opacity-60';

export default function LoginPage() {
  const { platformName } = useThemeStore();
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
            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900">
              Confirme seu acesso
            </h1>
            <p className="mt-3 text-sm leading-6 text-neutral-600">
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
                  className="h-14 w-full rounded-xl border border-neutral-200 bg-white text-center text-xl font-semibold text-neutral-900 outline-none transition-all hover:border-neutral-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10"
                />
              ))}
            </div>

            <label className="flex cursor-pointer items-center gap-3 text-sm text-neutral-600">
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
                  Verificando
                </>
              ) : (
                'Verificar acesso'
              )}
            </button>

            <p className="text-sm leading-6 text-neutral-500">
              Em caso de problemas, contate o suporte pelo WhatsApp{' '}
              <a
                href="https://wa.me/5511940968290?text=Ola%2C%20preciso%20de%20ajuda%20com%20a%20autenticacao%20de%202%20fatores"
                className="font-medium text-emerald-600 transition-colors hover:text-emerald-700"
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
            <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">
              Bem-vindo de volta
            </p>
            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-neutral-900 sm:text-4xl">
              Acesse sua conta
            </h1>
            <p className="mt-3 text-sm leading-6 text-neutral-600">
              Entre para acompanhar suas vendas e movimentacoes.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
            <div className="space-y-2">
              <label className="text-sm font-medium text-neutral-800">E-mail</label>
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
                  className={inputClass}
                  placeholder="seu@email.com"
                />
              </div>
              {errors.email && <p className="text-sm text-red-600">{errors.email.message}</p>}
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between gap-4">
                <label className="text-sm font-medium text-neutral-800">Senha</label>
                <Link
                  to="/forgot-password"
                  className="text-sm font-medium text-emerald-600 transition-colors hover:text-emerald-700"
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
                  className={`${inputClass} pr-12`}
                  placeholder="Sua senha"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-neutral-400 transition-colors hover:text-neutral-700"
                  aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                >
                  {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
                </button>
              </div>
              {errors.password && <p className="text-sm text-red-600">{errors.password.message}</p>}
            </div>

            <button type="submit" disabled={isLoading} className={`${primaryButtonClass} mt-2`}>
              {isLoading ? (
                <>
                  <Spinner />
                  Entrando
                </>
              ) : (
                <>
                  Entrar na plataforma
                  <ArrowRightIcon className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
                </>
              )}
            </button>
          </form>

          <div className="mt-8 border-t border-neutral-200 pt-6">
            <p className="text-sm text-neutral-600">
              Nao tem uma conta?{' '}
              <Link
                to="/register"
                className="font-semibold text-neutral-900 transition-colors hover:text-emerald-700"
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
