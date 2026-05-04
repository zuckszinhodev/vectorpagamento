import { ArrowsRightLeftIcon, ClockIcon } from '@heroicons/react/24/outline';

export default function AcquirersPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Adquirentes</h1>
        <p className="mt-1 text-sm text-tx-muted">
          Rotacao de adquirentes para melhorar estabilidade e performance das cobrancas.
        </p>
      </div>

      <section className="relative overflow-hidden rounded-2xl border border-border-DEFAULT bg-surface-card p-6 sm:p-8">
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(21,128,61,0.14),transparent_38%)] pointer-events-none" />

        <div className="relative max-w-2xl">
            <div className="inline-flex items-center gap-2 rounded-full bg-green-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-green-600">
              <ClockIcon className="h-4 w-4" />
              Em breve
            </div>

            <div className="mt-4 flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-green-500/10 text-green-600">
                <ArrowsRightLeftIcon className="h-6 w-6" />
              </div>

              <div>
                <h2 className="text-lg sm:text-xl font-semibold text-tx-primary">
                  Rotacao de adquirentes
                </h2>
                <p className="mt-2 text-sm leading-6 text-tx-secondary">
                  Esta area esta sendo preparada para centralizar a configuracao e o acompanhamento da rotacao entre adquirentes.
                  Assim que estiver pronta, voce podera visualizar o fluxo e os criterios de troca em um unico lugar.
                </p>
              </div>
            </div>
        </div>
      </section>
    </div>
  );
}
