import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HelmetProvider } from 'react-helmet-async';
import { Toaster, toast, ToastBar } from 'react-hot-toast';
import App from './App';
import './styles/globals.css';

// Polyfill crypto.randomUUID for non-secure contexts (HTTP + IP access).
// crypto.randomUUID is only defined in secure contexts (HTTPS/localhost),
// so over plain HTTP by IP it is undefined and every POST (which stamps an
// X-Idempotency-Key) would throw. getRandomValues IS available on HTTP.
(() => {
  try {
    const c: any = typeof crypto !== 'undefined' ? crypto : undefined;
    if (c && typeof c.randomUUID !== 'function') {
      c.randomUUID = () => {
        const b = (c.getRandomValues
          ? c.getRandomValues(new Uint8Array(16))
          : Uint8Array.from({ length: 16 }, () => Math.floor(Math.random() * 256)));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        const h = Array.from(b, (x: number) => x.toString(16).padStart(2, '0'));
        return `${h[0]}${h[1]}${h[2]}${h[3]}-${h[4]}${h[5]}-${h[6]}${h[7]}-${h[8]}${h[9]}-${h[10]}${h[11]}${h[12]}${h[13]}${h[14]}${h[15]}`;
      };
    }
  } catch { /* ignore — helpers that guard for randomUUID still work */ }
})();

const CHUNK_RELOAD_FLAG = 'vectorpagamentos:chunk-reload';

window.addEventListener('error', (event) => {
  const target = event.target;

  if (!(target instanceof HTMLScriptElement)) {
    return;
  }

  const src = target.src || '';
  if (!src.includes('/assets/') || !src.endsWith('.js')) {
    return;
  }

  if (sessionStorage.getItem(CHUNK_RELOAD_FLAG) === '1') {
    sessionStorage.removeItem(CHUNK_RELOAD_FLAG);
    return;
  }

  sessionStorage.setItem(CHUNK_RELOAD_FLAG, '1');
  window.location.reload();
});

window.addEventListener('unhandledrejection', (event) => {
  const reason = event.reason;
  const message = typeof reason?.message === 'string' ? reason.message : '';

  if (!message.includes('Failed to fetch dynamically imported module')) {
    return;
  }

  if (sessionStorage.getItem(CHUNK_RELOAD_FLAG) === '1') {
    sessionStorage.removeItem(CHUNK_RELOAD_FLAG);
    return;
  }

  sessionStorage.setItem(CHUNK_RELOAD_FLAG, '1');
  window.location.reload();
});

window.addEventListener('load', () => {
  sessionStorage.removeItem(CHUNK_RELOAD_FLAG);
});

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false, // Desabilitar retry para usar mock data
      refetchOnWindowFocus: false,
      staleTime: 5 * 60 * 1000, // 5 minutes
    },
  },
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <HelmetProvider>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter
          future={{
            v7_startTransition: true,
            v7_relativeSplatPath: true,
          }}
        >
          <App />
          <Toaster
            position="top-right"
            toastOptions={{
              duration: 4000,
              style: {
                background: '#1f2937',
                color: '#f9fafb',
                cursor: 'pointer',
              },
            }}
          >
            {(t) => (
              <div onClick={() => toast.dismiss(t.id)} style={{ cursor: 'pointer' }}>
                <ToastBar toast={t} />
              </div>
            )}
          </Toaster>
        </BrowserRouter>
      </QueryClientProvider>
    </HelmetProvider>
  </React.StrictMode>
);
