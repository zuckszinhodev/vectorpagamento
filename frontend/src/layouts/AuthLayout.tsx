import { Outlet } from 'react-router-dom';
import LightPillar from '@/components/ui/LightPillar';
import { useThemeStore } from '@/stores/themeStore';

export default function AuthLayout() {
  const { platformName } = useThemeStore();
  return (
    <div className="flex min-h-screen overflow-hidden bg-[#080b0a]">
      <div className="relative hidden min-h-screen overflow-hidden bg-black lg:flex lg:w-[56%]">
        <LightPillar
          topColor="#047857"
          bottomColor="#6EE7B7"
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
        <div className="absolute inset-0 z-10 bg-[radial-gradient(circle_at_32%_28%,rgba(52,211,153,0.16),transparent_34%),linear-gradient(120deg,rgba(0,0,0,0.28),rgba(0,0,0,0.78))]" />
        <div className="absolute inset-y-0 right-0 z-20 w-px bg-white/10" />

        <div className="absolute inset-0 z-30 flex items-center justify-center px-14 text-center xl:px-20">
          <div className="max-w-3xl">
            <p className="text-xs font-semibold uppercase tracking-[0.34em] text-emerald-200/80">
              {platformName} Gateway
            </p>
            <h2 className="mx-auto mt-6 max-w-xl text-2xl font-semibold leading-tight tracking-normal text-white xl:text-3xl">
              Pagamentos digitais com controle operacional e liquidez Instantânea.
            </h2>
            <img
              src="/img/banner.png"
              alt={platformName}
              className="mx-auto mt-8 h-auto w-full max-w-md object-contain"
              loading="lazy"
            />
          </div>
        </div>
      </div>

      <div className="flex min-h-screen w-full flex-col bg-[#0b0d0c] lg:w-[44%]">
        <div className="flex flex-1 items-center justify-center overflow-y-auto px-6 py-8 sm:px-10 lg:px-14 xl:px-20">
          <div className="w-full max-w-[440px]">
            <Outlet />
          </div>
        </div>
      </div>
    </div>
  );
}
