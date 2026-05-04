import React from 'react';

interface EmptyStateProps {
  icon?: React.ReactNode;
  title: string;
  description?: string;
  action?: {
    label: string;
    onClick: () => void;
  };
}

export function EmptyState({
  icon,
  title,
  description,
  action,
}: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center py-12 px-6 text-center">
      {icon && (
        <div className="w-16 h-16 mb-4 text-tx-muted opacity-50">{icon}</div>
      )}
      <h3 className="text-lg font-semibold text-tx-primary mb-2">{title}</h3>
      {description && (
        <p className="text-sm text-tx-secondary mb-6 max-w-md">{description}</p>
      )}
      {action && (
        <button
          onClick={action.onClick}
          className="px-4 py-2 bg-accent-primary text-white rounded-element hover:bg-accent-hover transition-colors duration-fast"
        >
          {action.label}
        </button>
      )}
    </div>
  );
}
