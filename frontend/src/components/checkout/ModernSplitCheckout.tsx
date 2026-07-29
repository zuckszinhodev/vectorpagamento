import React, { useMemo, useState } from 'react';
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
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';

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
  isLoading?: boolean;
  orderBumps?: any[];
  selectedBumps?: Set<string>;
  onToggleBump?: (bumpId: string) => void;
  blocks?: CheckoutBlock[];
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

export function ModernSplitCheckout({
  product,
  theme,
  payment,
  checkoutSettings,
  onSubmit,
  isLoading,
  orderBumps = [],
  selectedBumps = new Set(),
  onToggleBump,
  blocks = [],
}: ModernSplitCheckoutProps) {
  const [paymentMethod, setPaymentMethod] = useState<'pix' | 'credit_card' | 'boleto'>('pix');
  const [showCoupon, setShowCoupon] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<CheckoutFormValues>();

  const enabledBlocks = blocks.filter((block) => block.enabled);
  const paymentMethods = payment?.methods?.length
    ? payment.methods
    : checkoutSettings?.payment_methods?.length
    ? checkoutSettings.payment_methods
    : ['pix'];
  const pixDiscount = payment?.pixDiscount || 0;
  const shippingBlock = enabledBlocks.find((block) => block.type === 'shipping');
  const shippingCost = shippingBlock?.settings?.type === 'physical' && !shippingBlock.settings?.freeShipping
    ? Math.round((Number(shippingBlock.settings?.shippingPrice) || 0) * 100)
    : 0;
  const bumpTotal = orderBumps
    .filter((bump) => selectedBumps.has(bump.id))
    .reduce((sum, bump) => sum + bump.price, 0);
  const basePrice = paymentMethod === 'pix' && pixDiscount > 0
    ? Math.round(product.price * (1 - pixDiscount / 100))
    : product.price;
  const subtotal = basePrice + bumpTotal;
  const total = subtotal + shippingCost;
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

  const inputClass =
    'w-full px-3.5 py-2.5 text-sm outline-none transition-shadow placeholder:opacity-60 focus:ring-2';

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
    onSubmit({ ...data, payment_method: paymentMethod });
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
    if (!block.settings.imageUrl) return null;

    const image = (
      <img
        src={block.settings.imageUrl}
        alt={block.settings.altText || 'Banner'}
        className="w-full object-cover"
        style={{
          borderRadius: radiusMap[block.settings.borderRadius || 'lg'] || radiusMap.lg,
          maxHeight: `${block.settings.maxHeight || 300}px`,
        }}
      />
    );

    return (
      <div className={compact ? '' : 'p-2'} style={compact ? undefined : cardStyle}>
        {block.settings.linkUrl ? (
          <a
            href={block.settings.linkUrl}
            target={block.settings.openInNewTab ? '_blank' : '_self'}
            rel={block.settings.openInNewTab ? 'noopener noreferrer' : undefined}
          >
            {image}
          </a>
        ) : image}
      </div>
    );
  };

  const renderPaymentForm = (block: CheckoutBlock) => (
    // id permite que o botão de compra do resumo (fora do form) submeta este form.
    <form id={CHECKOUT_FORM_ID} onSubmit={handleSubmit(handleFormSubmit)} className="space-y-4">
      {/* Dados pessoais */}
      <div className="p-5" style={cardStyle}>
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
      {(checkoutSettings?.collect_address || product.shipping_required || shippingBlock?.settings?.type === 'physical') && (
        <div className="p-5" style={cardStyle}>
          <SectionTitle icon={<TruckIcon className="h-4 w-4" />}>Endereço de entrega</SectionTitle>
          <div className="space-y-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <input {...register('address.zipcode')} type="text" inputMode="numeric" placeholder="CEP*" className={inputClass} style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }} />
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
          </div>
        </div>
      )}

      {/* Order bumps */}
      {orderBumps.length > 0 && (
        <div className="p-5" style={cardStyle}>
          <h3 className="mb-3 text-sm font-semibold" style={{ color: theme.textColor }}>
            Aproveite estas ofertas
          </h3>
          <div className="space-y-2.5">
            {orderBumps.map((bump) => {
              const active = selectedBumps.has(bump.id);
              return (
                <button
                  type="button"
                  key={bump.id}
                  onClick={() => onToggleBump?.(bump.id)}
                  className="w-full p-3.5 text-left transition-all"
                  style={{
                    borderRadius: ui.radiusInner,
                    border: `1.5px solid ${active ? theme.primaryColor : ui.border}`,
                    backgroundColor: active ? ui.primarySoft : 'transparent',
                  }}
                >
                  <div className="flex items-start gap-3">
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
                    <span className="flex-1">
                      <span className="block text-sm font-semibold" style={{ color: theme.textColor }}>
                        {bump.call_to_action || bump.title}
                      </span>
                      {bump.description && (
                        <span className="mt-0.5 block text-xs" style={{ color: theme.mutedColor }}>
                          {bump.description}
                        </span>
                      )}
                      <span className="mt-1.5 flex items-center gap-2">
                        <span className="text-sm font-bold" style={{ color: theme.primaryColor }}>
                          + {formatCurrency(bump.price)}
                        </span>
                        {bump.original_price > bump.price && (
                          <span className="text-xs line-through" style={{ color: theme.mutedColor }}>
                            {formatCurrency(bump.original_price)}
                          </span>
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
      <div className="p-5" style={cardStyle}>
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
          {isLoading ? 'Processando...' : `Finalizar compra • ${formatCurrency(total)}`}
        </button>

        <p className="mt-3 flex items-center justify-center gap-1.5 text-xs" style={{ color: theme.mutedColor }}>
          <LockClosedIcon className="h-3.5 w-3.5" />
          Pagamento seguro com criptografia SSL
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
          <div className="p-5" style={cardStyle}>
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
            className="flex items-start gap-3 p-5"
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
      case 'trust_badges':
        return (
          <div className="grid grid-cols-3 gap-2 p-4" style={cardStyle}>
            {[
              { icon: ShieldCheckIcon, label: 'Compra segura' },
              { icon: LockClosedIcon, label: 'SSL' },
              { icon: CheckBadgeIcon, label: 'Satisfação garantida' },
            ].map(({ icon: Icon, label }) => (
              <div key={label} className="flex flex-col items-center gap-1.5 text-center">
                <span
                  className="flex h-9 w-9 items-center justify-center"
                  style={{ backgroundColor: ui.primarySoft, borderRadius: ui.radiusInner }}
                >
                  <Icon className="h-5 w-5" style={{ color: theme.primaryColor }} />
                </span>
                <span className="text-[11px] font-medium leading-tight" style={{ color: theme.mutedColor }}>
                  {label}
                </span>
              </div>
            ))}
          </div>
        );
      case 'shipping':
        return (
          <div className="p-5" style={cardStyle}>
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
          <div className="p-5" style={cardStyle}>
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
      <div className="mx-auto max-w-6xl px-4 py-6 lg:py-10">
        {/* 7/5 em vez de 50/50: o formulário é o foco, o resumo é apoio. */}
        <div className="grid gap-5 lg:grid-cols-12 lg:gap-8">
          {/* Resumo aparece primeiro no mobile (contexto antes de preencher) */}
          <aside className="lg:order-2 lg:col-span-5">
            <div className="lg:sticky lg:top-6">
              <div className="p-5" style={cardStyle}>
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
                        className={inputClass}
                        style={{ ...inputStyle, ['--tw-ring-color' as any]: ui.primaryRing }}
                      />
                      <button
                        type="button"
                        className="flex-shrink-0 px-4 text-sm font-semibold"
                        style={primaryButtonStyle()}
                      >
                        Aplicar
                      </button>
                    </div>
                  )}
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

                {/* CTA no resumo: submete o form da outra coluna via form=id */}
                <button
                  type="submit"
                  form={CHECKOUT_FORM_ID}
                  disabled={isLoading}
                  className="mt-4 w-full py-3.5 text-base font-bold transition-all hover:opacity-90 disabled:opacity-50"
                  style={primaryButtonStyle()}
                >
                  {isLoading ? 'Processando...' : `Finalizar compra • ${formatCurrency(total)}`}
                </button>
              </div>

              {/* Selos de confiança sob o resumo */}
              <div className="mt-3 flex items-center justify-center gap-4">
                {[
                  { icon: LockClosedIcon, label: 'SSL' },
                  { icon: ShieldCheckIcon, label: 'Compra segura' },
                  { icon: CheckBadgeIcon, label: 'Dados protegidos' },
                ].map(({ icon: Icon, label }) => (
                  <span key={label} className="flex items-center gap-1.5 text-[11px]" style={{ color: theme.mutedColor }}>
                    <Icon className="h-3.5 w-3.5" style={{ color: theme.primaryColor }} />
                    {label}
                  </span>
                ))}
              </div>
            </div>
          </aside>

          {/* Formulário */}
          <main className="space-y-4 lg:order-1 lg:col-span-7">
            {enabledBlocks.map((block) => (
              <div key={block.id}>{renderLeftBlock(block)}</div>
            ))}
          </main>
        </div>
      </div>
    </div>
  );
}
