import React, { useEffect } from 'react';
import { X, CheckCircle, AlertCircle, Info, AlertTriangle } from 'lucide-react';

export type ToastType = 'success' | 'error' | 'info' | 'warning';

interface ToastProps {
  id: string;
  type: ToastType;
  title: string;
  message?: string;
  duration?: number;
  onClose: (id: string) => void;
}

export function Toast({
  id,
  type,
  title,
  message,
  duration = 5000,
  onClose,
}: ToastProps) {
  useEffect(() => {
    if (duration) {
      const timer = setTimeout(() => onClose(id), duration);
      return () => clearTimeout(timer);
    }
  }, [id, duration, onClose]);

  const icons = {
    success: <CheckCircle className="w-5 h-5 text-status-success" />,
    error: <AlertCircle className="w-5 h-5 text-status-error" />,
    info: <Info className="w-5 h-5 text-accent-primary" />,
    warning: <AlertTriangle className="w-5 h-5 text-status-warning" />,
  };

  const accents = {
    success: 'border-l-status-success',
    error: 'border-l-status-error',
    info: 'border-l-accent-primary',
    warning: 'border-l-status-warning',
  };

  return (
    <div
      className={`flex items-start gap-3 p-4 rounded-card border border-border-DEFAULT border-l-4 ${accents[type]} bg-surface-elevated shadow-tooltip animate-slide-up`}
    >
      <div className="flex-shrink-0 mt-0.5">{icons[type]}</div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold text-tx-primary">{title}</p>
        {message && <p className="text-sm text-tx-secondary mt-1">{message}</p>}
      </div>
      <button
        onClick={() => onClose(id)}
        className="flex-shrink-0 text-tx-muted hover:text-tx-secondary transition-colors duration-fast"
      >
        <X className="w-5 h-5" />
      </button>
    </div>
  );
}

// Toast Container Component
interface ToastContainerProps {
  toasts: Array<{
    id: string;
    type: ToastType;
    title: string;
    message?: string;
  }>;
  onClose: (id: string) => void;
}

export function ToastContainer({ toasts, onClose }: ToastContainerProps) {
  return (
    <div className="fixed top-4 right-4 z-50 space-y-3 max-w-md">
      {toasts.map((toast) => (
        <Toast key={toast.id} {...toast} onClose={onClose} />
      ))}
    </div>
  );
}

// Toast Hook
export function useToast() {
  const [toasts, setToasts] = React.useState<
    Array<{
      id: string;
      type: ToastType;
      title: string;
      message?: string;
    }>
  >([]);

  const show = React.useCallback(
    (type: ToastType, title: string, message?: string) => {
      const id = crypto.randomUUID();
      setToasts((prev) => [...prev, { id, type, title, message }]);
    },
    []
  );

  const close = React.useCallback((id: string) => {
    setToasts((prev) => prev.filter((toast) => toast.id !== id));
  }, []);

  return {
    toasts,
    show,
    close,
    success: (title: string, message?: string) => show('success', title, message),
    error: (title: string, message?: string) => show('error', title, message),
    info: (title: string, message?: string) => show('info', title, message),
    warning: (title: string, message?: string) => show('warning', title, message),
  };
}
