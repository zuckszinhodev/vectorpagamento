/**
 * CircuitBackground — Subtle PCB-inspired SVG pattern
 * Used as a page-level background texture, barely visible,
 * reinforcing the "digital infrastructure" identity.
 */
export function CircuitBackground({ className = '' }: { className?: string }) {
  return (
    <div
      className={`pointer-events-none fixed inset-0 z-0 opacity-[0.03] dark:opacity-[0.04] ${className}`}
      aria-hidden="true"
    >
      <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <pattern
            id="circuit-pattern"
            x="0"
            y="0"
            width="120"
            height="120"
            patternUnits="userSpaceOnUse"
          >
            {/* Horizontal traces */}
            <line x1="0" y1="30" x2="50" y2="30" stroke="currentColor" strokeWidth="1" />
            <line x1="70" y1="30" x2="120" y2="30" stroke="currentColor" strokeWidth="1" />
            <line x1="0" y1="90" x2="40" y2="90" stroke="currentColor" strokeWidth="1" />
            <line x1="80" y1="90" x2="120" y2="90" stroke="currentColor" strokeWidth="1" />

            {/* Vertical traces */}
            <line x1="30" y1="0" x2="30" y2="30" stroke="currentColor" strokeWidth="1" />
            <line x1="90" y1="30" x2="90" y2="90" stroke="currentColor" strokeWidth="1" />
            <line x1="60" y1="60" x2="60" y2="120" stroke="currentColor" strokeWidth="1" />

            {/* Corner turns (90°) */}
            <polyline
              points="50,30 60,30 60,60"
              fill="none"
              stroke="currentColor"
              strokeWidth="1"
            />
            <polyline
              points="40,90 60,90 60,60"
              fill="none"
              stroke="currentColor"
              strokeWidth="1"
            />
            <polyline
              points="80,90 90,90 90,30 70,30"
              fill="none"
              stroke="currentColor"
              strokeWidth="1"
            />

            {/* Junction nodes */}
            <circle cx="30" cy="30" r="2.5" fill="currentColor" />
            <circle cx="60" cy="60" r="2.5" fill="currentColor" />
            <circle cx="90" cy="90" r="2.5" fill="currentColor" />
            <circle cx="60" cy="90" r="2" fill="currentColor" />
            <circle cx="90" cy="30" r="2" fill="currentColor" />

            {/* Small via holes */}
            <circle cx="50" cy="30" r="1.5" fill="none" stroke="currentColor" strokeWidth="0.8" />
            <circle cx="40" cy="90" r="1.5" fill="none" stroke="currentColor" strokeWidth="0.8" />
            <circle cx="80" cy="90" r="1.5" fill="none" stroke="currentColor" strokeWidth="0.8" />
          </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#circuit-pattern)" />
      </svg>
    </div>
  );
}

export default CircuitBackground;
