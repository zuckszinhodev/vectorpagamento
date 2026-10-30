import { type ComponentType, type ReactNode, type SVGProps } from 'react';
import { clsx } from 'clsx';

interface AdminPageShellProps {
  children: ReactNode;
  className?: string;
}

interface AdminPageHeaderProps {
  title: string;
  subtitle: string;
  icon?: ComponentType<SVGProps<SVGSVGElement>>;
  actions?: ReactNode;
  badge?: ReactNode;
  className?: string;
}

export function AdminPageShell({ children, className }: AdminPageShellProps) {
  return <div className={clsx('admin-page mx-auto w-full max-w-[1400px] space-y-6', className)}>{children}</div>;
}

export function AdminPageHeader({
  title,
  subtitle,
  icon: Icon,
  actions,
  badge,
  className,
}: AdminPageHeaderProps) {
  return (
    <section className={clsx('admin-page-header relative overflow-hidden rounded-2xl border border-white/[0.06] bg-gradient-to-br from-[#1c0c0f] via-[#100c0e] to-[#0b0b0d] p-5 text-white sm:p-6', className)}>
      <span className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-red-500/60 to-transparent" />

      <div className="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-start gap-3">
          {Icon ? (
            <div className="hidden rounded-xl bg-gradient-to-br from-red-500 to-red-800 p-3 sm:block">
              <Icon className="h-6 w-6 text-white" />
            </div>
          ) : null}

          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-red-400">Gestão Administrativa</p>
            <h1 className="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{title}</h1>
            <p className="mt-1 text-sm text-gray-400">{subtitle}</p>
            {badge ? <div className="mt-3">{badge}</div> : null}
          </div>
        </div>

        {actions ? <div className="flex flex-wrap items-center gap-2 lg:justify-end">{actions}</div> : null}
      </div>
    </section>
  );
}
