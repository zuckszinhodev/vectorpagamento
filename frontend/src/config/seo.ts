/**
 * SEO Configuration
 * Centralized configuration for SEO meta tags and URLs
 */

export const SEO_CONFIG = {
  // Base URL - Change this when deploying to production
  baseUrl: 'https://app.vectorpagamentos.com.br',
  
  // Site Information
  siteName: 'VectorPagamentos',
  siteTitle: 'VectorPagamentos - Gateway de Pagamento Completo',
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
    name: 'VectorPagamentos',
    url: 'https://app.vectorpagamentos.com.br',
    logo: 'https://app.vectorpagamentos.com.br/logo.png',
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
