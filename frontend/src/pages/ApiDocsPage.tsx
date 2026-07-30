import { useState, useEffect } from 'react';
import { useThemeStore } from '@/stores/themeStore';
import {
  DocumentTextIcon,
  KeyIcon,
  CodeBracketIcon,
  BoltIcon,
  GlobeAltIcon,
  ShieldCheckIcon,
} from '@heroicons/react/24/outline';

// --- Internal CodeBlock Component ---
function CodeBlock({ curl, js }: { curl: string; js: string }) {
  const [tab, setTab] = useState<'curl' | 'js'>('curl');

  return (
    <div className="rounded-lg overflow-hidden border border-border-DEFAULT mt-3">
      <div className="flex bg-gray-800 border-b border-gray-700">
        <button
          onClick={() => setTab('curl')}
          className={`px-4 py-2 text-sm font-medium transition-colors ${
            tab === 'curl'
              ? 'text-green-400 border-b-2 border-green-400 bg-gray-900'
              : 'text-gray-400 hover:text-gray-200'
          }`}
        >
          cURL
        </button>
        <button
          onClick={() => setTab('js')}
          className={`px-4 py-2 text-sm font-medium transition-colors ${
            tab === 'js'
              ? 'text-green-400 border-b-2 border-green-400 bg-gray-900'
              : 'text-gray-400 hover:text-gray-200'
          }`}
        >
          JavaScript
        </button>
      </div>
      <pre className="bg-gray-900 p-4 overflow-x-auto text-sm leading-relaxed">
        <code className="text-gray-100 whitespace-pre">{tab === 'curl' ? curl : js}</code>
      </pre>
    </div>
  );
}

// --- Method Badge ---
function MethodBadge({ method }: { method: string }) {
  const colors: Record<string, string> = {
    GET: 'bg-green-500/20 text-green-400 border-green-500/30',
    POST: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
    PUT: 'bg-amber-500/20 text-amber-400 border-amber-500/30',
    DELETE: 'bg-red-500/20 text-red-400 border-red-500/30',
  };

  return (
    <span className={`inline-block px-2 py-0.5 text-xs font-bold rounded border ${colors[method] || ''}`}>
      {method}
    </span>
  );
}

// --- Sidebar sections ---
const sections = [
  { id: 'introduction', label: 'Introdução', icon: DocumentTextIcon },
  { id: 'authentication', label: 'Autenticação', icon: KeyIcon },
  { id: 'rate-limits', label: 'Rate Limits & Erros', icon: ShieldCheckIcon },
  { id: 'charges', label: 'Cobranças (Cashin)', icon: BoltIcon },
  { id: 'withdrawals', label: 'Saques (Cashout)', icon: GlobeAltIcon },
  { id: 'webhooks', label: 'Webhooks', icon: CodeBracketIcon },
];

const EXTERNAL_API_BASE_URL = 'https://api.vectorpagamentos.com/api/v1/external';

// --- Main Component ---
export default function ApiDocsPage({ apiKey }: { apiKey?: string | null }) {
  const platformName = useThemeStore((s) => s.platformName);
  const [activeSection, setActiveSection] = useState('introduction');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const displayKey = apiKey || 'sk_live_xxxxxxxxxxxx';

  useEffect(() => {
    const handleScroll = () => {
      const offsets = sections.map(({ id }) => {
        const el = document.getElementById(id);
        return { id, top: el?.getBoundingClientRect().top ?? Infinity };
      });
      const current = offsets.find((o) => o.top >= -100) || offsets[offsets.length - 1];
      if (current) setActiveSection(current.id);
    };
    window.addEventListener('scroll', handleScroll, { passive: true });
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  return (
    <div className="min-h-screen bg-surface-page">
      {/* Hero */}
      <div className="bg-gray-900 border-b border-border-DEFAULT">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <h1 className="text-3xl font-bold text-white">Documentação da API</h1>
          <p className="mt-2 text-gray-400">
            Integre a {platformName} na sua aplicação com nossa API REST.
          </p>
          <div className="mt-4 inline-flex items-center gap-2 bg-gray-800 rounded-lg px-4 py-2 border border-gray-700">
            <span className="text-gray-400 text-sm">Base URL:</span>
            <code className="text-green-400 text-sm font-mono">{EXTERNAL_API_BASE_URL}</code>
          </div>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex gap-8">
          {/* Mobile sidebar toggle */}
          <button
            onClick={() => setSidebarOpen(!sidebarOpen)}
            className="lg:hidden fixed bottom-4 right-4 z-50 bg-green-600 text-white p-3 rounded-full shadow-lg"
            aria-label="Toggle navigation"
          >
            <DocumentTextIcon className="w-6 h-6" />
          </button>

          {/* Sidebar */}
          <aside
            className={`${
              sidebarOpen ? 'translate-x-0' : '-translate-x-full'
            } lg:translate-x-0 fixed lg:sticky top-0 lg:top-8 left-0 z-40 lg:z-0 w-64 h-screen lg:h-auto bg-surface-card lg:bg-transparent border-r lg:border-r-0 border-border-DEFAULT p-4 lg:p-0 transition-transform lg:transition-none overflow-y-auto lg:self-start lg:shrink-0`}
          >
            <nav className="space-y-1">
              {sections.map(({ id, label, icon: Icon }) => (
                <a
                  key={id}
                  href={`#${id}`}
                  onClick={() => setSidebarOpen(false)}
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeSection === id
                      ? 'bg-green-500/10 text-green-500 border border-green-500/20'
                      : 'text-tx-secondary hover:text-tx-primary hover:bg-surface-card'
                  }`}
                >
                  <Icon className="w-5 h-5 shrink-0" />
                  {label}
                </a>
              ))}
            </nav>
          </aside>

          {/* Overlay for mobile sidebar */}
          {sidebarOpen && (
            <div
              className="fixed inset-0 bg-black/50 z-30 lg:hidden"
              onClick={() => setSidebarOpen(false)}
            />
          )}

          {/* Main content */}
          <main className="flex-1 min-w-0 space-y-16">
            {/* INTRODUCTION */}
            <section id="introduction">
              <h2 className="text-2xl font-bold text-tx-primary">Introduction</h2>
              <div className="mt-4 bg-surface-card rounded-lg border border-border-DEFAULT p-6 space-y-4">
                <p className="text-tx-secondary">
                  The {platformName} API is organized around REST. Our API accepts JSON-encoded
                  request bodies, returns JSON-encoded responses, and uses standard HTTP response codes
                  and authentication.
                </p>
                <div className="grid sm:grid-cols-3 gap-4 mt-4">
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Base URL</p>
                    <code className="text-xs text-green-500 font-mono mt-1 block">
                      {EXTERNAL_API_BASE_URL}
                    </code>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Format</p>
                    <p className="text-xs text-tx-secondary mt-1">All requests and responses in JSON</p>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Amounts</p>
                    <p className="text-xs text-tx-secondary mt-1">
                      In centavos (R$ 10.00 = 1000)
                    </p>
                  </div>
                </div>
              </div>
            </section>

            {/* AUTHENTICATION */}
            <section id="authentication">
              <h2 className="text-2xl font-bold text-tx-primary">Authentication</h2>
              <div className="mt-4 bg-surface-card rounded-lg border border-border-DEFAULT p-6 space-y-4">
                <p className="text-tx-secondary">
                  Para integrações externas, envie uma chave secreta no header <code className="text-green-500 bg-gray-800 px-1.5 py-0.5 rounded text-sm">X-API-Key</code>.
                  Chaves podem ser geradas em <strong className="text-tx-primary">Configurações &gt; Chaves de API</strong>.
                </p>
                <div className="bg-gray-900 rounded-lg p-4 border border-gray-700">
                  <p className="text-sm text-gray-400 mb-2">Header</p>
                  <code className="text-green-400 font-mono text-sm">
                    X-API-Key: {displayKey}
                  </code>
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <code className="text-sm text-green-500 font-mono">sk_live_*</code>
                    <p className="text-xs text-tx-secondary mt-1">Produção. Use para criar cobranças e saques pelo servidor.</p>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <code className="text-sm text-amber-500 font-mono">sk_test_*</code>
                    <p className="text-xs text-tx-secondary mt-1">Sandbox/testes. Nunca exponha chaves secretas no frontend.</p>
                  </div>
                </div>
                <p className="text-xs text-tx-muted">
                  O header <code className="text-green-500 bg-gray-800 px-1 py-0.5 rounded">Authorization: Bearer</code> é usado para JWT do painel. Na API externa, prefira <code className="text-green-500 bg-gray-800 px-1 py-0.5 rounded">X-API-Key</code>.
                </p>
              </div>
            </section>

            {/* RATE LIMITS & ERRORS */}
            <section id="rate-limits">
              <h2 className="text-2xl font-bold text-tx-primary">Rate Limits & Errors</h2>
              <div className="mt-4 bg-surface-card rounded-lg border border-border-DEFAULT p-6 space-y-6">
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Rate Limits</h3>
                  <p className="text-tx-secondary mt-1">
                    120 requests per minute per API key. Exceeding this limit returns a <code className="text-green-500 bg-gray-800 px-1.5 py-0.5 rounded text-sm">429</code> status.
                  </p>
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Error Format</h3>
                  <pre className="bg-gray-900 rounded-lg p-4 mt-2 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": false,
  "error": {
    "code": "string",
    "message": "string"
  }
}`}</code>
                  </pre>
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">HTTP Status Codes</h3>
                  <div className="mt-2 overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-border-DEFAULT">
                          <th className="text-left py-2 text-tx-muted font-medium">Code</th>
                          <th className="text-left py-2 text-tx-muted font-medium">Error</th>
                          <th className="text-left py-2 text-tx-muted font-medium">Description</th>
                        </tr>
                      </thead>
                      <tbody className="text-tx-secondary">
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">400</td><td>invalid_request</td><td>Invalid request parameters</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">401</td><td>unauthorized</td><td>Invalid or missing API key</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">403</td><td>forbidden</td><td>Insufficient permissions</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">404</td><td>not_found</td><td>Resource not found</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">429</td><td>rate_limited</td><td>Too many requests</td></tr>
                        <tr><td className="py-2 font-mono">500</td><td>internal_error</td><td>Internal server error</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </section>

            {/* CHARGES (CASHIN) */}
            <section id="charges">
              <h2 className="text-2xl font-bold text-tx-primary">Charges (Cashin)</h2>

              {/* Create PIX Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges</code>
                  <span className="text-tx-muted text-sm">- Create PIX Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Creates a new PIX charge. Returns a QR code and copy-paste code for payment.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/charges \\
  -H "X-API-Key: ${displayKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 5000,
    "payment_method": "pix",
    "customer": {
      "name": "João Silva",
      "email": "joao@email.com",
      "document": "12345678900"
    },
    "description": "Produto X"
  }'`}
                  js={`const response = await fetch('${EXTERNAL_API_BASE_URL}/charges', {
  method: 'POST',
  headers: {
    'X-API-Key': '${displayKey}',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    amount: 5000,
    payment_method: 'pix',
    customer: {
      name: 'João Silva',
      email: 'joao@email.com',
      document: '12345678900',
    },
    description: 'Produto X',
  }),
});

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 5000,
    "status": "pending",
    "payment_method": "pix",
    "pix_qr_code": "base64...",
    "pix_copy_paste": "00020126...",
    "expires_at": "2024-01-01T01:00:00Z"
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Create Credit Card Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges</code>
                  <span className="text-tx-muted text-sm">- Create Credit Card Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Creates a charge using a credit card. Supports installments.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/charges \\
  -H "X-API-Key: ${displayKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 10000,
    "payment_method": "credit_card",
    "installments": 3,
    "customer": {
      "name": "João Silva",
      "email": "joao@email.com",
      "document": "12345678900"
    },
    "card": {
      "number": "4111111111111111",
      "holder_name": "JOAO SILVA",
      "exp_month": "12",
      "exp_year": "2025",
      "cvv": "123"
    }
  }'`}
                  js={`const response = await fetch('${EXTERNAL_API_BASE_URL}/charges', {
  method: 'POST',
  headers: {
    'X-API-Key': '${displayKey}',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    amount: 10000,
    payment_method: 'credit_card',
    installments: 3,
    customer: {
      name: 'João Silva',
      email: 'joao@email.com',
      document: '12345678900',
    },
    card: {
      number: '4111111111111111',
      holder_name: 'JOAO SILVA',
      exp_month: '12',
      exp_year: '2025',
      cvv: '123',
    },
  }),
});

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 10000,
    "status": "approved",
    "payment_method": "credit_card",
    "installments": 3,
    "paid_at": "2024-01-01T12:00:00Z"
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Create Boleto Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges</code>
                  <span className="text-tx-muted text-sm">- Create Boleto Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Creates a charge with boleto payment method. Returns a URL and barcode.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/charges \\
  -H "X-API-Key: ${displayKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 15000,
    "payment_method": "boleto",
    "customer": {
      "name": "João Silva",
      "email": "joao@email.com",
      "document": "12345678900"
    }
  }'`}
                  js={`const response = await fetch('${EXTERNAL_API_BASE_URL}/charges', {
  method: 'POST',
  headers: {
    'X-API-Key': '${displayKey}',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    amount: 15000,
    payment_method: 'boleto',
    customer: {
      name: 'João Silva',
      email: 'joao@email.com',
      document: '12345678900',
    },
  }),
});

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 15000,
    "status": "pending",
    "payment_method": "boleto",
    "boleto_url": "https://...",
    "boleto_barcode": "23793.38128...",
    "expires_at": "2024-01-05T23:59:59Z"
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* List Charges */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="GET" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges</code>
                  <span className="text-tx-muted text-sm">- List Charges</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Returns a paginated list of charges. Supports filtering by status.
                </p>
                <CodeBlock
                  curl={`curl -X GET "${EXTERNAL_API_BASE_URL}/charges?status=approved&page=1&per_page=20" \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/charges?status=approved&page=1&per_page=20',
  {
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "total": 100,
    "per_page": 20
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Get Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="GET" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges/:id</code>
                  <span className="text-tx-muted text-sm">- Get Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Retrieves the details of a specific charge by its ID.
                </p>
                <CodeBlock
                  curl={`curl -X GET ${EXTERNAL_API_BASE_URL}/charges/chr_xxx \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/charges/chr_xxx',
  {
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 5000,
    "status": "approved",
    "payment_method": "pix",
    "paid_at": "2024-01-01T12:00:00Z"
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Cancel Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges/:id/cancel</code>
                  <span className="text-tx-muted text-sm">- Cancel Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Cancels a pending charge. Only charges with status &quot;pending&quot; can be cancelled.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/charges/chr_xxx/cancel \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/charges/chr_xxx/cancel',
  {
    method: 'POST',
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "status": "cancelled"
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Refund Charge */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/charges/:id/refund</code>
                  <span className="text-tx-muted text-sm">- Refund Charge</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Refunds a charge. Optionally specify an amount for partial refunds.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/charges/chr_xxx/refund \\
  -H "X-API-Key: ${displayKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 5000
  }'`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/charges/chr_xxx/refund',
  {
    method: 'POST',
    headers: {
      'X-API-Key': '${displayKey}',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      amount: 5000, // optional, for partial refund
    }),
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "status": "refunded",
    "refunded_amount": 5000
  }
}`}</code>
                  </pre>
                </div>
              </div>
            </section>

            {/* WITHDRAWALS (CASHOUT) */}
            <section id="withdrawals">
              <h2 className="text-2xl font-bold text-tx-primary">Withdrawals (Cashout)</h2>

              {/* Get Balance */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="GET" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/withdrawals/summary</code>
                  <span className="text-tx-muted text-sm">- Get Balance</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Returns the current balance summary including available, pending, and total withdrawn amounts.
                </p>
                <CodeBlock
                  curl={`curl -X GET ${EXTERNAL_API_BASE_URL}/withdrawals/summary \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/withdrawals/summary',
  {
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "available_balance": 150000,
    "pending_balance": 25000,
    "total_withdrawn": 500000
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* List Withdrawals */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="GET" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/withdrawals</code>
                  <span className="text-tx-muted text-sm">- List Withdrawals</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Returns a paginated list of withdrawals. Supports filtering by status.
                </p>
                <CodeBlock
                  curl={`curl -X GET "${EXTERNAL_API_BASE_URL}/withdrawals?status=completed&page=1" \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/withdrawals?status=completed&page=1',
  {
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "total": 45,
    "per_page": 20
  }
}`}</code>
                  </pre>
                </div>
              </div>

              {/* Request Withdrawal */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/withdrawals</code>
                  <span className="text-tx-muted text-sm">- Request Withdrawal</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Solicita um novo saque do saldo disponível via PIX. Você deve especificar a chave PIX de destino.
                </p>

                {/* Parameters Table */}
                <div className="mt-4 overflow-x-auto">
                  <table className="w-full text-sm border border-border-DEFAULT rounded-lg overflow-hidden">
                    <thead className="bg-gray-800 text-gray-300">
                      <tr>
                        <th className="text-left px-4 py-2 font-medium">Parâmetro</th>
                        <th className="text-left px-4 py-2 font-medium">Tipo</th>
                        <th className="text-left px-4 py-2 font-medium">Obrigatório</th>
                        <th className="text-left px-4 py-2 font-medium">Descrição</th>
                      </tr>
                    </thead>
                    <tbody className="text-gray-300 divide-y divide-border-DEFAULT">
                      <tr className="bg-gray-900/50">
                        <td className="px-4 py-2 font-mono text-green-400">amount</td>
                        <td className="px-4 py-2">number</td>
                        <td className="px-4 py-2">Sim</td>
                        <td className="px-4 py-2">Valor em centavos (min: 100 = R$1,00)</td>
                      </tr>
                      <tr className="bg-gray-900/30">
                        <td className="px-4 py-2 font-mono text-green-400">method</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Sim</td>
                        <td className="px-4 py-2"><code className="text-xs bg-gray-800 px-1 rounded">pix</code></td>
                      </tr>
                      <tr className="bg-gray-900/50">
                        <td className="px-4 py-2 font-mono text-green-400">destination.type</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Sim</td>
                        <td className="px-4 py-2"><code className="text-xs bg-gray-800 px-1 rounded">pix</code></td>
                      </tr>
                      <tr className="bg-gray-900/30">
                        <td className="px-4 py-2 font-mono text-green-400">destination.pix_key</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Sim</td>
                        <td className="px-4 py-2">Chave PIX de destino</td>
                      </tr>
                      <tr className="bg-gray-900/50">
                        <td className="px-4 py-2 font-mono text-green-400">destination.pix_type</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Sim</td>
                        <td className="px-4 py-2"><code className="text-xs bg-gray-800 px-1 rounded">cpf</code>, <code className="text-xs bg-gray-800 px-1 rounded">cnpj</code>, <code className="text-xs bg-gray-800 px-1 rounded">email</code>, <code className="text-xs bg-gray-800 px-1 rounded">phone</code> ou <code className="text-xs bg-gray-800 px-1 rounded">random</code></td>
                      </tr>
                      <tr className="bg-gray-900/30">
                        <td className="px-4 py-2 font-mono text-green-400">notes</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Não</td>
                        <td className="px-4 py-2">Observações (máx 500 caracteres)</td>
                      </tr>
                      <tr className="bg-gray-900/50">
                        <td className="px-4 py-2 font-mono text-green-400">idempotency_key</td>
                        <td className="px-4 py-2">string</td>
                        <td className="px-4 py-2">Não</td>
                        <td className="px-4 py-2">Chave de idempotência para evitar duplicatas (máx 120 caracteres)</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/withdrawals \\
  -H "X-API-Key: ${displayKey}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "amount": 50000,
    "method": "pix",
    "destination": {
      "type": "pix",
      "pix_key": "email@exemplo.com",
      "pix_type": "email"
    }
  }'`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/withdrawals',
  {
    method: 'POST',
    headers: {
      'X-API-Key': '${displayKey}',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      amount: 50000,
      method: 'pix',
      destination: {
        type: 'pix',
        pix_key: 'email@exemplo.com',
        pix_type: 'email',
      },
    }),
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "wdr_xxx",
    "amount": 50000,
    "fee": 0,
    "net_amount": 50000,
    "method": "pix",
    "status": "pending",
    "destination": {
      "type": "pix",
      "pix_key": "email@exemplo.com",
      "pix_type": "email"
    },
    "created_at": "2024-01-01T12:00:00Z"
  }
}`}</code>
                  </pre>
                </div>

              </div>

              {/* Cancel Withdrawal */}
              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6">
                <div className="flex items-center gap-3 flex-wrap">
                  <MethodBadge method="POST" />
                  <code className="text-sm font-mono text-tx-primary">/api/v1/external/withdrawals/:id/cancel</code>
                  <span className="text-tx-muted text-sm">- Cancel Withdrawal</span>
                </div>
                <p className="text-tx-secondary text-sm mt-3">
                  Cancels a pending withdrawal request.
                </p>
                <CodeBlock
                  curl={`curl -X POST ${EXTERNAL_API_BASE_URL}/withdrawals/wdr_xxx/cancel \\
  -H "X-API-Key: ${displayKey}"`}
                  js={`const response = await fetch(
  '${EXTERNAL_API_BASE_URL}/withdrawals/wdr_xxx/cancel',
  {
    method: 'POST',
    headers: {
      'X-API-Key': '${displayKey}',
    },
  }
);

const data = await response.json();`}
                />
                <div className="mt-4">
                  <p className="text-sm font-medium text-tx-muted mb-2">Response</p>
                  <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "success": true,
  "data": {
    "id": "wdr_xxx",
    "status": "cancelled"
  }
}`}</code>
                  </pre>
                </div>
              </div>
            </section>

            {/* WEBHOOKS */}
            <section id="webhooks">
              <h2 className="text-2xl font-bold text-tx-primary">Webhooks</h2>

              <div className="mt-6 bg-surface-card rounded-lg border border-border-DEFAULT p-6 space-y-6">
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Events</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    Configure webhook URLs in your dashboard to receive real-time notifications for the following events:
                  </p>
                  <div className="mt-3 grid sm:grid-cols-2 gap-2">
                    {[
                      'charge.created',
                      'charge.approved',
                      'charge.failed',
                      'charge.refunded',
                      'charge.expired',
                      'charge.chargeback',
                      'withdrawal.completed',
                      'withdrawal.failed',
                    ].map((event) => (
                      <div key={event} className="flex items-center gap-2 bg-surface-page rounded px-3 py-2 border border-border-DEFAULT">
                        <span className="w-2 h-2 rounded-full bg-green-500 shrink-0" />
                        <code className="text-sm font-mono text-tx-primary">{event}</code>
                      </div>
                    ))}
                  </div>
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Payload Example</h3>
                  <pre className="bg-gray-900 rounded-lg p-4 mt-2 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "event": "charge.approved",
  "data": {
    "id": "chr_xxx",
    "amount": 5000,
    "status": "approved",
    "payment_method": "pix",
    "paid_at": "2024-01-01T12:00:00Z",
    "customer": {
      "name": "João Silva",
      "email": "joao@email.com"
    }
  },
  "created_at": "2024-01-01T12:00:00Z"
}`}</code>
                  </pre>
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Signature Verification</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    All webhook requests include a <code className="text-green-500 bg-gray-800 px-1.5 py-0.5 rounded text-sm">X-Webhook-Signature</code> header
                    containing an HMAC-SHA256 signature of the raw request body using your webhook secret.
                  </p>
                  <CodeBlock
                    curl={`# Verify the signature in your server
# The signature is: HMAC-SHA256(raw_body, webhook_secret)
# Compare with the X-Webhook-Signature header

echo -n "$REQUEST_BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET"`}
                    js={`import crypto from 'crypto';

function verifyWebhookSignature(rawBody: string, signature: string, secret: string): boolean {
  const expected = crypto
    .createHmac('sha256', secret)
    .update(rawBody)
    .digest('hex');

  return crypto.timingSafeEqual(
    Buffer.from(signature),
    Buffer.from(expected)
  );
}

// In your webhook handler:
app.post('/webhooks/vector', (req, res) => {
  const signature = req.headers['x-webhook-signature'];
  const isValid = verifyWebhookSignature(
    req.rawBody,
    signature,
    process.env.WEBHOOK_SECRET
  );

  if (!isValid) {
    return res.status(401).json({ error: 'Invalid signature' });
  }

  const { event, data } = req.body;
  // Handle the event...

  res.status(200).json({ received: true });
});`}
                  />
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Retry Policy</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    If your endpoint does not return a 2xx response, we retry up to 3 times with exponential backoff:
                  </p>
                  <div className="mt-3 flex flex-wrap gap-3">
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">1st retry</p>
                      <p className="text-sm font-medium text-tx-primary">1 minute</p>
                    </div>
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">2nd retry</p>
                      <p className="text-sm font-medium text-tx-primary">5 minutes</p>
                    </div>
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">3rd retry</p>
                      <p className="text-sm font-medium text-tx-primary">30 minutes</p>
                    </div>
                  </div>
                </div>
              </div>
            </section>

          </main>
        </div>
      </div>
    </div>
  );
}
