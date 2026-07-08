import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

const chunkGroups: Record<string, string[]> = {
  'vendor-react': ['react', 'react-dom', 'react-router-dom'],
  'vendor-ui': ['framer-motion', '@headlessui/react', 'lucide-react', '@heroicons/react'],
  'vendor-charts': ['chart.js', 'react-chartjs-2'],
  'vendor-forms': ['react-hook-form', 'zod', '@tanstack/react-query', '@tanstack/react-table'],
  'vendor-dnd': ['@dnd-kit/core', '@dnd-kit/sortable', '@dnd-kit/utilities', '@hello-pangea/dnd'],
  'vendor-utils': ['axios', 'date-fns', 'clsx', 'tailwind-merge', 'class-variance-authority', 'zustand'],
};

function manualChunks(id: string) {
  if (!id.includes('node_modules')) {
    return;
  }

  const normalizedId = id.replace(/\\/g, '/');

  for (const [chunkName, packages] of Object.entries(chunkGroups)) {
    if (packages.some((pkg) => normalizedId.includes(`/node_modules/${pkg}/`))) {
      return chunkName;
    }
  }
}

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks,
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
