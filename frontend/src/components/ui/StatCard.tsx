import React from 'react';

interface StatCardProps {
  title: string;
  value: string | number;
  change?: {
    value: number;
    label?: string;
  };
  icon?: React.ReactNode;
  color?: 'blue' | 'green' | 'emerald' | 'orange' | 'red';
  loading?: boolean;
}

export function StatCard({
  title,
  value,
  change,
  icon,
  loading = false,
}: StatCardProps) {
  if (loading) {
    return (
      <div className="rounded-card border border-border-DEFAULT bg-surface-card p-5 animate-pulse">
        <div className="h-3 bg-surface-hover rounded w-1/2 mb-4"></div>
        <div className="h-8 bg-surface-hover rounded w-3/4 mb-2"></div>
        <div className="h-3 bg-surface-hover rounded w-1/3"></div>
      </div>
    );
  }

  return (
    <div className="rounded-card border border-border-DEFAULT bg-surface-card p-5 transition-colors duration-fast hover:border-accent-primary/30">
      <div className="flex items-start justify-between mb-3">
        <p className="text-sm font-medium text-tx-secondary">{title}</p>
        {icon && (
          <div className="p-2 rounded-element bg-accent-subtle text-accent-primary">{icon}</div>
        )}
      </div>

      <p className="text-2xl font-semibold font-mono text-tx-primary tracking-tight mb-2">{value}</p>

      {change && (
        <div className="flex items-center gap-1.5">
          <span
            className={`inline-flex items-center gap-0.5 text-xs font-medium px-1.5 py-0.5 rounded-pill ${
              change.value >= 0
                ? 'bg-status-success-subtle text-status-success'
                : 'bg-status-error-subtle text-status-error'
            }`}
          >
            {change.value >= 0 ? '\u2191' : '\u2193'}
            {Math.abs(change.value).toFixed(1)}%
          </span>
          {change.label && (
            <span className="text-xs text-tx-muted">{change.label}</span>
          )}
        </div>
      )}
    </div>
  );
}
