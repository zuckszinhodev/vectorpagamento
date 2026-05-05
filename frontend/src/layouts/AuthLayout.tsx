import { Outlet } from 'react-router-dom';

function BrandMark() {
  return (
    <div className="flex items-center">
      <img src="/img/logo.png" alt="Vector Pagamentos" className="h-12 w-auto" />
    </div>
  );
}

export default function AuthLayout() {
  return (
    <div className="box-border h-screen overflow-hidden bg-[#06070b] p-3 sm:p-5">
      <div className="mx-auto flex h-full w-full max-w-[1760px] overflow-hidden rounded-[22px] border border-white/12 bg-[#07090f] shadow-[0_20px_70px_rgba(0,0,0,0.45)]">
        <section className="relative hidden w-[52%] overflow-hidden border-r border-white/10 lg:flex lg:flex-col lg:justify-between">
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_22%_15%,rgba(21,128,61,0.28),rgba(7,9,15,0)_36%),radial-gradient(circle_at_80%_18%,rgba(59,130,246,0.16),rgba(7,9,15,0)_40%),linear-gradient(180deg,#0b0f18_0%,#07090f_100%)]" />
          <div className="absolute right-14 top-8 h-72 w-72 rounded-full border border-white/10" />
          <div className="absolute right-20 top-14 h-56 w-56 rounded-full border border-white/10" />
          <div className="absolute right-28 top-20 h-40 w-40 rounded-full border border-white/10" />

          <div className="relative z-10 px-10 pb-8 pt-10">
            <BrandMark />
          </div>

          <div className="relative z-10 px-10 pb-12">
            <h1 className="max-w-xl text-6xl font-bold leading-[1.08] tracking-tight text-zinc-100">
              Sua plataforma de vendas <span className="text-green-400">inovadora.</span>
            </h1>
            <p className="mt-5 max-w-lg text-2xl leading-relaxed text-zinc-400">
              Aceite pagamentos com segurança e performance. Tudo em um só lugar.
            </p>

          </div>
        </section>

        <section className="relative flex w-full items-center justify-center bg-[#06080d] px-5 py-5 lg:w-[48%] lg:px-12">
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_18%,rgba(21,128,61,0.12),rgba(6,8,13,0)_36%)]" />
          <div className="relative z-10 w-full max-w-[470px]">
            <div className="mb-8 flex items-center justify-center lg:hidden">
              <BrandMark />
            </div>
            <Outlet />
          </div>
        </section>
      </div>
    </div>
  );
}

