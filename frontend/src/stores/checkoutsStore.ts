import { create } from 'zustand';
import { checkoutsService, type Checkout, type CreateCheckoutData, type UpdateCheckoutData } from '@/services/api';
import toast from 'react-hot-toast';

interface CheckoutsState {
  checkouts: Checkout[];
  currentCheckout: Checkout | null;
  isLoading: boolean;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  } | null;
  fetchCheckouts: (params?: any) => Promise<void>;
  fetchCheckout: (id: string) => Promise<void>;
  fetchCheckoutBySlug: (slug: string) => Promise<void>;
  createCheckout: (data: CreateCheckoutData) => Promise<Checkout>;
  updateCheckout: (id: string, data: UpdateCheckoutData) => Promise<Checkout>;
  deleteCheckout: (id: string) => Promise<void>;
}

export const useCheckoutsStore = create<CheckoutsState>((set, get) => ({
  checkouts: [],
  currentCheckout: null,
  isLoading: false,
  pagination: null,

  fetchCheckouts: async (params?) => {
    set({ isLoading: true });
    try {
      const response = await checkoutsService.list(params);
      set({
        checkouts: response.data,
        pagination: response.meta,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar checkouts');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchCheckout: async (id: string) => {
    set({ isLoading: true });
    try {
      const response = await checkoutsService.get(id);
      set({
        currentCheckout: response.data,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar checkout');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchCheckoutBySlug: async (slug: string) => {
    set({ isLoading: true });
    try {
      const response = await checkoutsService.getBySlug(slug);
      set({
        currentCheckout: response.data,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar checkout');
      set({ isLoading: false });
      throw error;
    }
  },

  createCheckout: async (data: CreateCheckoutData) => {
    set({ isLoading: true });
    try {
      const response = await checkoutsService.create(data);
      set({ isLoading: false });
      toast.success('Checkout criado com sucesso!');
      
      // Refresh the list
      get().fetchCheckouts();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao criar checkout');
      throw error;
    }
  },

  updateCheckout: async (id: string, data: UpdateCheckoutData) => {
    set({ isLoading: true });
    try {
      const response = await checkoutsService.update(id, data);
      set({ isLoading: false });
      toast.success('Checkout atualizado com sucesso!');
      
      // Refresh the list
      get().fetchCheckouts();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao atualizar checkout');
      throw error;
    }
  },

  deleteCheckout: async (id: string) => {
    set({ isLoading: true });
    try {
      await checkoutsService.delete(id);
      set({ isLoading: false });
      toast.success('Checkout deletado com sucesso!');
      
      // Refresh the list
      get().fetchCheckouts();
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao deletar checkout');
      throw error;
    }
  },
}));
