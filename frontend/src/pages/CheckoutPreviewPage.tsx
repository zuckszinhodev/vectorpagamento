import { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { ModernSplitCheckout } from '@/components/checkout/ModernSplitCheckout';
import { ClassicCheckoutPreview } from '@/components/checkout/ClassicCheckoutPreview';

export default function CheckoutPreviewPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [previewData, setPreviewData] = useState<any>(null);

  useEffect(() => {
    const previewId = searchParams.get('id');
    if (previewId) {
      try {
        // Recuperar settings do localStorage usando o ID único
        const settingsJson = localStorage.getItem(`checkout_preview_${previewId}`);
        
        if (settingsJson) {
          const settings = JSON.parse(settingsJson);
          setPreviewData(settings);
          
          // Limpar do localStorage após 1 hora (opcional)
          setTimeout(() => {
            localStorage.removeItem(`checkout_preview_${previewId}`);
          }, 3600000); // 1 hora
        } else {
          setPreviewData({ error: 'Preview não encontrado ou expirado' });
        }
      } catch (error) {
        setPreviewData({ error: 'Erro ao carregar preview' });
      }
    } else {
      setPreviewData({ error: 'ID de preview inválido' });
    }
  }, [searchParams]);

  if (!previewData) {
    return (
      <div style={{ 
        display: 'flex', 
        alignItems: 'center', 
        justifyContent: 'center', 
        minHeight: '100vh',
        background: '#f5f5f5'
      }}>
        <div style={{ textAlign: 'center' }}>
          <div style={{ 
            width: '48px', 
            height: '48px', 
            border: '4px solid #e5e7eb', 
            borderTopColor: '#10b981',
            borderRadius: '50%',
            animation: 'spin 1s linear infinite',
            margin: '0 auto 16px'
          }}></div>
          <p style={{ color: '#6b7280' }}>Carregando preview...</p>
        </div>
        <style>{`
          @keyframes spin {
            to { transform: rotate(360deg); }
          }
        `}</style>
      </div>
    );
  }

  if (previewData.error) {
    return (
      <div style={{ 
        display: 'flex', 
        alignItems: 'center', 
        justifyContent: 'center', 
        minHeight: '100vh',
        background: '#f5f5f5',
        padding: '2rem'
      }}>
        <div style={{ 
          background: 'white', 
          padding: '2rem', 
          borderRadius: '12px',
          boxShadow: '0 4px 6px rgba(0,0,0,0.1)',
          textAlign: 'center',
          maxWidth: '400px'
        }}>
          <h1 style={{ fontSize: '1.5rem', fontWeight: 'bold', marginBottom: '1rem', color: '#1f2937' }}>
            {previewData.error}
          </h1>
          <button
            onClick={() => navigate(-1)}
            style={{
              background: '#10b981',
              color: 'white',
              padding: '0.75rem 1.5rem',
              borderRadius: '8px',
              border: 'none',
              cursor: 'pointer',
              fontWeight: '600'
            }}
          >
            Voltar
          </button>
        </div>
      </div>
    );
  }

  // Renderizar o preview usando o mesmo componente do checkout real
  const { theme, product, payment, conversion, blocks } = previewData;
  const template = (theme as any)?.template || 'classic';

  // Se for modern-split, usar o componente ModernSplitCheckout
  if (template === 'modern-split' && product) {
    return (
      <ModernSplitCheckout
        product={product}
        theme={theme}
        onSubmit={() => {}}
        isLoading={false}
        orderBumps={[]}
        selectedBumps={new Set()}
        onToggleBump={() => {}}
        blocks={blocks || []}
      />
    );
  }

  // Layout clássico (simplificado para preview)
  return (
    <div style={{ minHeight: '100vh', background: theme.backgroundColor || '#f8fafc', fontFamily: `${theme.fontFamily || 'Inter'}, -apple-system, sans-serif`, padding: '1rem' }}>
      <ClassicCheckoutPreview
        theme={theme}
        product={product}
        blocks={blocks || []}
      />
    </div>
  );
}
