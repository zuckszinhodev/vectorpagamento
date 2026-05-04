import { ReactNode } from 'react';
import { clsx } from 'clsx';

interface CardProps {
  children: ReactNode;
  className?: string;
  padding?: 'none' | 'sm' | 'md' | 'lg';
  hover?: boolean;
}

export function Card({ children, className, padding = 'md', hover = false }: CardProps) {
  const paddingStyles = {
    none: '',
    sm: 'p-4',
    md: 'p-5',
    lg: 'p-8',
  };

  return (
    <div
      className={clsx(
        'rounded-card border border-border-DEFAULT bg-surface-card',
        hover && 'transition-colors duration-fast hover:bg-surface-hover',
        paddingStyles[padding],
        className
      )}
    >
      {children}
    </div>
  );
}

interface CardHeaderProps {
  children: ReactNode;
  className?: string;
  action?: ReactNode;
}

export function CardHeader({ children, className, action }: CardHeaderProps) {
  return (
    <div
      className={clsx(
        'flex items-center justify-between border-b border-border-DEFAULT pb-4',
        className
      )}
    >
      <div>{children}</div>
      {action && <div>{action}</div>}
    </div>
  );
}

interface CardTitleProps {
  children: ReactNode;
  className?: string;
}

export function CardTitle({ children, className }: CardTitleProps) {
  return (
    <h3
      className={clsx(
        'text-lg font-semibold text-tx-primary',
        className
      )}
    >
      {children}
    </h3>
  );
}

interface CardDescriptionProps {
  children: ReactNode;
  className?: string;
}

export function CardDescription({ children, className }: CardDescriptionProps) {
  return (
    <p className={clsx('mt-1 text-sm text-tx-secondary', className)}>
      {children}
    </p>
  );
}

interface CardContentProps {
  children: ReactNode;
  className?: string;
}

export function CardContent({ children, className }: CardContentProps) {
  return <div className={clsx('pt-4', className)}>{children}</div>;
}

interface CardFooterProps {
  children: ReactNode;
  className?: string;
}

export function CardFooter({ children, className }: CardFooterProps) {
  return (
    <div
      className={clsx(
        'flex items-center justify-end gap-3 border-t border-border-DEFAULT pt-4',
        className
      )}
    >
      {children}
    </div>
  );
}

export default Card;
