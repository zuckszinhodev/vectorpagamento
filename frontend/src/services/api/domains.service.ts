import { api } from '@/lib/api';

export type DomainPurpose = 'checkout' | 'members';
export type DomainType = 'custom' | 'Vector Pagamentos';

export interface CustomDomain {
  id: string;
  domain: string;
  type: DomainType;
  subdomain_slug?: string | null;
  status: 'pending' | 'verified' | 'failed' | 'expired';
  is_primary: boolean;
  use_for: DomainPurpose[];
  ssl_enabled: boolean;
  verified_at?: string;
  last_check_at?: string;
  last_check_error?: string;
  created_at: string;
  dns_config?: {
    cname: {
      type: string;
      host: string;
      value: string;
    };
    txt: {
      type: string;
      host: string;
      value: string;
    };
  };
}

export interface DnsInstruction {
  step: number;
  title: string;
  description: string;
  record?: {
    type: string;
    host: string;
    value: string;
    ttl: number;
  };
}

export const domainsService = {
  /**
   * List all domains for the account
   */
  async list(): Promise<{ data: CustomDomain[] }> {
    const response = await api.get('/domains');
    return response.data;
  },

  /**
   * Add a new custom domain
   */
  async create(domain: string, useFor?: DomainPurpose[]): Promise<{ data: CustomDomain; message: string }> {
    const response = await api.post('/domains', { domain, type: 'custom', use_for: useFor || ['checkout', 'members'] });
    return response.data;
  },

  /**
   * Create a Vector Pagamentos subdomain (e.g., meusite.Vector Pagamentos.com.br)
   */
  async createSubdomain(subdomain: string, useFor?: DomainPurpose[]): Promise<{ data: CustomDomain; message: string }> {
    const response = await api.post('/domains', { subdomain, type: 'Vector Pagamentos', use_for: useFor || ['checkout', 'members'] });
    return response.data;
  },

  /**
   * Get domain details
   */
  async get(id: string): Promise<{ data: CustomDomain }> {
    const response = await api.get(`/domains/${id}`);
    return response.data;
  },

  /**
   * Verify domain DNS
   */
  async verify(id: string): Promise<{ success: boolean; data?: CustomDomain; message?: string; error?: { details: string[] } }> {
    const response = await api.post(`/domains/${id}/verify`);
    return response.data;
  },

  /**
   * Set domain as primary
   */
  async setPrimary(id: string): Promise<{ data: CustomDomain; message: string }> {
    const response = await api.post(`/domains/${id}/primary`);
    return response.data;
  },

  /**
   * Delete a domain
   */
  async remove(id: string): Promise<{ message: string }> {
    const response = await api.delete(`/domains/${id}`);
    return response.data;
  },

  /**
   * Get DNS instructions
   */
  async getDnsInstructions(id: string): Promise<{ data: { domain: string; instructions: DnsInstruction[] } }> {
    const response = await api.get(`/domains/${id}/dns-instructions`);
    return response.data;
  },

  /**
   * Update domain use_for purposes
   */
  async updateUseFor(id: string, useFor: DomainPurpose[]): Promise<{ data: CustomDomain; message: string }> {
    const response = await api.put(`/domains/${id}/use-for`, { use_for: useFor });
    return response.data;
  },

  /**
   * Set custom domain on a landing page
   */
  async setLandingPageDomain(lpId: string, domain: string | null): Promise<{ data: any; message: string }> {
    const response = await api.post(`/landing-pages/${lpId}/set-domain`, { custom_domain: domain });
    return response.data;
  },
};
