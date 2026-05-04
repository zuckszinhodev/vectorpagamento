import { forwardRef, InputHTMLAttributes } from 'react';
import { clsx } from 'clsx';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  leftIcon?: React.ReactNode;
  rightIcon?: React.ReactNode;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, label, error, hint, leftIcon, rightIcon, id, ...props }, ref) => {
    const inputId = id || label?.toLowerCase().replace(/\s+/g, '-');

    return (
      <div className="w-full">
        {label && (
          <label
            htmlFor={inputId}
            className="mb-1 block text-sm font-medium text-tx-secondary"
          >
            {label}
          </label>
        )}
        <div className="relative">
          {leftIcon && (
            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
              <span className="text-tx-muted">{leftIcon}</span>
            </div>
          )}
          <input
            ref={ref}
            id={inputId}
            className={clsx(
              'block w-full rounded-element border px-4 py-2.5 text-sm transition-all duration-fast',
              'bg-surface-input text-tx-primary',
              'placeholder:text-tx-muted',
              'focus:outline-none focus:ring-2 focus:ring-offset-0',
              error
                ? 'border-status-error focus:border-status-error focus:ring-status-error/20'
                : 'border-border-DEFAULT focus:border-accent-primary focus:ring-accent-primary/15',
              'disabled:cursor-not-allowed disabled:opacity-50',
              leftIcon && 'pl-10',
              rightIcon && 'pr-10',
              className
            )}
            {...props}
          />
          {rightIcon && (
            <div className="absolute inset-y-0 right-0 flex items-center pr-3">
              <span className="text-tx-muted">{rightIcon}</span>
            </div>
          )}
        </div>
        {error && <p className="mt-1 text-sm text-status-error">{error}</p>}
        {hint && !error && <p className="mt-1 text-sm text-tx-muted">{hint}</p>}
      </div>
    );
  }
);

Input.displayName = 'Input';

export default Input;
