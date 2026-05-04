import React from 'react';

export function Skeleton({
  width = '100%',
  height = '1rem',
  className = '',
}: {
  width?: string;
  height?: string;
  className?: string;
}) {
  return (
    <div
      className={`animate-pulse rounded-element bg-surface-hover ${className}`}
      style={{ width, height }}
    />
  );
}

export function SkeletonCard() {
  return (
    <div className="rounded-card border border-border-DEFAULT bg-surface-card p-5 animate-pulse">
      <Skeleton width="40%" height="1rem" className="mb-4" />
      <Skeleton width="60%" height="2rem" className="mb-2" />
      <Skeleton width="30%" height="0.875rem" />
    </div>
  );
}

export function SkeletonTable({ rows = 5, columns = 5 }) {
  return (
    <div className="rounded-card border border-border-DEFAULT bg-surface-card overflow-hidden">
      <div className="animate-pulse">
        <div className="h-12 bg-surface-hover"></div>
        {[...Array(rows)].map((_, i) => (
          <div key={i} className="border-t border-border-DEFAULT p-4">
            <div className="grid gap-4" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
              {[...Array(columns)].map((_, j) => (
                <Skeleton key={j} height="1rem" />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
