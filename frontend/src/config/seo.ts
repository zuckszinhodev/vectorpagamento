/**
 * SEO Configuration
 * Centralized configuration for SEO meta tags and URLs
 */

export const SEO_CONFIG = {
  // Base URL - Change this when deploying to production
  baseUrl: 'https://app.vectorpagamentos.com',
  
  // Site Information
  siteName: 'Vector Pagamentos',
  siteTitle: 'Vector Pagamentos - Gateway de Pagamento Completo',
  siteDescription: 'Plataforma completa de pagamentos com checkout personalizÃ¡vel, sistema de afiliados, gestÃ£o de produtos digitais e fÃ­sicos. Aceite PIX, cartÃ£o e boleto.',
  
  // Social Media
  twitterHandle: '@vectorpagamentos',
  
  // Images
  defaultImage: '/img/bannersocial.png',
  logo: '/logo.png',
  
  // Keywords
  defaultKeywords: 'gateway de pagamento, checkout, afiliados, pagamento online, pix, cartÃ£o de crÃ©dito, boleto, api de pagamento, webhook, gestÃ£o financeira, produtos digitais, ecommerce',
  
  // Theme
  themeColor: '#16a34a',
  
  // Organization Schema
  organization: {
    name: 'Vector Pagamentos',
    url: 'https://app.vectorpagamentos.com',
    logo: 'https://app.vectorpagamentos.com/logo.png',
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


