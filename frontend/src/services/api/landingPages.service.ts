import { api } from '@/lib/api';

export interface LandingPage {
  id: string;
  account_id: string;
  product_id?: string;
  checkout_id?: string;
  name: string;
  slug: string;
  description?: string;
  prompt?: string;
  ai_model?: string;
  content: LandingPageContent;
  sections?: LandingPageSection[];
  styles?: Record<string, any>;
  seo?: {
    title?: string;
    description?: string;
    keywords?: string[];
  };
  template: string;
  color_scheme: string;
  custom_colors?: Record<string, string>;
  status: 'draft' | 'published' | 'archived';
  published_at?: string;
  views_count: number;
  clicks_count: number;
  conversions_count: number;
  custom_domain?: string;
  subdomain?: string;
  version: number;
  created_at: string;
  updated_at: string;
  product?: {
    id: string;
    name: string;
    price: number;
    image_url?: string;
  };
  checkout?: {
    id: string;
    name: string;
    slug: string;
  };
}

export interface LandingPageContent {
  meta?: {
    title?: string;
    description?: string;
    keywords?: string[];
  };
  theme?: {
    primaryColor?: string;
    secondaryColor?: string;
    backgroundColor?: string;
    textColor?: string;
    accentColor?: string;
  };
  sections?: LandingPageSection[];
  checkoutUrl?: string;
}

export interface LandingPageSection {
  type: string;
  id: string;
  settings?: {
    backgroundColor?: string;
    padding?: string;
    alignment?: string;
  };
  content: Record<string, any>;
}

export interface GenerateLandingPageData {
  prompt: string;
  product_id?: string;
  checkout_id?: string;
  name?: string;
  template?: string;
  custom_domain?: string;
  options?: {
    tone?: 'professional' | 'casual' | 'urgent' | 'luxury';
    target_audience?: string;
    language?: string;
  };
}

export interface UpdateLandingPageData {
  name?: string;
  slug?: string;
  description?: string;
  content?: LandingPageContent;
  sections?: LandingPageSection[];
  styles?: Record<string, any>;
  seo?: Record<string, any>;
  template?: string;
  color_scheme?: string;
  custom_colors?: Record<string, string>;
  status?: string;
}

export interface LandingPageTemplate {
  name: string;
  description: string;
  preview: string;
}

export interface LandingPageSectionType {
  name: string;
  description: string;
  icon: string;
}

export const landingPagesService = {
  async list(params?: {
    status?: string;
    search?: string;
    page?: number;
    per_page?: number;
  }): Promise<{
    success: boolean;
    data: LandingPage[];
    meta: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
    };
  }> {
    const response = await api.get('/landing-pages', { params });
    return response.data;
  },

  async get(id: string): Promise<{ success: boolean; data: LandingPage }> {
    const response = await api.get(`/landing-pages/${id}`);
    return response.data;
  },

  async generate(data: GenerateLandingPageData): Promise<{ success: boolean; data: LandingPage }> {
    const response = await api.post('/landing-pages/generate', data);
    return response.data;
  },

  async update(id: string, data: UpdateLandingPageData): Promise<{ success: boolean; data: LandingPage }> {
    const response = await api.put(`/landing-pages/${id}`, data);
    return response.data;
  },

  async delete(id: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/landing-pages/${id}`);
    return response.data;
  },

  async duplicate(id: string): Promise<{ success: boolean; data: LandingPage }> {
    const response = await api.post(`/landing-pages/${id}/duplicate`);
    return response.data;
  },

  async publish(id: string): Promise<{ success: boolean; data: { url: string; status: string } }> {
    const response = await api.post(`/landing-pages/${id}/publish`);
    return response.data;
  },

  async unpublish(id: string): Promise<{ success: boolean }> {
    const response = await api.post(`/landing-pages/${id}/unpublish`);
    return response.data;
  },

  async preview(id: string): Promise<{ success: boolean; data: { html: string; url: string } }> {
    const response = await api.get(`/landing-pages/${id}/preview`);
    return response.data;
  },

  async regenerateSection(
    id: string,
    sectionId: string,
    prompt: string
  ): Promise<{ success: boolean; data: LandingPage }> {
    const response = await api.post(`/landing-pages/${id}/regenerate-section`, {
      section_id: sectionId,
      prompt,
    });
    return response.data;
  },

  async chatEdit(
    id: string,
    message: string,
    history: { role: 'user' | 'assistant'; content: string }[] = []
  ): Promise<{ success: boolean; data: { landing_page: LandingPage; html: string }; message: string }> {
    const response = await api.post(`/landing-pages/${id}/chat-edit`, {
      message,
      history,
    });
    return response.data;
  },

  async getAnalytics(id: string): Promise<{
    success: boolean;
    data: {
      views: number;
      clicks: number;
      conversions: number;
      conversion_rate: number;
      url: string;
    };
  }> {
    const response = await api.get(`/landing-pages/${id}/analytics`);
    return response.data;
  },

  async getTemplates(): Promise<{ success: boolean; data: Record<string, LandingPageTemplate> }> {
    const response = await api.get('/landing-pages/templates');
    return response.data;
  },

  async getSectionTypes(): Promise<{ success: boolean; data: Record<string, LandingPageSectionType> }> {
    const response = await api.get('/landing-pages/section-types');
    return response.data;
  },
};

export default landingPagesService;
