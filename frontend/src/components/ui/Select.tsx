import { forwardRef, SelectHTMLAttributes } from 'react';
import { clsx } from 'clsx';

interface SelectOption {
  value: string | number;
  label: string;
}

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  error?: string;
  hint?: string;
  options: SelectOption[];
  placeholder?: string;
}

export const Select = forwardRef<HTMLSelectElement, SelectProps>(
  ({ className, label, error, hint, options, placeholder, id, ...props }, ref) => {
    const selectId = id || label?.toLowerCase().replace(/\s+/g, '-');

    return (
      <div className="w-full">
        {label && (
          <label
            htmlFor={selectId}
            className="mb-1 block text-sm font-medium text-tx-secondary"
          >
            {label}
          </label>
        )}
        <select
          ref={ref}
          id={selectId}
          className={clsx(
            'block w-full rounded-element border px-4 py-2.5 text-sm transition-all duration-fast',
            'bg-surface-input text-tx-primary',
            'focus:outline-none focus:ring-2 focus:ring-offset-0',
            error
              ? 'border-status-error focus:border-status-error focus:ring-status-error/20'
              : 'border-border-DEFAULT focus:border-accent-primary focus:ring-accent-primary/15',
            'disabled:cursor-not-allowed disabled:opacity-50',
            className
          )}
          {...props}
        >
          {placeholder && (
            <option value="" disabled>
              {placeholder}
            </option>
          )}
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        {error && <p className="mt-1 text-sm text-status-error">{error}</p>}
        {hint && !error && <p className="mt-1 text-sm text-tx-muted">{hint}</p>}
      </div>
    );
  }
);

Select.displayName = 'Select';

export default Select;
