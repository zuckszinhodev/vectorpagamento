/**
 * Detects whether the current host is a custom/subdomain (not the main app).
 * Used to determine if the frontend should show public-facing pages
 * (checkout, member area) instead of the admin dashboard.
 */

const SYSTEM_HOSTS = [
  'localhost',
  '127.0.0.1',
  'app.wazerpay.com.br',
  'wazerpay.com.br',
  'www.wazerpay.com.br',
  'api.wazerpay.com.br',
];

const SYSTEM_SUBDOMAINS = ['api', 'app', 'www', 'checkout', 'pay', 'members', 'mail', 'ftp', 'admin'];

const GATEPRO_BASE = 'wazerpay.com.br';

/**
 * Returns true if the current host is a custom domain or GatePro subdomain
 * (e.g., teste.wazerpay.com.br or meusite.com), not the main app.
 */
export function isCustomDomain(): boolean {
  const host = window.location.hostname.toLowerCase();

  // System hosts → not custom
  if (SYSTEM_HOSTS.includes(host)) return false;

  // Localhost with port → not custom
  if (host === 'localhost' || host === '127.0.0.1') return false;

  // Check if it's a GatePro subdomain
  if (host.endsWith('.' + GATEPRO_BASE)) {
    const sub = host.slice(0, -(GATEPRO_BASE.length + 1));
    // System subdomains → not custom
    if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return false;
    // User subdomain → custom!
    return true;
  }

  // Any other domain → custom domain
  return true;
}

/**
 * Extracts the GatePro subdomain slug if applicable.
 * Returns null if not a GatePro subdomain.
 * e.g., "teste.wazerpay.com.br" → "teste"
 */
export function getSubdomainSlug(): string | null {
  const host = window.location.hostname.toLowerCase();
  if (!host.endsWith('.' + GATEPRO_BASE)) return null;

  const sub = host.slice(0, -(GATEPRO_BASE.length + 1));
  if (SYSTEM_SUBDOMAINS.includes(sub) || sub.includes('.')) return null;
  return sub;
}

