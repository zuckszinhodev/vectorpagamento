import { Outlet } from 'react-router-dom';
import LightPillar from '@/components/ui/LightPillar';

export default function AuthLayout() {
  return (
    <div className="flex h-screen overflow-hidden bg-[#101010]">
      {/* Left side - LightPillar Effect */}
      <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-black" style={{ minHeight: '100vh' }}>
        <LightPillar
          topColor="#7C3AED"
          bottomColor="#C084FC"
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
        {/* Banner image overlay */}
        <img
          src="/img/loginbanner.png?v=20260304"
          alt="Vector Pagamentos"
          className="absolute inset-0 w-full h-full object-cover z-10 pointer-events-none"
        />
      </div>

      {/* Right side - Auth forms */}
      <div className="flex w-full flex-col lg:w-1/2 bg-[#101010] overflow-hidden">
        {/* Form container */}
        <div className="flex flex-1 items-center justify-center px-8 lg:px-16 py-4 overflow-y-auto">
          <div className="w-full max-w-md">
            {/* Logo acima do formulário */}
            <div className="flex justify-center mb-4">
              <h1 className="text-5xl font-extrabold tracking-tight" style={{ color: 'white' }}>
                Wazer<span className="text-emerald-500">Pay</span>
              </h1>
            </div>
            
            <Outlet />
          </div>
        </div>
      </div>
    </div>
  );
}
