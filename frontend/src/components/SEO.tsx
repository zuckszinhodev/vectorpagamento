import { Helmet } from 'react-helmet-async';
import { SEO_CONFIG, getFullUrl, getImageUrl } from '@/config/seo';

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
  image = SEO_CONFIG.defaultImage,
  url = '',
  type = 'website',
  keywords = SEO_CONFIG.defaultKeywords,
}: SEOProps) {
  const fullTitle = title.includes('VectorPagamentos') ? title : `${title} | VectorPagamentos`;
  const fullUrl = url ? getFullUrl(url) : SEO_CONFIG.baseUrl;
  const fullImage = getImageUrl(image);

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
      <meta property="og:image" content={fullImage} />
      <meta property="og:image:secure_url" content={fullImage} />
      
      {/* Twitter */}
      <meta name="twitter:card" content="summary_large_image" />
      <meta name="twitter:url" content={fullUrl} />
      <meta name="twitter:title" content={fullTitle} />
      <meta name="twitter:description" content={description} />
      <meta name="twitter:image" content={fullImage} />
      
      {/* Canonical */}
      <link rel="canonical" href={fullUrl} />
    </Helmet>
  );
}
