import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// Sem manualChunks: agrupar bibliotecas à mão fazia o bundler puxar chart.js
// e @xyflow para o carregamento inicial (o login baixava os dois sem usar).
// A divisão automática segue os import() das rotas e só baixa o que a tela usa.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    sourcemap: false,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/[hash].js',
        chunkFileNames: 'assets/[hash].js',
      },
    },
  },
  server: {
    port: 3000,
    headers: {
      'Cross-Origin-Opener-Policy': 'unsafe-none',
      'Cross-Origin-Embedder-Policy': 'unsafe-none',
    },
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});
