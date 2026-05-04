import { clsx } from 'clsx';

interface BadgeProps {
  children: React.ReactNode;
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info' | 'primary';
  size?: 'sm' | 'md';
  dot?: boolean;
  className?: string;
}

export function Badge({ children, variant = 'default', size = 'sm', dot = false, className }: BadgeProps) {
  const variants = {
    default: 'bg-surface-hover text-tx-secondary',
    success: 'bg-status-success-subtle text-status-success',
    warning: 'bg-status-warning-subtle text-status-warning',
    danger: 'bg-status-error-subtle text-status-error',
    info: 'bg-accent-subtle text-accent-primary',
    primary: 'bg-accent-subtle text-accent-primary',
  };

  const dotColors = {
    default: 'bg-tx-muted',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    danger: 'bg-status-error',
    info: 'bg-accent-primary',
    primary: 'bg-accent-primary',
  };

  const sizes = {
    sm: 'px-2 py-0.5 text-xs',
    md: 'px-2.5 py-1 text-sm',
  };

  return (
    <span
      className={clsx(
        'inline-flex items-center gap-1.5 rounded-pill font-medium',
        variants[variant],
        sizes[size],
        className
      )}
    >
      {dot && <span className={clsx('h-1.5 w-1.5 rounded-full', dotColors[variant])} />}
      {children}
    </span>
  );
}

export default Badge;
