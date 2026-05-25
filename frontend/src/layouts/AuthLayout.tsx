import { Outlet } from 'react-router-dom';
import LightPillar from '@/components/ui/LightPillar';

export default function AuthLayout() {
  return (
    <div className="flex h-screen overflow-hidden bg-[#101010]">
      {/* Left side - LightPillar Effect */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-black" style={{ minHeight: '100vh' }}>
        <LightPillar
          topColor="#059669"
          bottomColor="#34D399"
          intensity={1}
          rotationSpeed={0.3}
          glowAmount={0.002}
          pillarWidth={3}
          pillarHeight={0.4}
          noiseIntensity={0.5}
          pillarRotation={25}
          interactive={false}
          mixBlendMode="screen"
          quality="medium"
        />
        <div className="absolute inset-0 z-10 bg-gradient-to-b from-black/20 via-black/35 to-black/60" />

        <div className="absolute inset-0 z-20 flex items-center justify-center px-12">
          <div className="max-w-2xl text-center">
            <p className="text-sm font-semibold uppercase tracking-[0.22em] text-emerald-300/90">VectorPagamentos Gateway</p>
            <h2 className="relative mt-4 text-4xl font-extrabold leading-tight xl:text-5xl">
              <span className="absolute inset-0 bg-gradient-to-r from-emerald-300 via-white to-emerald-400 bg-clip-text text-transparent blur-[10px] opacity-55" aria-hidden="true">
                Aprovacao maxima. Liquidacao imediata.
              </span>
              <span className="relative bg-gradient-to-r from-emerald-200 via-white to-emerald-300 bg-clip-text text-transparent drop-shadow-[0_0_24px_rgba(52,211,153,0.45)]">
                Aprovacao maxima. Liquidacao imediata.
              </span>
            </h2>
            <ul className="mt-6 space-y-3 text-lg font-medium text-emerald-100/95">
              <li>Saque instantaneo para manter seu caixa girando</li>
              <li>Operacao N2 e N3 com alta disponibilidade</li>
              <li>Rotacao automatica de nominais para mais aprovacao</li>
              <li>PIX, cartao e boleto com conciliacao em tempo real</li>
            </ul>
          </div>
        </div>
      </div>

      {/* Right side - Auth forms */}
      <div className="flex w-full flex-col lg:w-1/2 bg-[#101010] overflow-hidden">
        {/* Form container */}
        <div className="flex flex-1 items-center justify-center px-8 lg:px-16 py-4 overflow-y-auto">
          <div className="w-full max-w-md">
            {/* Logo acima do formulário */}
            <div className="flex justify-center mb-4">
              <img
                src="/img/logo.png"
                alt="VectorPagamentos"
                className="h-14 w-auto object-contain"
              />
            </div>
            
            <Outlet />
          </div>
        </div>
      </div>
    </div>
  );
}
