import React from 'react';

interface StatusBadgeProps {
  status: string;
  variant?: 'default' | 'dot';
}

type StatusInfo = { label: string; bg: string; text: string; dot: string };

const statusConfig: Record<string, StatusInfo> = {
  // Payment statuses
  pending:    { label: 'Pendente',     bg: 'bg-status-warning-subtle', text: 'text-status-warning', dot: 'bg-status-warning' },
  paid:       { label: 'Pago',         bg: 'bg-status-success-subtle', text: 'text-status-success', dot: 'bg-status-success' },
  approved:   { label: 'Pago',         bg: 'bg-status-success-subtle', text: 'text-status-success', dot: 'bg-status-success' },
  failed:     { label: 'Falhou',       bg: 'bg-status-error-subtle',   text: 'text-status-error',   dot: 'bg-status-error' },
  canceled:   { label: 'Cancelado',    bg: 'bg-surface-hover',         text: 'text-tx-muted',       dot: 'bg-tx-muted' },
  refunded:   { label: 'Reembolsado',  bg: 'bg-accent-subtle',         text: 'text-accent-primary', dot: 'bg-accent-primary' },
  // General statuses
  active:     { label: 'Ativo',        bg: 'bg-status-success-subtle', text: 'text-status-success', dot: 'bg-status-success' },
  inactive:   { label: 'Inativo',      bg: 'bg-surface-hover',         text: 'text-tx-muted',       dot: 'bg-tx-muted' },
  processing: { label: 'Processando',  bg: 'bg-accent-subtle',         text: 'text-accent-primary', dot: 'bg-accent-primary' },
  // Risk levels
  low:        { label: 'Baixo',        bg: 'bg-status-success-subtle', text: 'text-status-success', dot: 'bg-status-success' },
  medium:     { label: 'Médio',        bg: 'bg-status-warning-subtle', text: 'text-status-warning', dot: 'bg-status-warning' },
  high:       { label: 'Alto',         bg: 'bg-status-warning-subtle', text: 'text-status-warning', dot: 'bg-status-warning' },
  critical:   { label: 'Crítico',      bg: 'bg-status-error-subtle',   text: 'text-status-error',   dot: 'bg-status-error' },
};

const fallback: StatusInfo = {
  label: '', bg: 'bg-surface-hover', text: 'text-tx-muted', dot: 'bg-tx-muted',
};

export function StatusBadge({ status, variant = 'default' }: StatusBadgeProps) {
  const config = statusConfig[status.toLowerCase()] || { ...fallback, label: status };

  if (variant === 'dot') {
    return (
      <span className="inline-flex items-center gap-1.5">
        <span className={`h-2 w-2 rounded-full ${config.dot}`} />
        <span className="text-sm font-medium text-tx-secondary">{config.label}</span>
      </span>
    );
  }

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-pill px-2.5 py-0.5 text-xs font-medium ${config.bg} ${config.text}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${config.dot}`} />
      {config.label}
    </span>
  );
}
