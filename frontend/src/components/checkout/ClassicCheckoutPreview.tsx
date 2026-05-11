import { formatCurrency } from '@/lib/api';
import DOMPurify from 'dompurify';

interface Product {
  id?: string;
  name?: string;
  description?: string;
  price?: number;
  originalPrice?: number;
  image_url?: string;
}

interface BlockSettings {
  [key: string]: any;
}

interface CheckoutBlock {
  id: string;
  type: string;
  enabled: boolean;
  settings: BlockSettings;
}

interface Theme {
  primaryColor?: string;
  secondaryColor?: string;
  backgroundColor?: string;
  cardBackground?: string;
  textColor?: string;
  mutedColor?: string;
  borderRadius?: string;
  buttonStyle?: string;
}

interface ClassicCheckoutPreviewProps {
  theme: Theme;
  product?: Product | null;
  blocks: CheckoutBlock[];
}

const getBorderRadiusValue = (value?: string) => {
  switch (value) {
    case 'none': return '0px';
    case 'sm': return '4px';
    case 'md': return '6px';
    case 'lg': return '8px';
    case 'xl': return '12px';
    case '2xl': return '16px';
    case 'full': return '9999px';
    default: return '12px';
  }
};

export function ClassicCheckoutPreview({ theme, product, blocks }: ClassicCheckoutPreviewProps) {
  const enabledBlocks = (blocks || []).filter((block) => block.enabled);

  if (enabledBlocks.length === 0) {
    return (
      <div className="p-6 text-center text-sm text-tx-muted" style={{ backgroundColor: theme.cardBackground, borderRadius: getBorderRadiusValue(theme.borderRadius), border: '1px solid #e5e7eb' }}>
        Ative blocos no painel esquerdo para visualizar o checkout clássico.
      </div>
    );
  }

  return (
    <div
      className="mx-auto"
      style={{
        backgroundColor: theme.cardBackground,
        borderRadius: getBorderRadiusValue(theme.borderRadius),
        boxShadow: '0 10px 20px rgba(0, 0, 0, 0.12)',
        overflow: 'hidden',
        border: '1px solid #e5e7eb',
      }}
    >
      {enabledBlocks.map((block) => {
        const settings = block.settings || {};

        switch (block.type) {
          case 'header':
            return (
              <div key={block.id} className="flex items-center justify-between gap-2 p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <div>
                  <h1 className="text-xl font-bold" style={{ color: theme.primaryColor || '#22c55e' }}>
                    {settings.title || product?.name || 'Vector Pagamentos'}
                  </h1>
                  {settings.showSecureBadge && (
                    <span className="text-xs font-semibold text-status-success">Compra Segura</span>
                  )}
                </div>
                {settings.showLogo && settings.logoUrl && (
                  <img src={settings.logoUrl} alt="Logo" className="h-10 object-contain" />
                )}
              </div>
            );

          case 'product':
            if (!product) return null;
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                {settings.showImage && product.image_url && (
                  <img src={product.image_url} alt={product.name} className="mb-3 w-full rounded-lg object-cover" />
                )}
                <h2 className="text-2xl font-bold" style={{ color: theme.textColor || '#1e293b' }}>
                  {product.name}
                </h2>
                {settings.showDescription && product.description && (
                  <p className="mt-2 text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                    {product.description}
                  </p>
                )}
                <div className="mt-3 flex items-end gap-3">
                  <span className="text-2xl font-bold" style={{ color: theme.primaryColor || '#22c55e' }}>
                    {formatCurrency(product.price || 0)}
                  </span>
                  {settings.showOriginalPrice && product.originalPrice && (
                    <span className="text-sm line-through" style={{ color: theme.mutedColor || '#64748b' }}>
                      {formatCurrency(product.originalPrice)}
                    </span>
                  )}
                </div>
              </div>
            );

          case 'timer':
            return (
              <div key={block.id} className="p-4 border-b bg-yellow-50" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <p className="font-semibold" style={{ color: theme.primaryColor || '#22c55e' }}>
                  {settings.message || 'Oferta expira em:'}
                </p>
              </div>
            );

          case 'benefits':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <h3 className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>
                  {settings.title || 'Benefícios'}
                </h3>
                <ul className="mt-2 list-disc pl-5 text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  {(settings.items || []).map((item: any, idx: number) => (
                    <li key={idx}>{item.text}</li>
                  ))}
                </ul>
              </div>
            );

          case 'bonus':
            return (
              <div key={block.id} className="p-4 border-b bg-surface-hover" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <h3 className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>Bônus</h3>
                <ul className="mt-2 list-disc pl-5 text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  {(settings.items || []).map((item: any, idx: number) => (
                    <li key={idx}>{`${item.name} - ${item.value}`}</li>
                  ))}
                </ul>
              </div>
            );

          case 'guarantee':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <p className="text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  {settings.title || 'Garantia Incondicional'}: {settings.days || 7} dias.
                </p>
              </div>
            );

          case 'testimonials':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <h3 className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>Depoimentos</h3>
                <div className="mt-2 space-y-2">
                  {(settings.items || []).slice(0, 3).map((item: any, idx: number) => (
                    <div key={idx} className="rounded-lg bg-surface-card p-3" style={{ border: '1px solid #e2e8f0' }}>
                      <p className="text-sm" style={{ color: theme.mutedColor || '#64748b' }}>{item.text}</p>
                      <p className="mt-1 text-xs font-medium" style={{ color: theme.textColor || '#1e293b' }}>{item.name}</p>
                    </div>
                  ))}
                </div>
              </div>
            );

          case 'faq':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <h3 className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>FAQ</h3>
                <div className="mt-2 space-y-2 text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  {(settings.items || []).map((item: any, idx: number) => (
                    <div key={idx}>
                      <p className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>{item.question}</p>
                      <p>{item.answer}</p>
                    </div>
                  ))}
                </div>
              </div>
            );

          case 'trust_badges':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <h3 className="font-semibold" style={{ color: theme.textColor || '#1e293b' }}>Selos de confiança</h3>
                <div className="mt-2 flex flex-wrap gap-2 text-xs" style={{ color: theme.mutedColor || '#64748b' }}>
                  {(settings.badges || ['compra_segura', 'ssl']).map((badge: string) => (
                    <span key={badge} className="rounded-full bg-gray-100 px-2 py-1">{badge.replace('_', ' ')}</span>
                  ))}
                </div>
              </div>
            );

          case 'social_proof':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <p className="text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  Prova social: {settings.names?.slice(0, 2).join(', ')} compraram agora.
                </p>
              </div>
            );

          case 'shipping':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <p className="text-sm" style={{ color: theme.mutedColor || '#64748b' }}>
                  {settings.message || 'Entrega imediata por e-mail após confirmação.'}
                </p>
              </div>
            );

          case 'coupon':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <input type="text" value={settings.placeholder || 'Digite seu cupom'} disabled className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm" />
              </div>
            );

          case 'custom_html':
            return (
              <div key={block.id} className="p-4 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
                <div dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(settings.html || '') }} />
              </div>
            );

          default:
            return null;
        }
      })}

      {/* Dados Pessoais */}
      <div className="p-5 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
        <div className="flex items-center gap-2 mb-4">
          <svg className="h-5 w-5" style={{ color: theme.primaryColor || '#22c55e' }} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
          </svg>
          <span className="text-sm font-semibold" style={{ color: theme.primaryColor || '#22c55e' }}>Dados Pessoais</span>
        </div>
        <div className="space-y-3">
          <input
            type="text"
            placeholder="E-mail*"
            disabled
            className="w-full border bg-white px-3 py-2.5 text-sm text-gray-400"
            style={{ borderRadius: getBorderRadiusValue(theme.borderRadius), borderColor: '#e2e8f0' }}
          />
          <input
            type="text"
            placeholder="Nome completo*"
            disabled
            className="w-full border bg-white px-3 py-2.5 text-sm text-gray-400"
            style={{ borderRadius: getBorderRadiusValue(theme.borderRadius), borderColor: '#e2e8f0' }}
          />
          <div className="flex gap-3">
            <input
              type="text"
              placeholder="Celular*"
              disabled
              className="w-full border bg-white px-3 py-2.5 text-sm text-gray-400"
              style={{ borderRadius: getBorderRadiusValue(theme.borderRadius), borderColor: '#e2e8f0' }}
            />
            <input
              type="text"
              placeholder="CPF*"
              disabled
              className="w-full border bg-white px-3 py-2.5 text-sm text-gray-400"
              style={{ borderRadius: getBorderRadiusValue(theme.borderRadius), borderColor: '#e2e8f0' }}
            />
          </div>
        </div>
      </div>

      {/* Pagamento */}
      <div className="p-5 border-b" style={{ borderColor: `${theme.primaryColor || '#22c55e'}20` }}>
        <div className="flex items-center gap-2 mb-4">
          <svg className="h-5 w-5" style={{ color: theme.primaryColor || '#22c55e' }} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
          </svg>
          <span className="text-sm font-semibold" style={{ color: theme.primaryColor || '#22c55e' }}>Pagamento</span>
        </div>
        <div className="space-y-2">
          {/* PIX option */}
          <div
            className="flex items-center justify-between border-2 px-4 py-3"
            style={{
              borderColor: theme.primaryColor || '#22c55e',
              borderRadius: getBorderRadiusValue(theme.borderRadius),
              backgroundColor: `${theme.primaryColor || '#22c55e'}08`,
            }}
          >
            <div className="flex items-center gap-3">
              <div
                className="h-4 w-4 rounded-full border-[5px]"
                style={{ borderColor: theme.primaryColor || '#22c55e' }}
              />
              <span className="text-sm font-medium" style={{ color: theme.textColor || '#1e293b' }}>PIX</span>
              <span className="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Aprovação imediata</span>
            </div>
            <svg className="h-6 w-6" style={{ color: theme.primaryColor || '#22c55e' }} viewBox="0 0 24 24" fill="currentColor">
              <path d="M13.152 11.986l3.258-3.259a1.087 1.087 0 011.537 0l.347.347a1.087 1.087 0 010 1.537l-3.259 3.258 3.259 3.258a1.087 1.087 0 010 1.537l-.347.347a1.087 1.087 0 01-1.537 0l-3.258-3.259-3.258 3.259a1.087 1.087 0 01-1.537 0l-.347-.347a1.087 1.087 0 010-1.537l3.259-3.258-3.259-3.258a1.087 1.087 0 010-1.537l.347-.347a1.087 1.087 0 011.537 0l3.258 3.259z" />
            </svg>
          </div>

        </div>
      </div>

      <div className="p-5" style={{ borderTop: `1px solid ${theme.primaryColor || '#22c55e'}20` }}>
        <button
          className="w-full py-3.5 text-sm font-bold text-white"
          style={{
            background: theme.buttonStyle === 'gradient'
              ? `linear-gradient(135deg, ${theme.primaryColor || '#22c55e'}, ${theme.secondaryColor || '#22c55e'})`
              : theme.primaryColor || '#22c55e',
            borderRadius: getBorderRadiusValue(theme.borderRadius),
          }}
        >
          PAGAR AGORA
        </button>
        <div className="mt-3 flex items-center justify-center gap-1 text-xs" style={{ color: theme.mutedColor || '#94a3b8' }}>
          <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
          </svg>
          <span>Pagamento 100% seguro</span>
        </div>
      </div>
    </div>
  );
}
