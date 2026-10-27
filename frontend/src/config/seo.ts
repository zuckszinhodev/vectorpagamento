/**
 * SEO Configuration
 * Centralized configuration for SEO meta tags and URLs
 */

// Domínio em que o app está aberto. O canonical precisa apontar para ele:
// um domínio fixo de outra marca fazia o Lighthouse acusar canonicals conflitantes.
const BASE_URL = typeof window !== 'undefined' ? window.location.origin : 'https://gorillapay.tech';

export const SEO_CONFIG = {
  baseUrl: BASE_URL,

  // Site Information
  siteName: 'GorillaPay',
  siteTitle: 'GorillaPay - Gateway de Pagamento Completo',
  siteDescription: 'Plataforma completa de pagamentos com checkout personalizável, sistema de afiliados, gestão de produtos digitais e físicos. Aceite PIX, cartão e boleto.',
  
  // Social Media
  twitterHandle: '@vectorpagamentos',
  
  // Images
  defaultImage: '/img/bannersocial.png',
  logo: '/logo.png',
  
  // Keywords
  defaultKeywords: 'gateway de pagamento, checkout, afiliados, pagamento online, pix, cartão de crédito, boleto, api de pagamento, webhook, gestão financeira, produtos digitais, ecommerce',
  
  // Theme
  themeColor: '#10b981',
  
  // Organization Schema
  organization: {
    name: 'GorillaPay',
    url: BASE_URL,
    logo: `${BASE_URL}/img/logo.png`,
  },
} as const;

/**
 * Get full URL for a path
 */
export function getFullUrl(path: string = ''): string {
  const cleanPath = path.startsWith('/') ? path : `/${path}`;
  return `${SEO_CONFIG.baseUrl}${cleanPath}`;
}

/**
 * Get full image URL
 */
export function getImageUrl(imagePath: string): string {
  if (imagePath.startsWith('http')) {
    return imagePath;
  }
  return getFullUrl(imagePath);
}
