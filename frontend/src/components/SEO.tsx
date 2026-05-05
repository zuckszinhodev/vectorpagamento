import { Helmet } from 'react-helmet-async';
import { SEO_CONFIG, getFullUrl } from '@/config/seo';

interface SEOProps {
  title?: string;
  description?: string;
  image?: string;
  url?: string;
  type?: string;
  keywords?: string;
}

export default function SEO({
  title = SEO_CONFIG.siteTitle,
  description = SEO_CONFIG.siteDescription,
  image: _image = SEO_CONFIG.defaultImage,
  url = '',
  type = 'website',
  keywords = SEO_CONFIG.defaultKeywords,
}: SEOProps) {
  const fullTitle = title.includes('Vector Pagamentos') ? title : `${title} | Vector Pagamentos`;
  const fullUrl = url ? getFullUrl(url) : SEO_CONFIG.baseUrl;

  return (
    <Helmet>
      {/* Primary Meta Tags */}
      <title>{fullTitle}</title>
      <meta name="title" content={fullTitle} />
      <meta name="description" content={description} />
      <meta name="keywords" content={keywords} />
      
      {/* Open Graph / Facebook */}
      <meta property="og:type" content={type} />
      <meta property="og:url" content={fullUrl} />
      <meta property="og:title" content={fullTitle} />
      <meta property="og:description" content={description} />
      
      {/* Twitter */}
      <meta name="twitter:card" content="summary" />
      <meta name="twitter:url" content={fullUrl} />
      <meta name="twitter:title" content={fullTitle} />
      <meta name="twitter:description" content={description} />
      
      {/* Canonical */}
      <link rel="canonical" href={fullUrl} />
    </Helmet>
  );
}
