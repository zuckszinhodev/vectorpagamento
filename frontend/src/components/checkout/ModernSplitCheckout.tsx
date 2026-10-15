import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import DOMPurify from 'dompurify';
import { formatCurrency } from '@/lib/api';
import {
  ShieldCheckIcon,
  LockClosedIcon,
  CreditCardIcon,
  TruckIcon,
  UserIcon,
  TagIcon,
  ChevronDownIcon,
  CheckIcon,
  CheckBadgeIcon,
  GiftIcon,
  ClockIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import { useThemeStore } from '@/stores/themeStore';

interface CheckoutBlock {
  id: string;
  type: string;
  enabled: boolean;
  settings: Record<string, any>;
}

/**
 * Todos os tokens configuráveis pelo Checkout Builder.
 * Cores são obrigatórias; os demais têm default e são opcionais para
 * manter compatibilidade com chamadas antigas.
 */
interface CheckoutTheme {
  primaryColor: string;
  secondaryColor: string;
  backgroundColor: string;
  cardBackground: string;
  textColor: string;
  mutedColor: string;
  borderRadius?: string;
  fontFamily?: string;
  buttonStyle?: string;
  shadow?: string;
}

interface ModernSplitCheckoutProps {
  product: {
    id: string;
    name: string;
    description?: string;
    price: number;
    originalPrice?: number;
    image_url?: string;
    shipping_required?: boolean;
    free_shipping?: boolean;
    shipping_price?: number;
    type?: string;
  };
  theme: CheckoutTheme;
  payment?: {
    methods?: string[];
    maxInstallments?: number;
    pixDiscount?: number;
  };
  checkoutSettings?: {
    collect_cpf?: boolean;
    collect_phone?: boolean;
    collect_address?: boolean;
    payment_methods?: string[];
  };
  onSubmit: (data: any) => void;
  onApplyCoupon?: (code: string) => Promise<{ code: string; discount_amount: number }>;
  isLoading?: boolean;
  orderBumps?: any[];
  selectedBumps?: Set<string>;
  onToggleBump?: (bumpId: string) => void;
  blocks?: CheckoutBlock[];
  /** Textos configurados na aba Config do builder. */
  texts?: { ctaLabel?: string; ctaLoadingLabel?: string; summaryTitle?: string; securityNote?: string };
  /** Botão de compra fixo no rodapé do celular. */
  stickyCta?: boolean;
  /** Cotação de frete; ausente, o checkout usa apenas o frete fixo do produto. */
  onQuoteShipping?: (postalCode: string) => Promise<ShippingQuoteResult>;
}

export interface ShippingOption {
  id: number;
  name: string;
  company: string;
  /** Em centavos. */
  price: number;
  delivery_time: number | null;
}

export interface ShippingQuoteResult {
  options: ShippingOption[];
  reason?: string | null;
}

/** Tipagem do formulário — sem isto, errors.card.number não compila. */
interface CheckoutFormValues {
  email: string;
  name: string;
  phone?: string;
  document?: string;
  address?: {
    zipcode?: string;
    street?: string;
    number?: string;
    complement?: string;
    neighborhood?: string;
    city?: string;
    state?: string;
  };
  card?: {
    number?: string;
    holder_name?: string;
    expiry?: string;
    cvv?: string;
    installments?: number;
  };
}

const radiusMap: Record<string, string> = {
  none: '0px',
  sm: '4px',
  md: '6px',
  lg: '8px',
  xl: '12px',
  '2xl': '16px',
  full: '9999px',
};

/** Liga o botão de compra do resumo ao <form> que fica na outra coluna. */
const CHECKOUT_FORM_ID = 'vector-checkout-form';

const shadowMap: Record<string, string> = {
  none: 'none',
  sm: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
  md: '0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
  lg: '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.05)',
  xl: '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.05)',
};

/** Converte #rrggbb (ou #rgb) em rgba com alpha — usado para bordas/tints derivados. */
function withAlpha(hex: string, alpha: number): string {
  if (!hex?.startsWith('#')) return hex;
  let h = hex.slice(1);
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  if (h.length !== 6) return hex;
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Contador regressivo do bloco `timer`.
 *
 * Fica em componente separado porque tem estado próprio por segundo; dentro do
 * checkout, re-renderizaria o formulário inteiro a cada tique.
 */
function CountdownBanner({
  block,
  theme,
  ui,
}: {
  block: CheckoutBlock;
  theme: CheckoutTheme;
  ui: { radius: string; radiusInner: string; border: string; shadow: string };
}) {
  const deadline = block.settings?.mode === 'deadline' ? block.settings?.expiresAt : null;

  /** Segundos restantes até a data limite, ou a duração fixa configurada. */
  const secondsLeft = () => {
    if (deadline) {
      const target = new Date(deadline).getTime();
      if (Number.isNaN(target)) return 0;
      return Math.max(0, Math.floor((target - Date.now()) / 1000));
    }
    const { hours = 0, minutes = 15, seconds = 0 } = block.settings || {};
    return Math.max(0, Number(hours) * 3600 + Number(minutes) * 60 + Number(seconds));
  };

  const initial = useMemo(secondsLeft, [block.settings, deadline]);
  const [remaining, setRemaining] = useState(initial);

  useEffect(() => setRemaining(initial), [initial]);

  useEffect(() => {
    if (remaining <= 0) return;
    // Com data limite, recalcula do relógio a cada tique: assim a contagem não
    // atrasa se a aba ficar em segundo plano e o navegador estrangular o timer.
    const id = window.setInterval(
      () => setRemaining(deadline ? secondsLeft() : (value) => Math.max(0, value - 1)),
      1000,
    );
    return () => window.clearInterval(id);
  }, [remaining, deadline]);

  const pad = (n: number) => String(n).padStart(2, '0');
  const hours = Math.floor(remaining / 3600);
  const parts = [
    // Horas só aparecem quando existem: "00" fixo à esquerda enfraquece a urgência.
    ...(hours > 0 ? [{ value: hours, label: 'HORAS' }] : []),
    { value: Math.floor((remaining % 3600) / 60), label: 'MIN' },
    { value: remaining % 60, label: 'SEG' },
  ];
  const expired = remaining <= 0;
  const clock = `${hours > 0 ? pad(hours) + ':' : ''}${pad(Math.floor((remaining % 3600) / 60))}:${pad(remaining % 60)}`;

  // Etiqueta: pílula compacta com o relógio corrido.
  if (block.settings.style === 'badge') {
    return (
      <div className="flex justify-center">
        <span
          className="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold"
          style={{ borderRadius: 9999, backgroundColor: withAlpha(theme.primaryColor, 0.12), color: theme.primaryColor }}
        >
          <ClockIcon className="h-3.5 w-3.5" />
          {expired ? 'Oferta expirada' : `${block.settings.message || 'Expira em'} ${clock}`}
        </span>
      </div>
    );
  }

  // Em linha: texto puro, sem moldura.
  if (block.settings.style === 'inline') {
    return (
      <p className="flex items-center justify-center gap-2 text-sm font-semibold" style={{ color: theme.textColor }}>
        <ClockIcon className="h-4 w-4" style={{ color: theme.primaryColor }} />
        {expired ? 'Esta oferta expirou' : block.settings.message || 'Oferta expira em:'}
        {!expired && (
          <span className="tabular-nums font-bold" style={{ color: theme.primaryColor }}>{clock}</span>
        )}
      </p>
    );
  }

  return (
    <div
      className="flex flex-col items-center gap-3 px-4 py-3.5 sm:flex-row sm:justify-between sm:gap-4"
      style={{
        borderRadius: ui.radius,
        backgroundColor: theme.cardBackground,
        border: `1px solid ${ui.border}`,
        boxShadow: ui.shadow,
      }}
    >
      <span className="flex items-center gap-2 text-sm font-semibold" style={{ color: theme.textColor }}>
        <ClockIcon className="h-4 w-4 flex-shrink-0" style={{ color: theme.primaryColor }} />
        {expired ? 'Esta oferta expirou' : block.settings.message || 'Oferta expira em:'}
      </span>

      {!expired && (
        <span className="flex items-end gap-1.5">
          {parts.map((part, i) => (
            <span key={part.label} className="flex items-end gap-1.5">
              {i > 0 && (
                <span
                  className="pb-3 text-lg font-bold leading-none opacity-40"
                  style={{ color: theme.textColor }}
                >
                  :
                </span>
              )}
              <span className="flex flex-col items-center gap-1">
                <span
                  className="min-w-[3rem] px-2.5 py-1.5 text-center text-2xl font-extrabold leading-none tabular-nums"
                  style={{ borderRadius: ui.radiusInner, backgroundColor: theme.primaryColor, color: '#fff' }}
                >
                  {pad(part.value)}
                </span>
                <span
                  className="text-[9px] font-bold tracking-[0.08em]"
                  style={{ color: theme.mutedColor }}
                >
                  {part.label}
                </span>
              </span>
            </span>
          ))}
        </span>
      )}
    </div>
  );
}

export function ModernSplitCheckout({
  product,
  theme,
  payment,
  checkoutSettings,
  onSubmit,
  onApplyCoupon,
  isLoading,
  orderBumps = [],
  selectedBumps = new Set(),
  onToggleBump,
  blocks = [],
  onQuoteShipping,
  texts,
  stickyCta,
}: ModernSplitCheckoutProps) {
  // Nome da plataforma vem da configuracao do sistema (DB), nunca fixo no codigo.
  const platformName = useThemeStore((s) => s.platformName);
  const gatewayLogoUrl = useThemeStore((s) => s.logoUrl) || '/img/gorillapay-logo.webp';
  const [paymentMethod, setPaymentMethod] = useState<'pix' | 'credit_card' | 'boleto'>('pix');
  const [showCoupon, setShowCoupon] = useState(false);
  const [couponCode, setCouponCode] = useState('');
  const [appliedCoupon, setAppliedCoupon] = useState<{ code: string; discount_amount: number } | null>(null);
  const [couponError, setCouponError] = useState('');
  const [couponLoading, setCouponLoading] = useState(false);
  const applyCoupon = async () => {
    if (!onApplyCoupon || !couponCode.trim() || couponLoading) return;
    setCouponLoading(true);
    setCouponError('');
    setAppliedCoupon(null);
    try {
      setAppliedCoupon(await onApplyCoupon(couponCode.trim().toUpperCase()));
    } catch (error: any) {
      setCouponError(error.response?.data?.errors?.coupon_code?.[0] || error.response?.data?.message || 'Não foi possível aplicar este cupom.');
    } finally {
      setCouponLoading(false);
    }
  };

  const {
    register,
    handleSubmit,
    setValue,
    formState: { errors },
  } = useForm<CheckoutFormValues>();

  const [zipLoading, setZipLoading] = useState(false);
  const [zipError, setZipError] = useState<string | null>(null);
  const [shippingOptions, setShippingOptions] = useState<ShippingOption[]>([]);
  const [selectedShippingId, setSelectedShippingId] = useState<number | null>(null);
  const [shippingQuoting, setShippingQuoting] = useState(false);
  const [shippingNotice, setShippingNotice] = useState<string | null>(null);
  const zipRequestId = useRef(0);

  /**
   * Preenche o endereço a partir do CEP usando o ViaCEP (público, sem chave).
   * Falha nunca bloqueia a compra: o cliente segue preenchendo à mão.
   */
  const lookupZipcode = async (digits: string) => {
    const requestId = ++zipRequestId.current;
    setZipLoading(true);
    setZipError(null);
    try {
      const response = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
      const data = await response.json();
      if (requestId !== zipRequestId.current) return;
      if (data?.erro) {
        setZipError('CEP não encontrado. Preencha o endereço manualmente.');
        return;
      }
      if (data?.logradouro) setValue('address.street', data.logradouro);
      if (data?.bairro) setValue('address.neighborhood', data.bairro);
      if (data?.localidade) setValue('address.city', data.localidade);
      if (data?.uf) setValue('address.state', data.uf);

      if (needsShipping && onQuoteShipping) {
        void quoteShipping(digits, requestId);
      }
    } catch {
      if (requestId === zipRequestId.current) setZipError('Não consegui buscar o CEP. Preencha o endereço manualmente.');
    } finally {
      if (requestId === zipRequestId.current) setZipLoading(false);
    }
  };

  /** Busca as opções de entrega para o CEP informado. */
  const quoteShipping = async (digits: string, requestId: number) => {
    if (!onQuoteShipping) return;
    setShippingQuoting(true);
    setShippingNotice(null);
    try {
      const result = await onQuoteShipping(digits);
      if (requestId !== zipRequestId.current) return;
      setShippingOptions(result.options);
      // Pré-seleciona a mais barata: é o que o cliente escolheria na dúvida.
      setSelectedShippingId(result.options[0]?.id ?? null);

      if (result.options.length === 0) {
        setShippingNotice(
          result.reason === 'free_shipping'
            ? null
            : 'Não encontramos opções de entrega para este CEP.',
        );
      }
    } catch {
      if (requestId === zipRequestId.current) setShippingNotice('Não consegui calcular o frete agora.');
    } finally {
      if (requestId === zipRequestId.current) setShippingQuoting(false);
    }
  };

  const enabledBlocks = blocks.filter((block) => block.enabled);
  // O cabeçalho é uma faixa no topo da página, não um card da coluna: sai da
  // lista para não ser renderizado dentro do formulário.
  const headerBlock = enabledBlocks.find((block) => block.type === 'header');
  const columnBlocks = enabledBlocks.filter((block) => block.type !== 'header');
  // Bloco 'produto' é desenhado no resumo, mas seus interruptores valem aqui.
  const productBlock = enabledBlocks.find((block) => block.type === 'product');
  const showProductDescription = productBlock?.settings?.showDescription !== false;
  const paymentMethods = payment?.methods?.length
    ? payment.methods
    : checkoutSettings?.payment_methods?.length
    ? checkoutSettings.payment_methods
    : ['pix'];
  const pixDiscount = payment?.pixDiscount || 0;
  const shippingBlock = enabledBlocks.find((block) => block.type === 'shipping');

  /**
   * Produto físico manda: se ele exige entrega, o endereço é obrigatório mesmo
   * que o vendedor não tenha ligado `collect_address` nem adicionado o bloco de
   * entrega. Sem isso dava para vender físico sem para onde enviar.
   */
  const needsShipping =
    product.shipping_required === true ||
    product.type === 'physical' ||
    shippingBlock?.settings?.type === 'physical';

  const selectedShipping = shippingOptions.find((o) => o.id === selectedShippingId) ?? null;

  /**
   * Ordem de precedência do frete: opção cotada escolhida pelo cliente, depois
   * frete grátis, depois valor fixo do produto, e por último o bloco antigo.
   */
  const shippingCost = (() => {
    if (!needsShipping) return 0;
    if (product.free_shipping) return 0;
    if (selectedShipping) return selectedShipping.price;
    if (typeof product.shipping_price === 'number' && product.shipping_price > 0) {
      return product.shipping_price;
    }
    if (shippingBlock?.settings?.type === 'physical' && !shippingBlock.settings?.freeShipping) {
      return Math.round((Number(shippingBlock.settings?.shippingPrice) || 0) * 100);
    }
    return 0;
  })();
  const bumpTotal = orderBumps
    .filter((bump) => selectedBumps.has(bump.id))
    .reduce((sum, bump) => sum + bump.price, 0);
  const basePrice = !appliedCoupon && paymentMethod === 'pix' && pixDiscount > 0
    ? Math.round(product.price * (1 - pixDiscount / 100))
    : product.price;
  const subtotal = basePrice + bumpTotal;
  const total = subtotal + shippingCost - (appliedCoupon?.discount_amount || 0);
  const orderSummaryItems = useMemo(
    () => orderBumps.filter((bump) => selectedBumps.has(bump.id)),
    [orderBumps, selectedBumps]
  );

  /**
   * Design tokens resolvidos a partir do tema do builder.
   * Tudo que é visual deriva daqui — nada de cor hardcoded, para que o
   * checkout funcione tanto em tema claro quanto escuro.
   */
  const ui = useMemo(() => {
    const radius = radiusMap[theme.borderRadius || 'xl'] || radiusMap.xl;
    return {
      radius,
      // Inputs/badges usam um raio menor que os cards para não ficar "bolha".
      radiusInner: theme.borderRadius === 'full' ? '9999px' : `min(${radius}, 10px)`,
      shadow: shadowMap[theme.shadow || 'lg'] || shadowMap.lg,
      font: `${theme.fontFamily || 'Inter'}, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`,
      border: withAlpha(theme.textColor, 0.12),
      borderStrong: withAlpha(theme.textColor, 0.22),
      subtle: withAlpha(theme.textColor, 0.04),
      primarySoft: withAlpha(theme.primaryColor, 0.08),
      primaryRing: withAlpha(theme.primaryColor, 0.18),
    };
  }, [theme]);

  const cardStyle: React.CSSProperties = {
    backgroundColor: theme.cardBackground,
    borderRadius: ui.radius,
    border: `1px solid ${ui.border}`,
    boxShadow: ui.shadow,
  };

  const inputStyle: React.CSSProperties = {
    backgroundColor: theme.cardBackground,
    color: theme.textColor,
    borderRadius: ui.radiusInner,
    border: `1px solid ${ui.border}`,
  };

  /**
   * `text-base` no mobile é obrigatório: com fonte menor que 16px o iOS dá
   * zoom automático ao focar o campo e desloca a página inteira. Em telas
   * maiores volta a 14px. A altura também é maior no toque.
   */
  const inputClass =
    'w-full px-3.5 py-3 text-base sm:py-2.5 sm:text-sm outline-none transition-shadow placeholder:opacity-60 focus:ring-2';

  /** Respeita buttonStyle do builder (solid / gradient / outline). */
  const primaryButtonStyle = (): React.CSSProperties => {
    const base: React.CSSProperties = { borderRadius: ui.radiusInner };
    switch (theme.buttonStyle) {
      case 'solid':
        return { ...base, background: theme.primaryColor, color: '#ffffff' };
      case 'outline':
        return {
          ...base,
          background: 'transparent',
          border: `2px solid ${theme.primaryColor}`,
          color: theme.primaryColor,
        };
      case 'gradient':
      default:
        return {
          ...base,
          background: `linear-gradient(135deg, ${theme.primaryColor}, ${theme.secondaryColor || theme.primaryColor})`,
          color: '#ffffff',
        };
    }
  };

  const handleFormSubmit = (data: CheckoutFormValues) => {
    if (shippingQuoting || zipLoading) return;
    if (couponLoading) return;
    onSubmit({ ...data, coupon_code: appliedCoupon?.code, payment_method: paymentMethod, display_amount: total, shipping_service_id: selectedShippingId });
  };

  /** Cabeçalho de seção: ícone em pílula + título (padrão do layout). */
  const SectionTitle = ({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) => (
    <h2 className="mb-4 flex items-center gap-2.5 text-[15px] font-semibold" style={{ color: theme.primaryColor }}>
      <span
        className="flex h-7 w-7 items-center justify-center"
        style={{ backgroundColor: ui.primarySoft, borderRadius: ui.radiusInner, color: theme.primaryColor }}
      >
        {icon}
      </span>
      {children}
    </h2>
  );

  const Field = ({
    error,
    children,
  }: {
    error?: string;
    children: React.ReactNode;
  }) => (
    <div>
      {children}
      {error && <p className="mt-1.5 text-xs font-medium text-red-500">{error}</p>}
    </div>
  );

  const renderBanner = (block: CheckoutBlock, compact = false) => {
    const s = block.settings;
    if (!s.imageUrl) return null;

    // 'auto' preserva a proporção original e usa altura máxima; qualquer outro
    // valor fixa a proporção e o recorte fica por conta do object-fit.
    const aspect = s.aspectRatio && s.aspectRatio !== 'auto' ? s.aspectRatio.replace('/', ' / ') : undefined;

    const image = (
      <img
        src={s.imageUrl}
        alt={s.altText || 'Banner'}
        className="w-full"
        style={{
          aspectRatio: aspect,
          objectFit: s.objectFit || 'cover',
          objectPosition: s.objectPosition || 'center',
          borderRadius: radiusMap[s.borderRadius || 'lg'] || radiusMap.lg,
          maxHeight: aspect ? undefined : `${s.maxHeight || 300}px`,
        }}
      />
    );

    const clickable = s.linkUrl ? (
      <a
        href={s.linkUrl}
        target={s.openInNewTab !== false ? '_blank' : '_self'}
        rel={s.openInNewTab !== false ? 'noopener noreferrer' : undefined}
        className="block"
      >
        {image}
      </a>
    ) : image;

    // Largura personalizada + alinhamento dentro da coluna.
    const constrained =
      s.width === 'custom' ? (
        <div
          style={{
            width: `${s.widthPercent ?? 100}%`,
            marginLeft: s.align === 'right' || s.align === 'center' ? 'auto' : undefined,
            marginRight: s.align === 'left' || s.align === 'center' ? 'auto' : undefined,
          }}
        >
          {clickable}
        </div>
      ) : clickable;

    // Sem moldura, o banner encosta no fundo da página — útil para faixas.
    const framed = !compact && s.showCard !== false;

    return (
      <div className={framed ? 'p-2' : ''} style={framed ? cardStyle : undefined}>
        {constrained}
      </div>
    );
  };

  const renderPaymentForm = (block: CheckoutBlock) => (
    // id permite que o botão de compra do resumo (fora do form) submeta este form.
    <form id={CHECKOUT_FORM_ID} onSubmit={handleSubmit(handleFormSubmit)} className="space-y-4">
      {/* Dados pessoais */}
      <div className="p-4 sm:p-5" style={cardStyle}>
        <SectionTitle icon={<UserIcon className="h-4 w-4" />}>Dados pessoais</SectionTitle>

        <div className="space-y-3">
          <Field error={errors.email ? 'E-mail é obrigatório' : undefined}>
            <input
              {...register('email', { required: true })}
              type="email"
              autoComplete="email"
              placeholder="E-mail*"
              className={inputClass}
              style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
            />
          </Field>

          <Field error={errors.name ? 'Nome é obrigatório' : undefined}>
            <input
              {...register('name', { required: true })}
              type="text"
              autoComplete="name"
              placeholder="Nome completo*"
              className={inputClass}
              style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
            />
          </Field>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {checkoutSettings?.collect_phone !== false && (
              <Field error={errors.phone ? 'Celular é obrigatório' : undefined}>
                <input
                  {...register('phone', { required: true })}
                  type="tel"
                  autoComplete="tel"
                  placeholder="Celular*"
                  className={inputClass}
                  style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                />
              </Field>
            )}
            {checkoutSettings?.collect_cpf !== false && (
              <Field error={errors.document ? 'CPF é obrigatório' : undefined}>
                <input
                  {...register('document', { required: true })}
                  type="text"
                  inputMode="numeric"
                  placeholder="CPF*"
                  className={inputClass}
                  style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                />
              </Field>
            )}
          </div>
        </div>
      </div>

      {/* Endereço */}
      {(needsShipping || checkoutSettings?.collect_address) && (
        <div className="p-4 sm:p-5" style={cardStyle}>
          <SectionTitle icon={<TruckIcon className="h-4 w-4" />}>Endereço de entrega</SectionTitle>
          <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="relative">
                <input
                  {...register('address.zipcode', { required: needsShipping ? 'Informe o CEP de entrega' : false })}
                  type="text"
                  inputMode="numeric"
                  maxLength={9}
                  placeholder="CEP*"
                  onChange={(e) => {
                    // Máscara 00000-000 enquanto digita.
                    const digits = e.target.value.replace(/\D/g, '').slice(0, 8);
                    e.target.value = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
                    setValue('address.zipcode', e.target.value);
                    setSelectedShippingId(null);
                    setShippingOptions([]);
                    zipRequestId.current++;
                    setShippingQuoting(false);
                    setZipLoading(false);
                    if (digits.length === 8) lookupZipcode(digits);
                    else if (zipError) setZipError(null);
                  }}
                  className={inputClass}
                  style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                />
                {zipLoading && (
                  <span
                    className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin rounded-full border-2 border-current border-t-transparent"
                    style={{ color: theme.primaryColor }}
                  />
                )}
              </div>
              <input {...register('address.street')} type="text" placeholder="Rua/Avenida" className={`${inputClass} sm:col-span-2`} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
            </div>
            <div className="grid grid-cols-3 gap-3">
              <input {...register('address.number')} type="text" placeholder="Número" className={inputClass} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
              <input {...register('address.complement')} type="text" placeholder="Complemento" className={`${inputClass} col-span-2`} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
            </div>
            <input {...register('address.neighborhood')} type="text" placeholder="Bairro" className={inputClass} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
            <div className="grid grid-cols-4 gap-3">
              <input {...register('address.city')} type="text" placeholder="Cidade" className={`${inputClass} col-span-3`} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
              <input {...register('address.state')} type="text" placeholder="UF" maxLength={2} className={`${inputClass} text-center uppercase`} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
            </div>
            {zipError && <p className="text-xs font-medium text-red-500">{zipError}</p>}

            {/* Opções de entrega cotadas */}
            {needsShipping && !product.free_shipping && (shippingQuoting || shippingOptions.length > 0 || shippingNotice) && (
              <div className="pt-1">
                <p className="mb-2 text-xs font-semibold" style={{ color: theme.textColor }}>
                  Forma de entrega
                </p>

                {shippingQuoting && (
                  <p className="text-xs" style={{ color: theme.mutedColor }}>Calculando frete...</p>
                )}

                {!shippingQuoting && shippingNotice && (
                  <p className="text-xs" style={{ color: theme.mutedColor }}>{shippingNotice}</p>
                )}

                {!shippingQuoting && shippingOptions.length > 0 && (
                  <div className="space-y-2">
                    {shippingOptions.map((option) => {
                      const active = option.id === selectedShippingId;
                      return (
                        <button
                          key={option.id}
                          type="button"
                          onClick={() => setSelectedShippingId(option.id)}
                          className="flex w-full items-center gap-3 p-3 text-left transition-all"
                          style={{
                            borderRadius: ui.radiusInner,
                            border: `1.5px solid ${active ? theme.primaryColor : ui.border}`,
                            backgroundColor: active ? ui.primarySoft : 'transparent',
                          }}
                        >
                          <span
                            className="flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full"
                            style={{ border: `1.5px solid ${active ? theme.primaryColor : ui.borderStrong}` }}
                          >
                            {active && (
                              <span className="h-2 w-2 rounded-full" style={{ backgroundColor: theme.primaryColor }} />
                            )}
                          </span>
                          <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium" style={{ color: theme.textColor }}>
                              {option.company ? `${option.company} · ${option.name}` : option.name}
                            </span>
                            {option.delivery_time != null && (
                              <span className="block text-xs" style={{ color: theme.mutedColor }}>
                                {option.delivery_time === 1
                                  ? 'Chega em 1 dia útil'
                                  : `Chega em até ${option.delivery_time} dias úteis`}
                              </span>
                            )}
                          </span>
                          <span className="flex-shrink-0 text-sm font-bold" style={{ color: theme.primaryColor }}>
                            {formatCurrency(option.price)}
                          </span>
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* Order bumps */}
      {orderBumps.length > 0 && (
        <div className="p-4 sm:p-5" style={cardStyle}>
          <div className="mb-3 flex items-center gap-2">
            <GiftIcon className="h-4 w-4" style={{ color: theme.primaryColor }} />
            <h3 className="text-sm font-semibold" style={{ color: theme.textColor }}>
              Aproveite estas ofertas
            </h3>
          </div>

          <div className="space-y-3">
            {orderBumps.map((bump) => {
              const active = selectedBumps.has(bump.id);
              const hasDiscount = bump.original_price > bump.price;
              const discount =
                bump.discount_percent ||
                (hasDiscount ? Math.round((1 - bump.price / bump.original_price) * 100) : 0);

              return (
                <button
                  type="button"
                  key={bump.id}
                  onClick={() => onToggleBump?.(bump.id)}
                  className="relative w-full overflow-hidden text-left transition-all"
                  style={{
                    borderRadius: ui.radiusInner,
                    // Tracejado quando inativo: sinaliza "oferta opcional" e
                    // separa visualmente do resto do formulário.
                    border: `2px ${active ? 'solid' : 'dashed'} ${active ? theme.primaryColor : ui.borderStrong}`,
                    backgroundColor: active ? ui.primarySoft : 'transparent',
                  }}
                >
                  {discount > 0 && (
                    <span
                      className="absolute right-0 top-0 px-2 py-0.5 text-[10px] font-bold text-white"
                      style={{ backgroundColor: theme.primaryColor, borderBottomLeftRadius: ui.radiusInner }}
                    >
                      -{discount}%
                    </span>
                  )}

                  <div className="flex items-start gap-3 p-3.5">
                    <span
                      className="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center"
                      style={{
                        borderRadius: '5px',
                        border: `1.5px solid ${active ? theme.primaryColor : ui.borderStrong}`,
                        backgroundColor: active ? theme.primaryColor : 'transparent',
                      }}
                    >
                      {active && <CheckIcon className="h-3.5 w-3.5 text-white" strokeWidth={3} />}
                    </span>

                    {bump.image_url && (
                      <img
                        src={bump.image_url}
                        alt=""
                        className="h-16 w-16 flex-shrink-0 object-cover"
                        style={{ borderRadius: ui.radiusInner, border: `1px solid ${ui.border}` }}
                      />
                    )}

                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold leading-snug" style={{ color: theme.textColor }}>
                        {bump.title || bump.product?.name}
                      </span>

                      {bump.description && (
                        <span className="mt-1 block text-xs leading-relaxed" style={{ color: theme.mutedColor }}>
                          {bump.description}
                        </span>
                      )}

                      <span className="mt-2 flex flex-wrap items-baseline gap-2">
                        <span className="text-base font-bold" style={{ color: theme.primaryColor }}>
                          + {formatCurrency(bump.price)}
                        </span>
                        {hasDiscount && (
                          <span className="text-xs line-through" style={{ color: theme.mutedColor }}>
                            {formatCurrency(bump.original_price)}
                          </span>
                        )}
                      </span>

                      <span
                        className="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-semibold"
                        style={{
                          borderRadius: ui.radiusInner,
                          backgroundColor: active ? theme.primaryColor : ui.primarySoft,
                          color: active ? '#ffffff' : theme.primaryColor,
                        }}
                      >
                        {active ? (
                          <>
                            <CheckIcon className="h-3 w-3" strokeWidth={3} />
                            Adicionado ao pedido
                          </>
                        ) : (
                          bump.call_to_action || 'Sim, eu quero!'
                        )}
                      </span>
                    </span>
                  </div>
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* Pagamento */}
      <div className="p-4 sm:p-5" style={cardStyle}>
        <SectionTitle icon={<CreditCardIcon className="h-4 w-4" />}>Pagamento</SectionTitle>

        <div className="mb-4 space-y-2.5">
          {paymentMethods.includes('pix') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('pix')}
              className="flex w-full items-center justify-between px-4 py-3.5 transition-all"
              style={{
                borderRadius: ui.radiusInner,
                border: `1.5px solid ${paymentMethod === 'pix' ? theme.primaryColor : ui.border}`,
                backgroundColor: paymentMethod === 'pix' ? ui.primarySoft : 'transparent',
              }}
            >
              <span className="flex items-center gap-2.5">
                <span
                  className="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px]"
                  style={{ borderColor: paymentMethod === 'pix' ? theme.primaryColor : ui.borderStrong }}
                >
                  {paymentMethod === 'pix' && (
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: theme.primaryColor }} />
                  )}
                </span>
                <span className="text-sm font-semibold" style={{ color: theme.textColor }}>PIX</span>
                {pixDiscount > 0 && (
                  <span
                    className="px-1.5 py-0.5 text-[10px] font-bold"
                    style={{ borderRadius: '4px', backgroundColor: withAlpha(theme.primaryColor, 0.15), color: theme.primaryColor }}
                  >
                    -{pixDiscount}%
                  </span>
                )}
              </span>
              <PixIcon className="h-6 w-6" />
            </button>
          )}

          {paymentMethods.includes('credit_card') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('credit_card')}
              className="flex w-full items-center justify-between px-4 py-3.5 transition-all"
              style={{
                borderRadius: ui.radiusInner,
                border: `1.5px solid ${paymentMethod === 'credit_card' ? theme.primaryColor : ui.border}`,
                backgroundColor: paymentMethod === 'credit_card' ? ui.primarySoft : 'transparent',
              }}
            >
              <span className="flex items-center gap-2.5">
                <span
                  className="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px]"
                  style={{ borderColor: paymentMethod === 'credit_card' ? theme.primaryColor : ui.borderStrong }}
                >
                  {paymentMethod === 'credit_card' && (
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: theme.primaryColor }} />
                  )}
                </span>
                <span className="text-sm font-semibold" style={{ color: theme.textColor }}>Cartão de crédito</span>
              </span>
              <CreditCardIcon className="h-5 w-5" style={{ color: theme.mutedColor }} />
            </button>
          )}

          {paymentMethods.includes('boleto') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('boleto')}
              className="flex w-full items-center justify-between px-4 py-3.5 transition-all"
              style={{
                borderRadius: ui.radiusInner,
                border: `1.5px solid ${paymentMethod === 'boleto' ? theme.primaryColor : ui.border}`,
                backgroundColor: paymentMethod === 'boleto' ? ui.primarySoft : 'transparent',
              }}
            >
              <span className="flex items-center gap-2.5">
                <span
                  className="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px]"
                  style={{ borderColor: paymentMethod === 'boleto' ? theme.primaryColor : ui.borderStrong }}
                >
                  {paymentMethod === 'boleto' && (
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: theme.primaryColor }} />
                  )}
                </span>
                <span className="text-sm font-semibold" style={{ color: theme.textColor }}>Boleto</span>
              </span>
              <LockClosedIcon className="h-5 w-5" style={{ color: theme.mutedColor }} />
            </button>
          )}
        </div>

        {paymentMethod === 'credit_card' && (
          <div className="mb-4 space-y-3">
            <Field error={errors.card?.number?.message}>
              <input
                {...register('card.number', { required: 'Número do cartão é obrigatório' })}
                type="text"
                inputMode="numeric"
                autoComplete="cc-number"
                placeholder="Número do cartão"
                className={inputClass}
                style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
              />
            </Field>

            <Field error={errors.card?.holder_name?.message}>
              <input
                {...register('card.holder_name', { required: 'Nome do titular é obrigatório' })}
                type="text"
                autoComplete="cc-name"
                placeholder="Nome do titular"
                className={inputClass}
                style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
              />
            </Field>

            <div className="grid grid-cols-3 gap-3">
              <Field error={errors.card?.expiry?.message}>
                <input
                  {...register('card.expiry', { required: 'Validade é obrigatória' })}
                  type="text"
                  autoComplete="cc-exp"
                  placeholder="MM/AA"
                  className={inputClass}
                  style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                />
              </Field>
              <Field error={errors.card?.cvv?.message}>
                <input
                  {...register('card.cvv', { required: 'CVV é obrigatório' })}
                  type="text"
                  inputMode="numeric"
                  autoComplete="cc-csc"
                  placeholder="CVV"
                  className={inputClass}
                  style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                />
              </Field>
              <select
                {...register('card.installments')}
                className={inputClass}
                style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                defaultValue={1}
              >
                {Array.from({ length: payment?.maxInstallments || 12 }, (_, index) => index + 1).map((installment) => (
                  <option key={installment} value={installment}>{installment}x</option>
                ))}
              </select>
            </div>
          </div>
        )}

        <button
          type="submit"
          disabled={isLoading}
          className="w-full py-3.5 text-base font-bold transition-all hover:opacity-90 disabled:opacity-50"
          style={primaryButtonStyle()}
        >
          {isLoading
            ? texts?.ctaLoadingLabel || 'Processando...'
            : texts?.ctaLabel || `Finalizar compra • ${formatCurrency(total)}`}
        </button>

        <p className="mt-3 flex items-center justify-center gap-1.5 text-xs" style={{ color: theme.mutedColor }}>
          <LockClosedIcon className="h-3.5 w-3.5" />
          {texts?.securityNote || 'Pagamento seguro com criptografia SSL'}
        </p>
      </div>

      {block.settings.showInstallments !== false && paymentMethod === 'credit_card' && (
        <div className="px-4 py-3 text-xs" style={{ ...cardStyle, color: theme.mutedColor }}>
          Parcelamento disponível em até {payment?.maxInstallments || 12}x.
        </div>
      )}
    </form>
  );

  const renderLeftBlock = (block: CheckoutBlock) => {
    switch (block.type) {
      case 'testimonials':
        return (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <h3 className="mb-3 text-sm font-semibold" style={{ color: theme.textColor }}>
              {block.settings.title || 'O que dizem nossos clientes'}
            </h3>
            <div className="space-y-2.5">
              {(block.settings.items || []).slice(0, block.settings.count || 3).map((item: any, idx: number) => (
                <div key={idx} className="p-3.5" style={{ backgroundColor: ui.subtle, borderRadius: ui.radiusInner }}>
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold" style={{ color: theme.textColor }}>{item.name}</span>
                    <span className="text-xs text-amber-400">{'★'.repeat(item.rating || 5)}</span>
                  </div>
                  <p className="mt-1.5 text-xs leading-relaxed" style={{ color: theme.mutedColor }}>{item.text}</p>
                </div>
              ))}
            </div>
          </div>
        );
      case 'guarantee':
        return (
          <div
            className="flex items-start gap-3 p-4 sm:p-5"
            style={{ ...cardStyle, borderColor: withAlpha(theme.primaryColor, 0.25), backgroundColor: ui.primarySoft }}
          >
            <ShieldCheckIcon className="h-6 w-6 flex-shrink-0" style={{ color: theme.primaryColor }} />
            <div>
              <h3 className="text-sm font-bold" style={{ color: theme.textColor }}>
                {block.settings.title || 'Garantia incondicional'}
              </h3>
              <p className="mt-0.5 text-xs" style={{ color: theme.mutedColor }}>
                {block.settings.days || 7} dias — {block.settings.description || 'se não gostar, devolvemos 100% do seu dinheiro.'}
              </p>
            </div>
          </div>
        );
      // 'trust_badges' nao vira card: os selos ficam no rodape da pagina.
      case 'trust_badges':
        return null;

      case 'urgency': {
        const fg = block.settings.textColor || theme.primaryColor;
        const bg = block.settings.backgroundColor || withAlpha(theme.primaryColor, 0.1);
        const text = block.settings.message || 'Oferta por tempo limitado';

        // Ponto pulsante: sinaliza escassez "ao vivo" sem poluir o texto.
        const dot = (
          <span className="relative flex h-2 w-2 flex-shrink-0">
            <span
              className="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
              style={{ backgroundColor: fg }}
            />
            <span className="relative inline-flex h-2 w-2 rounded-full" style={{ backgroundColor: fg }} />
          </span>
        );

        // Etiqueta: pílula compacta, centralizada, sem ocupar a linha inteira.
        if (block.settings.style === 'badge') {
          return (
            <div className="flex justify-center">
              <span
                className="inline-flex items-center gap-2 px-3.5 py-1.5 text-xs font-bold"
                style={{ borderRadius: 9999, backgroundColor: bg, color: fg, border: `1px solid ${withAlpha(fg, 0.25)}` }}
              >
                {dot}
                {text}
              </span>
            </div>
          );
        }

        // Em linha: só texto, sem moldura — para quem quer discrição.
        if (block.settings.style === 'inline') {
          return (
            <p className="flex items-center justify-center gap-2 text-sm font-semibold" style={{ color: fg }}>
              {dot}
              {text}
            </p>
          );
        }

        // Faixa (padrão): barra de largura total.
        return (
          <div
            className="flex items-center justify-center gap-2.5 px-4 py-2.5 text-center text-sm font-semibold"
            style={{
              borderRadius: ui.radius,
              backgroundColor: bg,
              color: fg,
              border: `1px solid ${withAlpha(fg, 0.22)}`,
            }}
          >
            {dot}
            <span className="leading-snug">{text}</span>
          </div>
        );
      }

      case 'timer':
        return <CountdownBanner block={block} theme={theme} ui={ui} />;

      case 'benefits':
        return (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <h3 className="mb-3 text-sm font-semibold" style={{ color: theme.textColor }}>
              {block.settings.title || 'O que você vai receber:'}
            </h3>
            <ul className="space-y-2.5">
              {(block.settings.items || []).map((item: any, i: number) => (
                <li key={i} className="flex items-start gap-2.5">
                  <span
                    className="mt-0.5 flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full"
                    style={{ backgroundColor: theme.primaryColor }}
                  >
                    <CheckIcon className="h-3 w-3 text-white" strokeWidth={3} />
                  </span>
                  <span className="text-sm leading-snug" style={{ color: theme.textColor }}>
                    {typeof item === 'string' ? item : item.text}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        );

      case 'bonus':
        return (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <div className="mb-3 flex items-center gap-2">
              <GiftIcon className="h-4 w-4" style={{ color: theme.primaryColor }} />
              <h3 className="text-sm font-semibold" style={{ color: theme.textColor }}>
                {block.settings.title || 'Bônus exclusivos'}
              </h3>
            </div>
            <div className="space-y-2">
              {(block.settings.items || []).map((item: any, i: number) => (
                <div
                  key={i}
                  className="flex items-center justify-between gap-3 px-3 py-2.5"
                  style={{ borderRadius: ui.radiusInner, backgroundColor: ui.subtle }}
                >
                  <span className="min-w-0 flex-1 text-sm" style={{ color: theme.textColor }}>
                    {item.name}
                  </span>
                  {item.value && (
                    <span className="flex-shrink-0 text-xs font-semibold line-through" style={{ color: theme.mutedColor }}>
                      {item.value}
                    </span>
                  )}
                  <span
                    className="flex-shrink-0 px-2 py-0.5 text-[10px] font-bold"
                    style={{ borderRadius: ui.radiusInner, backgroundColor: theme.primaryColor, color: '#fff' }}
                  >
                    GRÁTIS
                  </span>
                </div>
              ))}
            </div>
          </div>
        );

      case 'faq':
        return (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <h3 className="mb-3 text-sm font-semibold" style={{ color: theme.textColor }}>
              {block.settings.title || 'Perguntas frequentes'}
            </h3>
            <div className="space-y-2">
              {(block.settings.items || []).map((item: any, i: number) => (
                <details key={i} className="group" style={{ borderTop: i > 0 ? `1px solid ${ui.border}` : undefined }}>
                  <summary
                    className="flex cursor-pointer items-center justify-between gap-3 py-2.5 text-sm font-medium"
                    style={{ color: theme.textColor }}
                  >
                    {item.question}
                    <ChevronDownIcon
                      className="h-4 w-4 flex-shrink-0 transition-transform group-open:rotate-180"
                      style={{ color: theme.mutedColor }}
                    />
                  </summary>
                  <p className="pb-3 text-xs leading-relaxed" style={{ color: theme.mutedColor }}>
                    {item.answer}
                  </p>
                </details>
              ))}
            </div>
          </div>
        );

      case 'video': {
        const url = block.settings.videoUrl;
        if (!url) return null;
        // Aceita link normal do YouTube e converte para embed.
        const embed = url
          .replace('watch?v=', 'embed/')
          .replace('youtu.be/', 'www.youtube.com/embed/');
        return (
          <div className="overflow-hidden p-2" style={cardStyle}>
            <div className="relative w-full" style={{ aspectRatio: '16 / 9' }}>
              <iframe
                src={embed}
                title="Vídeo"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowFullScreen
                className="absolute inset-0 h-full w-full"
                style={{ borderRadius: ui.radiusInner, border: 0 }}
              />
            </div>
          </div>
        );
      }
      case 'shipping':
        return (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <h3 className="mb-1.5 text-sm font-semibold" style={{ color: theme.textColor }}>Entrega</h3>
            <p className="text-xs" style={{ color: theme.mutedColor }}>
              {block.settings.type === 'physical'
                ? block.settings.freeShipping
                  ? 'Frete grátis para este produto.'
                  : `Frete: ${formatCurrency(shippingCost)}`
                : block.settings.message || 'Entrega imediata por e-mail após a confirmação.'}
            </p>
          </div>
        );
      case 'custom_html':
        return block.settings.html ? (
          <div className="p-4 sm:p-5" style={cardStyle}>
            <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(block.settings.html) }} />
          </div>
        ) : null;
      case 'banner':
        return renderBanner(block);
      case 'social_proof':
        return (
          <div className="px-4 py-3 text-xs" style={{ ...cardStyle, color: theme.mutedColor }}>
            {block.settings.names?.slice(0, 2).join(', ') || 'Clientes'} compraram recentemente.
          </div>
        );
      case 'payment':
        return renderPaymentForm(block);
      default:
        return null;
    }
  };

  return (
    <div className="min-h-screen" style={{ backgroundColor: theme.backgroundColor, fontFamily: ui.font }}>
      {headerBlock && (
        <header style={{ borderBottom: `1px solid ${ui.border}`, backgroundColor: theme.cardBackground }}>
          <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5">
            <div className="min-w-0">
              <h1 className="truncate text-lg font-bold" style={{ color: theme.primaryColor }}>
                {headerBlock.settings.title || platformName}
              </h1>
              {headerBlock.settings.showSecureBadge && (
                <span className="mt-0.5 flex items-center gap-1 text-xs font-semibold" style={{ color: theme.primaryColor }}>
                  <LockClosedIcon className="h-3.5 w-3.5" />
                  Compra Segura
                </span>
              )}
            </div>
            {headerBlock.settings.showLogo && headerBlock.settings.logoUrl && (
              <img
                src={headerBlock.settings.logoUrl}
                alt="Logo"
                className="h-9 flex-shrink-0 object-contain"
              />
            )}
          </div>
        </header>
      )}

      <div className="mx-auto max-w-6xl px-3 py-4 sm:px-4 sm:py-6 lg:py-10">
        {/* 7/5 em vez de 50/50: o formulário é o foco, o resumo é apoio. */}
        <div className="grid gap-4 sm:gap-5 lg:grid-cols-12 lg:gap-8">
          {/* Resumo aparece primeiro no mobile (contexto antes de preencher) */}
          <aside className="lg:order-2 lg:col-span-5">
            <div className="lg:sticky lg:top-6">
              <div className="p-4 sm:p-5" style={cardStyle}>
                {/* Título do resumo: só aparece se configurado na aba Config */}
                {texts?.summaryTitle && (
                  <h2
                    className="mb-3 text-sm font-semibold"
                    style={{ color: theme.textColor }}
                  >
                    {texts.summaryTitle}
                  </h2>
                )}

                {/* Produto */}
                <div className="flex gap-4 pb-4" style={{ borderBottom: `1px solid ${ui.border}` }}>
                  {product.image_url && (
                    <img
                      src={product.image_url}
                      alt={product.name}
                      className="h-20 w-20 flex-shrink-0 object-cover"
                      style={{ borderRadius: ui.radiusInner }}
                    />
                  )}
                  <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold leading-snug" style={{ color: theme.textColor }}>
                      {product.name}
                    </h3>
                    {/* Descrição respeita o interruptor do bloco Produto. */}
                    {showProductDescription && product.description && (
                      <div
                        className="checkout-description mt-1 text-xs leading-relaxed"
                        style={{ color: theme.mutedColor }}
                        dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(product.description) }}
                      />
                    )}
                    <div className="mt-1.5 flex items-baseline gap-2">
                      <span className="text-base font-bold" style={{ color: theme.primaryColor }}>
                        {formatCurrency(basePrice)}
                      </span>
                      {product.originalPrice && product.originalPrice > basePrice && (
                        <span className="text-xs line-through" style={{ color: theme.mutedColor }}>
                          {formatCurrency(product.originalPrice)}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Cupom */}
                <div className="py-3.5" style={{ borderBottom: `1px solid ${ui.border}` }}>
                  <button
                    type="button"
                    onClick={() => setShowCoupon((c) => !c)}
                    className="flex w-full items-center justify-between text-left"
                  >
                    <span className="flex items-center gap-2 text-sm" style={{ color: theme.textColor }}>
                      <TagIcon className="h-4 w-4" style={{ color: theme.primaryColor }} />
                      Tenho um cupom de desconto
                    </span>
                    <ChevronDownIcon
                      className="h-4 w-4 transition-transform"
                      style={{ color: theme.mutedColor, transform: showCoupon ? 'rotate(180deg)' : undefined }}
                    />
                  </button>
                  {showCoupon && (
                    <div className="mt-3 flex gap-2">
                      <input
                        type="text"
                        placeholder="Digite seu cupom"
                        aria-label="Código do cupom"
                        value={couponCode}
                        disabled={couponLoading || isLoading}
                        onChange={(event) => { setCouponCode(event.target.value); setAppliedCoupon(null); setCouponError(''); }}
                        className={inputClass}
                        style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                      />
                      <button
                        type="button"
                        className="flex-shrink-0 px-4 text-sm font-semibold"
                        style={primaryButtonStyle()}
                        onClick={applyCoupon}
                        disabled={couponLoading || isLoading || !couponCode.trim() || !onApplyCoupon}
                      >
                        {couponLoading ? 'Aplicando...' : 'Aplicar'}
                      </button>
                    </div>
                  )}
                  {couponError && <p role="alert" className="mt-2 text-xs text-red-500">{couponError}</p>}
                  {appliedCoupon && <p role="status" className="mt-2 text-sm" style={{ color: theme.primaryColor }}>Cupom {appliedCoupon.code}: −{formatCurrency(appliedCoupon.discount_amount)}</p>}
                </div>

                {/* Bumps selecionados */}
                {orderSummaryItems.length > 0 && (
                  <div className="space-y-2 py-3.5" style={{ borderBottom: `1px solid ${ui.border}` }}>
                    {orderSummaryItems.map((bump) => (
                      <div key={bump.id} className="flex justify-between gap-3 text-xs">
                        <span style={{ color: theme.mutedColor }}>{bump.title}</span>
                        <span className="font-semibold" style={{ color: theme.textColor }}>
                          {formatCurrency(bump.price)}
                        </span>
                      </div>
                    ))}
                  </div>
                )}

                {/* Totais */}
                <div className="space-y-2 pt-3.5 text-sm">
                  <div className="flex justify-between">
                    <span style={{ color: theme.mutedColor }}>Subtotal</span>
                    <span style={{ color: theme.textColor }}>{formatCurrency(subtotal)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span style={{ color: theme.mutedColor }}>Entrega</span>
                    <span style={{ color: shippingCost === 0 ? theme.primaryColor : theme.textColor }}>
                      {shippingCost === 0 ? 'Grátis' : formatCurrency(shippingCost)}
                    </span>
                  </div>
                  <div
                    className="flex items-baseline justify-between pt-3"
                    style={{ borderTop: `1px solid ${ui.border}` }}
                  >
                    <span className="text-sm font-semibold" style={{ color: theme.textColor }}>Total</span>
                    <span className="text-xl font-bold" style={{ color: theme.primaryColor }}>
                      {formatCurrency(total)}
                    </span>
                  </div>
                </div>

                {/* Sem CTA aqui: o único botão de compra fica ao fim do
                    formulário, para não haver dois pontos de finalização. */}
              </div>

            </div>
          </aside>

          {/* Formulário */}
          <main className="space-y-4 lg:order-1 lg:col-span-7">
            {columnBlocks.map((block) => (
              <div key={block.id}>{renderLeftBlock(block)}</div>
            ))}
          </main>
        </div>
      </div>

      {/*
        Barra fixa no celular: o botão do formulário fica longe depois que o
        cliente rola, e este atalho submete o mesmo form via `form={id}`.
        Só no mobile — no desktop o resumo já acompanha a rolagem.
      */}
      {stickyCta && (
        <div
          className="fixed inset-x-0 bottom-0 z-40 border-t p-3 lg:hidden"
          style={{ borderColor: ui.border, backgroundColor: theme.cardBackground, boxShadow: ui.shadow }}
        >
          <div className="flex items-center gap-3">
            <div className="min-w-0">
              <p className="text-[10px] uppercase tracking-wide" style={{ color: theme.mutedColor }}>Total</p>
              <p className="text-base font-bold leading-none" style={{ color: theme.primaryColor }}>
                {formatCurrency(total)}
              </p>
            </div>
            <button
              type="submit"
              form={CHECKOUT_FORM_ID}
              disabled={isLoading}
              className="flex-1 py-3 text-sm font-bold transition-all disabled:opacity-50"
              style={primaryButtonStyle()}
            >
              {isLoading ? texts?.ctaLoadingLabel || 'Processando...' : texts?.ctaLabel || 'Finalizar compra'}
            </button>
          </div>
        </div>
      )}

      {/* Espaço para a barra fixa não cobrir o rodapé */}
      {stickyCta && <div className="h-20 lg:hidden" aria-hidden />}

      <footer className="mt-8" style={{ borderTop: `1px solid ${ui.border}`, backgroundColor: theme.cardBackground }}>
        <div className="mx-auto max-w-6xl px-4 py-8">
          {/* Selos de confiança */}
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {[
              { icon: LockClosedIcon, title: 'Conexão SSL', text: 'Dados criptografados de ponta a ponta' },
              { icon: ShieldCheckIcon, title: 'Compra segura', text: 'Ambiente protegido contra fraudes' },
              { icon: CheckBadgeIcon, title: 'Satisfação garantida', text: 'Reembolso dentro do prazo de garantia' },
              { icon: CreditCardIcon, title: 'Pagamento protegido', text: 'Não armazenamos dados do seu cartão' },
            ].map(({ icon: Icon, title, text }) => (
              <div key={title} className="flex flex-col items-center gap-2 text-center">
                <span
                  className="flex h-10 w-10 items-center justify-center"
                  style={{ backgroundColor: ui.primarySoft, borderRadius: ui.radiusInner }}
                >
                  <Icon className="h-5 w-5" style={{ color: theme.primaryColor }} />
                </span>
                <span className="text-xs font-semibold" style={{ color: theme.textColor }}>
                  {title}
                </span>
                <span className="text-[11px] leading-snug" style={{ color: theme.mutedColor }}>
                  {text}
                </span>
              </div>
            ))}
          </div>

          <div className="my-6" style={{ borderTop: `1px solid ${ui.border}` }} />

          <div className="mb-7 flex items-center justify-center">
            <img
              src={gatewayLogoUrl}
              alt={platformName}
              className="h-14 w-auto max-w-[220px] object-contain"
            />
          </div>

          <div className="flex flex-col items-center gap-4 text-center sm:flex-row sm:justify-between sm:text-left">
            <div className="min-w-0">
              <p className="text-[11px] leading-relaxed" style={{ color: theme.mutedColor }}>
                Este site é apenas o processador do pagamento. A responsabilidade pela entrega e pelo suporte
                do produto é do vendedor.
              </p>
            </div>

            <nav className="flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
              {[
                { label: 'Termos de uso', href: '/terms' },
                { label: 'Privacidade', href: '/privacy' },
                { label: 'Ajuda', href: '/ajuda' },
              ].map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-xs underline-offset-2 transition-opacity hover:opacity-70 hover:underline"
                  style={{ color: theme.mutedColor }}
                >
                  {link.label}
                </a>
              ))}
            </nav>
          </div>

          <p className="mt-5 text-center text-[11px]" style={{ color: theme.mutedColor }}>
            © {new Date().getFullYear()} {platformName}. Todos os direitos reservados.
          </p>
        </div>
      </footer>
    </div>
  );
}
