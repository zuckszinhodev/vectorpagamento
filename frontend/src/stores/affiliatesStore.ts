import { create } from 'zustand';
import { affiliatesService, type Affiliate, type CreateAffiliateData, type UpdateAffiliateData, type AffiliateStatistics } from '@/services/api/affiliates.service';
import toast from 'react-hot-toast';

interface AffiliatesState {
  affiliates: Affiliate[];
  currentAffiliate: Affiliate | null;
  currentStatistics: AffiliateStatistics | null;
  isLoading: boolean;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  } | null;
  fetchAffiliates: (params?: any) => Promise<void>;
  fetchAffiliate: (id: string) => Promise<void>;
  fetchStatistics: (id: string) => Promise<void>;
  createAffiliate: (data: CreateAffiliateData) => Promise<Affiliate>;
  updateAffiliate: (id: string, data: UpdateAffiliateData) => Promise<Affiliate>;
  deleteAffiliate: (id: string) => Promise<void>;
}

export const useAffiliatesStore = create<AffiliatesState>((set, get) => ({
  affiliates: [],
  currentAffiliate: null,
  currentStatistics: null,
  isLoading: false,
  pagination: null,

  fetchAffiliates: async (params?) => {
    set({ isLoading: true });
    try {
      const response = await affiliatesService.list(params);
      set({
        affiliates: response.data,
        pagination: response.meta,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar afiliados');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchAffiliate: async (id: string) => {
    set({ isLoading: true });
    try {
      const response = await affiliatesService.get(id);
      set({
        currentAffiliate: response.data,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar afiliado');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchStatistics: async (id: string) => {
    try {
      const response = await affiliatesService.getStatistics(id);
      set({
        currentStatistics: response.data,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar estatísticas');
      throw error;
    }
  },

  createAffiliate: async (data: CreateAffiliateData) => {
    set({ isLoading: true });
    try {
      const response = await affiliatesService.create(data);
      set({ isLoading: false });
      toast.success('Afiliado criado com sucesso!');
      
      // Refresh the list
      get().fetchAffiliates();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao criar afiliado');
      throw error;
    }
  },

  updateAffiliate: async (id: string, data: UpdateAffiliateData) => {
    set({ isLoading: true });
    try {
      const response = await affiliatesService.update(id, data);
      set({ isLoading: false });
      toast.success('Afiliado atualizado com sucesso!');
      
      // Refresh the list
      get().fetchAffiliates();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao atualizar afiliado');
      throw error;
    }
  },

  deleteAffiliate: async (id: string) => {
    set({ isLoading: true });
    try {
      await affiliatesService.delete(id);
      set({ isLoading: false });
      toast.success('Afiliado deletado com sucesso!');
      
      // Refresh the list
      get().fetchAffiliates();
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao deletar afiliado');
      throw error;
    }
  },
}));
