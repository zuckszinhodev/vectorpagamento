import { type ReactNode } from 'react';
import { clsx } from 'clsx';

interface AdminPageShellProps {
  children: ReactNode;
  className?: string;
}

interface AdminPageHeaderProps {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  className?: string;
}

export function AdminPageShell({ children, className }: AdminPageShellProps) {
  return <div className={clsx('space-y-6', className)}>{children}</div>;
}

export function AdminPageHeader({
  title,
  subtitle,
  actions,
  className,
}: AdminPageHeaderProps) {
  return (
    <div className={clsx('flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between', className)}>
      <div>
        <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-gray-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
