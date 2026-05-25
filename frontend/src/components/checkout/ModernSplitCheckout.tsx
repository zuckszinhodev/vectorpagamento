import React, { useState } from 'react';
import { useForm } from 'react-hook-form';
import { formatCurrency } from '@/lib/api';
import { ShieldCheckIcon, LockClosedIcon, CreditCardIcon } from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';

interface ModernSplitCheckoutProps {
  product: {
    id: string;
    name: string;
    description?: string;
    price: number;
    originalPrice?: number;
    image_url?: string;
  };
  theme: {
    primaryColor: string;
    secondaryColor: string;
    backgroundColor: string;
    cardBackground: string;
    textColor: string;
    mutedColor: string;
  };
  onSubmit: (data: any) => void;
  isLoading?: boolean;
  orderBumps?: any[];
  selectedBumps?: Set<string>;
  onToggleBump?: (bumpId: string) => void;
  blocks?: any[];
}

export function ModernSplitCheckout({
  product,
  theme,
  onSubmit,
  isLoading,
  orderBumps = [],
  selectedBumps = new Set(),
  onToggleBump,
  blocks = [],
}: ModernSplitCheckoutProps) {
  const [paymentMethod, setPaymentMethod] = useState<'pix' | 'credit_card'>('pix');
  const [showCoupon, setShowCoupon] = useState(false);
  
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm();

  const bumpTotal = orderBumps
    .filter(b => selectedBumps.has(b.id))
    .reduce((sum, b) => sum + b.price, 0);
  
  const subtotal = product.price + bumpTotal;
  const shipping = 0; // Pode ser calculado dinamicamente
  const total = subtotal + shipping;

  const handleFormSubmit = (data: any) => {
    // Add payment method to form data
    onSubmit({
      ...data,
      payment_method: paymentMethod,
    });
  };

  // Filtrar blocos habilitados
  const enabledBlocks = blocks.filter((b: any) => b.enabled);
  
  // Encontrar blocos específicos
  const headerBlock = enabledBlocks.find((b: any) => b.type === 'header');
  const timerBlock = enabledBlocks.find((b: any) => b.type === 'timer');
  const urgencyBlock = enabledBlocks.find((b: any) => b.type === 'urgency');
  const benefitsBlock = enabledBlocks.find((b: any) => b.type === 'benefits');
  const bonusBlock = enabledBlocks.find((b: any) => b.type === 'bonus');
  const videoBlock = enabledBlocks.find((b: any) => b.type === 'video');
  const guaranteeBlock = enabledBlocks.find((b: any) => b.type === 'guarantee');
  const faqBlock = enabledBlocks.find((b: any) => b.type === 'faq');
  const testimonialsBlock = enabledBlocks.find((b: any) => b.type === 'testimonials');
  const trustBadgesBlock = enabledBlocks.find((b: any) => b.type === 'trust_badges');

  return (
    <div className="min-h-screen" style={{ backgroundColor: '#f9fafb' }}>
      {/* Banner superior - configurável pelo bloco header */}
      {headerBlock && (
        <div 
          className="w-full py-2 text-center text-sm font-medium text-white"
          style={{ backgroundColor: theme.primaryColor }}
        >
          {headerBlock.settings.title || product.name}
        </div>
      )}

      {/* Logo/Título */}
      <div className="text-center py-6 px-4">
        {headerBlock?.settings.logoUrl ? (
          <img
            src={headerBlock.settings.logoUrl}
            alt="Logo"
            className="h-16 mx-auto object-contain mb-2"
          />
        ) : (
          <h1 
            className="text-3xl font-bold"
            style={{ color: theme.primaryColor }}
          >
            {product.name}
          </h1>
        )}
      </div>

      {/* Timer de Urgência */}
      {timerBlock && (
        <div className="max-w-7xl mx-auto px-4 mb-6">
          <div 
            className="rounded-xl p-4 text-center shadow-md border"
            style={{ 
              backgroundColor: `${theme.primaryColor}15`,
              borderColor: `${theme.primaryColor}30`
            }}
          >
            <p className="text-sm font-semibold mb-2" style={{ color: theme.textColor }}>
              {timerBlock.settings.message || 'Oferta expira em:'}
            </p>
            <p className="text-3xl font-bold tabular-nums" style={{ color: theme.primaryColor }}>
              {String(timerBlock.settings.hours || 0).padStart(2, '0')}:
              {String(timerBlock.settings.minutes || 15).padStart(2, '0')}:
              {String(timerBlock.settings.seconds || 0).padStart(2, '0')}
            </p>
          </div>
        </div>
      )}

      {/* Mensagem de Urgência */}
      {urgencyBlock && (
        <div className="max-w-7xl mx-auto px-4 mb-6">
          <div 
            className="rounded-lg p-3 text-center font-bold text-sm shadow-sm border"
            style={{ 
              backgroundColor: urgencyBlock.settings.backgroundColor || '#fef3c7',
              color: urgencyBlock.settings.textColor || '#92400e',
              borderColor: urgencyBlock.settings.textColor ? `${urgencyBlock.settings.textColor}30` : '#fbbf2430'
            }}
          >
            {urgencyBlock.settings.message || '🚨 Últimas vagas disponíveis!'}
          </div>
        </div>
      )}

      {/* Vídeo */}
      {videoBlock && videoBlock.settings.videoUrl && (
        <div className="max-w-7xl mx-auto px-4 mb-6">
          <div className="relative rounded-xl overflow-hidden shadow-lg border-2 border-white" style={{ paddingBottom: '56.25%' }}>
            <iframe
              src={videoBlock.settings.videoUrl}
              className="absolute top-0 left-0 w-full h-full"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowFullScreen
            />
          </div>
        </div>
      )}

      {/* Layout dividido */}
      <div className="max-w-7xl mx-auto px-4 pb-8">
        <div className="grid lg:grid-cols-2 gap-6">
          {/* Coluna Esquerda - Formulário */}
          <div className="space-y-5">
            {/* Benefícios */}
            {benefitsBlock && (
              <div className="bg-white rounded-xl p-5 shadow-md border border-gray-100">
                <h3 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                  </svg>
                  {benefitsBlock.settings.title || 'O que você vai receber:'}
                </h3>
                <ul className="space-y-3">
                  {benefitsBlock.settings.items?.map((item: any, idx: number) => (
                    <li key={idx} className="flex items-start gap-3">
                      <div className="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
                        <svg 
                          className="w-4 h-4" 
                          style={{ color: theme.primaryColor }} 
                          fill="currentColor" 
                          viewBox="0 0 20 20"
                        >
                          <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                        </svg>
                      </div>
                      <span className="text-sm text-gray-800 leading-relaxed font-medium">{item.text}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {/* Bônus */}
            {bonusBlock && (
              <div 
                className="rounded-xl p-5 shadow-md relative overflow-hidden"
                style={{ 
                  backgroundColor: `${theme.primaryColor}12`,
                  border: `2px dashed ${theme.primaryColor}60`
                }}
              >
                <h3 className="font-bold text-lg mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
                  <span className="text-2xl">🎁</span>
                  {bonusBlock.settings.title || 'Bônus Exclusivos'}
                </h3>
                <ul className="space-y-3 relative z-10">
                  {bonusBlock.settings.items?.map((item: any, idx: number) => (
                    <li key={idx} className="flex items-center justify-between bg-white rounded-lg p-3 shadow-sm">
                      <span className="flex items-center gap-2">
                        <div className="w-6 h-6 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
                          <svg className="w-4 h-4" style={{ color: theme.primaryColor }} fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                          </svg>
                        </div>
                        <span className="font-bold text-gray-900 text-sm">{item.name}</span>
                      </span>
                      <span className="line-through text-gray-500 font-semibold text-sm">{item.value}</span>
                    </li>
                  ))}
                </ul>
                <div className="mt-4 text-center">
                  <p className="text-sm font-bold px-4 py-2 rounded-lg inline-block" style={{ 
                    color: theme.primaryColor,
                    backgroundColor: 'white'
                  }}>
                    ✨ Tudo isso GRÁTIS hoje! ✨
                  </p>
                </div>
              </div>
            )}

            <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-5">
              {/* Dados Pessoais */}
              <div className="bg-white rounded-xl p-5 shadow-md border border-gray-100">
                <h2 
                  className="text-lg font-bold mb-4 flex items-center gap-2"
                  style={{ color: theme.primaryColor }}
                >
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
                      style={{ 
                        '--tw-ring-color': theme.primaryColor 
                      } as React.CSSProperties}
                    />
                    {errors.email && (
                      <p className="text-red-500 text-sm mt-2 font-medium">E-mail é obrigatório</p>
                    )}
                  </div>

                  <div>
                    <input
                      {...register('name', { required: true })}
                      type="text"
                      placeholder="Nome completo*"
                      className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                      style={{ 
                        '--tw-ring-color': theme.primaryColor 
                      } as React.CSSProperties}
                    />
                    {errors.name && (
                      <p className="text-red-500 text-sm mt-2 font-medium">Nome é obrigatório</p>
                    )}
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <input
                        {...register('phone', { required: true })}
                        type="tel"
                        placeholder="Celular*"
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                        style={{ 
                          '--tw-ring-color': theme.primaryColor 
                        } as React.CSSProperties}
                      />
                    </div>
                    <div>
                      <input
                        {...register('document', { required: true })}
                        type="text"
                        placeholder="CPF*"
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                        style={{ 
                          '--tw-ring-color': theme.primaryColor 
                        } as React.CSSProperties}
                      />
                    </div>
                  </div>
                </div>
              </div>

              {/* Endereço de Entrega */}
              <div className="bg-white rounded-xl p-5 shadow-md border border-gray-100">
                <h2 
                  className="text-lg font-bold mb-4 flex items-center gap-2"
                  style={{ color: theme.primaryColor }}
                >
                  <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
                    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clipRule="evenodd" />
                    </svg>
                  </div>
                  Endereço de Entrega
                </h2>
                
                <div className="space-y-3">
                  <div>
                    <input
                      {...register('address.zipcode')}
                      type="text"
                      placeholder="CEP*"
                      className="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent transition-all text-sm font-medium"
                      style={{ 
                        '--tw-ring-color': theme.primaryColor 
                      } as React.CSSProperties}
                    />
                  </div>
                </div>
              </div>

              {/* Pagamento */}
              <div className="bg-white rounded-xl p-5 shadow-md border border-gray-100">
                <h2 
                  className="text-lg font-bold mb-4 flex items-center gap-2"
                  style={{ color: theme.primaryColor }}
                >
                  <div className="w-8 h-8 rounded-full flex items-center justify-center" style={{ backgroundColor: `${theme.primaryColor}20` }}>
                    <CreditCardIcon className="w-5 h-5" />
                  </div>
                  Pagamento
                </h2>

                {/* Order Bumps - antes do método de pagamento */}
                {orderBumps && orderBumps.length > 0 && (
                  <div className="mb-5 space-y-3">
                    <h3 className="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
                      <span className="text-xl">🎁</span>
                      Aproveite estas ofertas especiais
                    </h3>
                    {orderBumps.map(bump => (
                      <div
                        key={bump.id}
                        onClick={() => onToggleBump?.(bump.id)}
                        className="relative cursor-pointer rounded-2xl border-3 p-5 transition-all hover:shadow-xl transform hover:-translate-y-1"
                        style={{
                          borderWidth: '3px',
                          borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#e5e7eb',
                          backgroundColor: selectedBumps.has(bump.id) ? `${theme.primaryColor}08` : '#ffffff',
                          boxShadow: selectedBumps.has(bump.id) ? `0 8px 20px ${theme.primaryColor}30` : '0 2px 8px rgba(0,0,0,0.08)'
                        }}
                      >
                        {/* Badge de Desconto */}
                        {bump.original_price && bump.original_price > bump.price && (
                          <div 
                            className="absolute -top-3 -right-3 px-4 py-2 rounded-full text-sm font-extrabold text-white shadow-lg z-10 animate-pulse"
                            style={{ backgroundColor: theme.primaryColor }}
                          >
                            {Math.round(((bump.original_price - bump.price) / bump.original_price) * 100)}% OFF
                          </div>
                        )}
                        <div className="flex items-start gap-4">
                          <div
                            className="mt-1 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg border-3 transition-all"
                            style={{
                              borderWidth: '3px',
                              borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : '#d1d5db',
                              backgroundColor: selectedBumps.has(bump.id) ? theme.primaryColor : 'transparent',
                            }}
                          >
                            {selectedBumps.has(bump.id) && (
                              <svg className="h-4 w-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                              </svg>
                            )}
                          </div>
                          <div className="flex-1">
                            <p className="font-extrabold text-base text-gray-900 leading-tight">
                              ✨ {bump.call_to_action || bump.title}
                            </p>
                            {bump.description && (
                              <p className="text-sm mt-2 text-gray-600 leading-relaxed">
                                {bump.description}
                              </p>
                            )}
                            <div className="mt-3 flex items-center gap-3">
                              <span className="font-extrabold text-lg" style={{ color: theme.primaryColor }}>
                                + {formatCurrency(bump.price)}
                              </span>
                              {bump.original_price && bump.original_price > bump.price && (
                                <span className="text-sm line-through text-gray-400 font-semibold">
                                  {formatCurrency(bump.original_price)}
                                </span>
                              )}
                            </div>
                          </div>
                          {(bump.image_url || bump.product?.image_url) && (
                            <img
                              src={bump.image_url || bump.product?.image_url}
                              alt={bump.title}
                              className="h-20 w-20 rounded-xl object-cover flex-shrink-0 border-3 transition-all shadow-md"
                              style={{ 
                                borderWidth: '3px',
                                borderColor: selectedBumps.has(bump.id) ? theme.primaryColor : 'transparent' 
                              }}
                            />
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {/* Método de Pagamento */}
                <div className="space-y-4 mb-6">
                  {/* PIX */}
                  <button
                    type="button"
                    onClick={() => setPaymentMethod('pix')}
                    className={`w-full flex items-center justify-between px-6 py-5 border-3 rounded-2xl transition-all transform hover:scale-[1.02] ${
                      paymentMethod === 'pix'
                        ? 'border-current shadow-xl'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                    style={{ 
                      borderWidth: '3px',
                      borderColor: paymentMethod === 'pix' ? theme.primaryColor : undefined,
                      backgroundColor: paymentMethod === 'pix' ? `${theme.primaryColor}10` : 'white',
                      boxShadow: paymentMethod === 'pix' ? `0 8px 20px ${theme.primaryColor}30` : undefined
                    }}
                  >
                    <div className="flex items-center gap-3">
                      <div 
                        className={`w-7 h-7 rounded-full border-3 flex items-center justify-center ${
                          paymentMethod === 'pix' ? 'border-current' : 'border-gray-300'
                        }`}
                        style={{ 
                          borderWidth: '3px',
                          borderColor: paymentMethod === 'pix' ? theme.primaryColor : undefined 
                        }}
                      >
                        {paymentMethod === 'pix' && (
                          <div 
                            className="w-4 h-4 rounded-full"
                            style={{ backgroundColor: theme.primaryColor }}
                          />
                        )}
                      </div>
                      <span className="font-bold text-lg">PIX</span>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-sm text-green-600 font-bold bg-green-100 px-3 py-1 rounded-full">
                        Aprovação imediata
                      </span>
                      <PixIcon className="h-8 w-8" />
                    </div>
                  </button>


                </div>
              </div>

              {/* Botão de Finalizar */}
              <button
                type="submit"
                disabled={isLoading}
                className="w-full py-4 rounded-xl text-white font-bold text-lg transition-all hover:opacity-90 hover:shadow-lg transform hover:scale-[1.02] disabled:opacity-50 disabled:cursor-not-allowed"
                style={{ 
                  background: `linear-gradient(135deg, ${theme.primaryColor}, ${theme.secondaryColor || theme.primaryColor})`,
                  boxShadow: `0 4px 12px ${theme.primaryColor}40`
                }}
              >
                {isLoading ? (
                  <span className="flex items-center justify-center gap-2">
                    <svg className="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Processando...
                  </span>
                ) : (
                  <span className="flex items-center justify-center gap-2">
                    <ShieldCheckIcon className="h-6 w-6" />
                    Finalizar Compra Segura
                  </span>
                )}
              </button>

              {/* Trust Badges */}
              {trustBadgesBlock && (
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
              )}
            </form>

            {/* Garantia */}
            {guaranteeBlock && (
              <div className="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-5 border-2 border-green-200 shadow-md">
                <div className="flex items-center gap-4">
                  <div className="w-14 h-14 rounded-xl bg-gradient-to-br from-green-400 to-emerald-500 flex items-center justify-center flex-shrink-0 shadow-md">
                    <ShieldCheckIcon className="w-8 h-8 text-white" />
                  </div>
                  <div>
                    <h3 className="font-bold text-green-900 text-lg">
                      {guaranteeBlock.settings.title || 'Garantia Incondicional'}
                    </h3>
                    <p className="text-sm text-green-700 mt-1 font-semibold">
                      {guaranteeBlock.settings.days || 7} dias de garantia
                    </p>
                    <p className="text-xs text-green-600 mt-1">
                      {guaranteeBlock.settings.description || 'Se não gostar, devolvemos 100% do seu dinheiro'}
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* FAQ */}
            {faqBlock && (
              <div className="bg-white rounded-xl p-5 shadow-md border border-gray-100">
                <h3 className="text-lg font-bold mb-4 flex items-center gap-2" style={{ color: theme.primaryColor }}>
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                  </svg>
                  {faqBlock.settings.title || 'Perguntas Frequentes'}
                </h3>
                <div className="space-y-3">
                  {faqBlock.settings.items?.map((item: any, idx: number) => (
                    <div key={idx} className="border-b border-gray-200 pb-3 last:border-0 last:pb-0">
                      <h4 className="font-bold text-sm text-gray-900 mb-2">
                        {item.question}
                      </h4>
                      <p className="text-xs text-gray-600 leading-relaxed">
                        {item.answer}
                      </p>
                    </div>
                  ))}
                </div>
              </div>
            )}

          </div>

          {/* Coluna Direita - Resumo do Pedido */}
          <div className="lg:sticky lg:top-4 h-fit">
            <div className="bg-white rounded-xl p-5 shadow-lg border border-gray-100">
              {/* Produto Principal */}
              <div className="pb-4 border-b border-gray-200">
                <div className="flex gap-4">
                  {product.image_url && (
                    <img
                      src={product.image_url}
                      alt={product.name}
                      className="w-20 h-20 object-cover rounded-lg shadow-sm"
                    />
                  )}
                  <div className="flex-1">
                    <h3 className="font-bold text-sm leading-tight">{product.name}</h3>
                    <p className="text-base font-bold mt-1" style={{ color: theme.primaryColor }}>
                      {formatCurrency(product.price)}
                    </p>
                    <div className="flex items-center gap-2 mt-1">
                      <span className="text-xs text-gray-500">Qtd:</span>
                      <span className="text-xs font-bold bg-gray-100 px-2 py-0.5 rounded">1</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Order Bumps Selecionados */}
              {orderBumps.filter(b => selectedBumps.has(b.id)).length > 0 && (
                <div className="py-4 border-b border-gray-200">
                  <h4 className="text-xs font-bold text-gray-700 mb-3 uppercase">Itens Adicionais</h4>
                  <div className="space-y-3">
                    {orderBumps.filter(b => selectedBumps.has(b.id)).map(bump => (
                      <div key={bump.id} className="flex gap-3">
                        {(bump.image_url || bump.product?.image_url) && (
                          <img
                            src={bump.image_url || bump.product?.image_url}
                            alt={bump.title}
                            className="w-12 h-12 object-cover rounded-lg shadow-sm"
                          />
                        )}
                        <div className="flex-1">
                          <p className="text-xs font-bold text-gray-900 leading-tight">{bump.title}</p>
                          <p className="text-sm font-bold mt-0.5" style={{ color: theme.primaryColor }}>
                            {formatCurrency(bump.price)}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Cupom */}
              <div className="py-4 border-b border-gray-200">
                <button
                  type="button"
                  onClick={() => setShowCoupon(!showCoupon)}
                  className="flex items-center gap-2 text-sm font-bold transition-colors hover:opacity-80"
                  style={{ color: theme.primaryColor }}
                >
                  <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z" />
                    <path fillRule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clipRule="evenodd" />
                  </svg>
                  Cupom de desconto
                  <svg 
                    className={`w-4 h-4 transition-transform ${showCoupon ? 'rotate-180' : ''}`} 
                    fill="currentColor" 
                    viewBox="0 0 20 20"
                  >
                    <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                  </svg>
                </button>
                
                {showCoupon && (
                  <div className="mt-3 flex gap-2">
                    <input
                      type="text"
                      placeholder="Código do cupom"
                      className="flex-1 px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-opacity-50 focus:border-transparent"
                      style={{ 
                        '--tw-ring-color': theme.primaryColor 
                      } as React.CSSProperties}
                    />
                    <button
                      type="button"
                      className="px-4 py-2 rounded-lg text-sm font-bold text-white transition-all hover:opacity-90"
                      style={{ backgroundColor: theme.primaryColor }}
                    >
                      Aplicar
                    </button>
                  </div>
                )}
              </div>

              {/* Totais */}
              <div className="py-4 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-gray-600">Subtotal</span>
                  <span className="font-bold">{formatCurrency(subtotal)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-600">Entrega</span>
                  <span className="font-bold text-green-600">{shipping === 0 ? 'Grátis' : formatCurrency(shipping)}</span>
                </div>
                <div className="flex justify-between pt-3 border-t border-gray-200 text-lg font-bold">
                  <span>Total</span>
                  <span style={{ color: theme.primaryColor }}>{formatCurrency(total)}</span>
                </div>
                <p className="text-xs text-gray-500 text-center pt-1">
                  ou 3x de {formatCurrency(total / 3)} sem juros
                </p>
              </div>

              {/* Selo de Qualidade */}
              <div className="mt-4 pt-4 border-t border-gray-200 text-center">
                <div className="inline-flex items-center gap-2 bg-gradient-to-r from-yellow-50 to-amber-50 rounded-xl px-4 py-3 border border-yellow-200">
                  <div className="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-amber-500 flex items-center justify-center shadow-md">
                    <svg className="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                  </div>
                  <div className="text-left">
                    <p className="font-bold text-sm text-yellow-900">
                      Qualidade Garantida!
                    </p>
                    <div className="flex gap-0.5 mt-0.5">
                      {[...Array(5)].map((_, i) => (
                        <svg key={i} className="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                          <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* Depoimentos - abaixo do selo de qualidade */}
              {testimonialsBlock && (
                <div className="mt-8 pt-8 border-t-2 border-gray-100">
                  <h4 className="text-sm font-extrabold text-gray-800 mb-5 uppercase tracking-wider flex items-center gap-2">
                    <svg className="w-5 h-5" style={{ color: theme.primaryColor }} fill="currentColor" viewBox="0 0 20 20">
                      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                    Depoimentos
                  </h4>
                  <div className="space-y-4 max-h-96 overflow-y-auto pr-2">
                    {testimonialsBlock.settings.items?.slice(0, testimonialsBlock.settings.count || 3).map((testimonial: any, idx: number) => (
                      <div key={idx} className="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-4 border border-gray-200 hover:shadow-md transition-all">
                        <div className="flex items-start gap-3">
                          {testimonial.avatar ? (
                            <img
                              src={testimonial.avatar}
                              alt={testimonial.name}
                              className="w-12 h-12 rounded-full object-cover flex-shrink-0 border-2 border-white shadow-md"
                              onError={(e) => {
                                e.currentTarget.style.display = 'none';
                                const fallback = e.currentTarget.nextElementSibling as HTMLElement;
                                if (fallback) fallback.style.display = 'flex';
                              }}
                            />
                          ) : null}
                          <div 
                            className="w-12 h-12 rounded-full bg-gradient-to-br from-emerald-400 to-pink-400 flex items-center justify-center text-white font-extrabold text-base flex-shrink-0 shadow-md"
                            style={{ display: testimonial.avatar ? 'none' : 'flex' }}
                          >
                            {testimonial.name?.charAt(0) || '?'}
                          </div>
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between mb-2">
                              <p className="text-sm font-bold text-gray-900 truncate">
                                {testimonial.name}
                              </p>
                              <div className="flex gap-0.5 flex-shrink-0 ml-2">
                                {[...Array(testimonial.rating || 5)].map((_, i) => (
                                  <svg key={i} className="w-3.5 h-3.5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                  </svg>
                                ))}
                              </div>
                            </div>
                            <p className="text-xs text-gray-700 italic line-clamp-3 leading-relaxed">
                              "{testimonial.text}"
                            </p>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
