/**
 * Glow Spinner — classic border-spin loader with emerald neon glow.
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
    <div className="flex h-screen items-center justify-center bg-surface-page px-6">
      <img
        src="/img/logo.png"
        alt={text || 'Vector'}
        className="h-auto w-48 max-w-[70vw] sm:w-56"
      />
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
