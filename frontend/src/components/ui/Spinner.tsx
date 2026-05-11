/**
 * Glow Spinner — classic border-spin loader with purple neon glow.
 * Supports three sizes: lg (full-page), md (section), sm (inline).
 */

interface SpinnerProps {
  /** Optional text shown below the spinner */
  text?: string;
  /** 'lg' = 40px, 'md' = 32px, 'sm' = 20px */
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

export function Spinner({ text, size = 'lg', className = '' }: SpinnerProps) {
  const sizeClass = {
    sm: 'h-5 w-5',
    md: 'h-8 w-8',
    lg: 'h-10 w-10',
  }[size];

  return (
    <div className={`flex flex-col items-center justify-center gap-3 ${className}`}>
      <div className={`glow-spinner ${sizeClass}`} />
      {text && (
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {text}
        </p>
      )}
    </div>
  );
}

/** Full-page centered spinner (h-screen) */
export function PageSpinner({ text }: { text?: string }) {
  return (
    <div className="flex h-screen items-center justify-center px-6">
      <div className="brand-loader-stage text-center">
        <div className="brand-loader-aura brand-loader-aura--left" aria-hidden="true" />
        <div className="brand-loader-aura brand-loader-aura--right" aria-hidden="true" />

        <div className="brand-loader-wordmark-shell">
          <div className="brand-loader-wordmark brand-loader-wordmark--sheen text-5xl font-black tracking-tight sm:text-6xl md:text-7xl" aria-hidden="true">
            <span className="brand-loader-wazer">Vector</span>
            <span className="brand-loader-pay"> Pagamentos</span>
          </div>

          <div className="brand-loader-wordmark brand-loader-wordmark--main text-5xl font-black tracking-tight sm:text-6xl md:text-7xl">
            <span className="brand-loader-wazer">Vector</span>
            <span className="brand-loader-pay"> Pagamentos</span>
          </div>
        </div>

        {text && (
          <p className="brand-loader-caption mt-4 text-sm text-tx-muted">
            {text}
          </p>
        )}
      </div>
    </div>
  );
}

/** Section-level centered spinner */
export function SectionSpinner({ text, className = '' }: { text?: string; className?: string }) {
  return (
    <div className={`flex items-center justify-center py-12 ${className}`}>
      <Spinner size="md" text={text} />
    </div>
  );
}

export default Spinner;

