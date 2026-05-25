import { useState, useEffect, useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { memberAreaService } from '@/services/api/memberArea.service';
import toast from 'react-hot-toast';

/* ── floating particles on canvas ── */
function ParticleCanvas() {
  const ref = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const c = ref.current;
    if (!c) return;
    const ctx = c.getContext('2d');
    if (!ctx) return;

    let raf: number;
    let w = 0, h = 0;

    interface P { x: number; y: number; r: number; vx: number; vy: number; a: number; pa: number; ps: number }
    const dots: P[] = [];

    const resize = () => {
      w = c.width = c.offsetWidth * devicePixelRatio;
      h = c.height = c.offsetHeight * devicePixelRatio;
      ctx.scale(devicePixelRatio, devicePixelRatio);
    };

    const init = () => {
      resize();
      dots.length = 0;
      const count = Math.min(60, Math.floor((c.offsetWidth * c.offsetHeight) / 12000));
      for (let i = 0; i < count; i++) {
        dots.push({
          x: Math.random() * c.offsetWidth,
          y: Math.random() * c.offsetHeight,
          r: 1 + Math.random() * 2,
          vx: (Math.random() - 0.5) * 0.3,
          vy: -0.15 - Math.random() * 0.35,
          a: 0.15 + Math.random() * 0.4,
          pa: Math.random() * Math.PI * 2,
          ps: 0.4 + Math.random() * 0.6,
        });
      }
    };

    const draw = (t: number) => {
      ctx.clearRect(0, 0, c.offsetWidth, c.offsetHeight);
      for (const p of dots) {
        p.x += p.vx;
        p.y += p.vy;
        p.pa += 0.016 * p.ps;
        if (p.y < -10) { p.y = c.offsetHeight + 10; p.x = Math.random() * c.offsetWidth; }
        if (p.x < -10) p.x = c.offsetWidth + 10;
        if (p.x > c.offsetWidth + 10) p.x = -10;
        const alpha = p.a * (0.5 + 0.5 * Math.sin(p.pa));
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(168,130,255,${alpha})`;
        ctx.fill();
      }
      // connections
      for (let i = 0; i < dots.length; i++) {
        for (let j = i + 1; j < dots.length; j++) {
          const dx = dots[i].x - dots[j].x;
          const dy = dots[i].y - dots[j].y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < 120) {
            ctx.beginPath();
            ctx.moveTo(dots[i].x, dots[i].y);
            ctx.lineTo(dots[j].x, dots[j].y);
            ctx.strokeStyle = `rgba(139,92,246,${0.08 * (1 - dist / 120)})`;
            ctx.lineWidth = 0.5;
            ctx.stroke();
          }
        }
      }
      raf = requestAnimationFrame(draw);
    };

    init();
    raf = requestAnimationFrame(draw);
    window.addEventListener('resize', init);
    return () => { cancelAnimationFrame(raf); window.removeEventListener('resize', init); };
  }, []);

  return <canvas ref={ref} className="absolute inset-0 w-full h-full" />;
}

/* ── animated input with floating label ── */
function FloatingInput({
  id, label, type = 'text', value, onChange, required, icon,
}: {
  id: string; label: string; type?: string; value: string;
  onChange: (v: string) => void; required?: boolean;
  icon: React.ReactNode;
}) {
  const [focused, setFocused] = useState(false);
  const active = focused || value.length > 0;

  return (
    <div className="relative group">
      <div className={`
        absolute left-4 top-1/2 -translate-y-1/2 transition-all duration-300 pointer-events-none
        ${active ? '-translate-y-[1.85rem] scale-[0.8] text-emerald-400' : 'text-gray-500'}
      `}>
        <div className="flex items-center gap-2">
          <span className="w-4 h-4">{icon}</span>
          <span className="text-sm font-medium">{label}</span>
        </div>
      </div>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        required={required}
        className={`
          w-full pt-6 pb-3 px-4 bg-white/[0.04] rounded-xl text-white text-sm
          border transition-all duration-300 outline-none
          ${focused
            ? 'border-emerald-500/60 shadow-[0_0_20px_rgba(139,92,246,0.15)] bg-white/[0.06]'
            : 'border-white/[0.08] hover:border-white/[0.15]'}
        `}
      />
      {/* animated bottom glow */}
      <div className={`
        absolute bottom-0 left-1/2 -translate-x-1/2 h-[2px] rounded-full bg-gradient-to-r from-emerald-500 via-emerald-400 to-emerald-500
        transition-all duration-500
        ${focused ? 'w-[90%] opacity-100' : 'w-0 opacity-0'}
      `} />
    </div>
  );
}

/* ── icons ── */
const EmailIcon = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-full h-full">
    <path d="M3 4a2 2 0 00-2 2v1.161l8.441 4.221a1.25 1.25 0 001.118 0L19 7.162V6a2 2 0 00-2-2H3z" />
    <path d="M19 8.839l-7.77 3.885a2.75 2.75 0 01-2.46 0L1 8.839V14a2 2 0 002 2h14a2 2 0 002-2V8.839z" />
  </svg>
);
const LockIcon = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-full h-full">
    <path fillRule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clipRule="evenodd" />
  </svg>
);
const UserIcon = () => (
  <svg viewBox="0 0 20 20" fill="currentColor" className="w-full h-full">
    <path d="M10 8a3 3 0 100-6 3 3 0 000 6zM3.465 14.493a1.23 1.23 0 00.41 1.412A9.957 9.957 0 0010 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 00-13.074.003z" />
  </svg>
);

export default function MemberLoginPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const rawRedirect = searchParams.get('redirect') || '/members/dashboard';
  // Strict validation: only allow /members/* paths, reject protocol/domain-relative URLs
  const redirect = (
    rawRedirect.startsWith('/members/') &&
    !rawRedirect.includes('//') &&
    !rawRedirect.includes('\\') &&
    !rawRedirect.includes('\0')
  ) ? rawRedirect : '/members/dashboard';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isRegister, setIsRegister] = useState(false);
  const [name, setName] = useState('');
  const [mounted, setMounted] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => { requestAnimationFrame(() => setMounted(true)); }, []);

  const loginMutation = useMutation({
    mutationFn: () => memberAreaService.memberLogin({ email, password }),
    onSuccess: (response) => {
      localStorage.setItem('member_token', response.data.token.access_token);
      localStorage.setItem('member_data', JSON.stringify(response.data.member));
      toast.success('Login realizado com sucesso!');
      navigate(redirect);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Credenciais inválidas');
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    loginMutation.mutate();
  };

  return (
    <div className="relative min-h-screen flex items-center justify-center overflow-hidden bg-[#0a0a12]">

      {/* ── animated gradient orbs ── */}
      <div className="absolute inset-0 overflow-hidden">
        <div className="absolute -top-32 -left-32 w-[500px] h-[500px] rounded-full bg-emerald-600/15 blur-[120px] animate-pulse" style={{ animationDuration: '6s' }} />
        <div className="absolute -bottom-40 -right-40 w-[600px] h-[600px] rounded-full bg-emerald-500/10 blur-[140px] animate-pulse" style={{ animationDuration: '8s', animationDelay: '2s' }} />
        <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[400px] h-[400px] rounded-full bg-indigo-600/8 blur-[100px] animate-pulse" style={{ animationDuration: '7s', animationDelay: '4s' }} />
      </div>

      {/* ── particles ── */}
      <ParticleCanvas />

      {/* ── grid texture ── */}
      <div
        className="absolute inset-0 opacity-[0.03]"
        style={{
          backgroundImage: `linear-gradient(rgba(139,92,246,0.3) 1px, transparent 1px), linear-gradient(90deg, rgba(139,92,246,0.3) 1px, transparent 1px)`,
          backgroundSize: '60px 60px',
        }}
      />

      {/* ── main card ── */}
      <div className={`
        relative z-10 w-full max-w-[420px] mx-4
        transition-all duration-700 ease-out
        ${mounted ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-8 scale-95'}
      `}>

        {/* ── glow behind card ── */}
        <div className="absolute -inset-1 bg-gradient-to-r from-emerald-600/20 via-emerald-500/10 to-emerald-600/20 rounded-3xl blur-xl" />

        <div className="relative bg-white/[0.04] backdrop-blur-2xl rounded-2xl border border-white/[0.08] shadow-2xl shadow-emerald-950/30 overflow-hidden">

          {/* ── top gradient bar ── */}
          <div className="h-1 bg-gradient-to-r from-transparent via-emerald-500 to-transparent" />

          <div className="p-8 sm:p-10">

            {/* ── logo / brand ── */}
            <div className={`text-center mb-8 transition-all duration-700 delay-200 ${mounted ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'}`}>
              <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-500 mb-4 shadow-lg shadow-emerald-600/30">
                <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
                </svg>
              </div>
              <h1 className="text-2xl font-bold text-white tracking-tight">Área de Membros</h1>
              <p className="text-sm text-gray-400 mt-1.5">Acesse seus cursos e conteúdos exclusivos</p>
            </div>

            {/* ── tab toggle ── */}
            <div className={`flex bg-white/[0.04] rounded-xl p-1 mb-8 transition-all duration-700 delay-300 ${mounted ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'}`}>
              <button
                type="button"
                onClick={() => setIsRegister(false)}
                className={`flex-1 py-2.5 text-sm font-medium rounded-lg transition-all duration-300 ${!isRegister ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-600/25' : 'text-gray-400 hover:text-gray-300'}`}
              >
                Entrar
              </button>
              <button
                type="button"
                onClick={() => setIsRegister(true)}
                className={`flex-1 py-2.5 text-sm font-medium rounded-lg transition-all duration-300 ${isRegister ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-600/25' : 'text-gray-400 hover:text-gray-300'}`}
              >
                Criar conta
              </button>
            </div>

            {/* ── form ── */}
            <form onSubmit={handleSubmit} className={`space-y-5 transition-all duration-700 delay-[400ms] ${mounted ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'}`}>

              {/* name (register only) */}
              <div className={`grid transition-all duration-400 ease-in-out ${isRegister ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                <div className="overflow-hidden">
                  <div className="pb-1">
                    <FloatingInput id="name" label="Nome completo" value={name} onChange={setName} required={isRegister} icon={<UserIcon />} />
                  </div>
                </div>
              </div>

              <FloatingInput id="email" label="E-mail" type="email" value={email} onChange={setEmail} required icon={<EmailIcon />} />

              {/* password with show/hide */}
              <div className="relative">
                <FloatingInput id="password" label="Senha" type={showPassword ? 'text' : 'password'} value={password} onChange={setPassword} required icon={<LockIcon />} />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-emerald-400 transition-colors"
                >
                  {showPassword ? (
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                    </svg>
                  ) : (
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                      <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                  )}
                </button>
              </div>

              {/* submit */}
              <button
                type="submit"
                disabled={loginMutation.isPending}
                className="relative w-full group"
              >
                {/* button glow */}
                <div className="absolute -inset-0.5 bg-gradient-to-r from-emerald-600 to-emerald-600 rounded-xl blur opacity-40 group-hover:opacity-70 transition-opacity duration-300" />
                <div className="relative flex items-center justify-center gap-2 py-3.5 px-4 bg-gradient-to-r from-emerald-600 to-emerald-600 hover:from-emerald-500 hover:to-emerald-500 text-white font-semibold text-sm rounded-xl transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-emerald-600/25">
                  {loginMutation.isPending ? (
                    <>
                      <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                      </svg>
                      Entrando...
                    </>
                  ) : (
                    <>
                      {isRegister ? 'Criar minha conta' : 'Acessar minha conta'}
                      <svg className="w-4 h-4 transition-transform duration-300 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                      </svg>
                    </>
                  )}
                </div>
              </button>
            </form>

            {/* ── divider ── */}
            <div className={`flex items-center gap-3 my-6 transition-all duration-700 delay-500 ${mounted ? 'opacity-100' : 'opacity-0'}`}>
              <div className="flex-1 h-px bg-gradient-to-r from-transparent to-white/[0.08]" />
              <span className="text-[11px] text-gray-500 uppercase tracking-widest">seguro</span>
              <div className="flex-1 h-px bg-gradient-to-l from-transparent to-white/[0.08]" />
            </div>

            {/* ── trust badges ── */}
            <div className={`flex justify-center gap-6 text-gray-500 transition-all duration-700 delay-[600ms] ${mounted ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'}`}>
              <div className="flex items-center gap-1.5 text-[11px]">
                <svg className="w-3.5 h-3.5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clipRule="evenodd" />
                </svg>
                SSL Seguro
              </div>
              <div className="flex items-center gap-1.5 text-[11px]">
                <svg className="w-3.5 h-3.5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 1c-1.828 0-3.623.149-5.371.435a.75.75 0 00-.629.74v7.659A6.25 6.25 0 0010 19a6.25 6.25 0 006-9.166V2.175a.75.75 0 00-.629-.74A33.227 33.227 0 0010 1zm2.28 7.28a.75.75 0 00-1.06-1.06L9 9.44 8.28 8.72a.75.75 0 00-1.06 1.06l1.25 1.25a.75.75 0 001.06 0l2.75-2.75z" clipRule="evenodd" />
                </svg>
                Dados protegidos
              </div>
            </div>
          </div>
        </div>

        {/* ── footer ── */}
        <div className={`text-center mt-6 transition-all duration-700 delay-700 ${mounted ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'}`}>
          <p className="text-[11px] text-gray-600">
            Ao entrar, você concorda com nossos{' '}
            <a href="/terms" className="text-emerald-400/80 hover:text-emerald-400 transition-colors">Termos</a>
            {' '}e{' '}
            <a href="/privacy" className="text-emerald-400/80 hover:text-emerald-400 transition-colors">Privacidade</a>
          </p>
        </div>

      </div>
    </div>
  );
}
