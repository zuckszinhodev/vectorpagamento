import React, { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import DOMPurify from 'dompurify';
import { formatCurrency } from '@/lib/api';
import {
  ShieldCheckIcon,
  LockClosedIcon,
  CreditCardIcon,
  TruckIcon,
  QuestionMarkCircleIcon,
  StarIcon,
  GiftIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';

interface CheckoutBlock {
  id: string;
  type: string;
  enabled: boolean;
  settings: Record<string, any>;
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
  theme: {
    primaryColor: string;
    secondaryColor: string;
    backgroundColor: string;
    cardBackground: string;
    textColor: string;
    mutedColor: string;
  };
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

const radiusMap: Record<string, string> = {
  none: '0px',
  sm: '4px',
  md: '6px',
  lg: '8px',
  xl: '12px',
  '2xl': '16px',
  full: '9999px',
};

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
  } = useForm();

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

  const handleFormSubmit = (data: any) => {
    onSubmit({
      ...data,
      payment_method: paymentMethod,
    });
  };

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
      <div className={compact ? '' : 'rounded-2xl bg-white p-3 shadow-md border border-gray-100'}>
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
    <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-5">
      <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
        <h2 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
          <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
            </svg>
          </div>
          Dados Pessoais
        </h2>

        <div className="space-y-3">
          <div>
            <input
              {...register('email', { required: true })}
              type="email"
              placeholder="E-mail*"
              className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
              style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
            />
            {errors.email && <p className="text-red-500 text-sm mt-2 font-medium">E-mail é obrigatório</p>}
          </div>

          <div>
            <input
              {...register('name', { required: true })}
              type="text"
              placeholder="Nome completo*"
              className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
              style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
            />
            {errors.name && <p className="text-red-500 text-sm mt-2 font-medium">Nome é obrigatório</p>}
          </div>

          <div className="grid grid-cols-2 gap-4">
            {checkoutSettings?.collect_phone !== false && (
              <div>
                <input
                  {...register('phone', { required: true })}
                  type="tel"
                  placeholder="Celular*"
                  className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                  style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
                />
              </div>
            )}
            {checkoutSettings?.collect_cpf !== false && (
              <div>
                <input
                  {...register('document', { required: true })}
                  type="text"
                  placeholder="CPF*"
                  className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                  style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
                />
              </div>
            )}
          </div>
        </div>
      </div>

      {(checkoutSettings?.collect_address || product.shipping_required || shippingBlock?.settings?.type === 'physical') && (
        <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
          <h2 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
            <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
              <TruckIcon className="w-5 h-5" />
            </div>
            Endereço de Entrega
          </h2>
          <div className="space-y-3">
            <input {...register('address.zipcode')} type="text" placeholder="CEP" className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
            <input {...register('address.street')} type="text" placeholder="Rua/Avenida" className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
            <div className="grid grid-cols-3 gap-3">
              <input {...register('address.number')} type="text" placeholder="Número" className="px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
              <input {...register('address.complement')} type="text" placeholder="Complemento" className="col-span-2 px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
            </div>
            <input {...register('address.neighborhood')} type="text" placeholder="Bairro" className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
            <div className="grid grid-cols-4 gap-3">
              <input {...register('address.city')} type="text" placeholder="Cidade" className="col-span-3 px-4 py-3 border-2 border-gray-200 rounded-lg text-sm" />
              <input {...register('address.state')} type="text" placeholder="UF" className="px-4 py-3 border-2 border-gray-200 rounded-lg text-sm text-center" />
            </div>
          </div>
        </div>
      )}

      {orderBumps.length > 0 && (
        <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
          <h3 className="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
            <span className="text-xl">🎁</span>
            Aproveite estas ofertas especiais
          </h3>
          <div className="space-y-3">
            {orderBumps.map((bump) => (
              <div
                key={bump.id}
                onClick={() => onToggleBump?.(bump.id)}
                className="relative cursor-pointer rounded-2xl border-2 p-4 transition-all hover:shadow-lg"
                style={{
                  borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#e5e7eb',
                  backgroundColor: selectedBumps.has(bump.id) ? `${theme.primaryColor}08` : '#ffffff',
                }}
              >
                <div className="flex items-start gap-4">
                  <div
                    className="mt-1 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md border-2"
                    style={{
                      borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#d1d5db',
                      backgroundColor: selectedBumps.has(bump.id) ? theme.primaryColor : 'transparent',
                    }}
                  >
                    {selectedBumps.has(bump.id) && <span className="text-xs font-bold text-white">✓</span>}
                  </div>
                  <div className="flex-1">
                    <p className="font-bold text-sm text-gray-900">{bump.call_to_action || bump.title}</p>
                    {bump.description && <p className="text-xs mt-1 text-gray-600">{bump.description}</p>}
                    <div className="mt-2 flex items-center gap-2">
                      <span className="font-bold text-base" style={{ color: theme.primaryColor }}>
                        + {formatCurrency(bump.price)}
                      </span>
                      {bump.original_price && bump.original_price > bump.price && (
                        <span className="text-xs line-through text-gray-400">{formatCurrency(bump.original_price)}</span>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
        <h2 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
          <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
            <CreditCardIcon className="w-5 h-5" />
          </div>
          Pagamento
        </h2>

        <div className="space-y-4 mb-6">
          {paymentMethods.includes('pix') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('pix')}
              className="w-full flex items-center justify-between px-6 py-5 border-2 rounded-2xl transition-all"
              style={{
                borderColor: paymentMethod === 'pix' ? theme.primaryColor : '#e5e7eb',
                backgroundColor: paymentMethod === 'pix' ? `${theme.primaryColor}10` : 'white',
              }}
            >
              <div className="flex items-center gap-3">
                <span className="font-bold text-lg">PIX</span>
                {pixDiscount > 0 && <span className="text-xs font-bold text-green-600">-{pixDiscount}%</span>}
              </div>
              <PixIcon className="h-8 w-8" />
            </button>
          )}

          {paymentMethods.includes('credit_card') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('credit_card')}
              className="w-full flex items-center justify-between px-6 py-5 border-2 rounded-2xl transition-all"
              style={{
                borderColor: paymentMethod === 'credit_card' ? theme.primaryColor : '#e5e7eb',
                backgroundColor: paymentMethod === 'credit_card' ? `${theme.primaryColor}10` : 'white',
              }}
            >
              <span className="font-bold text-lg">Cartão de crédito</span>
              <CreditCardIcon className="h-7 w-7" />
            </button>
          )}

          {paymentMethods.includes('boleto') && (
            <button
              type="button"
              onClick={() => setPaymentMethod('boleto')}
              className="w-full flex items-center justify-between px-6 py-5 border-2 rounded-2xl transition-all"
              style={{
                borderColor: paymentMethod === 'boleto' ? theme.primaryColor : '#e5e7eb',
                backgroundColor: paymentMethod === 'boleto' ? `${theme.primaryColor}10` : 'white',
              }}
            >
              <span className="font-bold text-lg">Boleto</span>
              <LockClosedIcon className="h-7 w-7" />
            </button>
          )}
        </div>

        {paymentMethod === 'credit_card' && (
          <div className="space-y-3 mb-6">
            <input
              {...register('card.number', { required: 'Número do cartão é obrigatório' })}
              type="text"
              inputMode="numeric"
              autoComplete="cc-number"
              placeholder="Número do cartão"
              className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
              style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
            />
            {errors.card?.number && <p className="text-red-500 text-sm mt-1 font-medium">{String(errors.card.number.message)}</p>}

            <input
              {...register('card.holder_name', { required: 'Nome do titular é obrigatório' })}
              type="text"
              autoComplete="cc-name"
              placeholder="Nome do titular"
              className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
              style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
            />
            {errors.card?.holder_name && <p className="text-red-500 text-sm mt-1 font-medium">{String(errors.card.holder_name.message)}</p>}

            <div className="grid grid-cols-3 gap-3">
              <div className="col-span-1">
                <input
                  {...register('card.expiry', { required: 'Validade é obrigatória' })}
                  type="text"
                  autoComplete="cc-exp"
                  placeholder="MM/AA"
                  className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                  style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
                />
                {errors.card?.expiry && <p className="text-red-500 text-sm mt-1 font-medium">{String(errors.card.expiry.message)}</p>}
              </div>

              <div className="col-span-1">
                <input
                  {...register('card.cvv', { required: 'CVV é obrigatório' })}
                  type="text"
                  inputMode="numeric"
                  autoComplete="cc-csc"
                  placeholder="CVV"
                  className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                  style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
                />
                {errors.card?.cvv && <p className="text-red-500 text-sm mt-1 font-medium">{String(errors.card.cvv.message)}</p>}
              </div>

              <div className="col-span-1">
                <select
                  {...register('card.installments')}
                  className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium bg-white"
                  style={{ '--tw-ring-color': theme.primaryColor } as React.CSSProperties}
                  defaultValue={1}
                >
                  {Array.from({ length: payment?.maxInstallments || 12 }, (_, index) => index + 1).map((installment) => (
                    <option key={installment} value={installment}>
                      {installment}x
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        )}

        <button
          type="submit"
          disabled={isLoading}
          className="w-full py-4 rounded-xl text-white font-bold text-lg transition-all hover:opacity-90 disabled:opacity-50"
          style={{ background: `linear-gradient(135deg, ${theme.primaryColor}, ${theme.secondaryColor || theme.primaryColor})` }}
        >
          {isLoading ? 'Processando...' : `Finalizar compra ${formatCurrency(total)}`}
        </button>

        <p className="text-center text-xs text-gray-500 mt-3">Seus dados estão protegidos com criptografia SSL</p>
      </div>

      {block.settings.showInstallments !== false && paymentMethod === 'credit_card' && (
        <div className="rounded-xl bg-white p-4 border border-gray-100 shadow-sm text-sm text-gray-600">
          Parcelamento disponível em até {payment?.maxInstallments || 12}x.
        </div>
      )}

      <div className="flex items-center justify-center gap-4 text-xs text-gray-600 bg-gray-50 rounded-lg p-3">
        <div className="flex items-center gap-1">
          <ShieldCheckIcon className="w-4 h-4 text-green-600" />
          <span className="font-medium">Compra Segura</span>
        </div>
        <div className="flex items-center gap-1">
          <LockClosedIcon className="w-4 h-4 text-green-600" />
          <span className="font-medium">Dados Protegidos</span>
        </div>
      </div>
    </form>
  );

  const renderLeftBlock = (block: CheckoutBlock) => {
    switch (block.type) {
      case 'header':
        return (
          <div className="text-center py-6 px-4 bg-white rounded-2xl shadow-md border border-gray-100">
            {block.settings.logoUrl ? (
              <img src={block.settings.logoUrl} alt="Logo" className="h-16 mx-auto object-contain mb-3" />
            ) : null}
            <h1 className="text-3xl font-bold" style={{ color: theme.primaryColor }}>
              {block.settings.title || product.name}
            </h1>
            {block.settings.showSecureBadge && <p className="mt-2 text-sm font-medium text-green-600">Compra segura</p>}
          </div>
        );
      case 'product':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            {block.settings.showImage && product.image_url && (
              <img src={product.image_url} alt={product.name} className="w-full rounded-xl object-cover mb-4" />
            )}
            <h2 className="text-2xl font-bold" style={{ color: theme.textColor }}>{product.name}</h2>
            {block.settings.showDescription && product.description && (
              <div
                className="mt-3 text-sm leading-6"
                style={{ color: theme.mutedColor }}
                dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(product.description) }}
              />
            )}
            <div className="mt-4 flex items-end gap-3">
              <span className="text-3xl font-bold" style={{ color: theme.primaryColor }}>{formatCurrency(product.price)}</span>
              {block.settings.showOriginalPrice && product.originalPrice && (
                <span className="text-lg line-through" style={{ color: theme.mutedColor }}>{formatCurrency(product.originalPrice)}</span>
              )}
            </div>
          </div>
        );
      case 'urgency':
        return (
          <div className="rounded-xl p-4 text-center font-bold shadow-sm" style={{ backgroundColor: block.settings.backgroundColor || '#fef3c7', color: block.settings.textColor || '#92400e' }}>
            {block.settings.message || 'Últimas vagas disponíveis'}
          </div>
        );
      case 'timer':
        return (
          <div className="rounded-2xl p-5 text-center shadow-md border bg-white border-gray-100">
            <p className="text-sm font-semibold mb-2" style={{ color: theme.textColor }}>{block.settings.message || 'Oferta expira em:'}</p>
            <p className="text-3xl font-bold tabular-nums" style={{ color: theme.primaryColor }}>
              {String(block.settings.hours || 0).padStart(2, '0')}:{String(block.settings.minutes || 15).padStart(2, '0')}:{String(block.settings.seconds || 0).padStart(2, '0')}
            </p>
          </div>
        );
      case 'benefits':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <h3 className="text-lg font-bold mb-4" style={{ color: theme.primaryColor }}>{block.settings.title || 'Benefícios'}</h3>
            <ul className="space-y-3">
              {(block.settings.items || []).map((item: any, idx: number) => (
                <li key={idx} className="flex items-start gap-3 text-sm text-gray-800">
                  <span className="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-full" style={{ backgroundColor: `${theme.primaryColor}20`, color: theme.primaryColor }}>✓</span>
                  <span>{item.text}</span>
                </li>
              ))}
            </ul>
          </div>
        );
      case 'bonus':
        return (
          <div className="rounded-2xl p-5 shadow-md" style={{ backgroundColor: `${theme.primaryColor}12`, border: `2px dashed ${theme.primaryColor}60` }}>
            <h3 className="font-bold text-lg mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
              <GiftIcon className="w-5 h-5" />
              {block.settings.title || 'Bônus Exclusivos'}
            </h3>
            <div className="space-y-3">
              {(block.settings.items || []).map((item: any, idx: number) => (
                <div key={idx} className="flex items-center justify-between bg-white rounded-lg p-3 shadow-sm">
                  <span className="font-bold text-sm text-gray-900">{item.name}</span>
                  <span className="line-through text-gray-500 text-sm">{item.value}</span>
                </div>
              ))}
            </div>
          </div>
        );
      case 'video':
        return block.settings.videoUrl ? (
          <div className="bg-white rounded-2xl p-3 shadow-md border border-gray-100">
            <div className="relative overflow-hidden rounded-xl" style={{ paddingBottom: '56.25%' }}>
              <iframe
                src={block.settings.videoUrl}
                className="absolute top-0 left-0 w-full h-full"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowFullScreen
              />
            </div>
          </div>
        ) : null;
      case 'faq':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <h3 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
              <QuestionMarkCircleIcon className="w-5 h-5" />
              {block.settings.title || 'Perguntas Frequentes'}
            </h3>
            <div className="space-y-3">
              {(block.settings.items || []).map((item: any, idx: number) => (
                <div key={idx} className="border-b border-gray-200 pb-3 last:border-b-0 last:pb-0">
                  <h4 className="font-bold text-sm text-gray-900 mb-1">{item.question}</h4>
                  <p className="text-sm text-gray-600">{item.answer}</p>
                </div>
              ))}
            </div>
          </div>
        );
      case 'testimonials':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <h3 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
              <StarIcon className="w-5 h-5" />
              Depoimentos
            </h3>
            <div className="space-y-4">
              {(block.settings.items || []).slice(0, block.settings.count || 3).map((item: any, idx: number) => (
                <div key={idx} className="rounded-xl bg-gray-50 p-4 border border-gray-100">
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-sm text-gray-900">{item.name}</span>
                    <span className="text-yellow-500">{'★'.repeat(item.rating || 5)}</span>
                  </div>
                  <p className="text-sm text-gray-600 mt-2">{item.text}</p>
                </div>
              ))}
            </div>
          </div>
        );
      case 'guarantee':
        return (
          <div className="bg-gradient-to-br from-green-50 to-emerald-50 rounded-2xl p-5 border border-green-200 shadow-md">
            <h3 className="font-bold text-green-900 text-lg">{block.settings.title || 'Garantia Incondicional'}</h3>
            <p className="text-sm text-green-700 mt-1">{block.settings.days || 7} dias de garantia</p>
            <p className="text-xs text-green-600 mt-1">{block.settings.description || 'Se não gostar, devolvemos 100% do seu dinheiro'}</p>
          </div>
        );
      case 'trust_badges':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <div className="flex flex-wrap items-center gap-3 text-xs text-gray-600">
              <span className="rounded-full bg-gray-100 px-3 py-1">Compra segura</span>
              <span className="rounded-full bg-gray-100 px-3 py-1">SSL</span>
              <span className="rounded-full bg-gray-100 px-3 py-1">Satisfação garantida</span>
            </div>
          </div>
        );
      case 'shipping':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <h3 className="font-bold text-base mb-2" style={{ color: theme.textColor }}>Entrega</h3>
            <p className="text-sm" style={{ color: theme.mutedColor }}>
              {block.settings.type === 'physical'
                ? block.settings.freeShipping
                  ? 'Frete grátis para este produto.'
                  : `Frete: R$ ${Number(block.settings.shippingPrice || 0).toFixed(2)}`
                : block.settings.message || 'Entrega imediata por e-mail após a confirmação.'}
            </p>
          </div>
        );
      case 'coupon':
        return (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <button
              type="button"
              onClick={() => setShowCoupon((current) => !current)}
              className="text-sm font-bold"
              style={{ color: theme.primaryColor }}
            >
              Cupom de desconto
            </button>
            {showCoupon && (
              <div className="mt-3 flex gap-2">
                <input type="text" placeholder={block.settings.placeholder || 'Digite seu cupom'} className="flex-1 px-3 py-2 border-2 border-gray-200 rounded-lg text-sm" />
                <button type="button" className="px-4 py-2 rounded-lg text-sm font-bold text-white" style={{ backgroundColor: theme.primaryColor }}>
                  Aplicar
                </button>
              </div>
            )}
          </div>
        );
      case 'custom_html':
        return block.settings.html ? (
          <div className="bg-white rounded-2xl p-5 shadow-md border border-gray-100">
            <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(block.settings.html) }} />
          </div>
        ) : null;
      case 'banner':
        return renderBanner(block);
      case 'social_proof':
        return (
          <div className="bg-white rounded-2xl p-4 shadow-md border border-gray-100 text-sm" style={{ color: theme.mutedColor }}>
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
    <div className="min-h-screen" style={{ backgroundColor: theme.backgroundColor }}>
      <div className="max-w-7xl mx-auto px-4 py-6">
        <div className="grid lg:grid-cols-2 gap-6">
          <div className="space-y-5">
            {enabledBlocks.map((block) => (
              <div key={block.id}>{renderLeftBlock(block)}</div>
            ))}
          </div>

          <div className="lg:sticky lg:top-4 h-fit">
            <div className="bg-white rounded-2xl p-5 shadow-lg border border-gray-100">
              <div className="pb-4 border-b border-gray-200">
                <div className="flex gap-4">
                  {product.image_url && (
                    <img src={product.image_url} alt={product.name} className="w-20 h-20 object-cover rounded-lg shadow-sm" />
                  )}
                  <div className="flex-1">
                    <h3 className="font-bold text-sm leading-tight">{product.name}</h3>
                    <p className="text-base font-bold mt-1" style={{ color: theme.primaryColor }}>{formatCurrency(basePrice)}</p>
                    {product.originalPrice && <p className="text-xs line-through text-gray-400 mt-1">{formatCurrency(product.originalPrice)}</p>}
                  </div>
                </div>
              </div>

              {orderSummaryItems.length > 0 && (
                <div className="py-4 border-b border-gray-200">
                  <h4 className="text-xs font-bold text-gray-700 mb-3 uppercase">Itens adicionais</h4>
                  <div className="space-y-3">
                    {orderSummaryItems.map((bump) => (
                      <div key={bump.id} className="flex justify-between gap-3 text-sm">
                        <span className="text-gray-700">{bump.title}</span>
                        <span className="font-bold" style={{ color: theme.primaryColor }}>{formatCurrency(bump.price)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              <div className="py-4 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-600">Subtotal</span>
                  <span className="font-bold">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600">Entrega</span>
                  <span className="font-bold text-green-600">{shippingCost === 0 ? 'Grátis' : formatCurrency(shippingCost)}</span>
                </div>
                <div className="flex justify-between pt-3 border-t border-gray-200 text-lg font-bold">
                  <span>Total</span>
                  <span style={{ color: theme.primaryColor }}>{formatCurrency(total)}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
