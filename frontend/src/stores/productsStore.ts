import { create } from 'zustand';
import { productsService, type Product, type CreateProductData, type UpdateProductData } from '@/services/api';
import toast from 'react-hot-toast';

interface ProductsState {
  products: Product[];
  currentProduct: Product | null;
  isLoading: boolean;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  } | null;
  fetchProducts: (params?: any) => Promise<void>;
  fetchProduct: (id: string) => Promise<void>;
  createProduct: (data: CreateProductData) => Promise<Product>;
  updateProduct: (id: string, data: UpdateProductData) => Promise<Product>;
  deleteProduct: (id: string) => Promise<void>;
}

export const useProductsStore = create<ProductsState>((set, get) => ({
  products: [],
  currentProduct: null,
  isLoading: false,
  pagination: null,

  fetchProducts: async (params?) => {
    set({ isLoading: true });
    try {
      const response = await productsService.list(params);
      set({
        products: response.data,
        pagination: response.meta,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar produtos');
      set({ isLoading: false });
      throw error;
    }
  },

  fetchProduct: async (id: string) => {
    set({ isLoading: true });
    try {
      const response = await productsService.get(id);
      set({
        currentProduct: response.data,
        isLoading: false,
      });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Erro ao carregar produto');
      set({ isLoading: false });
      throw error;
    }
  },

  createProduct: async (data: CreateProductData) => {
    set({ isLoading: true });
    try {
      const response = await productsService.create(data);
      set({ isLoading: false });
      toast.success('Produto criado com sucesso!');
      
      // Refresh the list
      get().fetchProducts();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao criar produto');
      throw error;
    }
  },

  updateProduct: async (id: string, data: UpdateProductData) => {
    set({ isLoading: true });
    try {
      const response = await productsService.update(id, data);
      set({ isLoading: false });
      toast.success('Produto atualizado com sucesso!');
      
      // Refresh the list
      get().fetchProducts();
      
      return response.data;
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao atualizar produto');
      throw error;
    }
  },

  deleteProduct: async (id: string) => {
    set({ isLoading: true });
    try {
      await productsService.delete(id);
      set({ isLoading: false });
      toast.success('Produto deletado com sucesso!');
      
      // Refresh the list
      get().fetchProducts();
    } catch (error: any) {
      set({ isLoading: false });
      toast.error(error.response?.data?.message || 'Erro ao deletar produto');
      throw error;
    }
  },
}));
