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
  'app.vectorpagamentos.com.br',
  'vectorpagamentos.com.br',
  'www.vectorpagamentos.com.br',
  'api.vectorpagamentos.com.br',
];

const SYSTEM_SUBDOMAINS = ['api', 'app', 'www', 'checkout', 'pay', 'members', 'mail', 'ftp', 'admin'];

const VECTOR_PAGAMENTOS_BASES = ['vectorpagamentos.com', 'vectorpagamentos.com.br'];

/**
 * Returns true if the current host is a custom domain or Vector Pagamentos subdomain
 * (e.g., teste.Vector Pagamentos.com.br or meusite.com), not the main app.
 */
export function isCustomDomain(): boolean {
  const host = window.location.hostname.toLowerCase();

  // System hosts → not custom
  if (SYSTEM_HOSTS.includes(host)) return false;

  // Localhost with port → not custom
  if (host === 'localhost' || host === '127.0.0.1') return false;

  const baseDomain = VECTOR_PAGAMENTOS_BASES.find((base) => host.endsWith('.' + base));

  // Check if it's a Vector Pagamentos subdomain
  if (baseDomain) {
    const sub = host.slice(0, -(baseDomain.length + 1));
    // System subdomains → not custom
    if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return false;
    // User subdomain → custom!
    return true;
  }

  // Any other domain → custom domain
  return true;
}

/**
 * Extracts the Vector Pagamentos subdomain slug if applicable.
 * Returns null if not a Vector Pagamentos subdomain.
 * e.g., "teste.Vector Pagamentos.com.br" → "teste"
 */
export function getSubdomainSlug(): string | null {
  const host = window.location.hostname.toLowerCase();
  const baseDomain = VECTOR_PAGAMENTOS_BASES.find((base) => host.endsWith('.' + base));
  if (!baseDomain) return null;

  const sub = host.slice(0, -(baseDomain.length + 1));
  if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return null;
  return sub;
}
