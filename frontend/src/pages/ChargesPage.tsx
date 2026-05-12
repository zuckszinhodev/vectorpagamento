import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { Dialog, Transition } from '@headlessui/react';
import { Fragment } from 'react';
import {
  PlusIcon,

  MagnifyingGlassIcon,
  EllipsisVerticalIcon,
  ArrowPathIcon,
  XMarkIcon,
  EyeIcon,
  ArrowDownTrayIcon,
  ClipboardDocumentIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import toast from 'react-hot-toast';
import QRCode from 'qrcode';

interface Charge {
  id: string;
  amount: number;
  currency: string;
  status: string;
  payment_method: string;
  customer: {
    email: string;
    name: string;
  };
  created_at: string;
  paid_at?: string;
  acquirer_name?: string;
  description?: string;
  has_split?: boolean;
  split_amount?: number;
  split_recipients?: number;
  has_chargeback?: boolean;
  metadata?: {
    source?: string;
    split_id?: string;
    original_charge_id?: string;
    original_amount?: number;
    [key: string]: any;
  };
}

interface ChargeFilters {
  status?: string;
  payment_method?: string;
  date_from?: string;
  date_to?: string;
  search?: string;
}

function normalizeChargeStatus(status: string) {
  return status === 'waiting_payment' ? 'pending' : status;
}

function StatusBadge({ status }: { status: string }) {
  const normalizedStatus = normalizeChargeStatus(status);
  const styles: Record<string, string> = {
    paid: 'badge-success',
    approved: 'badge-success',
    pending: 'badge-warning',
    processing: 'badge-warning',
    failed: 'badge-danger',
    refunded: 'badge-info',
    cancelled: 'badge-default',
    expired: 'badge-default',
    chargeback: 'badge-danger',
  };

  const labels: Record<string, string> = {
    paid: 'Pago',
    approved: 'Pago',
    pending: 'Pendente',
    processing: 'Processando',
    failed: 'Falhou',
    refunded: 'Reembolsado',
    cancelled: 'Cancelado',
    expired: 'Expirado',
    chargeback: 'Chargeback',
  };

  return (
    <span className={`badge ${styles[normalizedStatus] || 'badge-default'}`}>
      {labels[normalizedStatus] || normalizedStatus}
    </span>
  );
}

function NewPixChargeModal({
  isOpen,
  onClose,
}: {
  isOpen: boolean;
  onClose: () => void;
}) {
  const [amount, setAmount] = useState('');
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerDocument, setCustomerDocument] = useState('');
  const [pixCode, setPixCode] = useState('');
  const [qrCodeUrl, setQrCodeUrl] = useState('');
  const [showQrCode, setShowQrCode] = useState(false);
  const queryClient = useQueryClient();

  const createChargeMutation = useMutation({
    mutationFn: async (data: { amount: number; customer_name: string; customer_email: string; customer_document: string }) => {
      const response = await api.post('/charges', {
        amount: data.amount,
        payment_method: 'pix',
        customer: {
          name: data.customer_name,
          email: data.customer_email,
          document: data.customer_document.replace(/\D/g, ''),
        },
      });
      return response.data;
    },
    onSuccess: async (response) => {
      const chargeData = response.data || response;
      
      // Usar PIX retornado pela API ou gerar código simulado como fallback
      const pixCopyPaste = chargeData.pix_copy_paste || chargeData.pix_qr_code || 
        `00020126580014br.gov.bcb.pix0136${chargeData.id}@Vector Pagamentos.com52040000530398654${(parseFloat(amount) * 100).toString().padStart(4, '0')}5802BR5913${customerName.substring(0, 13)}6009SAO PAULO62070503***6304`;
      
      setPixCode(pixCopyPaste);
      
      // Usar QR Code da API ou gerar localmente
      if (chargeData.pix_qr_code && chargeData.pix_qr_code.startsWith('data:')) {
        setQrCodeUrl(chargeData.pix_qr_code);
        setShowQrCode(true);
      } else {
        // Gerar QR Code localmente
        try {
          const qrUrl = await QRCode.toDataURL(pixCopyPaste, {
            width: 300,
            margin: 2,
            color: {
              dark: '#000000',
              light: '#FFFFFF'
            }
          });
          setQrCodeUrl(qrUrl);
          setShowQrCode(true);
        } catch (err) {
          toast.error('Erro ao gerar QR Code');
        }
      }
      
      toast.success('Cobrança PIX criada com sucesso!');
      queryClient.invalidateQueries({ queryKey: ['charges'] });
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao criar cobrança');
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!amount || !customerName || !customerEmail || !customerDocument) {
      toast.error('Preencha todos os campos obrigatórios');
      return;
    }

    const cleanDoc = customerDocument.replace(/\D/g, '');
    if (cleanDoc.length !== 11 && cleanDoc.length !== 14) {
      toast.error('CPF deve ter 11 dígitos ou CNPJ 14 dígitos');
      return;
    }

    const amountValue = parseFloat(amount.replace(',', '.'));
    if (isNaN(amountValue) || amountValue <= 0) {
      toast.error('Valor inválido');
      return;
    }

    createChargeMutation.mutate({
      amount: Math.round(amountValue * 100), // Converter para centavos
      customer_name: customerName,
      customer_email: customerEmail,
      customer_document: customerDocument,
    });
  };

  const handleCopyPixCode = () => {
    navigator.clipboard.writeText(pixCode)
      .then(() => toast.success('Código PIX copiado!'))
      .catch(() => toast.error('Erro ao copiar código. Tente novamente.'));
  };

  const handleClose = () => {
    setAmount('');
    setCustomerName('');
    setCustomerEmail('');
    setCustomerDocument('');
    setPixCode('');
    setQrCodeUrl('');
    setShowQrCode(false);
    onClose();
  };

  return (
    <Transition.Root show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={handleClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50 transition-opacity" />
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
              <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary">
                    {showQrCode ? 'PIX QR Code Gerado' : 'Nova Cobrança PIX'}
                  </Dialog.Title>
                  <button
                    onClick={handleClose}
                    className="text-tx-muted hover:text-tx-muted"
                  >
                    <XMarkIcon className="h-6 w-6" />
                  </button>
                </div>

                {!showQrCode ? (
                  <form onSubmit={handleSubmit} className="px-4 sm:px-6 py-4">
                    <div className="space-y-4">
                      <div>
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          Valor (R$)
                        </label>
                        <input
                          type="text"
                          placeholder="0,00"
                          className="input"
                          value={amount}
                          onChange={(e) => setAmount(e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          Nome do Cliente
                        </label>
                        <input
                          type="text"
                          placeholder="João Silva"
                          className="input"
                          value={customerName}
                          onChange={(e) => setCustomerName(e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          E-mail do Cliente
                        </label>
                        <input
                          type="email"
                          placeholder="joao@email.com"
                          className="input"
                          value={customerEmail}
                          onChange={(e) => setCustomerEmail(e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          CPF/CNPJ do Cliente
                        </label>
                        <input
                          type="text"
                          placeholder="000.000.000-00"
                          className="input"
                          value={customerDocument}
                          onChange={(e) => {
                            let v = e.target.value.replace(/\D/g, '');
                            if (v.length <= 11) {
                              v = v.replace(/(\d{3})(\d)/, '$1.$2');
                              v = v.replace(/(\d{3})(\d)/, '$1.$2');
                              v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                            } else {
                              v = v.replace(/(\d{2})(\d)/, '$1.$2');
                              v = v.replace(/(\d{3})(\d)/, '$1.$2');
                              v = v.replace(/(\d{3})(\d)/, '$1/$2');
                              v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
                            }
                            setCustomerDocument(v);
                          }}
                          maxLength={18}
                          required
                        />
                      </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                      <button
                        type="button"
                        onClick={handleClose}
                        className="btn-secondary"
                      >
                        Cancelar
                      </button>
                      <button
                        type="submit"
                        disabled={createChargeMutation.isPending}
                        className="btn-primary inline-flex items-center"
                      >
                        <PixIcon className="mr-2 h-5 w-5" />
                        {createChargeMutation.isPending ? 'Gerando...' : 'Gerar PIX'}
                      </button>
                    </div>
                  </form>
                ) : (
                  <div className="px-4 sm:px-6 py-4">
                    <div className="space-y-4">
                      <div className="flex justify-center">
                        {qrCodeUrl && (
                          <img
                            src={qrCodeUrl}
                            alt="QR Code PIX"
                            className="rounded-element border-4 border-border-DEFAULT"
                          />
                        )}
                      </div>
                      
                      <div>
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          Código PIX Copia e Cola
                        </label>
                        <div className="relative">
                          <textarea
                            readOnly
                            value={pixCode}
                            className="input min-h-[100px] font-mono text-xs"
                            onClick={(e) => e.currentTarget.select()}
                          />
                          <button
                            onClick={handleCopyPixCode}
                            className="absolute right-2 top-2 rounded p-2 hover:bg-surface-hover"
                            title="Copiar código"
                          >
                            <ClipboardDocumentIcon className="h-5 w-5 text-tx-muted" />
                          </button>
                        </div>
                      </div>

                      <div className="rounded-lg bg-accent-subtle p-4">
                        <p className="text-sm text-accent-primary">
                          <strong>Instruções:</strong>
                          <br />
                          1. Abra o app do seu banco
                          <br />
                          2. Escolha pagar via PIX QR Code ou Copia e Cola
                          <br />
                          3. Escaneie o QR Code ou cole o código
                          <br />
                          4. Confirme o pagamento
                        </p>
                      </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                      <button
                        onClick={handleClose}
                        className="btn-primary"
                      >
                        Fechar
                      </button>
                    </div>
                  </div>
                )}
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

function ChargeDetailModal({
  charge,
  isOpen,
  onClose,
}: {
  charge: Charge | null;
  isOpen: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();

  const refundMutation = useMutation({
    mutationFn: async (chargeId: string) => {
      await api.post(`/charges/${chargeId}/refund`);
    },
    onSuccess: () => {
      toast.success('Reembolso processado com sucesso!');
      queryClient.invalidateQueries({ queryKey: ['charges'] });
      onClose();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao processar reembolso');
    },
  });

  if (!charge) return null;

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
          <div className="fixed inset-0 bg-black/50 transition-opacity" />
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
              <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary">
                    Detalhes da Cobrança
                  </Dialog.Title>
                  <button
                    onClick={onClose}
                    className="text-tx-muted hover:text-tx-muted"
                  >
                    <XMarkIcon className="h-6 w-6" />
                  </button>
                </div>

                <div className="px-4 sm:px-6 py-4">
                  <div className="space-y-4">
                    {charge.has_chargeback && (
                      <div className="rounded-lg bg-amber-50 border border-amber-200 p-3">
                        <div className="flex items-center gap-2">
                          <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">
                            ⚠️ EM DISPUTA
                          </span>
                          <span className="text-sm font-medium text-amber-900">
                            Esta cobrança possui um chargeback ativo
                          </span>
                        </div>
                      </div>
                    )}
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">ID</span>
                      <span className="font-mono text-sm text-tx-primary">{charge.id}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Valor</span>
                      <span className="text-lg font-bold text-tx-primary">
                        {formatCurrency(charge.amount)}
                      </span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Status</span>
                      <StatusBadge status={charge.status} />
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Método</span>
                      <span className="text-sm text-tx-primary">{charge.payment_method}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Nominal</span>
                      <span className="text-sm text-tx-primary">{charge.acquirer_name || ''}</span>
                    </div>
                    <hr className="border-border-DEFAULT" />
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Cliente</span>
                      <span className="text-sm text-tx-primary">{charge.customer?.name || '-'}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">E-mail</span>
                      <span className="text-sm text-tx-primary">{charge.customer?.email || '-'}</span>
                    </div>
                    <hr className="border-border-DEFAULT" />
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-tx-muted">Criado em</span>
                      <span className="text-sm text-tx-primary">{formatDate(charge.created_at)}</span>
                    </div>
                    {charge.paid_at && (
                      <div className="flex items-center justify-between">
                        <span className="text-sm text-tx-muted">Pago em</span>
                        <span className="text-sm text-tx-primary">{formatDate(charge.paid_at)}</span>
                      </div>
                    )}
                    {charge.metadata?.source === 'split' && (
                      <>
                        <hr className="border-border-DEFAULT" />
                        <div className="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3 space-y-2">
                          <div className="flex items-center gap-2">
                            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                              SPLIT
                            </span>
                            <span className="text-sm font-medium text-emerald-700 dark:text-emerald-300">
                              Recebimento via Split
                            </span>
                          </div>
                          {charge.description && (
                            <p className="text-xs text-emerald-600 dark:text-emerald-400">
                              {charge.description}
                            </p>
                          )}
                          {charge.metadata.original_amount && (
                            <div className="flex items-center justify-between">
                              <span className="text-xs text-tx-muted">Valor original da venda</span>
                              <span className="text-xs text-tx-primary font-mono">
                                {formatCurrency(charge.metadata.original_amount)}
                              </span>
                            </div>
                          )}
                        </div>
                      </>
                    )}
                  </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                  <button onClick={onClose} className="btn-secondary">
                    Fechar
                  </button>
                  {(['paid', 'approved'] as string[]).includes(charge.status) && (
                    <button
                      onClick={() => refundMutation.mutate(charge.id)}
                      disabled={refundMutation.isPending}
                      className="btn-danger"
                    >
                      {refundMutation.isPending ? 'Processando...' : 'Reembolsar'}
                    </button>
                  )}
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

function ChargeActionsDropdown({
  charge,
  onView,
  onSync,
  isSyncing,
}: {
  charge: Charge;
  onView: (charge: Charge) => void;
  onSync: (chargeId: string) => void;
  isSyncing: boolean;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isOpen || !buttonRef.current) {
      return;
    }

    const updatePosition = () => {
      if (!buttonRef.current) {
        return;
      }

      const rect = buttonRef.current.getBoundingClientRect();
      const menuWidth = 192;
      const menuHeight = menuRef.current?.offsetHeight ?? 144;
      const viewportPadding = 8;

      let left = rect.right - menuWidth;
      left = Math.max(viewportPadding, Math.min(left, window.innerWidth - menuWidth - viewportPadding));

      let top = rect.bottom + viewportPadding;
      if (top + menuHeight > window.innerHeight - viewportPadding) {
        top = Math.max(viewportPadding, rect.top - menuHeight - viewportPadding);
      }

      setPosition({ top, left });
    };

    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (
        menuRef.current?.contains(target) ||
        buttonRef.current?.contains(target)
      ) {
        return;
      }

      setIsOpen(false);
    };

    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsOpen(false);
      }
    };

    updatePosition();
    const animationFrameId = window.requestAnimationFrame(updatePosition);

    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    document.addEventListener('mousedown', handleClickOutside);
    document.addEventListener('keydown', handleEscape);

    return () => {
      window.cancelAnimationFrame(animationFrameId);
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
      document.removeEventListener('mousedown', handleClickOutside);
      document.removeEventListener('keydown', handleEscape);
    };
  }, [isOpen]);

  const menuContent = isOpen
    ? createPortal(
        <div
          ref={menuRef}
          className="fixed z-[100] w-48 rounded-card border border-border-DEFAULT bg-surface-elevated shadow-float focus:outline-none"
          style={{ top: position.top, left: position.left }}
        >
          <div className="p-1">
            <button
              type="button"
              onClick={() => {
                onView(charge);
                setIsOpen(false);
              }}
              className="flex w-full items-center rounded-element px-3 py-2 text-sm text-tx-secondary transition-colors hover:bg-surface-hover"
            >
              <EyeIcon className="mr-3 h-4 w-4" />
              Ver detalhes
            </button>
            {normalizeChargeStatus(charge.status) === 'pending' && (
              <button
                type="button"
                onClick={() => {
                  onSync(charge.id);
                  setIsOpen(false);
                }}
                disabled={isSyncing}
                className="flex w-full items-center rounded-element px-3 py-2 text-sm text-tx-secondary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
              >
                <ArrowPathIcon className={`mr-3 h-4 w-4 ${isSyncing ? 'animate-spin' : ''}`} />
                Sincronizar Status
              </button>
            )}
            <button
              type="button"
              onClick={() => setIsOpen(false)}
              className="flex w-full items-center rounded-element px-3 py-2 text-sm text-tx-secondary transition-colors hover:bg-surface-hover"
            >
              <ArrowDownTrayIcon className="mr-3 h-4 w-4" />
              Exportar
            </button>
          </div>
        </div>,
        document.body
      )
    : null;

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        onClick={(event) => {
          event.stopPropagation();
          setIsOpen((current) => !current);
        }}
        className="rounded p-1 hover:bg-surface-hover"
        aria-label="Abrir ações da cobrança"
      >
        <EllipsisVerticalIcon className="h-5 w-5 text-tx-muted" />
      </button>
      {menuContent}
    </>
  );
}

export default function ChargesPage() {
  const [filters, setFilters] = useState<ChargeFilters>({});
  const [selectedCharge, setSelectedCharge] = useState<Charge | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isNewChargeOpen, setIsNewChargeOpen] = useState(false);
  const [page, setPage] = useState(1);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['charges', filters, page],
    queryFn: async () => {
      const response = await api.get('/charges', {
        params: {
          ...filters,
          page,
          per_page: 10,
        },
      });
      return response.data;
    },
    refetchInterval: 5000, // Atualiza a cada 5 segundos para tempo real
  });

  const queryClient = useQueryClient();

  const syncChargeMutation = useMutation({
    mutationFn: async (chargeId: string) => {
      const response = await api.post(`/charges/${chargeId}/sync`);
      return response.data;
    },
    onSuccess: (data) => {
      if (data.synced) {
        toast.success(data.message || 'Status sincronizado!');
      } else {
        toast(data.message || 'Não foi possível sincronizar', { icon: 'ℹ️' });
      }
      queryClient.invalidateQueries({ queryKey: ['charges'] });
    },
    onError: () => {
      toast.error('Erro ao sincronizar status');
    },
  });

  const syncAllMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/charges/sync-all');
      return response.data;
    },
    onSuccess: (data) => {
      toast.success(data.message || 'Sincronização concluída!');
      queryClient.invalidateQueries({ queryKey: ['charges'] });
    },
    onError: () => {
      toast.error('Erro ao sincronizar cobranças');
    },
  });

  // Usar dados da API
  const charges: Charge[] = data?.data || [];
  const pagination = data?.meta || { total: 0, last_page: 1 };

  const handleViewCharge = (charge: Charge) => {
    setSelectedCharge(charge);
    setIsDetailOpen(true);
  };

  return (
    <div className="space-y-4 lg:space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-tx-primary">Cobranças</h1>
          <p className="text-xs lg:text-sm text-tx-muted mt-0.5 lg:mt-1">
            Gerencie todas as suas cobranças e pagamentos
          </p>
        </div>
        <button 
          onClick={() => setIsNewChargeOpen(true)}
          className="btn-primary inline-flex items-center gap-2 text-sm"
        >
          <PlusIcon className="h-4 w-4" />
          Nova Cobrança
        </button>
      </div>

      {/* Filters */}
      <div className="card p-3 lg:p-4">
        <div className="flex flex-col gap-3 lg:gap-4">
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-4 w-4 lg:h-5 lg:w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar por e-mail, ID..."
              className="input pl-9 lg:pl-10 text-sm"
              value={filters.search || ''}
              onChange={(e) => setFilters({ ...filters, search: e.target.value })}
            />
          </div>
          <div className="grid grid-cols-2 gap-2 lg:flex lg:gap-4">
            <select
              className="input text-sm"
              value={filters.status || ''}
              onChange={(e) => setFilters({ ...filters, status: e.target.value })}
            >
              <option value="">Todos os status</option>
              <option value="approved,paid">Pago</option>
              <option value="pending">Pendente</option>
              <option value="expired">Expirado</option>
              <option value="failed">Falhou</option>
              <option value="refunded">Reembolsado</option>
              <option value="cancelled">Cancelado</option>
            </select>
            <select
              className="input text-sm"
              value={filters.payment_method || ''}
              onChange={(e) => setFilters({ ...filters, payment_method: e.target.value })}
            >
              <option value="">Todos os métodos</option>
              <option value="credit_card">Cartão</option>
              <option value="pix">PIX</option>
              <option value="boleto">Boleto</option>
              <option value="split">Split</option>
            </select>
          </div>
          <div className="flex gap-2 lg:gap-4">
            <button
              onClick={() => syncAllMutation.mutate()}
              disabled={syncAllMutation.isPending}
              className="btn-secondary inline-flex items-center text-xs lg:text-sm flex-1 lg:flex-none justify-center"
              title="Sincronizar todas as cobranças pendentes com o gateway"
            >
              <ArrowPathIcon className={`mr-1.5 lg:mr-2 h-4 w-4 lg:h-5 lg:w-5 ${syncAllMutation.isPending ? 'animate-spin' : ''}`} />
              <span className="hidden sm:inline">{syncAllMutation.isPending ? 'Sincronizando...' : 'Sincronizar'}</span>
              <span className="sm:hidden">Sync</span>
            </button>
            <button
              onClick={() => refetch()}
              className="btn-secondary inline-flex items-center text-xs lg:text-sm flex-1 lg:flex-none justify-center"
            >
              <ArrowPathIcon className="mr-1.5 lg:mr-2 h-4 w-4 lg:h-5 lg:w-5" />
              Atualizar
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Card View */}
      <div className="lg:hidden space-y-3">
        {isLoading ? (
          <div className="card p-8 flex items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
          </div>
        ) : charges.length === 0 ? (
          <div className="card p-8 text-center text-tx-muted">
            Nenhuma cobrança encontrada
          </div>
        ) : (
          charges.map((charge) => (
            <div
              key={charge.id}
              className="card p-3 hover:bg-surface-hover/50 transition-colors"
              onClick={() => handleViewCharge(charge)}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 mb-1 flex-wrap">
                    <p className="font-medium text-sm text-tx-primary truncate">
                      {charge.customer?.name || 'Cliente'}
                    </p>
                    <StatusBadge status={charge.status} />
                    {charge.has_chargeback && (
                      <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">
                        EM DISPUTA
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-tx-muted truncate">{charge.customer?.email}</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold font-mono text-sm text-tx-primary">
                    {formatCurrency(charge.amount)}
                  </p>
                  {charge.metadata?.source === 'split' && (
                    <span className="inline-flex items-center rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                      SPLIT
                    </span>
                  )}
                </div>
              </div>
              <div className="flex items-center justify-between mt-2 pt-2 border-t border-border-DEFAULT">
                <span className="text-xs text-tx-muted">
                  {charge.payment_method === 'credit_card' ? 'Cartão' : charge.payment_method === 'pix' ? 'PIX' : charge.payment_method === 'boleto' ? 'Boleto' : charge.payment_method}
                </span>
                <span className="max-w-[40%] truncate text-xs text-tx-muted">
                  {charge.acquirer_name || ''}
                </span>
                <span className="text-xs text-tx-muted">{formatDate(charge.created_at)}</span>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Desktop Table */}
      <div className="hidden lg:block card">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border-DEFAULT">
            <thead>
              <tr className="border-b border-border-DEFAULT">
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Cliente
                </th>
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Valor
                </th>
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Status
                </th>
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Método
                </th>
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Nominal
                </th>
                <th className="px-6 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-tx-muted">
                  Data
                </th>
                <th className="relative px-6 py-3.5">
                  <span className="sr-only">Ações</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT bg-surface-card" style={{ overflow: 'visible' }}>
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
                    <div className="flex items-center justify-center">
                      <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
                    </div>
                  </td>
                </tr>
              ) : charges.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
                    Nenhuma cobrança encontrada
                  </td>
                </tr>
              ) : (
                charges.map((charge) => (
                  <tr
                    key={charge.id}
                    className="hover:bg-surface-hover/50 transition-colors cursor-pointer"
                  >
                    <td className="whitespace-nowrap px-6 py-4">
                      <div>
                        <p className="text-sm font-medium text-tx-primary">
                          {charge.customer?.name || 'Cliente'}
                        </p>
                        <p className="text-xs text-tx-muted mt-0.5">
                          {charge.customer?.email}
                        </p>
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-semibold font-mono text-tx-primary">
                            {formatCurrency(charge.amount)}
                          </span>
                          {charge.metadata?.source === 'split' && (
                            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                              SPLIT
                            </span>
                          )}
                          {charge.has_split && charge.split_amount > 0 && (
                            <span className="inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-900/30 dark:text-orange-300">
                              SPLIT
                            </span>
                          )}
                        </div>
                        {charge.has_split && charge.split_amount > 0 && (
                          <p className="text-[11px] text-orange-600 dark:text-orange-400 mt-0.5">
                            Split: -{formatCurrency(charge.split_amount)} → Líquido: {formatCurrency(charge.amount - charge.split_amount)}
                          </p>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <div className="flex items-center gap-2">
                        <StatusBadge status={charge.status} />
                        {charge.has_chargeback && (
                          <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">
                            EM DISPUTA
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {charge.payment_method === 'credit_card'
                        ? 'Cartão'
                        : charge.payment_method === 'pix'
                        ? 'PIX'
                        : charge.payment_method === 'boleto'
                        ? 'Boleto'
                        : charge.payment_method === 'split'
                        ? 'Split'
                        : charge.payment_method}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {charge.acquirer_name || ''}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {formatDate(charge.created_at)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                      <div className="flex justify-end">
                        <ChargeActionsDropdown
                          charge={charge}
                          onView={handleViewCharge}
                          onSync={(chargeId) => syncChargeMutation.mutate(chargeId)}
                          isSyncing={syncChargeMutation.isPending}
                        />
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Pagination - Shows for both mobile and desktop */}
      {(pagination.last_page || 1) > 1 && (
        <div className="flex items-center justify-between bg-surface-card rounded-lg border border-border-DEFAULT px-4 py-3 lg:px-6 lg:py-4">
          <div className="flex flex-1 justify-between lg:hidden">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="btn-secondary text-sm"
            >
              Anterior
            </button>
            <span className="text-sm text-tx-muted self-center">
              {page} / {pagination.last_page || 1}
            </span>
            <button
              onClick={() => setPage((p) => p + 1)}
              disabled={page >= (pagination.last_page || 1)}
              className="btn-secondary text-sm"
            >
              Próximo
            </button>
          </div>
          <div className="hidden lg:flex lg:flex-1 lg:items-center lg:justify-between">
            <div>
              <p className="text-sm text-tx-secondary">
                Mostrando{' '}
                <span className="font-medium">
                  {(page - 1) * 10 + 1}
                </span>{' '}
                a{' '}
                <span className="font-medium">
                  {Math.min(page * 10, pagination.total || 0)}
                </span>{' '}
                de{' '}
                <span className="font-medium">{pagination.total || 0}</span>{' '}
                resultados
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className="btn-secondary"
              >
                Anterior
              </button>
              {/* Page numbers */}
              {Array.from({ length: Math.min(pagination.last_page || 1, 7) }, (_, i) => {
                const totalPages = pagination.last_page || 1;
                let pageNum: number;
                if (totalPages <= 7) {
                  pageNum = i + 1;
                } else if (page <= 4) {
                  pageNum = i + 1;
                } else if (page >= totalPages - 3) {
                  pageNum = totalPages - 6 + i;
                } else {
                  pageNum = page - 3 + i;
                }
                return (
                  <button
                    key={pageNum}
                    onClick={() => setPage(pageNum)}
                    className={`px-3 py-1.5 text-sm rounded-element font-medium transition-colors ${
                      page === pageNum
                        ? 'bg-accent-primary text-white shadow-sm'
                        : 'btn-secondary'
                    }`}
                  >
                    {pageNum}
                  </button>
                );
              })}
              <button
                onClick={() => setPage((p) => p + 1)}
                disabled={page >= (pagination.last_page || 1)}
                className="btn-secondary"
              >
                Próximo
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Detail Modal */}
      <ChargeDetailModal
        charge={selectedCharge}
        isOpen={isDetailOpen}
        onClose={() => setIsDetailOpen(false)}
      />

      {/* New Charge Modal */}
      <NewPixChargeModal
        isOpen={isNewChargeOpen}
        onClose={() => setIsNewChargeOpen(false)}
      />
    </div>
  );
}
