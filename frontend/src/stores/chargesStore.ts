import { create } from 'zustand';
import { chargesService, type Charge, type CreateChargeData } from '@/services/api/charges.service';
import toast from 'react-hot-toast';

interface ChargesState {
  charges: Charge[];
  currentCharge: Charge | null;
  isLoading: boolean;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  } | null;
  fetchCharges: (params?: any) => Promise<void>;
  fetchCharge: (id: string) => Promise<void>;
  createCharge: (data: CreateChargeData) => Promise<Charge>;
  cancelCharge: (id: string) => Promise<void>;
  refundCharge: (id: string, amount?: number) => Promise<void>;
}

export const useChargesStore = create<ChargesState>((set, get) => ({
  charges: [],
  currentCharge: null,
  isLoading: false,
  pagination: null,

  fetchCharges: async (params?) => {
    set({ isLoading: true });
    try {
      const response = await chargesService.list(params);
      set({
        charges: response.data,
        pagination: response.meta,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao carregar cobranças');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchCharge: async (id: string) => {
    set({ isLoading: true });
    try {
      const response = await chargesService.get(id);
      set({
        currentCharge: response.data,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao carregar cobrança');
      set({ isLoading: false });
      throw error;
    }
  },

  createCharge: async (data: CreateChargeData) => {
    set({ isLoading: true });
    try {
      const response = await chargesService.create(data);
      set({ isLoading: false });
      toast.success('Cobrança criada com sucesso!');
      
      // Refresh the list
      get().fetchCharges();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao criar cobrança');
      throw error;
    }
  },

  cancelCharge: async (id: string) => {
    set({ isLoading: true });
    try {
      await chargesService.cancel(id);
      set({ isLoading: false });
      toast.success('Cobrança cancelada com sucesso!');
      
      // Refresh the list
      get().fetchCharges();
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao cancelar cobrança');
      throw error;
    }
  },

  refundCharge: async (id: string, amount?: number) => {
    set({ isLoading: true });
    try {
      await chargesService.refund(id, amount);
      set({ isLoading: false });
      toast.success('Reembolso realizado com sucesso!');
      
      // Refresh the list
      get().fetchCharges();
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.error?.message || error.response?.data?.message || 'Erro ao realizar reembolso');
      throw error;
    }
  },
}));
