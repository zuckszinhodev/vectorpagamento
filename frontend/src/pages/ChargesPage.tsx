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
  CreditCardIcon,
  DocumentTextIcon,
  BanknotesIcon,
  UserIcon,
  EnvelopeIcon,
  IdentificationIcon,
  PhoneIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import { SectionSpinner } from '@/components/ui/Spinner';

/**
 * Rótulo visual do método de pagamento: ícone + nome.
 * Cores alinhadas às convenções de mercado (PIX verde, cartão índigo, boleto âmbar).
 */
function PaymentMethodBadge({ method }: { method?: string }) {
  const m = (method || '').toLowerCase();
  const config: Record<string, { label: string; icon: JSX.Element; bg: string; text: string }> = {
    pix: {
      label: 'PIX',
      icon: <PixIcon className="h-3.5 w-3.5" />,
      bg: 'bg-emerald-500/10',
      text: 'text-emerald-700 dark:text-emerald-300',
    },
    credit_card: {
      label: 'Cartão',
      icon: <CreditCardIcon className="h-3.5 w-3.5" />,
      bg: 'bg-indigo-500/10',
      text: 'text-indigo-700 dark:text-indigo-300',
    },
    boleto: {
      label: 'Boleto',
      icon: <DocumentTextIcon className="h-3.5 w-3.5" />,
      bg: 'bg-amber-500/10',
      text: 'text-amber-700 dark:text-amber-300',
    },
    split: {
      label: 'Split',
      icon: <BanknotesIcon className="h-3.5 w-3.5" />,
      bg: 'bg-purple-500/10',
      text: 'text-purple-700 dark:text-purple-300',
    },
  };
  const c = config[m] ?? {
    label: method || '—',
    icon: <BanknotesIcon className="h-3.5 w-3.5" />,
    bg: 'bg-surface-hover',
    text: 'text-tx-secondary',
  };
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${c.bg} ${c.text}`}>
      {c.icon}
      {c.label}
    </span>
  );
}
import toast from 'react-hot-toast';
import QRCode from 'qrcode';
import { useWebSocket } from '@/hooks/useWebSocket';
import { useAuthStore } from '@/stores/authStore';

interface Charge {
  id: string;
  amount: number;
  currency: string;
  status: string;
  payment_method: string;
  acquirer?: string;
  customer: {
    email: string;
    name: string;
  };
  created_at: string;
  paid_at?: string;
  acquirer_name?: string;
  pix_copy_paste?: string;
  pix_qr_code?: string;
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

const validateCPF = (cpf: string): boolean => {
  const cleanCPF = cpf.replace(/\D/g, '');
  if (cleanCPF.length !== 11) return false;
  if (/^(\d)\1+$/.test(cleanCPF)) return false;

  let sum = 0;
  for (let i = 0; i < 9; i += 1) {
    sum += Number.parseInt(cleanCPF.charAt(i), 10) * (10 - i);
  }

  let digit = 11 - (sum % 11);
  if (digit > 9) digit = 0;
  if (digit !== Number.parseInt(cleanCPF.charAt(9), 10)) return false;

  sum = 0;
  for (let i = 0; i < 10; i += 1) {
    sum += Number.parseInt(cleanCPF.charAt(i), 10) * (11 - i);
  }

  digit = 11 - (sum % 11);
  if (digit > 9) digit = 0;
  return digit === Number.parseInt(cleanCPF.charAt(10), 10);
};

const validateCNPJ = (cnpj: string): boolean => {
  const cleanCNPJ = cnpj.replace(/\D/g, '');
  if (cleanCNPJ.length !== 14) return false;
  if (/^(\d)\1+$/.test(cleanCNPJ)) return false;

  const weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  const weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

  let sum = 0;
  for (let i = 0; i < 12; i += 1) {
    sum += Number.parseInt(cleanCNPJ.charAt(i), 10) * weights1[i];
  }

  let digit = sum % 11 < 2 ? 0 : 11 - (sum % 11);
  if (digit !== Number.parseInt(cleanCNPJ.charAt(12), 10)) return false;

  sum = 0;
  for (let i = 0; i < 13; i += 1) {
    sum += Number.parseInt(cleanCNPJ.charAt(i), 10) * weights2[i];
  }

  digit = sum % 11 < 2 ? 0 : 11 - (sum % 11);
  return digit === Number.parseInt(cleanCNPJ.charAt(13), 10);
};

const validateCpfOrCnpj = (document: string): boolean => {
  const cleanDocument = document.replace(/\D/g, '');
  if (cleanDocument.length === 11) return validateCPF(cleanDocument);
  if (cleanDocument.length === 14) return validateCNPJ(cleanDocument);
  return false;
};

function StatusBadge({ status }: { status: string }) {
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
    approved: 'Aprovado',
    pending: 'Pendente',
    processing: 'Processando',
    failed: 'Falhou',
    refunded: 'Reembolsado',
    cancelled: 'Cancelado',
    expired: 'Expirado',
    chargeback: 'Chargeback',
  };

  return <span className={`badge ${styles[status] || 'badge-default'}`}>{labels[status] || status}</span>;
}

function extractPixMerchantName(pixPayload?: string): string | null {
  if (!pixPayload) return null;

  const payload = pixPayload.trim();
  if (!payload || payload.startsWith('data:') || /^https?:\/\//i.test(payload)) {
    return null;
  }

  const fields: Record<string, string> = {};
  let offset = 0;

  while (offset + 4 <= payload.length) {
    const id = payload.slice(offset, offset + 2);
    const rawLength = payload.slice(offset + 2, offset + 4);

    if (!/^\d{2}$/.test(id) || !/^\d{2}$/.test(rawLength)) {
      break;
    }

    const valueLength = Number(rawLength);
    const valueStart = offset + 4;
    if (valueStart + valueLength > payload.length) {
      break;
    }

    fields[id] = payload.slice(valueStart, valueStart + valueLength);
    offset = valueStart + valueLength;
  }

  const merchantName = (fields['59'] || '').trim();
  if (!merchantName) {
    return null;
  }

  return merchantName;
}

function getChargeNominal(charge: Charge): string {
  const metadataNominal = typeof charge.metadata?.nominal_name === 'string'
    ? charge.metadata.nominal_name.trim()
    : '';
  if (metadataNominal) return metadataNominal;

  const pixNominal = extractPixMerchantName(charge.pix_copy_paste || charge.pix_qr_code);
  if (pixNominal) return pixNominal;

  const acquirerNominal = (charge.acquirer_name || '').trim();
  return acquirerNominal || '-';
}

// Só para desenvolvimento local: import.meta.env.DEV remove o checkbox do build de produção
const TEST_PAYER = {
  name: 'Comprador Teste',
  email: 'comprador.teste@example.com',
  phone: '11999999999',
  document: '52998224725',
};

function NewPixChargeModal({
  isOpen,
  onClose,
}: {
  isOpen: boolean;
  onClose: () => void;
}) {
  // Valor em centavos; o campo funciona como máscara de moeda (digita da direita para a esquerda)
  const [amountCents, setAmountCents] = useState(0);
  const amount = (amountCents / 100).toFixed(2);
  const [customerName, setCustomerName] = useState('');
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerDocument, setCustomerDocument] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [pixCode, setPixCode] = useState('');
  const [qrCodeUrl, setQrCodeUrl] = useState('');
  const [showQrCode, setShowQrCode] = useState(false);
  const queryClient = useQueryClient();

  const createChargeMutation = useMutation({
    mutationFn: async (data: { amount: number; customer_name: string; customer_email: string; customer_document: string; customer_phone: string }) => {
      const phone = data.customer_phone.replace(/\D/g, '');
      const response = await api.post('/charges', {
        amount: data.amount,
        payment_method: 'pix',
        customer: {
          name: data.customer_name,
          email: data.customer_email,
          document: data.customer_document.replace(/\D/g, ''),
          phone,
        },
      });
      return response.data;
    },
    onSuccess: async (response) => {
      const chargeData = response.data || response;

      // Usar PIX retornado pela API ou gerar código simulado como fallback
      const pixCopyPaste = chargeData.pix_copy_paste || chargeData.pix_qr_code || 
        `00020126580014br.gov.bcb.pix0136${chargeData.id}@vectorpagamentos.com52040000530398654${(parseFloat(amount) * 100).toString().padStart(4, '0')}5802BR5913${customerName.substring(0, 13)}6009SAO PAULO62070503***6304`;
      
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
    
    if (amountCents <= 0) {
      toast.error('Informe o valor da cobrança');
      return;
    }

    if (!customerName || !customerEmail || !customerDocument || !customerPhone) {
      toast.error('Preencha todos os campos obrigatórios');
      return;
    }

    const cleanDoc = customerDocument.replace(/\D/g, '');
    if (cleanDoc.length !== 11 && cleanDoc.length !== 14) {
      toast.error('CPF deve ter 11 dígitos ou CNPJ 14 dígitos');
      return;
    }

    if (!validateCpfOrCnpj(cleanDoc)) {
      toast.error('CPF/CNPJ inválido. Verifique e tente novamente.');
      return;
    }

    const phoneDigits = customerPhone.replace(/\D/g, '');
    if (phoneDigits.length !== 10 && phoneDigits.length !== 11) {
      toast.error('Telefone inválido. Use DDD + número.');
      return;
    }

    createChargeMutation.mutate({
      customer_phone: customerPhone,
      amount: amountCents,
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
    setAmountCents(0);
    setCustomerName('');
    setCustomerEmail('');
    setCustomerDocument('');
    setCustomerPhone('');
    setPixCode('');
    setQrCodeUrl('');
    setShowQrCode(false);
    onClose();
  };

  const formatBRL = (cents: number) =>
    (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const docDigits = customerDocument.replace(/\D/g, '');
  const docComplete = docDigits.length === 11 || docDigits.length === 14;
  const docValid = docComplete && validateCpfOrCnpj(docDigits);
  const docLabel = docDigits.length > 11 ? 'CNPJ' : 'CPF';

  const handleDocumentChange = (raw: string) => {
    let v = raw.replace(/\D/g, '').slice(0, 14);
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
  };

  const handlePhoneChange = (raw: string) => {
    const d = raw.replace(/\D/g, '').slice(0, 11);
    let v = d;
    if (d.length > 2) v = `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length > 6) {
      const split = d.length === 11 ? 7 : 6;
      v = `(${d.slice(0, 2)}) ${d.slice(2, split)}-${d.slice(split)}`;
    }
    setCustomerPhone(v);
  };

  const handleTestPayerToggle = (checked: boolean) => {
    setCustomerName(checked ? TEST_PAYER.name : '');
    setCustomerEmail(checked ? TEST_PAYER.email : '');
    handlePhoneChange(checked ? TEST_PAYER.phone : '');
    handleDocumentChange(checked ? TEST_PAYER.document : '');
  };

  const quickAmounts = [1000, 5000, 10000, 25000, 50000];

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
          {/* No celular o modal vira uma folha presa na parte de baixo da tela */}
          <div className="flex min-h-full items-end justify-center text-center sm:items-center sm:p-6">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95"
              enterTo="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 translate-y-0 sm:scale-100"
              leaveTo="opacity-0 translate-y-full sm:translate-y-0 sm:scale-95"
            >
              <Dialog.Panel className="relative flex max-h-[92dvh] w-full max-w-2xl transform flex-col overflow-hidden rounded-t-2xl border border-b-0 border-border-DEFAULT bg-surface-elevated text-left shadow-2xl transition-all sm:max-h-[calc(100vh-2rem)] sm:rounded-2xl sm:border-b">
                <div className="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-tx-muted/30 sm:hidden" />
                <div className="border-b border-border-DEFAULT bg-gradient-to-r from-blue-500/15 via-transparent to-blue-500/5 px-4 pb-3 pt-2 sm:px-6 sm:py-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <Dialog.Title className="text-lg font-semibold text-tx-primary sm:text-xl">
                        {showQrCode ? 'PIX QR Code Gerado' : 'Nova Cobrança PIX'}
                      </Dialog.Title>
                      <p className="mt-0.5 text-xs text-tx-muted sm:mt-1 sm:text-sm">
                        {showQrCode
                          ? 'Compartilhe o QR Code ou copie o código PIX para concluir o pagamento.'
                          : 'Defina o valor e identifique o pagador. A cobrança aparece na sua lista assim que for gerada.'}
                      </p>
                    </div>
                    <button
                      onClick={handleClose}
                      className="-mr-2 shrink-0 rounded-full p-2 text-tx-muted transition-colors hover:bg-surface-hover hover:text-tx-primary"
                    >
                      <XMarkIcon className="h-5 w-5" />
                    </button>
                  </div>
                </div>

                {!showQrCode ? (
                  <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <div className="flex-1 space-y-5 overflow-y-auto overscroll-contain px-4 py-4 sm:space-y-6 sm:px-6 sm:py-5">
                      {/* Valor */}
                      <section className="rounded-2xl border border-border-DEFAULT bg-surface-card p-4 sm:p-5">
                        <label htmlFor="pix-amount" className="text-xs font-semibold uppercase tracking-wider text-tx-muted">
                          Valor da cobrança
                        </label>
                        <div className="mt-2 flex items-baseline gap-2">
                          <span className="text-2xl font-semibold text-tx-muted">R$</span>
                          <input
                            id="pix-amount"
                            type="text"
                            inputMode="numeric"
                            autoFocus
                            className="w-full min-w-0 bg-transparent text-3xl font-bold tracking-tight sm:text-4xl text-tx-primary outline-none placeholder:text-tx-muted/50"
                            placeholder="0,00"
                            value={amountCents > 0 ? formatBRL(amountCents) : ''}
                            onChange={(e) => {
                              const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                              setAmountCents(digits ? parseInt(digits, 10) : 0);
                            }}
                          />
                        </div>
                        <div className="mt-4 grid grid-cols-5 gap-1.5 sm:flex sm:flex-wrap sm:gap-2">
                          {quickAmounts.map((value) => (
                            <button
                              key={value}
                              type="button"
                              onClick={() => setAmountCents(value)}
                              className={`whitespace-nowrap rounded-full border px-1 py-1.5 text-xs font-medium transition-colors sm:px-3 sm:py-1 ${
                                amountCents === value
                                  ? 'border-accent-primary bg-accent-primary/15 text-accent-primary'
                                  : 'border-border-DEFAULT text-tx-secondary hover:border-accent-primary/50 hover:text-tx-primary'
                              }`}
                            >
                              R$ {formatBRL(value).replace(',00', '')}
                            </button>
                          ))}
                        </div>
                      </section>

                      {/* Pagador */}
                      <section className="space-y-4">
                        <div>
                          <h4 className="text-sm font-semibold text-tx-primary">Dados do pagador</h4>
                          <p className="mt-0.5 text-xs text-tx-muted">
                            Informe os dados reais de quem vai pagar. Eles são enviados à adquirente junto com a cobrança.
                          </p>
                        </div>

                        {import.meta.env.DEV && (
                          <label htmlFor="pix-test-payer" className="flex items-center gap-2 text-sm text-tx-secondary">
                            <input
                              id="pix-test-payer"
                              type="checkbox"
                              className="h-4 w-4"
                              checked={customerEmail === TEST_PAYER.email}
                              onChange={(e) => handleTestPayerToggle(e.target.checked)}
                            />
                            Preencher com dados de teste
                          </label>
                        )}

                        <div className="grid gap-3.5 sm:grid-cols-2 sm:gap-4">
                          <div className="sm:col-span-2">
                            <label htmlFor="pix-name" className="mb-1.5 block text-sm font-medium text-tx-secondary">
                              Nome completo
                            </label>
                            <div className="relative">
                              <UserIcon className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                              <input
                                id="pix-name"
                                type="text"
                                autoComplete="off"
                                placeholder="Nome do pagador"
                                className="input h-12 pl-11 text-base sm:text-sm"
                                value={customerName}
                                onChange={(e) => setCustomerName(e.target.value)}
                                required
                              />
                            </div>
                          </div>

                          <div>
                            <label htmlFor="pix-email" className="mb-1.5 block text-sm font-medium text-tx-secondary">
                              E-mail
                            </label>
                            <div className="relative">
                              <EnvelopeIcon className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                              <input
                                id="pix-email"
                                type="email"
                                autoComplete="off"
                                placeholder="email@dominio.com"
                                className="input h-12 pl-11 text-base sm:text-sm"
                                value={customerEmail}
                                onChange={(e) => setCustomerEmail(e.target.value)}
                                required
                              />
                            </div>
                          </div>

                          <div>
                            <label htmlFor="pix-phone" className="mb-1.5 block text-sm font-medium text-tx-secondary">
                              Telefone
                            </label>
                            <div className="relative">
                              <PhoneIcon className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                              <input
                                id="pix-phone"
                                type="tel"
                                inputMode="numeric"
                                autoComplete="off"
                                placeholder="(00) 00000-0000"
                                className="input h-12 pl-11 text-base sm:text-sm"
                                value={customerPhone}
                                onChange={(e) => handlePhoneChange(e.target.value)}
                                maxLength={15}
                                required
                              />
                            </div>
                          </div>

                          <div className="sm:col-span-2">
                            <label htmlFor="pix-document" className="mb-1.5 block text-sm font-medium text-tx-secondary">
                              CPF ou CNPJ
                            </label>
                            <div className="relative">
                              <IdentificationIcon className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
                              <input
                                id="pix-document"
                                type="text"
                                inputMode="numeric"
                                autoComplete="off"
                                placeholder="000.000.000-00"
                                className={`input h-12 pl-11 pr-10 text-base sm:text-sm ${docComplete && !docValid ? 'border-red-500/60' : ''}`}
                                value={customerDocument}
                                onChange={(e) => handleDocumentChange(e.target.value)}
                                maxLength={18}
                                required
                              />
                              {docComplete && (
                                docValid ? (
                                  <CheckCircleIcon className="absolute right-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-500" />
                                ) : (
                                  <ExclamationCircleIcon className="absolute right-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-red-500" />
                                )
                              )}
                            </div>
                            {docComplete && !docValid && (
                              <p className="mt-1 text-xs text-red-500">{docLabel} inválido</p>
                            )}
                          </div>
                        </div>
                      </section>
                    </div>

                    <div className="flex flex-col gap-2.5 border-t border-border-DEFAULT bg-surface-card px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-6 sm:py-4">
                      <div className="whitespace-nowrap text-sm text-tx-muted">
                        Total{' '}
                        <span className="font-semibold text-tx-primary">R$ {formatBRL(amountCents)}</span>
                      </div>
                      <div className="grid grid-cols-[auto_1fr] gap-2 sm:flex sm:gap-3">
                        <button
                          type="button"
                          onClick={handleClose}
                          className="btn-secondary h-12 sm:h-auto"
                        >
                          Cancelar
                        </button>
                        <button
                          type="submit"
                          disabled={createChargeMutation.isPending || amountCents <= 0}
                          className="btn-primary inline-flex h-12 items-center justify-center whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-60 sm:h-auto"
                        >
                          <PixIcon className="mr-2 h-5 w-5" />
                          {createChargeMutation.isPending ? 'Gerando...' : 'Gerar cobrança PIX'}
                        </button>
                      </div>
                    </div>
                  </form>
                ) : (
                  <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-4 sm:space-y-5 sm:px-6 sm:py-5">
                      <div className="flex justify-center">
                        {qrCodeUrl && (
                          <div className="rounded-2xl border border-border-DEFAULT bg-white p-4 shadow-sm">
                            <img
                              src={qrCodeUrl}
                              alt="QR Code PIX"
                              className="h-auto w-full max-w-[280px] rounded-lg"
                            />
                          </div>
                        )}
                      </div>

                      <div className="rounded-xl border border-border-DEFAULT bg-surface-card p-4">
                        <label className="mb-2 block text-sm font-medium text-tx-secondary">
                          Codigo PIX copia e cola
                        </label>
                        <div className="relative">
                          <textarea
                            readOnly
                            value={pixCode}
                            className="input min-h-[120px] pr-14 font-mono text-xs"
                            onClick={(e) => e.currentTarget.select()}
                          />
                          <button
                            onClick={handleCopyPixCode}
                            className="absolute right-2 top-2 rounded-lg p-2 transition-colors hover:bg-surface-hover"
                            title="Copiar codigo"
                          >
                            <ClipboardDocumentIcon className="h-5 w-5 text-tx-muted" />
                          </button>
                        </div>
                      </div>

                      <div className="rounded-xl border border-accent-primary/20 bg-accent-primary/10 p-4">
                        <p className="text-sm font-medium text-tx-primary">Como pagar</p>
                        <p className="mt-2 text-sm leading-6 text-tx-secondary">
                          Abra o app do seu banco, escolha pagar com PIX por QR Code ou copia e cola e confirme a operacao.
                        </p>
                      </div>
                    </div>

                    <div className="flex justify-end border-t border-border-DEFAULT bg-surface-card px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 sm:px-6 sm:py-4">
                      <button
                        onClick={handleClose}
                        className="btn-primary h-12 w-full sm:h-auto sm:w-auto"
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
  const [shareQrCodeUrl, setShareQrCodeUrl] = useState('');

  const pixPayload = charge?.pix_copy_paste || charge?.pix_qr_code || '';
  const canShareActivePix = Boolean(
    charge &&
    charge.payment_method === 'pix' &&
    ['pending', 'processing'].includes(charge.status) &&
    pixPayload,
  );

  useEffect(() => {
    if (!charge || !canShareActivePix) {
      setShareQrCodeUrl('');
      return;
    }

    if (charge.pix_qr_code?.startsWith('data:')) {
      setShareQrCodeUrl(charge.pix_qr_code);
      return;
    }

    let cancelled = false;

    QRCode.toDataURL(pixPayload, {
      width: 320,
      margin: 2,
      color: {
        dark: '#000000',
        light: '#FFFFFF',
      },
    })
      .then((url) => {
        if (!cancelled) {
          setShareQrCodeUrl(url);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setShareQrCodeUrl('');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [charge, canShareActivePix, pixPayload]);

  const handleShareQrCode = async () => {
    if (!charge || !pixPayload || !shareQrCodeUrl) {
      toast.error('QR Code indisponível para compartilhamento');
      return;
    }

    try {
      const response = await fetch(shareQrCodeUrl);
      const blob = await response.blob();
      const file = new File([blob], `cobranca-${charge.id}.png`, { type: blob.type || 'image/png' });

      if (navigator.share && navigator.canShare?.({ files: [file] })) {
        await navigator.share({
          title: 'QR Code PIX',
          text: `Pagamento PIX de ${formatCurrency(charge.amount)} para ${charge.customer?.name || 'cliente'}`,
          files: [file],
        });
        return;
      }

      await navigator.clipboard.writeText(pixPayload);
      toast.success('Compartilhamento indisponível neste dispositivo. Código PIX copiado.');
    } catch {
      toast.error('Não foi possível compartilhar o QR Code');
    }
  };

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
          <div className="flex min-h-full items-center justify-center p-4 text-center sm:p-6">
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
                      <span className="text-sm text-tx-primary">{getChargeNominal(charge)}</span>
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
                    {canShareActivePix && (
                      <>
                        <hr className="border-border-DEFAULT" />
                        <div className="rounded-lg bg-accent-subtle p-3">
                          <div className="flex items-start justify-between gap-3">
                            <div>
                              <p className="text-sm font-medium text-tx-primary">QR Code PIX ativo</p>
                              <p className="mt-1 text-xs text-tx-muted">
                                Compartilhe enquanto a cobrança ainda estiver pendente de pagamento.
                              </p>
                            </div>
                            <button
                              type="button"
                              onClick={() => void handleShareQrCode()}
                              disabled={!shareQrCodeUrl}
                              className="btn-secondary text-xs sm:text-sm"
                            >
                              Compartilhar QR Code
                            </button>
                          </div>
                        </div>
                      </>
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
            {charge.status === 'pending' && (
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
  const user = useAuthStore((state) => state.user);
  const [filters, setFilters] = useState<ChargeFilters>({});
  const [selectedCharge, setSelectedCharge] = useState<Charge | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isNewChargeOpen, setIsNewChargeOpen] = useState(false);
  const [page, setPage] = useState(1);
  const queryClient = useQueryClient();

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
    refetchInterval: 15000,
  });

  useWebSocket({
    channel: user?.account_id ? `private-account.${user.account_id}` : '',
    event: 'payment.status.changed',
    enabled: Boolean(user?.account_id),
    onMessage: (payload) => {
      const eventCharge = payload?.charge;
      const eventChargeId = payload?.charge_id || eventCharge?.id;

      if (!eventChargeId) {
        queryClient.invalidateQueries({ queryKey: ['charges'] });
        return;
      }

      queryClient.setQueriesData({ queryKey: ['charges'] }, (oldData: any) => {
        if (!oldData?.data || !Array.isArray(oldData.data)) {
          return oldData;
        }

        let found = false;
        const nextCharges = oldData.data.map((charge: Charge) => {
          if (charge.id !== eventChargeId) {
            return charge;
          }

          found = true;
          return {
            ...charge,
            ...eventCharge,
            status: payload?.status || eventCharge?.status || charge.status,
          };
        });

        if (!found && eventCharge) {
          nextCharges.unshift(eventCharge as Charge);
        }

        return {
          ...oldData,
          data: nextCharges,
        };
      });

      queryClient.invalidateQueries({ queryKey: ['charges'] });
    },
  });

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

  // Deep-link: quando a URL contém ?id=<charge_id> (ex.: vindo da busca inteligente),
  // busca a cobrança e abre o modal de detalhes automaticamente.
  //
  // Ref evita processar duas vezes no Strict Mode (que remonta o effect em dev).
  // Sem ela, o cleanup da 1ª execução setava cancelled=true, o setState era
  // silenciado e o `?id=` sumia da URL antes da 2ª execução — resultado: o
  // usuário chegava em /charges?id=... e nenhum modal abria.
  const deepLinkConsumedRef = useRef(false);
  useEffect(() => {
    if (deepLinkConsumedRef.current) return;
    const params = new URLSearchParams(window.location.search);
    const chargeId = params.get('id');
    if (!chargeId) return;
    deepLinkConsumedRef.current = true;

    (async () => {
      try {
        const response = await api.get(`/charges/${chargeId}`);
        const charge: Charge | undefined = response.data?.data;
        if (charge) {
          setSelectedCharge(charge);
          setIsDetailOpen(true);
        } else {
          toast.error('Cobrança não encontrada');
        }
      } catch {
        toast.error('Cobrança não encontrada');
      } finally {
        // Limpa o parâmetro para não reabrir o modal em reloads/back.
        const url = new URL(window.location.href);
        url.searchParams.delete('id');
        window.history.replaceState({}, '', url.toString());
      }
    })();
  }, []);

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
          data-tour="new-charge"
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
          </div>
        </div>
      </div>

      {/* Mobile Card View */}
      <div className="lg:hidden space-y-3">
        {isLoading ? (
          <div className="card">
            <SectionSpinner text="Carregando cobranças..." />
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
                  <p className="text-xs text-tx-muted truncate mt-0.5">Nominal: {getChargeNominal(charge)}</p>
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
                <PaymentMethodBadge method={charge.payment_method} />
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
                  Nominal
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
                  <td colSpan={7}>
                    <SectionSpinner text="Carregando cobranças..." />
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
                      <span className="text-sm text-tx-primary">{getChargeNominal(charge)}</span>
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
                          {charge.has_split && (charge.split_amount ?? 0) > 0 && (
                            <span className="inline-flex items-center rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold text-orange-700 dark:bg-orange-900/30 dark:text-orange-300">
                              SPLIT
                            </span>
                          )}
                        </div>
                        {charge.has_split && (charge.split_amount ?? 0) > 0 && (
                          <p className="text-[11px] text-orange-600 dark:text-orange-400 mt-0.5">
                            Split: -{formatCurrency(charge.split_amount ?? 0)} → Líquido: {formatCurrency(charge.amount - (charge.split_amount ?? 0))}
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
                    <td className="whitespace-nowrap px-6 py-4 text-sm">
                      <PaymentMethodBadge method={charge.payment_method} />
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
