import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '@/lib/api';
import { Spinner } from '@/components/ui/Spinner';

/**
 * Landing page for custom domains / Vector Pagamentos subdomains.
 * When a user visits teste.vectorpagamentos.com/ (root), this page:
 * 1. Calls the API to get the default checkout for this domain
 * 2. Redirects to /checkout/{slug} or /members/login
 */
export default function DomainLandingPage() {
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const resolveDefault = async () => {
      try {
        // Call the domain-aware pay endpoint (no slug = default checkout)
        const host = window.location.hostname;
        const response = await api.get('/pay/', {
          headers: { 'X-Forwarded-Host': host },
        });

        if (response.data?.success && response.data?.data?.slug) {
          // Redirect to the checkout page
          navigate(`/checkout/${response.data.data.slug}`, { replace: true });
          return;
        }
      } catch {
        // No default checkout found
      }

      // Fallback: redirect to member area login
      navigate('/members/login', { replace: true });
    };

    resolveDefault();
  }, [navigate]);

  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gray-50">
        <div className="text-center">
          <h1 className="text-2xl font-bold text-gray-900">Página não encontrada</h1>
          <p className="mt-2 text-gray-600">{error}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50">
      <Spinner />
    </div>
  );
}

