import React, { useState, useEffect } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { api, formatCurrency } from '@/lib/api';
import { safeHref } from '@/lib/safeUrl';
import { Spinner } from '@/components/ui/Spinner';
import toast from 'react-hot-toast';
import QRCode from 'qrcode';
import DOMPurify from 'dompurify';
import {
  ShieldCheckIcon,
  CheckCircleIcon,
  XCircleIcon,
  StarIcon,
  QuestionMarkCircleIcon,
  GiftIcon,
  BoltIcon,
  HeartIcon,
  TruckIcon,
  CreditCardIcon,
  ClipboardDocumentIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import { StarIcon as StarSolidIcon } from '@heroicons/react/24/solid';
import { ModernSplitCheckout } from '@/components/checkout/ModernSplitCheckout';

// Force light mode CSS variables for public checkout (overrides dashboard dark mode)
const LIGHT_MODE_VARS = {
  '--surface-page': '#FAFAFA',
  '--surface-card': '#FFFFFF',
  '--surface-elevated': '#FFFFFF',
  '--surface-hover': '#F4F4F5',
  '--surface-input': '#FFFFFF',
  '--text-primary': '#18181B',
  '--text-secondary': '#52525B',
  '--text-muted': '#A1A1AA',
  '--text-inverted': '#FFFFFF',
  '--accent-subtle': 'rgba(5,150,105,0.08)',
  '--status-success-subtle': 'rgba(34,197,94,0.10)',
  '--status-error-subtle': 'rgba(239,68,68,0.10)',
  '--status-warning-subtle': 'rgba(245,158,11,0.10)',
  '--border-default': '#E4E4E7',
  '--border-subtle': '#F4F4F5',
} as React.CSSProperties;

// Types matching the CheckoutBuilder
interface CheckoutBlock {
  id: string;
  type: string;
  enabled: boolean;
  settings: Record<string, any>;
}

interface CheckoutTheme {
  primaryColor: string;
  secondaryColor: string;
  backgroundColor: string;
  cardBackground: string;
  textColor: string;
  mutedColor: string;
  borderRadius: string;
  fontFamily: string;
  buttonStyle: string;
  shadow: string;
}

interface CheckoutProduct {
  id: string;
  name: string;
  description: string;
  price: number;
  originalPrice?: number;
  image_url?: string;
}

interface CheckoutPayment {
  methods: string[];
  maxInstallments: number;
  pixDiscount: number;
  boletoDiscount: number;
}

interface CheckoutConversion {
  timerEnabled: boolean;
  timerMinutes: number;
  timerMessage: string;
  exitPopupEnabled: boolean;
  testimonialsEnabled: boolean;
  guaranteeDays: number;
  socialProofEnabled: boolean;
}

interface CheckoutSettings {
  theme: CheckoutTheme;
  product: CheckoutProduct | null;
  payment: CheckoutPayment;
  conversion: CheckoutConversion;
  blocks: CheckoutBlock[];
  // Legacy flat settings (fallback)
  theme_color?: string;
  collect_cpf?: boolean;
  collect_address?: boolean;
  collect_phone?: boolean;
  payment_methods?: string[];
  max_installments?: number;
  pix_discount?: number;
  timer_enabled?: boolean;
  timer_minutes?: number;
  testimonials_enabled?: boolean;
  guarantee_days?: number;
}

interface CheckoutData {
  id: string;
  name: string;
  slug: string;
  type?: string;
  theme?: CheckoutTheme;
  settings?: CheckoutSettings;
  product: CheckoutProduct;
  facebook_pixel?: {
    pixel_id: string;
    track_page_view: boolean;
    track_initiate_checkout: boolean;
    track_add_payment_info: boolean;
    track_purchase: boolean;
  };
}

interface PaymentForm {
  name: string;
  email: string;
  document: string;
  phone?: string;
  address?: {
    street: string;
    number: string;
    complement?: string;
    neighborhood: string;
    city: string;
    state: string;
    zipcode: string;
  };
  card?: {
    number: string;
    holder_name: string;
    expiry: string;
    cvv: string;
    installments: number;
  };
}

interface PaymentResult {
  status: 'approved' | 'pending' | 'rejected';
  message: string;
  charge_id?: string;
  pix_qr_code?: string;
  pix_copy_paste?: string;
  boleto_url?: string;
  boleto_barcode?: string;
  boleto_digitable_line?: string;
  has_upsells?: boolean;
}

interface OrderBumpData {
  id: string;
  product_id: string;
  title: string;
  description?: string;
  call_to_action: string;
  image_url?: string;
  price: number;
  original_price?: number;
  display_position: string;
  is_pre_selected: boolean;
  product?: {
    id: string;
    name: string;
    price: number;
    image_url?: string;
  };
}

interface UpsellOfferData {
  id: string;
  title: string;
  description?: string;
  headline?: string;
  call_to_action: string;
  decline_text: string;
  image_url?: string;
  video_url?: string;
  price: number;
  original_price?: number;
  discount_percent?: number;
  type: 'upsell' | 'downsell';
  one_click_purchase: boolean;
  timer_seconds?: number;
  benefits: string[];
  product?: {
    id: string;
    name: string;
    price: number;
    image_url?: string;
  };
}

// Timer Component - no container, just the time display to match builder exactly
function Timer({ hours = 0, minutes = 15, seconds = 0, themeColor }: { hours?: number; minutes?: number; seconds?: number; themeColor: string }) {
  const initialTime = hours * 3600 + minutes * 60 + seconds;
  const [timeLeft, setTimeLeft] = useState(initialTime);

  useEffect(() => {
    const timer = setInterval(() => {
      setTimeLeft((prev) => (prev > 0 ? prev - 1 : 0));
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  const hrs = Math.floor(timeLeft / 3600);
  const mins = Math.floor((timeLeft % 3600) / 60);
  const secs = timeLeft % 60;

  return (
    <p className="mt-1 text-3xl font-bold" style={{ color: themeColor }}>
      {String(hrs).padStart(2, '0')}:{String(mins).padStart(2, '0')}:{String(secs).padStart(2, '0')}
    </p>
  );
}

// Social Proof Notification Component
interface SocialProofSettings {
  enabled: boolean;
  displayTime: number;
  intervalTime: number;
  position: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right';
  showPaymentMethod: boolean;
  paymentMethods: string[];
  names: string[];
}

function SocialProofNotification({ 
  settings, 
  productName 
}: { 
  settings: SocialProofSettings;
  productName: string;
}) {
  const [isVisible, setIsVisible] = useState(false);
  const [currentNotification, setCurrentNotification] = useState({
    name: '',
    paymentMethod: '',
    minutesAgo: 0
  });

  useEffect(() => {
    if (!settings.enabled || !settings.names?.length) return;

    const showNotification = () => {
      const randomName = settings.names[Math.floor(Math.random() * settings.names.length)];
      const randomMethod = settings.paymentMethods?.[Math.floor(Math.random() * (settings.paymentMethods?.length || 1))] || 'PIX';
      const randomMinutes = Math.floor(Math.random() * 15) + 1;

      setCurrentNotification({
        name: randomName,
        paymentMethod: randomMethod,
        minutesAgo: randomMinutes
      });
      setIsVisible(true);

      // Hide after displayTime
      setTimeout(() => {
        setIsVisible(false);
      }, (settings.displayTime || 5) * 1000);
    };

    // Show first notification after 3 seconds
    const initialTimeout = setTimeout(showNotification, 3000);

    // Set interval for subsequent notifications
    const interval = setInterval(showNotification, (settings.intervalTime || 8) * 1000 + (settings.displayTime || 5) * 1000);

    return () => {
      clearTimeout(initialTimeout);
      clearInterval(interval);
    };
  }, [settings]);

  if (!isVisible) return null;

  const positionClasses = {
    'bottom-left': 'bottom-4 left-4',
    'bottom-right': 'bottom-4 right-4',
    'top-left': 'top-4 left-4',
    'top-right': 'top-4 right-4',
  };

  return (
    <div 
      className={`fixed ${positionClasses[settings.position || 'bottom-left']} z-50 animate-pulse`}
      style={{
        animation: 'slideIn 0.3s ease-out'
      }}
    >
      <div className="bg-surface-card rounded-lg shadow-2xl border border-border-DEFAULT p-4 max-w-xs">
        <div className="flex items-center gap-3">
          <div className="h-10 w-10 rounded-full bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center text-white font-bold text-sm">
            {currentNotification.name.charAt(0).toUpperCase()}
          </div>
          <div className="flex-1">
            <p className="text-sm font-medium text-tx-primary">
              {currentNotification.name}
            </p>
            <p className="text-xs text-tx-muted">
              comprou {productName.length > 20 ? productName.slice(0, 20) + '...' : productName}
            </p>
            <div className="flex items-center gap-2 mt-1">
              {settings.showPaymentMethod && (
                <span className="text-xs text-status-success font-medium">
                  {currentNotification.paymentMethod}
                </span>
              )}
              <span className="text-xs text-tx-muted">
                há {currentNotification.minutesAgo} min
              </span>
            </div>
          </div>
          <div className="text-status-success">
            <CheckCircleIcon className="h-5 w-5" />
          </div>
        </div>
      </div>
      <style>{`
        @keyframes slideIn {
          from {
            opacity: 0;
            transform: translateY(20px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }
      `}</style>
    </div>
  );
}

// Success/Result Screens
function ResultScreen({ result }: { result: PaymentResult }) {
  const [qrCodeUrl, setQrCodeUrl] = useState<string>('');
  const [isVerifying, setIsVerifying] = useState(false);
  const [paymentStatus, setPaymentStatus] = useState<'pending' | 'approved' | 'rejected'>(result.status);
  const [copied, setCopied] = useState(false);
  const [showVerifyButton, setShowVerifyButton] = useState(false);
  const pixCode = result.pix_copy_paste || result.pix_qr_code || '';

  useEffect(() => {
    if (result.status === 'pending' && pixCode) {
      QRCode.toDataURL(pixCode, {
        width: 300,
        margin: 2,
        color: { dark: '#000000', light: '#ffffff' },
      }).then(setQrCodeUrl).catch((error) => {
        console.error('Falha ao gerar QR Code PIX:', error);
      });
    }
  }, [result.status, pixCode]);

  // Show verify button after 10 seconds
  useEffect(() => {
    if (paymentStatus !== 'pending') return;
    const timer = setTimeout(() => {
      setShowVerifyButton(true);
    }, 10000);
    return () => clearTimeout(timer);
  }, [paymentStatus]);

  // Auto-check payment status every 10 seconds (after initial 15 second delay)
  useEffect(() => {
    if (paymentStatus !== 'pending' || !result.charge_id) return;
    
    // Start auto-checking after 15 seconds
    const startDelay = setTimeout(() => {
      const interval = setInterval(async () => {
        try {
          const response = await api.get(`/checkout/charge/${result.charge_id}/status`);
          const data = response.data.data;
          if (data.status === 'paid' || data.status === 'approved') {
            setPaymentStatus('approved');
            toast.success('Pagamento confirmado!');
            clearInterval(interval);
          }
        } catch (error) {
          // Silently fail on auto-check
        }
      }, 10000);
      
      return () => clearInterval(interval);
    }, 15000);

    return () => clearTimeout(startDelay);
  }, [paymentStatus, result.charge_id]);

  const verifyPayment = async () => {
    if (!result.charge_id) {
      toast.error('ID da cobrança não encontrado');
      return;
    }

    setIsVerifying(true);
    try {
      const response = await api.get(`/checkout/charge/${result.charge_id}/status`);
      const data = response.data.data;
      
      if (data.status === 'paid' || data.status === 'approved') {
        setPaymentStatus('approved');
        toast.success('Pagamento confirmado!');
      } else if (data.status === 'pending') {
        toast('Pagamento ainda pendente. Aguarde alguns segundos e tente novamente.', { icon: '⏳' });
      } else if (data.status === 'expired') {
        toast.error('Este PIX expirou. Por favor, gere um novo.');
      } else if (data.status === 'cancelled') {
        toast.error('Este pagamento foi cancelado.');
      } else {
        toast(`Status atual: ${data.status}. Aguardando confirmação.`, { icon: 'ℹ️' });
      }
    } catch (error: any) {
      const errorMessage = error.response?.data?.error?.message || 'Erro ao verificar. Tente novamente.';
      toast.error(errorMessage);
    } finally {
      setIsVerifying(false);
    }
  };

  const handleCopy = () => {
    navigator.clipboard.writeText(pixCode);
    setCopied(true);
    toast.success('Código PIX copiado!');
    setTimeout(() => setCopied(false), 3000);
  };

  if (paymentStatus === 'approved') {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-green-50 to-emerald-100 p-4" style={LIGHT_MODE_VARS}>
        <div className="w-full max-w-md">
          {/* Confetti effect */}
          <div className="absolute inset-0 overflow-hidden pointer-events-none">
            <div className="animate-bounce absolute top-10 left-10 w-3 h-3 bg-green-400 rounded-full opacity-60" />
            <div className="animate-bounce absolute top-20 right-20 w-2 h-2 bg-emerald-400 rounded-full opacity-60" style={{ animationDelay: '0.2s' }} />
            <div className="animate-bounce absolute top-32 left-1/4 w-2 h-2 bg-teal-400 rounded-full opacity-60" style={{ animationDelay: '0.4s' }} />
          </div>
          
          <div className="rounded-3xl bg-surface-card p-8 shadow-2xl border border-green-100">
            <div className="text-center">
              <div className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-green-400 to-emerald-500">
                <CheckCircleIcon className="h-12 w-12 text-white" />
              </div>
              <h1 className="mt-6 text-3xl font-bold text-tx-primary">
                Pagamento Confirmado!
              </h1>
              <p className="mt-3 text-lg text-tx-secondary">
                Obrigado pela sua compra!
              </p>
              <div className="mt-6 rounded-xl bg-status-success-subtle p-4 border border-green-200">
                <p className="text-sm text-status-success">
                  📧 Você receberá um e-mail com os detalhes do seu pedido em instantes.
                </p>
              </div>
              <div className="mt-6 flex items-center justify-center gap-2 text-sm text-tx-muted">
                <ShieldCheckIcon className="h-5 w-5 text-status-success" />
                <span>Transação processada com segurança</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (paymentStatus === 'pending' && pixCode) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 p-4" style={LIGHT_MODE_VARS}>
        <div className="w-full max-w-md">
          <div className="rounded-2xl bg-white p-8 shadow-sm border border-gray-200">
            {/* Header */}
            <div className="text-center mb-8">
              <h1 className="text-xl font-semibold text-gray-900">Pagamento via PIX</h1>
              <p className="mt-1 text-sm text-gray-500">Escaneie o QR Code com o app do seu banco</p>
            </div>

            {/* QR Code */}
            <div className="flex justify-center mb-8">
              <div className="rounded-xl border border-gray-200 p-4 bg-white">
                {qrCodeUrl ? (
                  <img src={qrCodeUrl} alt="QR Code PIX" className="h-48 w-48" />
                ) : (
                  <div className="h-48 w-48 flex items-center justify-center">
                    <svg className="h-6 w-6 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                  </div>
                )}
              </div>
            </div>

            {/* Copy Code Section */}
            <div className="space-y-4">
              <p className="text-xs text-gray-500 text-center">Ou copie o código Pix Copia e Cola</p>

              <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                <span className="flex-1 text-xs text-gray-600 font-mono truncate select-all">{pixCode}</span>
                <button
                  onClick={handleCopy}
                  className={`shrink-0 rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                    copied
                      ? 'bg-green-600 text-white'
                      : 'bg-gray-900 text-white hover:bg-gray-700'
                  }`}
                >
                  {copied ? 'Copiado' : 'Copiar'}
                </button>
              </div>

              {/* Main CTA */}
              <button
                onClick={handleCopy}
                className={`w-full rounded-lg py-3.5 text-sm font-semibold transition-colors ${
                  copied
                    ? 'bg-green-600 text-white'
                    : 'bg-gray-900 text-white hover:bg-gray-800'
                }`}
              >
                {copied ? (
                  <span className="flex items-center justify-center gap-2">
                    <CheckCircleIcon className="h-5 w-5" />
                    Código copiado!
                  </span>
                ) : (
                  <span className="flex items-center justify-center gap-2">
                    <ClipboardDocumentIcon className="h-5 w-5" />
                    Copiar código PIX
                  </span>
                )}
              </button>

              {/* Verify Payment */}
              {showVerifyButton && (
                <button
                  onClick={verifyPayment}
                  disabled={isVerifying}
                  className="w-full rounded-lg border border-gray-300 bg-white py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors animate-fade-in"
                >
                  {isVerifying ? (
                    <span className="flex items-center justify-center gap-2">
                      <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                      </svg>
                      Verificando...
                    </span>
                  ) : 'Já realizei o pagamento'}
                </button>
              )}
            </div>

            {/* Footer */}
            <div className="mt-8 flex items-center justify-center gap-1.5 text-xs text-gray-400">
              <ShieldCheckIcon className="h-3.5 w-3.5" />
              <span>Pagamento protegido</span>
            </div>
          </div>
        </div>

        <style>{`
          @keyframes fade-in {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
          }
          .animate-fade-in {
            animation: fade-in 0.2s ease-out forwards;
          }
        `}</style>
      </div>
    );
  }

  if (result.status === 'pending' && result.boleto_url) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 p-4" style={LIGHT_MODE_VARS}>
        <div className="w-full max-w-md rounded-2xl bg-surface-card p-8 shadow-xl">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-tx-primary">Boleto Gerado!</h1>
            <p className="mt-2 text-tx-secondary">
              Clique no botão abaixo para visualizar o boleto
            </p>
            <a
              href={safeHref(result.boleto_url)}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-6 inline-block w-full rounded-lg bg-accent-primary py-3 font-medium text-white hover:bg-accent-hover"
            >
              Visualizar Boleto
            </a>
            <div className="mt-4">
              <p className="text-sm text-tx-muted">Código de barras:</p>
              <input
                type="text"
                value={result.boleto_barcode}
                readOnly
                className="mt-1 w-full rounded-element border border-border-DEFAULT p-3 text-center text-xs"
              />
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 p-4" style={LIGHT_MODE_VARS}>
      <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
        <div className="text-center">
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-status-error-subtle">
            <XCircleIcon className="h-10 w-10 text-status-error" />
          </div>
          <h1 className="mt-4 text-2xl font-bold text-tx-primary">
            Pagamento Recusado
          </h1>
          <p className="mt-2 text-tx-secondary">{result.message}</p>
          <button
            onClick={() => window.location.reload()}
            className="mt-6 w-full rounded-lg bg-accent-primary py-3 font-medium text-white hover:bg-accent-hover"
          >
            Tentar Novamente
          </button>
        </div>
      </div>
    </div>
  );
}

// Default blocks if none configured - matches CheckoutBuilderPage defaults
const defaultBlocks: CheckoutBlock[] = [
  { id: 'header', type: 'header', enabled: true, settings: { showLogo: true, showSecureBadge: true, title: 'VectorPagamentos' } },
  { id: 'product', type: 'product', enabled: true, settings: { showImage: true, showDescription: true, showOriginalPrice: true } },
  { id: 'urgency', type: 'urgency', enabled: true, settings: { 
    style: 'banner',
    message: '🚨 Últimas vagas! Apenas 7 restantes',
    backgroundColor: '#fef3c7',
    textColor: '#92400e'
  }},
  { id: 'timer', type: 'timer', enabled: true, settings: { message: 'Oferta expira em:', hours: 0, minutes: 15, seconds: 0 } },
  { id: 'benefits', type: 'benefits', enabled: true, settings: { title: 'O que você vai receber:', items: [
    { icon: 'check', text: 'Acesso vitalício ao conteúdo' },
    { icon: 'check', text: 'Suporte prioritário por 1 ano' },
    { icon: 'check', text: 'Certificado de conclusão' },
    { icon: 'check', text: 'Comunidade exclusiva de alunos' },
  ]}},
  { id: 'bonus', type: 'bonus', enabled: true, settings: {
    title: '🎁 Bônus Exclusivos',
    items: [
      { name: 'E-book Exclusivo', value: 'R$ 97,00' },
      { name: 'Planilha de Controle', value: 'R$ 47,00' },
      { name: 'Acesso ao Grupo VIP', value: 'R$ 197,00' },
    ]
  }},
  { id: 'payment', type: 'payment', enabled: true, settings: { showInstallments: true, highlightPix: true } },
  { id: 'guarantee', type: 'guarantee', enabled: true, settings: { days: 7, title: 'Garantia Incondicional', description: 'Se não gostar, devolvemos 100% do seu dinheiro' } },
  { id: 'testimonials', type: 'testimonials', enabled: true, settings: { count: 3, items: [
    { name: 'Maria Silva', avatar: '', rating: 5, text: 'Produto incrível! Superou todas as expectativas. Recomendo muito!' },
    { name: 'João Santos', avatar: '', rating: 5, text: 'Melhor investimento que fiz. Conteúdo de altíssima qualidade.' },
    { name: 'Ana Oliveira', avatar: '', rating: 5, text: 'Suporte excelente e entrega rápida. Estou muito satisfeita!' },
  ]}},
  { id: 'faq', type: 'faq', enabled: true, settings: {
    title: 'Perguntas Frequentes',
    items: [
      { question: 'Como funciona o acesso?', answer: 'Após a confirmação do pagamento, você receberá um e-mail com os dados de acesso.' },
      { question: 'Posso parcelar?', answer: 'Sim! Aceitamos parcelamento em até 12x no cartão de crédito.' },
      { question: 'Tem garantia?', answer: 'Sim, oferecemos garantia incondicional de 7 dias.' },
    ]
  }},
  { id: 'trust_badges', type: 'trust_badges', enabled: true, settings: {} },
];

export default function PublicCheckoutPage() {
  const { slug } = useParams<{ slug: string }>();
  const [searchParams] = useSearchParams();
  const affiliateCode = searchParams.get('ref');
  const isPreview = searchParams.get('preview') === 'true';

  const [paymentMethod, setPaymentMethod] = useState<'pix' | 'credit_card'>('pix');
  const [paymentResult, setPaymentResult] = useState<PaymentResult | null>(null);
  const [selectedBumps, setSelectedBumps] = useState<Set<string>>(new Set());
  const [orderBumps, setOrderBumps] = useState<OrderBumpData[]>([]);

  // Upsell flow state
  const [upsellFlowActive, setUpsellFlowActive] = useState(false);
  const [currentUpsell, setCurrentUpsell] = useState<UpsellOfferData | null>(null);
  const [upsellLoading, setUpsellLoading] = useState(false);
  const [upsellTimer, setUpsellTimer] = useState(0);

  // Receber settings do builder via postMessage (preview em tempo real)
  const [builderSettings, setBuilderSettings] = useState<any>(null);

  useEffect(() => {
    if (!isPreview) return;
    const handler = (event: MessageEvent) => {
      if (event.data?.type === 'checkout-builder-settings') {
        setBuilderSettings(event.data.settings);
      }
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [isPreview]);

  const { data: checkout, isLoading, error } = useQuery<CheckoutData>({
    queryKey: ['public-checkout', slug],
    queryFn: async () => {
      const response = await api.get(`/checkout/${slug}`);
      return response.data.data;
    },
    enabled: !!slug,
  });

  // Update page title with product name
  useEffect(() => {
    if (checkout?.product?.name) {
      document.title = checkout.product.name;
    }
  }, [checkout]);

  // Load order bumps and set pre-selected ones
  useEffect(() => {
    if (!checkout) return;
    const checkoutData = checkout as any;
    const bumps: OrderBumpData[] = checkoutData.order_bumps || [];
    setOrderBumps(bumps);
    const preSelected = new Set<string>();
    bumps.forEach((b: OrderBumpData) => {
      if (b.is_pre_selected) preSelected.add(b.id);
    });
    setSelectedBumps(preSelected);
  }, [checkout]);

  // Upsell timer countdown
  useEffect(() => {
    if (!upsellFlowActive || upsellTimer <= 0) return;
    const interval = setInterval(() => {
      setUpsellTimer(prev => (prev > 0 ? prev - 1 : 0));
    }, 1000);
    return () => clearInterval(interval);
  }, [upsellFlowActive, upsellTimer]);

  const fetchNextUpsell = async (chargeId: string) => {
    setUpsellLoading(true);
    try {
      const response = await api.get(`/checkout/charge/${chargeId}/upsell`);
      if (response.data.success && response.data.data) {
        setCurrentUpsell(response.data.data);
        setUpsellTimer(response.data.data.timer_seconds || 0);
        setUpsellFlowActive(true);
      } else {
        setUpsellFlowActive(false);
        setCurrentUpsell(null);
      }
    } catch {
      setUpsellFlowActive(false);
      setCurrentUpsell(null);
    } finally {
      setUpsellLoading(false);
    }
  };

  const handleAcceptUpsell = async () => {
    if (!currentUpsell || !paymentResult?.charge_id) return;
    setUpsellLoading(true);
    try {
      const response = await api.post(`/checkout/charge/${paymentResult.charge_id}/upsell/${currentUpsell.id}/accept`);
      const data = response.data.data;
      if (data.has_next_offer) {
        await fetchNextUpsell(paymentResult.charge_id);
      } else {
        setUpsellFlowActive(false);
        setCurrentUpsell(null);
      }
    } catch {
      toast.error('Erro ao processar oferta');
      setUpsellFlowActive(false);
      setCurrentUpsell(null);
    } finally {
      setUpsellLoading(false);
    }
  };

  const handleDeclineUpsell = async () => {
    if (!currentUpsell || !paymentResult?.charge_id) return;
    setUpsellLoading(true);
    try {
      const response = await api.post(`/checkout/charge/${paymentResult.charge_id}/upsell/${currentUpsell.id}/decline`);
      const data = response.data.data;
      if (data.has_next_offer) {
        await fetchNextUpsell(paymentResult.charge_id);
      } else {
        setUpsellFlowActive(false);
        setCurrentUpsell(null);
      }
    } catch {
      setUpsellFlowActive(false);
      setCurrentUpsell(null);
    } finally {
      setUpsellLoading(false);
    }
  };

  const toggleBump = (bumpId: string) => {
    setSelectedBumps(prev => {
      const next = new Set(prev);
      if (next.has(bumpId)) {
        next.delete(bumpId);
      } else {
        next.add(bumpId);
      }
      return next;
    });
  };

  // Facebook Pixel client-side injection
  useEffect(() => {
    if (!checkout?.facebook_pixel?.pixel_id) return;

    const pixelId = checkout.facebook_pixel.pixel_id;

    // Avoid double-loading the pixel script
    if ((window as any).fbq) return;

    // Standard Facebook Pixel base code
    const f = window as any;
    const n = (f.fbq = function (...args: any[]) {
      if (n.callMethod) {
        n.callMethod.apply(n, args);
      } else {
        n.queue.push(args);
      }
    });
    if (!f._fbq) f._fbq = n;
    n.push = n;
    n.loaded = true;
    n.version = '2.0';
    n.queue = [] as any[];

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    document.head.appendChild(script);

    f.fbq('init', pixelId);

    // Fire PageView
    if (checkout.facebook_pixel.track_page_view) {
      f.fbq('track', 'PageView');
    }

    // Fire InitiateCheckout
    if (checkout.facebook_pixel.track_initiate_checkout && checkout.product) {
      f.fbq('track', 'InitiateCheckout', {
        content_ids: [checkout.product.id],
        content_name: checkout.product.name,
        content_type: 'product',
        value: (checkout.product.price || 0) / 100,
        currency: checkout.product.currency || 'BRL',
      });
    }

    // Add noscript pixel image
    const noscript = document.createElement('noscript');
    const img = document.createElement('img');
    img.height = 1;
    img.width = 1;
    img.style.display = 'none';
    img.src = `https://www.facebook.com/tr?id=${pixelId}&ev=PageView&noscript=1`;
    noscript.appendChild(img);
    document.body.appendChild(noscript);

    return () => {
      // Cleanup on unmount
      script.remove();
      noscript.remove();
    };
  }, [checkout?.facebook_pixel]);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<PaymentForm>({
    defaultValues: {
      card: { installments: 1 },
    },
  });

  const paymentMutation = useMutation({
    mutationFn: async (data: PaymentForm) => {
      // Capture Facebook browser params from cookies/URL
      const fbclid = searchParams.get('fbclid') || undefined;
      const fbpCookie = document.cookie.match(/_fbp=([^;]+)/)?.[1];
      const fbcCookie = document.cookie.match(/_fbc=([^;]+)/)?.[1];

      const response = await api.post(`/checkout/${slug}/process`, {
        ...data,
        payment_method: paymentMethod,
        affiliate_code: affiliateCode,
        order_bumps: Array.from(selectedBumps),
        fbclid,
        fbp: fbpCookie,
        fbc: fbcCookie,
      });
      return response.data.data;
    },
    onSuccess: (result) => {
      setPaymentResult(result);

      // Check for upsells after successful payment
      if (result.has_upsells && result.charge_id) {
        fetchNextUpsell(result.charge_id);
      }

      // Fire Facebook Pixel Purchase event client-side for deduplication
      if (checkout?.facebook_pixel?.track_purchase && (window as any).fbq) {
        (window as any).fbq('track', 'Purchase', {
          content_ids: checkout.product ? [checkout.product.id] : [],
          content_name: checkout.product?.name,
          content_type: 'product',
          value: (checkout.product?.price || 0) / 100,
          currency: checkout.product?.currency || 'BRL',
        });
      }
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error?.message || 'Erro ao processar pagamento');
    },
  });

  const onSubmit = (data: PaymentForm) => {
    if (isPreview) {
      toast('Modo preview — pagamento desabilitado', { icon: '👁️' });
      return;
    }
    paymentMutation.mutate({
      ...data,
      card: paymentMethod === 'credit_card' ? data.card : undefined,
    });
  };

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-white">
        <Spinner size="lg" />
      </div>
    );
  }

  if (error || !checkout) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center p-4">
        <h1 className="text-2xl font-bold text-tx-primary">Checkout não encontrado</h1>
        <p className="mt-2 text-tx-secondary">Este link pode estar expirado ou inválido.</p>
      </div>
    );
  }

  // Upsell flow - show upsell screen instead of result
  if (paymentResult && upsellFlowActive && currentUpsell) {
    return (
      <div
        className="min-h-screen flex items-center justify-center p-4"
        style={{ ...LIGHT_MODE_VARS, backgroundColor: '#f8fafc', fontFamily: 'Inter, -apple-system, sans-serif' }}
      >
        <div className="w-full max-w-lg bg-white rounded-2xl shadow-xl overflow-hidden">
          {/* Timer bar */}
          {upsellTimer > 0 && (
            <div className="bg-red-500 text-white text-center py-2 text-sm font-bold">
              Oferta expira em {Math.floor(upsellTimer / 60)}:{String(upsellTimer % 60).padStart(2, '0')}
            </div>
          )}

          <div className="p-6 space-y-5">
            {/* Headline */}
            {currentUpsell.headline && (
              <h2 className="text-xl font-bold text-center text-gray-900">
                {currentUpsell.headline}
              </h2>
            )}

            {/* Image */}
            {(currentUpsell.image_url || currentUpsell.product?.image_url) && (
              <div className="aspect-video rounded-xl overflow-hidden bg-gray-100">
                <img
                  src={currentUpsell.image_url || currentUpsell.product?.image_url}
                  alt={currentUpsell.title}
                  className="w-full h-full object-cover"
                />
              </div>
            )}

            {/* Title & Description */}
            <div className="text-center">
              <h3 className="text-lg font-bold text-gray-900">{currentUpsell.title}</h3>
              {currentUpsell.description && (
                <p className="mt-2 text-sm text-gray-600">{currentUpsell.description}</p>
              )}
            </div>

            {/* Price */}
            <div className="text-center">
              {currentUpsell.original_price && currentUpsell.original_price > currentUpsell.price && (
                <p className="text-sm text-gray-400 line-through">
                  De {formatCurrency(currentUpsell.original_price)}
                </p>
              )}
              <p className="text-3xl font-bold text-green-600">
                {formatCurrency(currentUpsell.price)}
              </p>
              {currentUpsell.discount_percent && (
                <span className="inline-block mt-1 bg-green-100 text-green-700 text-xs font-bold px-2 py-1 rounded-full">
                  {currentUpsell.discount_percent}% OFF
                </span>
              )}
            </div>

            {/* Benefits */}
            {currentUpsell.benefits && currentUpsell.benefits.length > 0 && (
              <ul className="space-y-2">
                {currentUpsell.benefits.map((benefit, i) => (
                  <li key={i} className="flex items-center gap-2 text-sm text-gray-700">
                    <CheckCircleIcon className="h-5 w-5 text-green-500 flex-shrink-0" />
                    {benefit}
                  </li>
                ))}
              </ul>
            )}

            {/* Accept Button */}
            <button
              onClick={handleAcceptUpsell}
              disabled={upsellLoading}
              className="w-full py-4 rounded-xl text-white font-bold text-lg transition-all hover:opacity-90 disabled:opacity-50"
              style={{ background: 'linear-gradient(135deg, #22c55e, #16a34a)' }}
            >
              {upsellLoading ? 'Processando...' : currentUpsell.call_to_action}
            </button>

            {/* Decline Link */}
            <button
              onClick={handleDeclineUpsell}
              disabled={upsellLoading}
              className="w-full text-center text-sm text-gray-400 hover:text-gray-600 transition-colors disabled:opacity-50"
            >
              {currentUpsell.decline_text}
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (paymentResult) {
    return <ResultScreen result={paymentResult} />;
  }

  // Extract settings - support both new builder format and legacy format
  // Em preview, builderSettings do postMessage tem prioridade (atualização em tempo real)
  const apiSettings = (checkout.settings || {}) as Partial<CheckoutSettings>;
  const settings = builderSettings
    ? { ...apiSettings, ...builderSettings, theme: { ...(apiSettings.theme || {}), ...(builderSettings.theme || {}) } } as Partial<CheckoutSettings>
    : apiSettings;
  const themeData = builderSettings?.theme || (checkout.theme || settings.theme || {}) as Partial<CheckoutTheme>;
  
  // Check template type
  const template = (themeData as any).template || (settings.theme as any)?.template || 'classic';

  // Product: merge settings.product (from builder) with checkout.product (live from backend)
  // Live backend product data takes priority for image_url (user may have updated it)
  const rawProduct = builderSettings?.product || settings.product || checkout.product;
  const backendProduct = checkout.product;
  const product = rawProduct ? {
    ...rawProduct,
    // Normalize originalPrice from different sources
    originalPrice: rawProduct.originalPrice || (rawProduct as any).original_price,
    // Use live image_url from backend product (user-uploaded), fallback to settings snapshot
    image_url: backendProduct?.image_url || rawProduct.image_url,
  } : null;
  
  const payment = settings.payment || {
    methods: (settings.payment_methods || ['pix']).filter((m: string) => m !== 'credit_card'),
    maxInstallments: settings.max_installments || 12,
    pixDiscount: settings.pix_discount || 0,
    boletoDiscount: 0,
  };
  const conversion = settings.conversion || {
    timerEnabled: settings.timer_enabled !== false,
    timerMinutes: settings.timer_minutes || 15,
    testimonialsEnabled: settings.testimonials_enabled !== false,
    guaranteeDays: settings.guarantee_days || 7,
  };
  const blocks: CheckoutBlock[] = settings.blocks || defaultBlocks;
  const enabledBlocks = blocks.filter((b) => b.enabled);

  // Theme - defaults match CheckoutBuilderPage
  const theme = {
    primaryColor: themeData.primaryColor || settings.theme_color || '#10b981',
    secondaryColor: themeData.secondaryColor || '#6366f1',
    backgroundColor: themeData.backgroundColor || '#f8fafc',
    cardBackground: themeData.cardBackground || '#ffffff',
    textColor: themeData.textColor || '#1e293b',
    mutedColor: themeData.mutedColor || '#64748b',
    borderRadius: themeData.borderRadius || 'xl',
    fontFamily: themeData.fontFamily || 'Inter',
    buttonStyle: themeData.buttonStyle || 'gradient',
    shadow: themeData.shadow || 'lg',
  };

  // Helpers
  const getBorderRadius = () => {
    switch (theme.borderRadius) {
      case 'none': return '0';
      case 'sm': return '0.25rem';
      case 'md': return '0.375rem';
      case 'lg': return '0.5rem';
      case 'xl': return '0.75rem';
      case '2xl': return '1rem';
      case 'full': return '9999px';
      default: return '0.75rem';
    }
  };

  const getShadow = () => {
    switch (theme.shadow) {
      case 'none': return 'none';
      case 'sm': return '0 1px 2px 0 rgb(0 0 0 / 0.05)';
      case 'md': return '0 4px 6px -1px rgb(0 0 0 / 0.1)';
      case 'lg': return '0 10px 15px -3px rgb(0 0 0 / 0.1)';
      case 'xl': return '0 20px 25px -5px rgb(0 0 0 / 0.1)';
      default: return '0 10px 15px -3px rgb(0 0 0 / 0.1)';
    }
  };

  const getButtonStyle = () => {
    switch (theme.buttonStyle) {
      case 'solid':
        return { background: theme.primaryColor };
      case 'gradient':
        return { background: `linear-gradient(135deg, ${theme.primaryColor}, ${theme.secondaryColor})` };
      case 'outline':
        return { background: 'transparent', border: `2px solid ${theme.primaryColor}`, color: theme.primaryColor };
      default:
        return { background: theme.primaryColor };
    }
  };

  const pixPrice = payment.pixDiscount ? product.price * (1 - payment.pixDiscount / 100) : product.price;
  const bumpTotal = orderBumps.filter(b => selectedBumps.has(b.id)).reduce((sum, b) => sum + b.price, 0);
  const totalWithBumps = pixPrice + bumpTotal;

  // Find social proof block settings
  const socialProofBlock = enabledBlocks.find(b => b.type === 'social_proof');
  const socialProofSettings: SocialProofSettings | null = socialProofBlock ? {
    enabled: socialProofBlock.settings.enabled !== false,
    displayTime: socialProofBlock.settings.displayTime || 5,
    intervalTime: socialProofBlock.settings.intervalTime || 8,
    position: socialProofBlock.settings.position || 'bottom-left',
    showPaymentMethod: socialProofBlock.settings.showPaymentMethod !== false,
    paymentMethods: socialProofBlock.settings.paymentMethods || ['PIX', 'Cartão de Crédito'],
    names: socialProofBlock.settings.names || ['João Silva', 'Maria Santos', 'Pedro Oliveira'],
  } : null;

  // Render Modern Split Layout if template is 'modern-split'
  if (template === 'modern-split' && product) {
    return (
      <ModernSplitCheckout
        product={product}
        theme={theme}
        onSubmit={onSubmit}
        isLoading={paymentMutation.isPending}
        orderBumps={orderBumps}
        selectedBumps={selectedBumps}
        onToggleBump={toggleBump}
        blocks={blocks}
      />
    );
  }

  // Classic Layout (default)
  return (
    <div
      className="min-h-screen"
      style={{
        ...LIGHT_MODE_VARS,
        backgroundColor: theme.backgroundColor,
        fontFamily: `${theme.fontFamily}, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`,
      }}
    >
      {/* Container responsivo: mobile 100%, tablet/desktop até 900px */}
      <div className="mx-auto max-w-[375px] md:max-w-[600px] lg:max-w-[900px] p-4">
        <div
          style={{
            backgroundColor: theme.cardBackground,
            borderRadius: getBorderRadius(),
            boxShadow: getShadow(),
            overflow: 'hidden',
          }}
        >
          <form onSubmit={handleSubmit(onSubmit)}>
            {enabledBlocks.map((block) => (
              <div key={block.id}>
                {/* Header Block */}
                {block.type === 'header' && (
                  <div
                    className="flex items-center justify-between p-4 border-b"
                    style={{ borderColor: `${theme.primaryColor}15` }}
                  >
                    {block.settings.logoUrl ? (
                      <img
                        src={block.settings.logoUrl}
                        alt="Logo"
                        className="h-8 max-w-[150px] object-contain"
                      />
                    ) : (
                      <h1
                        className="text-xl font-bold"
                        style={{ color: theme.primaryColor }}
                      >
                        {block.settings.title || 'VectorPagamentos'}
                      </h1>
                    )}
                    {block.settings.showSecureBadge && (
                      <span className="flex items-center gap-1 text-xs font-medium text-status-success bg-status-success-subtle px-2 py-1 rounded-full">
                        <ShieldCheckIcon className="h-3.5 w-3.5" />
                        Compra Segura
                      </span>
                    )}
                  </div>
                )}

                {/* Product Block */}
                {block.type === 'product' && product && (
                  <div className="p-5">
                    {block.settings.showImage && product.image_url && (
                      <div className="mb-4 aspect-video overflow-hidden rounded-xl bg-surface-hover">
                        <img
                          src={product.image_url}
                          alt={product.name}
                          className="h-full w-full object-cover"
                          onError={(e) => {
                            const target = e.currentTarget;
                            // Hide the image container if the image fails to load
                            if (target.parentElement) {
                              target.parentElement.style.display = 'none';
                            }
                          }}
                          loading="lazy"
                        />
                      </div>
                    )}
                    <h2 className="text-xl font-bold" style={{ color: theme.textColor }}>
                      {product.name}
                    </h2>
                    {block.settings.showDescription && product.description && (
                      <div
                        className="mt-2 text-sm checkout-description"
                        style={{ color: theme.mutedColor }}
                        dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(product.description) }}
                      />
                    )}
                    <div className="mt-4 flex items-end gap-2">
                      <p className="text-3xl font-bold" style={{ color: theme.primaryColor }}>
                        {formatCurrency(product.price)}
                      </p>
                      {block.settings.showOriginalPrice && product.originalPrice && (
                        <p className="text-lg line-through" style={{ color: theme.mutedColor }}>
                          {formatCurrency(product.originalPrice)}
                        </p>
                      )}
                    </div>
                    {payment.pixDiscount > 0 && (
                      <p className="mt-1 text-sm font-medium text-status-success">
                        ou {formatCurrency(pixPrice)} no PIX ({payment.pixDiscount}% off)
                      </p>
                    )}
                  </div>
                )}

                {/* Urgency Block - matches builder exactly */}
                {block.type === 'urgency' && (
                  <div
                    className="mx-4 mb-4 rounded-lg p-3 text-center font-medium"
                    style={{ 
                      backgroundColor: block.settings.backgroundColor || '#fef3c7',
                      color: block.settings.textColor || '#92400e'
                    }}
                  >
                    {block.settings.message || '🚨 Últimas vagas! Apenas 7 restantes'}
                  </div>
                )}

                {/* Timer Block */}
                {block.type === 'timer' && conversion.timerEnabled && (
                  <div
                    className="mx-4 mb-4 rounded-xl p-4 text-center"
                    style={{ backgroundColor: `${theme.primaryColor}10` }}
                  >
                    <p className="text-sm font-medium" style={{ color: theme.mutedColor }}>
                      {block.settings.message || 'Oferta expira em:'}
                    </p>
                    <Timer 
                      hours={block.settings.hours || 0}
                      minutes={block.settings.minutes || conversion.timerMinutes || 15}
                      seconds={block.settings.seconds || 0}
                      themeColor={theme.primaryColor} 
                    />
                  </div>
                )}

                {/* Benefits Block */}
                {block.type === 'benefits' && (
                  <div className="px-5 pb-4">
                    <h3 className="font-semibold mb-3" style={{ color: theme.textColor }}>
                      {block.settings.title || 'O que você vai receber:'}
                    </h3>
                    <ul className="space-y-2">
                      {block.settings.items?.map((item: any, i: number) => (
                        <li key={i} className="flex items-center gap-2 text-sm" style={{ color: theme.textColor }}>
                          <svg className="h-5 w-5 flex-shrink-0" style={{ color: theme.primaryColor }} fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                          </svg>
                          <span>{item.text}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {/* Bonus Block */}
                {block.type === 'bonus' && (
                  <div className="mx-4 mb-4 rounded-xl p-4" style={{ backgroundColor: `${theme.primaryColor}08`, border: `1px dashed ${theme.primaryColor}40` }}>
                    <h3 className="font-bold mb-3" style={{ color: theme.primaryColor }}>
                      {block.settings.title || '🎁 Bônus Exclusivos'}
                    </h3>
                    <ul className="space-y-2">
                      {block.settings.items?.map((item: any, i: number) => (
                        <li key={i} className="flex items-center justify-between text-sm">
                          <span className="flex items-center gap-2">
                            <GiftIcon className="h-4 w-4" style={{ color: theme.primaryColor }} />
                            <span style={{ color: theme.textColor }}>{item.name}</span>
                          </span>
                          {item.value && (
                            <span className="line-through" style={{ color: theme.mutedColor }}>{item.value}</span>
                          )}
                        </li>
                      ))}
                    </ul>
                    <p className="mt-3 text-center text-xs font-medium" style={{ color: theme.primaryColor }}>
                      Tudo isso GRÁTIS hoje!
                    </p>
                  </div>
                )}

                {/* Payment Block */}
                {block.type === 'payment' && (
                  <div className="p-5 border-t" style={{ borderColor: `${theme.primaryColor}15` }}>
                    {/* Order Bumps - before payment form */}
                    {orderBumps.filter(b => b.display_position === 'before_payment').length > 0 && (
                      <div className="mb-5 space-y-3">
                        <h3 className="text-sm font-semibold mb-3 flex items-center gap-2" style={{ color: theme.textColor }}>
                          <GiftIcon className="h-5 w-5" style={{ color: theme.primaryColor }} />
                          Aproveite estas ofertas especiais
                        </h3>
                        {orderBumps.filter(b => b.display_position === 'before_payment').map(bump => (
                          <div
                            key={bump.id}
                            onClick={() => toggleBump(bump.id)}
                            className="relative cursor-pointer rounded-xl border-2 p-4 transition-all hover:shadow-md"
                            style={{
                              borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#e2e8f0',
                              backgroundColor: selectedBumps.has(bump.id) ? `${theme.primaryColor}08` : '#ffffff',
                            }}
                          >
                            {/* Badge de Oferta */}
                            {bump.original_price && bump.original_price > bump.price && (
                              <div 
                                className="absolute -top-2 -right-2 px-2 py-1 rounded-full text-xs font-bold text-white shadow-md"
                                style={{ backgroundColor: theme.primaryColor }}
                              >
                                {Math.round(((bump.original_price - bump.price) / bump.original_price) * 100)}% OFF
                              </div>
                            )}
                            <div className="flex items-start gap-3">
                              <div
                                className="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded border-2 transition-all"
                                style={{
                                  borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#d1d5db',
                                  backgroundColor: selectedBumps.has(bump.id) ? theme.primaryColor : 'transparent',
                                }}
                              >
                                {selectedBumps.has(bump.id) && (
                                  <svg className="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                  </svg>
                                )}
                              </div>
                              <div className="flex-1">
                                <p className="font-bold text-sm" style={{ color: theme.textColor }}>
                                  ✨ {bump.call_to_action || bump.title}
                                </p>
                                {bump.description && (
                                  <p className="text-xs mt-1" style={{ color: theme.mutedColor }}>
                                    {bump.description}
                                  </p>
                                )}
                                <div className="mt-2 flex items-center gap-2">
                                  <span className="font-bold text-base" style={{ color: theme.primaryColor }}>
                                    + {formatCurrency(bump.price)}
                                  </span>
                                  {bump.original_price && bump.original_price > bump.price && (
                                    <span className="text-xs line-through" style={{ color: theme.mutedColor }}>
                                      {formatCurrency(bump.original_price)}
                                    </span>
                                  )}
                                </div>
                              </div>
                              {(bump.image_url || bump.product?.image_url) && (
                                <img
                                  src={bump.image_url || bump.product?.image_url}
                                  alt={bump.title}
                                  className="h-16 w-16 rounded-lg object-cover flex-shrink-0 border-2"
                                  style={{ borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : 'transparent' }}
                                />
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                    <h3 className="font-semibold mb-4 flex items-center gap-2" style={{ color: theme.textColor }}>
                      <PixIcon className="h-5 w-5" />
                      Pagamento via PIX
                    </h3>
                    
                    {/* Customer Fields */}
                    <div className="space-y-3">
                      <input
                        {...register('name', { required: 'Nome é obrigatório' })}
                        type="text"
                        placeholder="Nome completo"
                        className="w-full border p-3 text-sm focus:outline-none focus:ring-2"
                        style={{ borderRadius: getBorderRadius(), borderColor: '#e2e8f0', color: theme.textColor }}
                      />
                      {errors.name && <p className="text-xs text-status-error">{errors.name.message}</p>}
                      
                      <input
                        {...register('email', { required: 'E-mail é obrigatório', pattern: { value: /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i, message: 'E-mail inválido' } })}
                        type="email"
                        placeholder="E-mail"
                        className="w-full border p-3 text-sm focus:outline-none focus:ring-2"
                        style={{ borderRadius: getBorderRadius(), borderColor: '#e2e8f0' }}
                      />
                      {errors.email && <p className="text-xs text-status-error">{errors.email.message}</p>}
                      
                      {settings.collect_cpf !== false && (
                        <>
                          <input
                            {...register('document', { required: settings.collect_cpf !== false ? 'CPF é obrigatório' : false })}
                            type="text"
                            placeholder="CPF"
                            className="w-full border p-3 text-sm focus:outline-none focus:ring-2"
                            style={{ borderRadius: getBorderRadius(), borderColor: '#e2e8f0' }}
                          />
                          {errors.document && <p className="text-xs text-status-error">{errors.document.message}</p>}
                        </>
                      )}
                      
                      {settings.collect_phone !== false && (
                        <input
                          {...register('phone')}
                          type="tel"
                          placeholder="WhatsApp"
                          className="w-full border p-3 text-sm focus:outline-none focus:ring-2"
                          style={{ borderRadius: getBorderRadius(), borderColor: '#e2e8f0' }}
                        />
                      )}

                      {/* Payment Method Selector - Only PIX available */}
                      <div className="flex gap-2 pt-2">
                        {payment.methods.includes('pix') && (
                          <button
                            type="button"
                            onClick={() => setPaymentMethod('pix')}
                            className="flex-1 rounded-element border-2 p-3 text-sm font-medium flex items-center justify-center gap-2 transition-all"
                            style={{
                              borderColor: theme.primaryColor,
                              color: theme.primaryColor,
                              backgroundColor: `${theme.primaryColor}10`,
                            }}
                          >
                            <PixIcon className="h-5 w-5" />
                            PIX
                            {payment.pixDiscount > 0 && (
                              <span className="text-xs font-semibold" style={{ color: '#16a34a' }}>-{payment.pixDiscount}%</span>
                            )}
                          </button>
                        )}
                      </div>

                      {/* Submit Button */}
                      <button
                        type="submit"
                        disabled={paymentMutation.isPending}
                        className="w-full p-4 text-lg font-bold text-white transition-all hover:opacity-90 mt-4 flex items-center justify-center gap-2 disabled:opacity-50"
                        style={{
                          ...getButtonStyle(),
                          borderRadius: getBorderRadius(),
                        }}
                      >
                        {paymentMutation.isPending ? (
                          <>
                            <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            Processando...
                          </>
                        ) : (
                          <>
                            <ShieldCheckIcon className="h-6 w-6" />
                            {`PAGAR ${formatCurrency(totalWithBumps)} COM PIX`}
                          </>
                        )}
                      </button>
                      <p className="text-center text-xs" style={{ color: theme.mutedColor }}>
                        Seus dados estão protegidos com criptografia SSL
                      </p>
                    </div>
                  </div>
                )}

                {/* Guarantee Block */}
                {block.type === 'guarantee' && (
                  <div className="p-5 text-center border-t" style={{ borderColor: `${theme.primaryColor}15` }}>
                    <div className="inline-flex items-center gap-3 rounded-2xl bg-status-success-subtle px-6 py-4">
                      <div className="rounded-full bg-status-success-subtle p-2">
                        <ShieldCheckIcon className="h-8 w-8 text-status-success" />
                      </div>
                      <div className="text-left">
                        <p className="font-bold text-green-800">{block.settings.title || 'Garantia Incondicional'}</p>
                        <p className="text-sm text-status-success">{block.settings.days || conversion.guaranteeDays} dias de garantia</p>
                        <p className="text-xs text-status-success">{block.settings.description || 'Se não gostar, devolvemos 100% do seu dinheiro'}</p>
                      </div>
                    </div>
                  </div>
                )}

                {/* Testimonials Block */}
                {block.type === 'testimonials' && conversion.testimonialsEnabled && (
                  <div className="p-5 border-t" style={{ borderColor: `${theme.primaryColor}15` }}>
                    <h3 className="font-semibold mb-4 flex items-center gap-2" style={{ color: theme.textColor }}>
                      <StarIcon className="h-5 w-5" />
                      O que dizem nossos clientes
                    </h3>
                    <div className="space-y-3">
                      {block.settings.items?.slice(0, block.settings.count || 3).map((item: any, i: number) => (
                        <div key={i} className="rounded-xl bg-surface-page p-4">
                          <div className="flex items-center gap-3">
                            {item.avatar ? (
                              <img src={item.avatar} alt={item.name} className="h-10 w-10 rounded-full object-cover" />
                            ) : (
                              <div className="h-10 w-10 rounded-full bg-gradient-to-br from-emerald-400 to-pink-400 flex items-center justify-center text-white font-bold">
                                {item.name.charAt(0)}
                              </div>
                            )}
                            <div>
                              <p className="font-medium" style={{ color: theme.textColor }}>{item.name}</p>
                              <div className="flex text-yellow-400">
                                {[...Array(item.rating || 5)].map((_, j) => (
                                  <StarSolidIcon key={j} className="h-4 w-4" />
                                ))}
                              </div>
                            </div>
                          </div>
                          <p className="mt-3 text-sm" style={{ color: theme.mutedColor }}>
                            "{item.text}"
                          </p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {/* FAQ Block */}
                {block.type === 'faq' && (
                  <div className="p-5 border-t" style={{ borderColor: `${theme.primaryColor}15` }}>
                    <h3 className="font-semibold mb-4 flex items-center gap-2" style={{ color: theme.textColor }}>
                      <QuestionMarkCircleIcon className="h-5 w-5" />
                      {block.settings.title || 'Perguntas Frequentes'}
                    </h3>
                    <div className="space-y-3">
                      {block.settings.items?.map((item: any, i: number) => (
                        <div key={i} className="rounded-xl bg-surface-page p-4">
                          <p className="font-medium" style={{ color: theme.textColor }}>{item.question}</p>
                          <p className="mt-2 text-sm" style={{ color: theme.mutedColor }}>{item.answer}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}

                {/* Trust Badges Block */}
                {block.type === 'trust_badges' && (
                  <div className="p-5 border-t" style={{ borderColor: `${theme.primaryColor}15` }}>
                    <div className="flex flex-wrap items-center justify-center gap-4">
                      <div className="flex items-center gap-1 text-xs text-tx-muted">
                        <ShieldCheckIcon className="h-5 w-5 text-status-success" />
                        <span>Compra Segura</span>
                      </div>
                      <div className="flex items-center gap-1 text-xs text-tx-muted">
                        <BoltIcon className="h-5 w-5 text-yellow-500" />
                        <span>Acesso Imediato</span>
                      </div>
                      <div className="flex items-center gap-1 text-xs text-tx-muted">
                        <HeartIcon className="h-5 w-5 text-status-error" />
                        <span>Satisfação Garantida</span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Shipping Block */}
                {block.type === 'shipping' && (
                  block.settings.type === 'physical' ? (
                    <div className="mx-4 mb-4 rounded-element border border-gray-200 bg-white p-4">
                      <h3 className="text-sm font-semibold mb-3 flex items-center gap-2" style={{ color: theme.textColor }}>
                        <TruckIcon className="h-4 w-4" />
                        Endereço de Entrega
                      </h3>
                      <div className="space-y-2">
                        <div className="grid grid-cols-3 gap-2">
                          <input 
                            {...register('address.zipcode')}
                            type="text" 
                            placeholder="CEP" 
                            className="col-span-2 rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                          />
                          <button type="button" className="rounded bg-accent-primary px-2 py-1 text-xs text-white">Buscar</button>
                        </div>
                        <input 
                          {...register('address.street')}
                          type="text" 
                          placeholder="Rua/Avenida" 
                          className="w-full rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                        />
                        <div className="grid grid-cols-3 gap-2">
                          <input 
                            {...register('address.number')}
                            type="text" 
                            placeholder="Número" 
                            className="rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                          />
                          <input 
                            {...register('address.complement')}
                            type="text" 
                            placeholder="Complemento" 
                            className="col-span-2 rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                          />
                        </div>
                        <input 
                          {...register('address.neighborhood')}
                          type="text" 
                          placeholder="Bairro" 
                          className="w-full rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                        />
                        <div className="grid grid-cols-4 gap-2">
                          <input 
                            {...register('address.city')}
                            type="text" 
                            placeholder="Cidade" 
                            className="col-span-3 rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900" 
                          />
                          <input 
                            {...register('address.state')}
                            type="text" 
                            placeholder="UF" 
                            className="rounded bg-gray-50 border border-gray-300 px-2 py-1 text-xs text-gray-900 text-center" 
                          />
                        </div>
                        <div className="mt-3 rounded-lg bg-blue-50 border border-blue-200 p-2">
                          <p className="text-xs text-blue-600">
                            <TruckIcon className="inline h-3 w-3 mr-1" />
                            <strong>Frete:</strong> {block.settings.freeShipping ? 'GRÁTIS' : `R$ ${(block.settings.shippingPrice || 0).toFixed(2)}`}
                          </p>
                        </div>
                      </div>
                    </div>
                  ) : (
                    <div className="mx-4 mb-4 rounded-lg bg-accent-subtle p-3 text-center">
                      <p className="text-sm text-accent-primary">{block.settings.message || 'Produto digital - entrega imediata por e-mail'}</p>
                    </div>
                  )
                )}

                {/* Banner Block */}
                {block.type === 'banner' && block.settings.imageUrl && (
                  <div className="mx-4 mb-4">
                    {block.settings.linkUrl ? (
                      <a
                        href={block.settings.linkUrl}
                        target={block.settings.openInNewTab ? '_blank' : '_self'}
                        rel={block.settings.openInNewTab ? 'noopener noreferrer' : undefined}
                      >
                        <img
                          src={block.settings.imageUrl}
                          alt={block.settings.altText || 'Banner'}
                          className={`w-full object-cover rounded-${block.settings.borderRadius || 'lg'}`}
                          style={{ maxHeight: `${block.settings.maxHeight || 300}px` }}
                        />
                      </a>
                    ) : (
                      <img
                        src={block.settings.imageUrl}
                        alt={block.settings.altText || 'Banner'}
                        className={`w-full object-cover rounded-${block.settings.borderRadius || 'lg'}`}
                        style={{ maxHeight: `${block.settings.maxHeight || 300}px` }}
                      />
                    )}
                  </div>
                )}
              </div>
            ))}
          </form>
        </div>

        {/* Footer */}
        <div className="mt-6 text-center text-xs" style={{ color: theme.mutedColor }}>
          <p>© {new Date().getFullYear()} Powered by VectorPagamentos</p>
          <p className="mt-1">
            <a href="#" className="hover:underline">Termos de Uso</a>
            {' · '}
            <a href="#" className="hover:underline">Política de Privacidade</a>
          </p>
        </div>
      </div>

      {/* Social Proof Notifications */}
      {socialProofSettings && socialProofSettings.enabled && (
        <SocialProofNotification 
          settings={socialProofSettings} 
          productName={product.name} 
        />
      )}

      {/* Estilos para descrição do produto */}
      <style>{`
        .checkout-description {
          line-height: 1.6;
        }
        
        .checkout-description p {
          margin-bottom: 1em;
        }
        
        .checkout-description h1,
        .checkout-description h2,
        .checkout-description h3 {
          font-weight: bold;
          margin-top: 1.5em;
          margin-bottom: 0.5em;
        }
        
        .checkout-description h1 {
          font-size: 1.5em;
        }
        
        .checkout-description h2 {
          font-size: 1.3em;
        }
        
        .checkout-description h3 {
          font-size: 1.1em;
        }
        
        .checkout-description strong {
          font-weight: bold;
        }
        
        .checkout-description em {
          font-style: italic;
        }
        
        .checkout-description u {
          text-decoration: underline;
        }
        
        .checkout-description ul,
        .checkout-description ol {
          margin-left: 1.5em;
          margin-bottom: 1em;
        }
        
        .checkout-description ul {
          list-style-type: disc;
        }
        
        .checkout-description ol {
          list-style-type: decimal;
        }
        
        .checkout-description li {
          margin-bottom: 0.5em;
        }
        
        .checkout-description a {
          color: #10b981;
          text-decoration: underline;
        }
        
        .checkout-description img {
          max-width: 100%;
          height: auto;
          border-radius: 0.5rem;
          margin: 1em 0;
        }
        
        .checkout-description blockquote {
          border-left: 4px solid #10b981;
          padding-left: 1em;
          margin: 1em 0;
          font-style: italic;
        }
        
        .checkout-description code {
          background-color: rgba(0, 0, 0, 0.05);
          padding: 0.2em 0.4em;
          border-radius: 0.25rem;
          font-family: monospace;
          font-size: 0.9em;
        }
        
        .checkout-description pre {
          background-color: rgba(0, 0, 0, 0.05);
          padding: 1em;
          border-radius: 0.5rem;
          overflow-x: auto;
          margin: 1em 0;
        }
        
        .checkout-description pre code {
          background-color: transparent;
          padding: 0;
        }
      `}</style>
    </div>
  );
}
