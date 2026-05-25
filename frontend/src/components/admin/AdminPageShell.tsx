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
    <section className={clsx('admin-page-header relative overflow-hidden rounded-3xl border border-border-DEFAULT bg-slate-950 p-5 text-white shadow-xl sm:p-6', className)}>
      <div className="pointer-events-none absolute -top-20 right-[-80px] h-52 w-52 rounded-full bg-blue-500/20 blur-3xl" />
      <div className="pointer-events-none absolute -bottom-24 left-[-100px] h-56 w-56 rounded-full bg-cyan-400/10 blur-3xl" />

      <div className="relative flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-start gap-3">
          {Icon ? (
            <div className="hidden rounded-2xl border border-white/15 bg-white/10 p-3 sm:block">
              <Icon className="h-6 w-6 text-blue-200" />
            </div>
          ) : null}

          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-blue-200/80">Gestao Administrativa</p>
            <h1 className="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">{title}</h1>
            <p className="mt-1 text-sm text-slate-300">{subtitle}</p>
            {badge ? <div className="mt-3">{badge}</div> : null}
          </div>
        </div>

        {actions ? <div className="flex flex-wrap items-center gap-2 lg:justify-end">{actions}</div> : null}
      </div>
    </section>
  );
}
