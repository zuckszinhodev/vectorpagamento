/**
 * Detects whether the current host is a custom/subdomain (not the main app).
 * Used to determine if the frontend should show public-facing pages
 * (checkout, member area) instead of the admin dashboard.
 */

const SYSTEM_HOSTS = [
  'localhost',
  '127.0.0.1',
  'app.vectorpagamentos.com',
  'vectorpagamentos.com',
  'www.vectorpagamentos.com',
  'api.vectorpagamentos.com',
  'app.vectorpagamentos.com',
  'vectorpagamentos.com',
  'www.vectorpagamentos.com',
  'api.vectorpagamentos.com',
];

const SYSTEM_SUBDOMAINS = ['api', 'app', 'www', 'checkout', 'pay', 'members', 'mail', 'ftp', 'admin'];

const VECTOR_BASES = ['vectorpagamentos.com', 'vectorpagamentos.com'];

/**
 * Returns true if the current host is a custom domain or Vector Pagamentos subdomain
 * (e.g., teste.vectorpagamentos.com or meusite.com), not the main app.
 */
export function isCustomDomain(): boolean {
  const host = window.location.hostname.toLowerCase();

  // System hosts â†’ not custom
  if (SYSTEM_HOSTS.includes(host)) return false;

  // Localhost with port â†’ not custom
  if (host === 'localhost' || host === '127.0.0.1') return false;

  // Check if it's a Vector Pagamentos subdomain
  for (const base of VECTOR_BASES) {
    if (host.endsWith('.' + base)) {
      const sub = host.slice(0, -(base.length + 1));
      // System subdomains â†’ not custom
      if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return false;
      // User subdomain â†’ custom!
      return true;
    }
  }

  // Any other domain â†’ custom domain
  return true;
}

/**
 * Extracts the Vector Pagamentos subdomain slug if applicable.
 * Returns null if not a Vector Pagamentos subdomain.
 * e.g., "teste.vectorpagamentos.com" â†’ "teste"
 */
export function getSubdomainSlug(): string | null {
  const host = window.location.hostname.toLowerCase();
  for (const base of VECTOR_BASES) {
    if (!host.endsWith('.' + base)) continue;

    const sub = host.slice(0, -(base.length + 1));
    if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return null;
    return sub;
  }

  return null;
}

