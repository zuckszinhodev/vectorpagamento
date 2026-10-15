import { Outlet } from 'react-router-dom';
import LightPillar from '@/components/ui/LightPillar';
import { useThemeStore } from '@/stores/themeStore';

export default function AuthLayout() {
  const { platformName, themeVariant, theme } = useThemeStore();
  const isModern = themeVariant === 'modern';
  const isDark = isModern || theme === 'dark';

  return (
    <div className={`auth-page flex min-h-screen overflow-hidden ${isDark ? 'bg-[#070708] text-white' : 'bg-white text-neutral-900'}`}>
      <div className="relative hidden min-h-screen overflow-hidden bg-black lg:flex lg:w-[56%]">
        <LightPillar
          topColor={isModern ? '#7f1d1d' : '#047857'}
          bottomColor={isModern ? '#f87171' : '#6EE7B7'}
          intensity={0.85}
          rotationSpeed={0.18}
          glowAmount={0.0015}
          pillarWidth={2.6}
          pillarHeight={0.5}
          noiseIntensity={0.38}
          pillarRotation={25}
          interactive={false}
          mixBlendMode="screen"
          quality="medium"
        />
        <div
          className="absolute inset-0 z-10"
          style={{
            background: isModern
              ? 'radial-gradient(circle at 32% 28%, rgba(239,68,68,0.16), transparent 34%), linear-gradient(120deg, rgba(0,0,0,0.28), rgba(0,0,0,0.78))'
              : 'radial-gradient(circle at 32% 28%, rgba(52,211,153,0.16), transparent 34%), linear-gradient(120deg, rgba(0,0,0,0.28), rgba(0,0,0,0.78))',
          }}
        />
        <div className="absolute inset-y-0 right-0 z-20 w-px bg-white/10" />

        <div className="absolute inset-0 z-30 flex items-center justify-center px-14 text-center xl:px-20">
          <div className="max-w-3xl">
            <img
              src="/img/gorillapay-logo.webp"
              alt={platformName}
              className="mx-auto mb-8 h-auto w-full max-w-sm object-contain drop-shadow-2xl"
            />
            <p className={`text-xs font-semibold uppercase tracking-[0.34em] ${isModern ? 'text-red-300/80' : 'text-emerald-200/80'}`}>
              {platformName} Gateway
            </p>
            <h2 className="mx-auto mt-6 max-w-xl text-2xl font-semibold leading-tight tracking-normal text-white xl:text-3xl">
              Pagamentos digitais com controle operacional e liquidez Instantânea.
            </h2>
          </div>
        </div>
      </div>

      <div className={`relative flex min-h-screen w-full flex-col lg:w-[44%] ${isDark ? 'bg-[#09090b]' : 'bg-white'}`}>
        {isDark && (
          <>
            <div className={`pointer-events-none absolute -right-24 top-16 h-72 w-72 rounded-full blur-[110px] ${isModern ? 'bg-red-600/10' : 'bg-emerald-500/10'}`} />
            <div className={`pointer-events-none absolute -bottom-20 left-10 h-64 w-64 rounded-full blur-[120px] ${isModern ? 'bg-rose-500/[0.06]' : 'bg-emerald-400/[0.06]'}`} />
          </>
        )}
        <div className="flex flex-1 items-center justify-center overflow-y-auto px-6 py-8 sm:px-10 lg:px-14 xl:px-20">
          <div className="relative z-10 w-full max-w-[440px]">
            {/* Logo — visível apenas no mobile (o painel lateral fica oculto) */}
            <img
              src="/img/gorillapay-logo.webp"
              alt={platformName}
              className="mx-auto mb-8 h-auto w-40 object-contain lg:hidden"
            />
            <Outlet />
          </div>
        </div>
      </div>
    </div>
  );
}
