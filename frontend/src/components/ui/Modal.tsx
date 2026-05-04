import { Fragment, ReactNode } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { clsx } from 'clsx';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  children: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
}

export function Modal({ isOpen, onClose, children, size = 'md' }: ModalProps) {
  const sizes = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    full: 'sm:max-w-4xl',
  };

  return (
    <Transition.Root show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50 dark:bg-black/70 transition-opacity" />
        </Transition.Child>

        <div className="fixed inset-0 z-10 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enterTo="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 translate-y-0 sm:scale-100"
              leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <Dialog.Panel
                className={clsx(
                  'relative transform overflow-hidden rounded-card border border-border-DEFAULT bg-surface-elevated text-left shadow-float transition-all',
                  'sm:my-8 sm:w-full',
                  sizes[size]
                )}
              >
                {children}
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

interface ModalHeaderProps {
  children: ReactNode;
  onClose?: () => void;
}

export function ModalHeader({ children, onClose }: ModalHeaderProps) {
  return (
    <div className="flex items-center justify-between border-b border-border-DEFAULT px-6 py-4">
      <Dialog.Title className="text-lg font-semibold text-tx-primary">
        {children}
      </Dialog.Title>
      {onClose && (
        <button
          onClick={onClose}
          className="text-tx-muted transition-colors duration-fast hover:text-tx-secondary"
        >
          <XMarkIcon className="h-6 w-6" />
        </button>
      )}
    </div>
  );
}

interface ModalContentProps {
  children: ReactNode;
  className?: string;
}

export function ModalContent({ children, className }: ModalContentProps) {
  return <div className={clsx('px-6 py-4', className)}>{children}</div>;
}

interface ModalFooterProps {
  children: ReactNode;
}

export function ModalFooter({ children }: ModalFooterProps) {
  return (
    <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-6 py-4">
      {children}
    </div>
  );
}

export default Modal;
