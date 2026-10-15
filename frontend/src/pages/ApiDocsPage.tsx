import { useState, useEffect, useMemo } from 'react';
import { useThemeStore } from '@/stores/themeStore';
import {
  DocumentTextIcon,
  KeyIcon,
  CodeBracketIcon,
  BoltIcon,
  GlobeAltIcon,
  ShieldCheckIcon,
  CreditCardIcon,
  ShoppingBagIcon,
  UsersIcon,
  ChartBarIcon,
  ClipboardDocumentIcon,
  CheckIcon,
  SparklesIcon,
  PlayCircleIcon,
  ChevronDownIcon,
} from '@heroicons/react/24/outline';

// ─────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────
type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

interface Param {
  name: string;
  type: string;
  required: boolean;
  desc: string;
}

interface Endpoint {
  method: HttpMethod;
  path: string; // suffix after the external base, e.g. "/charges" or "/charges/:id/cancel"
  title: string;
  desc: string;
  params?: Param[];
  request?: string; // JSON body example
  response?: string; // JSON response example
}

interface Section {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  intro?: string;
  endpoints: Endpoint[];
}

const EXTERNAL_API_BASE_URL = 'https://api.vectorpagamentos.com/api/v1/external';
const API_PATH_PREFIX = '/api/v1/external';

// Real base URL used by the interactive tester — points at the same backend
// the app talks to (local in dev, production in prod).
const API_ROOT = (import.meta.env.VITE_API_URL || 'https://api.vectorpagamentos.com/api/v1').replace(/\/+$/, '');
const DEFAULT_TEST_BASE = `${API_ROOT}/external`;

// Extracts ":param" segments from a path, e.g. "/charges/:id/cancel" -> ["id"]
function pathParams(path: string): string[] {
  return (path.match(/:([a-zA-Z_]+)/g) || []).map((s) => s.slice(1));
}

// ─────────────────────────────────────────────────────────────
// Endpoint catalog (data-driven — code samples & the AI markdown
// export are both generated from this, so they never drift apart)
// ─────────────────────────────────────────────────────────────
const CATALOG: Section[] = [
  {
    id: 'charges',
    label: 'Cobranças (Cashin)',
    icon: BoltIcon,
    intro: 'Crie e gerencie cobranças via PIX, cartão de crédito e boleto.',
    endpoints: [
      {
        method: 'POST',
        path: '/charges',
        title: 'Criar cobrança PIX',
        desc: 'Cria uma cobrança PIX. Retorna o QR Code e o código copia-e-cola para pagamento.',
        params: [
          { name: 'amount', type: 'integer', required: true, desc: 'Valor em centavos (R$ 50,00 = 5000)' },
          { name: 'payment_method', type: 'string', required: true, desc: '"pix"' },
          { name: 'customer.name', type: 'string', required: true, desc: 'Nome do cliente' },
          { name: 'customer.email', type: 'string', required: true, desc: 'E-mail do cliente' },
          { name: 'customer.document', type: 'string', required: true, desc: 'CPF/CNPJ (somente números)' },
          { name: 'description', type: 'string', required: false, desc: 'Descrição da cobrança' },
        ],
        request: `{
  "amount": 5000,
  "payment_method": "pix",
  "customer": {
    "name": "João Silva",
    "email": "joao@email.com",
    "document": "12345678900"
  },
  "description": "Produto X"
}`,
        response: `{
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
}`,
      },
      {
        method: 'POST',
        path: '/charges',
        title: 'Criar cobrança com cartão',
        desc: 'Cria uma cobrança com cartão de crédito. Suporta parcelamento (até 12x).',
        params: [
          { name: 'amount', type: 'integer', required: true, desc: 'Valor em centavos' },
          { name: 'payment_method', type: 'string', required: true, desc: '"credit_card"' },
          { name: 'installments', type: 'integer', required: false, desc: 'Número de parcelas (1–12)' },
          { name: 'card.number', type: 'string', required: true, desc: 'Número do cartão' },
          { name: 'card.holder_name', type: 'string', required: true, desc: 'Nome impresso no cartão' },
          { name: 'card.exp_month', type: 'string', required: true, desc: 'Mês de expiração (MM)' },
          { name: 'card.exp_year', type: 'string', required: true, desc: 'Ano de expiração (YYYY)' },
          { name: 'card.cvv', type: 'string', required: true, desc: 'Código de segurança' },
        ],
        request: `{
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
    "exp_year": "2028",
    "cvv": "123"
  }
}`,
        response: `{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 10000,
    "status": "approved",
    "payment_method": "credit_card",
    "installments": 3,
    "paid_at": "2024-01-01T12:00:00Z"
  }
}`,
      },
      {
        method: 'POST',
        path: '/charges',
        title: 'Criar cobrança com boleto',
        desc: 'Cria uma cobrança com boleto bancário. Retorna a URL e o código de barras.',
        params: [
          { name: 'amount', type: 'integer', required: true, desc: 'Valor em centavos' },
          { name: 'payment_method', type: 'string', required: true, desc: '"boleto"' },
          { name: 'customer', type: 'object', required: true, desc: 'Dados do cliente (name, email, document)' },
        ],
        request: `{
  "amount": 15000,
  "payment_method": "boleto",
  "customer": {
    "name": "João Silva",
    "email": "joao@email.com",
    "document": "12345678900"
  }
}`,
        response: `{
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
}`,
      },
      {
        method: 'GET',
        path: '/charges',
        title: 'Listar cobranças',
        desc: 'Retorna uma lista paginada de cobranças. Suporta filtro por status.',
        params: [
          { name: 'status', type: 'string', required: false, desc: 'pending, approved, failed, refunded, cancelled' },
          { name: 'page', type: 'integer', required: false, desc: 'Página (padrão 1)' },
          { name: 'per_page', type: 'integer', required: false, desc: 'Itens por página (padrão 20)' },
        ],
        response: `{
  "success": true,
  "data": [ ... ],
  "meta": { "current_page": 1, "last_page": 5, "total": 100, "per_page": 20 }
}`,
      },
      {
        method: 'GET',
        path: '/charges/:id',
        title: 'Consultar cobrança',
        desc: 'Retorna os detalhes de uma cobrança específica pelo ID.',
        response: `{
  "success": true,
  "data": {
    "id": "chr_xxx",
    "amount": 5000,
    "status": "approved",
    "payment_method": "pix",
    "paid_at": "2024-01-01T12:00:00Z"
  }
}`,
      },
      {
        method: 'POST',
        path: '/charges/:id/cancel',
        title: 'Cancelar cobrança',
        desc: 'Cancela uma cobrança pendente. Apenas cobranças com status "pending" podem ser canceladas.',
        response: `{ "success": true, "data": { "id": "chr_xxx", "status": "cancelled" } }`,
      },
      {
        method: 'POST',
        path: '/charges/:id/refund',
        title: 'Estornar cobrança',
        desc: 'Estorna uma cobrança. Informe "amount" para estorno parcial (opcional).',
        params: [
          { name: 'amount', type: 'integer', required: false, desc: 'Valor em centavos para estorno parcial' },
        ],
        request: `{ "amount": 5000 }`,
        response: `{ "success": true, "data": { "id": "chr_xxx", "status": "refunded", "refunded_amount": 5000 } }`,
      },
    ],
  },
  {
    id: 'payments',
    label: 'Pagamentos',
    icon: CreditCardIcon,
    intro: 'Processe pagamentos de cobranças existentes, consulte métodos e simule parcelas.',
    endpoints: [
      {
        method: 'POST',
        path: '/payments/:chargeId/process',
        title: 'Processar pagamento',
        desc: 'Processa o pagamento de uma cobrança pendente. Usado quando a cobrança é criada primeiro e paga depois.',
        params: [
          { name: 'payment_method', type: 'string', required: false, desc: 'credit_card, debit_card, pix ou boleto' },
          { name: 'card', type: 'object', required: false, desc: 'Obrigatório para cartão (number, holder_name, expiration_month, expiration_year, cvv)' },
          { name: 'installments', type: 'integer', required: false, desc: 'Número de parcelas (1–12)' },
        ],
        request: `{
  "payment_method": "credit_card",
  "installments": 1,
  "card": {
    "number": "4111111111111111",
    "holder_name": "JOAO SILVA",
    "expiration_month": "12",
    "expiration_year": "2028",
    "cvv": "123"
  }
}`,
        response: `{ "success": true, "data": { "id": "chr_xxx", "status": "approved" } }`,
      },
      {
        method: 'GET',
        path: '/payments/methods',
        title: 'Métodos de pagamento',
        desc: 'Lista os métodos de pagamento disponíveis e suas capacidades.',
        response: `{
  "success": true,
  "data": [
    { "id": "credit_card", "name": "Cartão de Crédito", "enabled": true, "installments_enabled": true, "max_installments": 12 },
    { "id": "debit_card", "name": "Cartão de Débito", "enabled": true, "installments_enabled": false },
    { "id": "pix", "name": "PIX", "enabled": true, "instant": true },
    { "id": "boleto", "name": "Boleto Bancário", "enabled": true, "days_to_expire": 3 }
  ]
}`,
      },
      {
        method: 'GET',
        path: '/payments/installments',
        title: 'Simular parcelas',
        desc: 'Calcula as opções de parcelamento para um valor, incluindo juros.',
        params: [
          { name: 'amount', type: 'integer', required: true, desc: 'Valor em centavos (mín. 100)' },
        ],
        response: `{
  "success": true,
  "data": [
    { "number": 1, "amount": 10000, "total": 10000, "interest_free": true, "interest_rate": 0 },
    { "number": 2, "amount": 5000, "total": 10000, "interest_free": true, "interest_rate": 0 }
  ]
}`,
      },
      {
        method: 'POST',
        path: '/payments/transactions/:transactionId/capture',
        title: 'Capturar transação',
        desc: 'Captura uma transação de cartão pré-autorizada.',
        response: `{ "success": true, "data": { "id": "txn_xxx", "status": "captured" } }`,
      },
    ],
  },
  {
    id: 'products',
    label: 'Produtos',
    icon: ShoppingBagIcon,
    intro: 'Gerencie o catálogo de produtos usados em checkouts e links de pagamento.',
    endpoints: [
      {
        method: 'GET',
        path: '/products',
        title: 'Listar produtos',
        desc: 'Retorna produtos paginados. Suporta busca e filtro por tipo.',
        params: [
          { name: 'type', type: 'string', required: false, desc: 'digital, physical ou service' },
          { name: 'search', type: 'string', required: false, desc: 'Busca por nome ou descrição' },
          { name: 'page', type: 'integer', required: false, desc: 'Página' },
        ],
        response: `{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "total": 12 } }`,
      },
      {
        method: 'POST',
        path: '/products',
        title: 'Criar produto',
        desc: 'Cria um novo produto no catálogo.',
        params: [
          { name: 'name', type: 'string', required: true, desc: 'Nome do produto (máx. 255)' },
          { name: 'type', type: 'string', required: true, desc: 'digital, physical ou service' },
          { name: 'price', type: 'integer', required: true, desc: 'Preço em centavos (mín. 100)' },
          { name: 'description', type: 'string', required: false, desc: 'Descrição' },
          { name: 'category', type: 'string', required: false, desc: 'Categoria (obrigatória se afiliados ativos)' },
          { name: 'image_url', type: 'string', required: false, desc: 'URL da imagem' },
          { name: 'affiliate_enabled', type: 'boolean', required: false, desc: 'Habilita programa de afiliados' },
          { name: 'affiliate_commission_percent', type: 'number', required: false, desc: 'Comissão (%) 0–100' },
        ],
        request: `{
  "name": "Curso de Marketing",
  "type": "digital",
  "price": 19700,
  "description": "Curso completo",
  "category": "educacao"
}`,
        response: `{ "success": true, "data": { "id": "prd_xxx", "name": "Curso de Marketing", "slug": "curso-de-marketing-ab12cd", "price": 19700 } }`,
      },
      {
        method: 'GET',
        path: '/products/:id',
        title: 'Consultar produto',
        desc: 'Retorna os detalhes de um produto pelo ID.',
        response: `{ "success": true, "data": { "id": "prd_xxx", "name": "Curso de Marketing", "price": 19700 } }`,
      },
      {
        method: 'PUT',
        path: '/products/:id',
        title: 'Atualizar produto',
        desc: 'Atualiza os dados de um produto existente.',
        request: `{ "name": "Curso de Marketing 2.0", "price": 24700 }`,
        response: `{ "success": true, "data": { "id": "prd_xxx", "name": "Curso de Marketing 2.0", "price": 24700 } }`,
      },
      {
        method: 'DELETE',
        path: '/products/:id',
        title: 'Excluir produto',
        desc: 'Remove um produto do catálogo.',
        response: `{ "success": true }`,
      },
      {
        method: 'GET',
        path: '/products/:id/stats',
        title: 'Estatísticas do produto',
        desc: 'Retorna métricas de vendas e conversão do produto.',
        response: `{ "success": true, "data": { "sales": 42, "revenue": 827400, "conversion_rate": 3.2 } }`,
      },
    ],
  },
  {
    id: 'checkouts',
    label: 'Checkouts',
    icon: CodeBracketIcon,
    intro: 'Crie e gerencie páginas de checkout hospedadas para seus produtos.',
    endpoints: [
      {
        method: 'GET',
        path: '/checkouts',
        title: 'Listar checkouts',
        desc: 'Retorna uma lista paginada de checkouts.',
        response: `{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "total": 8 } }`,
      },
      {
        method: 'POST',
        path: '/checkouts',
        title: 'Criar checkout',
        desc: 'Cria uma nova página de checkout.',
        params: [
          { name: 'name', type: 'string', required: true, desc: 'Nome do checkout (máx. 255)' },
          { name: 'type', type: 'string', required: false, desc: 'redirect, embedded ou popup' },
          { name: 'product_id', type: 'uuid', required: false, desc: 'Produto vinculado' },
          { name: 'settings', type: 'object', required: false, desc: 'Configurações do checkout' },
          { name: 'theme', type: 'object', required: false, desc: 'Personalização visual' },
        ],
        request: `{
  "name": "Checkout Curso",
  "type": "redirect",
  "product_id": "prd_xxx"
}`,
        response: `{ "success": true, "data": { "id": "chk_xxx", "name": "Checkout Curso", "url": "https://pay..." } }`,
      },
      {
        method: 'GET',
        path: '/checkouts/:id',
        title: 'Consultar checkout',
        desc: 'Retorna os detalhes de um checkout pelo ID.',
        response: `{ "success": true, "data": { "id": "chk_xxx", "name": "Checkout Curso" } }`,
      },
      {
        method: 'PUT',
        path: '/checkouts/:id',
        title: 'Atualizar checkout',
        desc: 'Atualiza um checkout existente.',
        request: `{ "name": "Checkout Curso (Black Friday)" }`,
        response: `{ "success": true, "data": { "id": "chk_xxx", "name": "Checkout Curso (Black Friday)" } }`,
      },
      {
        method: 'DELETE',
        path: '/checkouts/:id',
        title: 'Excluir checkout',
        desc: 'Remove um checkout.',
        response: `{ "success": true }`,
      },
      {
        method: 'POST',
        path: '/checkouts/:id/clone',
        title: 'Clonar checkout',
        desc: 'Cria uma cópia de um checkout existente.',
        response: `{ "success": true, "data": { "id": "chk_yyy", "name": "Checkout Curso (cópia)" } }`,
      },
      {
        method: 'GET',
        path: '/checkouts/:id/analytics',
        title: 'Analytics do checkout',
        desc: 'Retorna métricas de visitas e conversão do checkout.',
        response: `{ "success": true, "data": { "views": 1240, "conversions": 87, "conversion_rate": 7.0 } }`,
      },
    ],
  },
  {
    id: 'affiliates',
    label: 'Afiliados',
    icon: UsersIcon,
    intro: 'Gerencie afiliados, comissões e acompanhe o desempenho de cada um.',
    endpoints: [
      {
        method: 'GET',
        path: '/affiliates',
        title: 'Listar afiliados',
        desc: 'Retorna uma lista paginada de afiliados.',
        response: `{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "total": 5 } }`,
      },
      {
        method: 'POST',
        path: '/affiliates',
        title: 'Criar afiliado',
        desc: 'Cadastra um novo afiliado.',
        params: [
          { name: 'name', type: 'string', required: true, desc: 'Nome do afiliado' },
          { name: 'email', type: 'string', required: true, desc: 'E-mail' },
          { name: 'commission_type', type: 'string', required: true, desc: 'percentage ou fixed' },
          { name: 'commission_value', type: 'number', required: true, desc: 'Valor da comissão (% ou centavos)' },
          { name: 'product_id', type: 'uuid', required: false, desc: 'Produto vinculado' },
        ],
        request: `{
  "name": "Maria Afiliada",
  "email": "maria@email.com",
  "commission_type": "percentage",
  "commission_value": 30
}`,
        response: `{ "success": true, "data": { "id": "aff_xxx", "code": "MARIA30", "status": "active" } }`,
      },
      {
        method: 'GET',
        path: '/affiliates/:id',
        title: 'Consultar afiliado',
        desc: 'Retorna os detalhes de um afiliado pelo ID.',
        response: `{ "success": true, "data": { "id": "aff_xxx", "name": "Maria Afiliada" } }`,
      },
      {
        method: 'PUT',
        path: '/affiliates/:id',
        title: 'Atualizar afiliado',
        desc: 'Atualiza os dados de um afiliado.',
        request: `{ "commission_value": 25 }`,
        response: `{ "success": true, "data": { "id": "aff_xxx", "commission_value": 25 } }`,
      },
      {
        method: 'DELETE',
        path: '/affiliates/:id',
        title: 'Excluir afiliado',
        desc: 'Remove um afiliado.',
        response: `{ "success": true }`,
      },
      {
        method: 'GET',
        path: '/affiliates/:id/stats',
        title: 'Estatísticas do afiliado',
        desc: 'Retorna métricas de vendas e comissões do afiliado.',
        response: `{ "success": true, "data": { "sales": 18, "commission_total": 53100 } }`,
      },
      {
        method: 'GET',
        path: '/affiliates/:id/commissions',
        title: 'Comissões do afiliado',
        desc: 'Lista as comissões geradas pelo afiliado.',
        response: `{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "total": 18 } }`,
      },
    ],
  },
  {
    id: 'withdrawals',
    label: 'Saques (Cashout)',
    icon: GlobeAltIcon,
    intro: 'Consulte saldo e solicite saques do seu saldo disponível via PIX.',
    endpoints: [
      {
        method: 'GET',
        path: '/withdrawals/summary',
        title: 'Consultar saldo',
        desc: 'Retorna o resumo de saldo: disponível, pendente e total sacado.',
        response: `{
  "success": true,
  "data": { "available_balance": 150000, "pending_balance": 25000, "total_withdrawn": 500000 }
}`,
      },
      {
        method: 'GET',
        path: '/withdrawals',
        title: 'Listar saques',
        desc: 'Retorna uma lista paginada de saques. Suporta filtro por status.',
        params: [
          { name: 'status', type: 'string', required: false, desc: 'pending, completed, failed, cancelled' },
          { name: 'page', type: 'integer', required: false, desc: 'Página' },
        ],
        response: `{ "success": true, "data": [ ... ], "meta": { "current_page": 1, "total": 45 } }`,
      },
      {
        method: 'GET',
        path: '/withdrawals/:id',
        title: 'Consultar saque',
        desc: 'Retorna os detalhes de um saque pelo ID.',
        response: `{ "success": true, "data": { "id": "wdr_xxx", "amount": 50000, "status": "pending" } }`,
      },
      {
        method: 'POST',
        path: '/withdrawals',
        title: 'Solicitar saque',
        desc: 'Solicita um novo saque do saldo disponível via PIX.',
        params: [
          { name: 'amount', type: 'integer', required: true, desc: 'Valor em centavos (mín. 100)' },
          { name: 'method', type: 'string', required: true, desc: '"pix"' },
          { name: 'destination.type', type: 'string', required: true, desc: '"pix"' },
          { name: 'destination.pix_key', type: 'string', required: true, desc: 'Chave PIX de destino' },
          { name: 'destination.pix_type', type: 'string', required: true, desc: 'cpf, cnpj, email, phone ou random' },
          { name: 'notes', type: 'string', required: false, desc: 'Observações (máx. 500)' },
          { name: 'idempotency_key', type: 'string', required: false, desc: 'Evita duplicatas (máx. 120)' },
        ],
        request: `{
  "amount": 50000,
  "method": "pix",
  "destination": {
    "type": "pix",
    "pix_key": "email@exemplo.com",
    "pix_type": "email"
  }
}`,
        response: `{
  "success": true,
  "data": {
    "id": "wdr_xxx",
    "amount": 50000,
    "fee": 0,
    "net_amount": 50000,
    "method": "pix",
    "status": "pending",
    "created_at": "2024-01-01T12:00:00Z"
  }
}`,
      },
      {
        method: 'POST',
        path: '/withdrawals/:id/cancel',
        title: 'Cancelar saque',
        desc: 'Cancela um saque pendente.',
        response: `{ "success": true, "data": { "id": "wdr_xxx", "status": "cancelled" } }`,
      },
    ],
  },
  {
    id: 'reports',
    label: 'Relatórios',
    icon: ChartBarIcon,
    intro: 'Consulte dados agregados de dashboard, transações, receita e exportações.',
    endpoints: [
      {
        method: 'GET',
        path: '/reports/dashboard',
        title: 'Dashboard',
        desc: 'Retorna o resumo do dashboard (receita, vendas, aprovação) por período.',
        params: [
          { name: 'period', type: 'string', required: false, desc: '7d, 30d, 90d, 1y (padrão 30d)' },
          { name: 'date_from', type: 'string', required: false, desc: 'Data inicial (YYYY-MM-DD)' },
          { name: 'date_to', type: 'string', required: false, desc: 'Data final (YYYY-MM-DD)' },
        ],
        response: `{ "success": true, "data": { "summary": { ... }, "daily_revenue": [ ... ] } }`,
      },
      {
        method: 'GET',
        path: '/reports/transactions',
        title: 'Transações',
        desc: 'Retorna um relatório de transações no período.',
        response: `{ "success": true, "data": [ ... ] }`,
      },
      {
        method: 'GET',
        path: '/reports/revenue',
        title: 'Receita',
        desc: 'Retorna a receita agregada por período.',
        response: `{ "success": true, "data": { ... } }`,
      },
      {
        method: 'GET',
        path: '/reports/products',
        title: 'Produtos mais vendidos',
        desc: 'Retorna o ranking de produtos por vendas/receita.',
        response: `{ "success": true, "data": [ ... ] }`,
      },
      {
        method: 'GET',
        path: '/reports/affiliates',
        title: 'Desempenho de afiliados',
        desc: 'Retorna o desempenho dos afiliados no período.',
        response: `{ "success": true, "data": [ ... ] }`,
      },
      {
        method: 'GET',
        path: '/reports/export/:type',
        title: 'Exportar relatório',
        desc: 'Exporta um relatório (ex.: charges, withdrawals) em arquivo.',
        response: `Arquivo (CSV/XLSX) ou { "success": true, "data": { "url": "https://..." } }`,
      },
    ],
  },
];

// ─────────────────────────────────────────────────────────────
// Code sample generation (kept in sync with the catalog)
// ─────────────────────────────────────────────────────────────
function buildCurl(ep: Endpoint, key: string): string {
  const url = `${EXTERNAL_API_BASE_URL}${ep.path}`;
  if (ep.method === 'GET') {
    return `curl -X GET "${url}" \\
  -H "X-API-Key: ${key}"`;
  }
  if (ep.request) {
    return `curl -X ${ep.method} ${url} \\
  -H "X-API-Key: ${key}" \\
  -H "Content-Type: application/json" \\
  -d '${ep.request}'`;
  }
  return `curl -X ${ep.method} ${url} \\
  -H "X-API-Key: ${key}"`;
}

function buildJs(ep: Endpoint, key: string): string {
  const url = `${EXTERNAL_API_BASE_URL}${ep.path}`;
  const hasBody = ep.method !== 'GET' && ep.request;
  const lines = [`const response = await fetch('${url}', {`];
  if (ep.method !== 'GET') lines.push(`  method: '${ep.method}',`);
  lines.push('  headers: {');
  lines.push(`    'X-API-Key': '${key}',`);
  if (hasBody) lines.push(`    'Content-Type': 'application/json',`);
  lines.push('  },');
  if (hasBody) lines.push(`  body: JSON.stringify(${ep.request}),`);
  lines.push('});');
  lines.push('');
  lines.push('const data = await response.json();');
  return lines.join('\n');
}

// ─────────────────────────────────────────────────────────────
// Full markdown export (for the "copy for AI" button)
// ─────────────────────────────────────────────────────────────
function buildMarkdown(platformName: string, key: string): string {
  const out: string[] = [];
  out.push(`# ${platformName} — Documentação da API`);
  out.push('');
  out.push(`API REST para integrações externas. Todos os corpos e respostas são JSON. Valores monetários em centavos (R$ 10,00 = 1000).`);
  out.push('');
  out.push(`- Base URL: \`${EXTERNAL_API_BASE_URL}\``);
  out.push(`- Autenticação: header \`X-API-Key: ${key}\``);
  out.push(`- Rate limit: 120 requisições/minuto por chave (excedente retorna 429).`);
  out.push(`- Idempotência: envie \`Idempotency-Key\` em requisições POST para evitar duplicatas.`);
  out.push('');
  out.push('## Formato de erro');
  out.push('```json');
  out.push('{ "success": false, "error": { "code": "string", "message": "string" } }');
  out.push('```');
  out.push('');
  out.push('## Códigos HTTP');
  out.push('| Código | Erro | Descrição |');
  out.push('| --- | --- | --- |');
  out.push('| 400 | invalid_request | Parâmetros inválidos |');
  out.push('| 401 | unauthorized | Chave de API ausente ou inválida |');
  out.push('| 403 | forbidden | Permissões insuficientes |');
  out.push('| 404 | not_found | Recurso não encontrado |');
  out.push('| 429 | rate_limited | Muitas requisições |');
  out.push('| 500 | internal_error | Erro interno |');
  out.push('');

  for (const section of CATALOG) {
    out.push(`## ${section.label}`);
    if (section.intro) out.push(section.intro);
    out.push('');
    for (const ep of section.endpoints) {
      out.push(`### ${ep.method} ${API_PATH_PREFIX}${ep.path} — ${ep.title}`);
      out.push(ep.desc);
      if (ep.params && ep.params.length) {
        out.push('');
        out.push('| Parâmetro | Tipo | Obrigatório | Descrição |');
        out.push('| --- | --- | --- | --- |');
        for (const p of ep.params) {
          out.push(`| ${p.name} | ${p.type} | ${p.required ? 'Sim' : 'Não'} | ${p.desc} |`);
        }
      }
      out.push('');
      out.push('```bash');
      out.push(buildCurl(ep, key));
      out.push('```');
      if (ep.response) {
        out.push('Resposta:');
        out.push('```json');
        out.push(ep.response);
        out.push('```');
      }
      out.push('');
    }
  }

  out.push('## Webhooks');
  out.push('Eventos: charge.created, charge.approved, charge.failed, charge.refunded, charge.expired, charge.chargeback, withdrawal.completed, withdrawal.failed.');
  out.push('Cada requisição inclui o header `X-Webhook-Signature` (HMAC-SHA256 do corpo bruto usando seu webhook secret).');
  out.push('Política de retentativa: 3 tentativas com backoff (1min, 5min, 30min) se o endpoint não retornar 2xx.');
  out.push('');
  return out.join('\n');
}

// ─────────────────────────────────────────────────────────────
// Small UI helpers
// ─────────────────────────────────────────────────────────────
function useCopy() {
  const [copied, setCopied] = useState(false);
  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch { /* noop */ }
      ta.remove();
    }
    setCopied(true);
    setTimeout(() => setCopied(false), 1800);
  };
  return { copied, copy };
}

function CodeBlock({ curl, js }: { curl: string; js: string }) {
  const [tab, setTab] = useState<'curl' | 'js'>('curl');
  const { copied, copy } = useCopy();
  const active = tab === 'curl' ? curl : js;

  return (
    <div className="mt-3 overflow-hidden rounded-xl border border-gray-700/60">
      <div className="flex items-center justify-between bg-gray-800/80 border-b border-gray-700/60">
        <div className="flex">
          <button
            onClick={() => setTab('curl')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${tab === 'curl' ? 'text-accent-primary border-b-2 border-accent-primary bg-gray-900' : 'text-gray-400 hover:text-gray-200'}`}
          >
            cURL
          </button>
          <button
            onClick={() => setTab('js')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${tab === 'js' ? 'text-accent-primary border-b-2 border-accent-primary bg-gray-900' : 'text-gray-400 hover:text-gray-200'}`}
          >
            JavaScript
          </button>
        </div>
        <button
          onClick={() => copy(active)}
          className="mr-2 inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium text-gray-300 hover:bg-gray-700/60 transition-colors"
        >
          {copied ? <CheckIcon className="h-3.5 w-3.5 text-emerald-400" /> : <ClipboardDocumentIcon className="h-3.5 w-3.5" />}
          {copied ? 'Copiado!' : 'Copiar'}
        </button>
      </div>
      <pre className="bg-gray-900 p-4 overflow-x-auto text-sm leading-relaxed">
        <code className="text-gray-100 whitespace-pre">{active}</code>
      </pre>
    </div>
  );
}

function MethodBadge({ method }: { method: string }) {
  const colors: Record<string, string> = {
    GET: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    POST: 'bg-blue-500/15 text-blue-400 border-blue-500/30',
    PUT: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
    DELETE: 'bg-red-500/15 text-red-400 border-red-500/30',
  };
  return (
    <span className={`inline-block px-2 py-0.5 text-xs font-bold rounded border ${colors[method] || ''}`}>
      {method}
    </span>
  );
}

interface TestResult {
  status: number;
  ok: boolean;
  body: string;
  durationMs: number;
}

function EndpointCard({ ep, apiKey, onTest, active }: { ep: Endpoint; apiKey: string; onTest: (ep: Endpoint) => void; active: boolean }) {
  return (
    <div className={`mt-6 rounded-xl border bg-surface-card p-5 sm:p-6 transition-colors ${active ? 'border-accent-primary' : 'border-border-DEFAULT'}`}>
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-3 flex-wrap">
          <MethodBadge method={ep.method} />
          <code className="text-sm font-mono text-tx-primary break-all">{API_PATH_PREFIX}{ep.path}</code>
        </div>
        <button
          onClick={() => onTest(ep)}
          className="inline-flex items-center gap-1.5 rounded-lg bg-accent-primary px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-accent-hover"
        >
          <PlayCircleIcon className="h-4 w-4" />
          Testar
        </button>
      </div>
      <p className="mt-2 text-base font-semibold text-tx-primary">{ep.title}</p>
      <p className="text-tx-secondary text-sm mt-1">{ep.desc}</p>

      {ep.params && ep.params.length > 0 && (
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
            <tbody className="text-tx-secondary divide-y divide-border-DEFAULT">
              {ep.params.map((p) => (
                <tr key={p.name}>
                  <td className="px-4 py-2 font-mono text-accent-primary">{p.name}</td>
                  <td className="px-4 py-2">{p.type}</td>
                  <td className="px-4 py-2">{p.required ? 'Sim' : 'Não'}</td>
                  <td className="px-4 py-2">{p.desc}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <CodeBlock curl={buildCurl(ep, apiKey)} js={buildJs(ep, apiKey)} />

      {ep.response && (
        <div className="mt-4">
          <p className="text-sm font-medium text-tx-muted mb-2">Resposta de exemplo</p>
          <pre className="bg-gray-900 rounded-lg p-4 overflow-x-auto border border-gray-700">
            <code className="text-gray-100 text-sm whitespace-pre">{ep.response}</code>
          </pre>
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Apiary-style live console (right column / drawer)
// ─────────────────────────────────────────────────────────────
function Console({
  ep,
  token,
  setToken,
  baseUrl,
  setBaseUrl,
  onClose,
}: {
  ep: Endpoint | null;
  token: string;
  setToken: (v: string) => void;
  baseUrl: string;
  setBaseUrl: (v: string) => void;
  onClose: () => void;
}) {
  const [showToken, setShowToken] = useState(false);
  const [pathValues, setPathValues] = useState<Record<string, string>>({});
  const [queryValues, setQueryValues] = useState<Record<string, string>>({});
  const [body, setBody] = useState('');
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<TestResult | null>(null);
  const [err, setErr] = useState('');

  const params = ep ? pathParams(ep.path) : [];
  const isGet = ep?.method === 'GET';
  const queryParams = ep && isGet ? (ep.params || []) : [];

  // Reset inputs when a different endpoint is loaded
  useEffect(() => {
    setPathValues({});
    setQueryValues({});
    setBody(ep?.request || '');
    setResult(null);
    setErr('');
  }, [ep]);

  const resolvedPath = useMemo(() => {
    if (!ep) return '';
    let p = ep.path;
    params.forEach((name) => {
      p = p.replace(`:${name}`, encodeURIComponent(pathValues[name] || `:${name}`));
    });
    return p;
  }, [ep, params, pathValues]);

  const send = async () => {
    if (!ep) return;
    setErr('');
    setResult(null);
    if (!token.trim()) { setErr('Informe sua chave de API abaixo para testar.'); return; }
    const missing = params.find((name) => !pathValues[name]?.trim());
    if (missing) { setErr(`Preencha o parâmetro ":${missing}".`); return; }

    let url = `${baseUrl.replace(/\/+$/, '')}${resolvedPath}`;
    if (isGet && queryParams.length) {
      const qs = new URLSearchParams();
      queryParams.forEach((p) => {
        const v = queryValues[p.name];
        if (v !== undefined && v !== '') qs.append(p.name, v);
      });
      const q = qs.toString();
      if (q) url += `?${q}`;
    }

    const headers: Record<string, string> = { 'X-API-Key': token.trim() };
    let payload: string | undefined;
    if (!isGet && body.trim()) {
      headers['Content-Type'] = 'application/json';
      try { JSON.parse(body); } catch { setErr('O corpo não é um JSON válido.'); return; }
      payload = body;
    }

    setLoading(true);
    const started = performance.now();
    try {
      const resp = await fetch(url, { method: ep.method, headers, body: payload });
      const text = await resp.text();
      let pretty = text;
      try { pretty = JSON.stringify(JSON.parse(text), null, 2); } catch { /* raw */ }
      setResult({ status: resp.status, ok: resp.ok, body: pretty, durationMs: Math.round(performance.now() - started) });
    } catch (e: any) {
      setErr(`Falha: ${e?.message || 'erro de rede/CORS'}. Verifique a Base URL e o CORS da API.`);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex h-full flex-col bg-[#0d1117] text-gray-100">
      <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
        <div className="flex items-center gap-2">
          <PlayCircleIcon className="h-5 w-5 text-accent-primary" />
          <span className="text-sm font-semibold">Testar Endpoint</span>
        </div>
        <button onClick={onClose} className="rounded-md p-1.5 text-gray-400 hover:bg-white/5 hover:text-white xl:hidden">
          <ChevronDownIcon className="h-5 w-5 rotate-90" />
        </button>
      </div>

      <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4">
        {/* Auth */}
        <div>
          <label className="block text-[11px] font-medium uppercase tracking-wider text-gray-400 mb-1">Chave de API</label>
          <div className="relative">
            <input
              type={showToken ? 'text' : 'password'}
              value={token}
              onChange={(e) => setToken(e.target.value)}
              placeholder="sk_live_..."
              autoComplete="off"
              className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 pr-16 text-sm font-mono text-gray-100 focus:border-accent-primary focus:outline-none"
            />
            <button type="button" onClick={() => setShowToken((s) => !s)} className="absolute right-2 top-1/2 -translate-y-1/2 text-xs font-medium text-accent-primary">
              {showToken ? 'Ocultar' : 'Mostrar'}
            </button>
          </div>
        </div>
        <div>
          <label className="block text-[11px] font-medium uppercase tracking-wider text-gray-400 mb-1">Base URL</label>
          <input
            value={baseUrl}
            onChange={(e) => setBaseUrl(e.target.value)}
            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm font-mono text-gray-100 focus:border-accent-primary focus:outline-none"
          />
        </div>

        <div className="border-t border-white/10 pt-4">
          {!ep ? (
            <p className="text-sm text-gray-500">
              Clique em <span className="font-semibold text-accent-primary">Testar</span> em qualquer endpoint à esquerda para carregá-lo aqui.
            </p>
          ) : (
            <>
              <div className="flex items-center gap-2 flex-wrap">
                <MethodBadge method={ep.method} />
                <code className="text-xs font-mono text-gray-300 break-all">{ep.path}</code>
              </div>
              <p className="mt-2 text-sm font-semibold">{ep.title}</p>

              {params.length > 0 && (
                <div className="mt-3 space-y-2">
                  {params.map((name) => (
                    <div key={name}>
                      <label className="block text-[11px] font-medium text-gray-400 mb-1">Path: {name}</label>
                      <input
                        value={pathValues[name] || ''}
                        onChange={(e) => setPathValues((v) => ({ ...v, [name]: e.target.value }))}
                        placeholder={name === 'id' ? 'ex: chr_123' : name}
                        className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-gray-100 focus:border-accent-primary focus:outline-none"
                      />
                    </div>
                  ))}
                </div>
              )}

              {isGet && queryParams.length > 0 && (
                <div className="mt-3 space-y-2">
                  {queryParams.map((p) => (
                    <div key={p.name}>
                      <label className="block text-[11px] font-medium text-gray-400 mb-1">{p.name} <span className="text-gray-600">({p.type})</span></label>
                      <input
                        value={queryValues[p.name] || ''}
                        onChange={(e) => setQueryValues((v) => ({ ...v, [p.name]: e.target.value }))}
                        placeholder={p.desc}
                        className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-gray-100 focus:border-accent-primary focus:outline-none"
                      />
                    </div>
                  ))}
                </div>
              )}

              {!isGet && (
                <div className="mt-3">
                  <label className="block text-[11px] font-medium text-gray-400 mb-1">Corpo (JSON)</label>
                  <textarea
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    spellCheck={false}
                    rows={Math.min(16, Math.max(4, body.split('\n').length))}
                    className="w-full rounded-lg border border-white/10 bg-black/40 px-3 py-2 font-mono text-xs text-gray-100 focus:border-accent-primary focus:outline-none"
                  />
                </div>
              )}

              <button
                onClick={send}
                disabled={loading}
                className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-60"
              >
                <PlayCircleIcon className="h-4 w-4" />
                {loading ? 'Enviando...' : `Enviar ${ep.method}`}
              </button>
              <code className="mt-2 block text-[11px] text-gray-500 break-all">{ep.method} {baseUrl.replace(/\/+$/, '')}{resolvedPath}</code>

              {err && <p className="mt-3 text-sm font-medium text-red-400">{err}</p>}

              {/* Output */}
              <div className="mt-4">
                <p className="text-[11px] font-medium uppercase tracking-wider text-gray-400 mb-2">Saída da API</p>
                {result ? (
                  <>
                    <div className="flex items-center gap-2 mb-2">
                      <span className={`inline-block rounded px-2 py-0.5 text-xs font-bold ${result.ok ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400'}`}>
                        {result.status} {result.ok ? 'OK' : 'ERRO'}
                      </span>
                      <span className="text-xs text-gray-500">{result.durationMs} ms</span>
                    </div>
                    <pre className="rounded-lg border border-white/10 bg-black/40 p-3 overflow-x-auto max-h-[50vh]">
                      <code className="text-xs text-gray-100 whitespace-pre">{result.body}</code>
                    </pre>
                  </>
                ) : (
                  <pre className="rounded-lg border border-white/10 bg-black/40 p-3 text-xs text-gray-600">
                    A resposta da API aparecerá aqui após enviar.
                  </pre>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────
// Page
// ─────────────────────────────────────────────────────────────
const NAV = [
  { id: 'introduction', label: 'Introdução', icon: DocumentTextIcon },
  { id: 'authentication', label: 'Autenticação', icon: KeyIcon },
  { id: 'rate-limits', label: 'Rate Limits & Erros', icon: ShieldCheckIcon },
  ...CATALOG.map((s) => ({ id: s.id, label: s.label, icon: s.icon })),
  { id: 'webhooks', label: 'Webhooks', icon: CodeBracketIcon },
];

export default function ApiDocsPage({ apiKey }: { apiKey?: string | null }) {
  const platformName = useThemeStore((s) => s.platformName);
  const [activeSection, setActiveSection] = useState('introduction');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const displayKey = apiKey || 'sk_live_xxxxxxxxxxxx';
  const { copied: docsCopied, copy: copyDocs } = useCopy();
  const markdown = useMemo(() => buildMarkdown(platformName, displayKey), [platformName, displayKey]);

  // Interactive tester config (shared with the console)
  const [token, setToken] = useState(apiKey || '');
  const [baseUrl, setBaseUrl] = useState(DEFAULT_TEST_BASE);
  const [activeEp, setActiveEp] = useState<Endpoint | null>(null);
  const [consoleOpen, setConsoleOpen] = useState(false);

  const handleTest = (ep: Endpoint) => {
    setActiveEp(ep);
    setConsoleOpen(true);
  };

  useEffect(() => {
    const handleScroll = () => {
      const offsets = NAV.map(({ id }) => {
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
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex min-w-0 items-center gap-4 sm:gap-6">
              <img
                src="/img/gorillapay-logo.webp"
                alt="GorillaPay"
                width={1024}
                height={650}
                className="h-auto w-20 shrink-0 object-contain sm:w-32"
              />
              <div className="min-w-0">
              <h1 className="text-3xl font-bold text-white">Documentação da API {platformName}</h1>
              <p className="mt-2 max-w-2xl text-gray-400">
                Integre a {platformName} na sua aplicação com nossa API REST. Cobranças, pagamentos, produtos, checkouts, afiliados, saques e relatórios.
              </p>
              <div className="mt-4 inline-flex max-w-full flex-wrap items-center gap-2 bg-gray-800 rounded-lg px-4 py-2 border border-gray-700">
                <span className="text-gray-400 text-sm">Base URL:</span>
                <code className="text-accent-primary text-sm font-mono break-all">{EXTERNAL_API_BASE_URL}</code>
              </div>
              </div>
            </div>
            <button
              onClick={() => copyDocs(markdown)}
              className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-accent-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-accent-hover"
              title="Copia toda a documentação em Markdown para colar em uma IA (ChatGPT, Claude, etc.)"
            >
              {docsCopied ? 'Documentação copiada!' : 'Copiar documentação para IA'}
            </button>
          </div>
        </div>
      </div>

      <div className="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="flex gap-8">
          {/* Mobile sidebar toggle */}
          <button
            onClick={() => setSidebarOpen(!sidebarOpen)}
            className="lg:hidden fixed bottom-4 right-4 z-50 bg-accent-primary text-white p-3 rounded-full shadow-lg"
            aria-label="Toggle navigation"
          >
            <DocumentTextIcon className="w-6 h-6" />
          </button>

          {/* Sidebar */}
          <aside
            className={`${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0 fixed lg:sticky top-0 lg:top-8 left-0 z-40 lg:z-0 w-64 h-screen lg:h-auto bg-surface-card lg:bg-transparent border-r lg:border-r-0 border-border-DEFAULT p-4 lg:p-0 transition-transform lg:transition-none overflow-y-auto lg:self-start lg:shrink-0`}
          >
            <nav className="space-y-1">
              {NAV.map(({ id, label, icon: Icon }) => (
                <a
                  key={id}
                  href={`#${id}`}
                  onClick={() => setSidebarOpen(false)}
                  className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors ${
                    activeSection === id
                      ? 'bg-accent-primary/10 text-accent-primary border border-accent-primary/20'
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
            <div className="fixed inset-0 bg-black/50 z-30 lg:hidden" onClick={() => setSidebarOpen(false)} />
          )}

          {/* Main content */}
          <main className="flex-1 min-w-0 space-y-16">
            {/* INTRODUCTION */}
            <section id="introduction">
              <h2 className="text-2xl font-bold text-tx-primary">Introdução</h2>
              <div className="mt-4 bg-surface-card rounded-xl border border-border-DEFAULT p-6 space-y-4">
                <p className="text-tx-secondary">
                  A API da {platformName} é organizada em torno de REST. Aceita corpos de requisição em JSON,
                  retorna respostas em JSON e usa códigos HTTP e autenticação padrão.
                </p>
                <div className="grid sm:grid-cols-3 gap-4 mt-4">
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Base URL</p>
                    <code className="text-xs text-accent-primary font-mono mt-1 block break-all">{EXTERNAL_API_BASE_URL}</code>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Formato</p>
                    <p className="text-xs text-tx-secondary mt-1">Todas as requisições e respostas em JSON</p>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <p className="text-sm font-medium text-tx-primary">Valores</p>
                    <p className="text-xs text-tx-secondary mt-1">Em centavos (R$ 10,00 = 1000)</p>
                  </div>
                </div>
              </div>
            </section>

            {/* AUTHENTICATION */}
            <section id="authentication">
              <h2 className="text-2xl font-bold text-tx-primary">Autenticação</h2>
              <div className="mt-4 bg-surface-card rounded-xl border border-border-DEFAULT p-6 space-y-4">
                <p className="text-tx-secondary">
                  Para integrações externas, envie uma chave secreta no header{' '}
                  <code className="text-accent-primary bg-gray-800 px-1.5 py-0.5 rounded text-sm">X-API-Key</code>.
                  Chaves podem ser geradas em <strong className="text-tx-primary">Configurações &gt; Chaves de API</strong>.
                </p>
                <div className="bg-gray-900 rounded-lg p-4 border border-gray-700">
                  <p className="text-sm text-gray-400 mb-2">Header</p>
                  <code className="text-accent-primary font-mono text-sm break-all">X-API-Key: {displayKey}</code>
                </div>
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <code className="text-sm text-accent-primary font-mono">sk_live_*</code>
                    <p className="text-xs text-tx-secondary mt-1">Produção. Use no servidor para criar cobranças e saques.</p>
                  </div>
                  <div className="bg-surface-page rounded-lg p-4 border border-border-DEFAULT">
                    <code className="text-sm text-amber-500 font-mono">sk_test_*</code>
                    <p className="text-xs text-tx-secondary mt-1">Sandbox/testes. Nunca exponha chaves secretas no frontend.</p>
                  </div>
                </div>
              </div>
            </section>

            {/* RATE LIMITS & ERRORS */}
            <section id="rate-limits">
              <h2 className="text-2xl font-bold text-tx-primary">Rate Limits & Erros</h2>
              <div className="mt-4 bg-surface-card rounded-xl border border-border-DEFAULT p-6 space-y-6">
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Rate Limits</h3>
                  <p className="text-tx-secondary mt-1">
                    120 requisições por minuto por chave de API. Ao exceder, a API retorna status{' '}
                    <code className="text-accent-primary bg-gray-800 px-1.5 py-0.5 rounded text-sm">429</code>.
                  </p>
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Idempotência</h3>
                  <p className="text-tx-secondary mt-1">
                    Envie o header{' '}
                    <code className="text-accent-primary bg-gray-800 px-1.5 py-0.5 rounded text-sm">Idempotency-Key</code>{' '}
                    em requisições POST para garantir que a mesma operação não seja processada duas vezes.
                  </p>
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Formato de erro</h3>
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
                  <h3 className="text-lg font-semibold text-tx-primary">Códigos HTTP</h3>
                  <div className="mt-2 overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-border-DEFAULT">
                          <th className="text-left py-2 text-tx-muted font-medium">Código</th>
                          <th className="text-left py-2 text-tx-muted font-medium">Erro</th>
                          <th className="text-left py-2 text-tx-muted font-medium">Descrição</th>
                        </tr>
                      </thead>
                      <tbody className="text-tx-secondary">
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">400</td><td>invalid_request</td><td>Parâmetros inválidos</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">401</td><td>unauthorized</td><td>Chave de API ausente ou inválida</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">403</td><td>forbidden</td><td>Permissões insuficientes</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">404</td><td>not_found</td><td>Recurso não encontrado</td></tr>
                        <tr className="border-b border-border-DEFAULT"><td className="py-2 font-mono">429</td><td>rate_limited</td><td>Muitas requisições</td></tr>
                        <tr><td className="py-2 font-mono">500</td><td>internal_error</td><td>Erro interno</td></tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </section>

            {/* DYNAMIC SECTIONS */}
            {CATALOG.map((section) => (
              <section id={section.id} key={section.id}>
                <h2 className="text-2xl font-bold text-tx-primary">{section.label}</h2>
                {section.intro && <p className="mt-2 text-tx-secondary">{section.intro}</p>}
                {section.endpoints.map((ep) => (
                  <EndpointCard
                    key={`${ep.method}-${ep.path}-${ep.title}`}
                    ep={ep}
                    apiKey={displayKey}
                    onTest={handleTest}
                    active={activeEp === ep}
                  />
                ))}
              </section>
            ))}

            {/* WEBHOOKS */}
            <section id="webhooks">
              <h2 className="text-2xl font-bold text-tx-primary">Webhooks</h2>
              <div className="mt-6 bg-surface-card rounded-xl border border-border-DEFAULT p-6 space-y-6">
                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Eventos</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    Configure URLs de webhook no seu dashboard para receber notificações em tempo real dos eventos:
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
                        <span className="w-2 h-2 rounded-full bg-accent-primary shrink-0" />
                        <code className="text-sm font-mono text-tx-primary">{event}</code>
                      </div>
                    ))}
                  </div>
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Exemplo de payload</h3>
                  <pre className="bg-gray-900 rounded-lg p-4 mt-2 overflow-x-auto border border-gray-700">
                    <code className="text-gray-100 text-sm">{`{
  "event": "charge.approved",
  "data": {
    "id": "chr_xxx",
    "amount": 5000,
    "status": "approved",
    "payment_method": "pix",
    "paid_at": "2024-01-01T12:00:00Z",
    "customer": { "name": "João Silva", "email": "joao@email.com" }
  },
  "created_at": "2024-01-01T12:00:00Z"
}`}</code>
                  </pre>
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Verificação de assinatura</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    Todas as requisições de webhook incluem o header{' '}
                    <code className="text-accent-primary bg-gray-800 px-1.5 py-0.5 rounded text-sm">X-Webhook-Signature</code>{' '}
                    contendo uma assinatura HMAC-SHA256 do corpo bruto usando seu webhook secret.
                  </p>
                  <CodeBlock
                    curl={`# Verifique a assinatura no seu servidor
# Assinatura: HMAC-SHA256(raw_body, webhook_secret)
# Compare com o header X-Webhook-Signature

echo -n "$REQUEST_BODY" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET"`}
                    js={`import crypto from 'crypto';

function verifyWebhookSignature(rawBody, signature, secret) {
  const expected = crypto
    .createHmac('sha256', secret)
    .update(rawBody)
    .digest('hex');

  return crypto.timingSafeEqual(
    Buffer.from(signature),
    Buffer.from(expected)
  );
}

// No seu handler de webhook:
app.post('/webhooks/vector', (req, res) => {
  const signature = req.headers['x-webhook-signature'];
  const isValid = verifyWebhookSignature(req.rawBody, signature, process.env.WEBHOOK_SECRET);

  if (!isValid) return res.status(401).json({ error: 'Invalid signature' });

  const { event, data } = req.body;
  // Trate o evento...

  res.status(200).json({ received: true });
});`}
                  />
                </div>

                <div>
                  <h3 className="text-lg font-semibold text-tx-primary">Política de retentativa</h3>
                  <p className="text-tx-secondary text-sm mt-2">
                    Se o seu endpoint não retornar uma resposta 2xx, tentamos novamente até 3 vezes com backoff exponencial:
                  </p>
                  <div className="mt-3 flex flex-wrap gap-3">
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">1ª tentativa</p>
                      <p className="text-sm font-medium text-tx-primary">1 minuto</p>
                    </div>
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">2ª tentativa</p>
                      <p className="text-sm font-medium text-tx-primary">5 minutos</p>
                    </div>
                    <div className="bg-surface-page rounded-lg px-4 py-2 border border-border-DEFAULT">
                      <p className="text-xs text-tx-muted">3ª tentativa</p>
                      <p className="text-sm font-medium text-tx-primary">30 minutos</p>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </main>

          {/* Live console — sticky right column on desktop */}
          <aside className="hidden xl:block w-[420px] shrink-0">
            <div className="sticky top-8 h-[calc(100vh-4rem)] overflow-hidden rounded-xl border border-white/10 shadow-lg">
              <Console
                ep={activeEp}
                token={token}
                setToken={setToken}
                baseUrl={baseUrl}
                setBaseUrl={setBaseUrl}
                onClose={() => setConsoleOpen(false)}
              />
            </div>
          </aside>
        </div>
      </div>

      {/* Live console — slide-over drawer on smaller screens */}
      {consoleOpen && (
        <div className="xl:hidden">
          <div className="fixed inset-0 z-40 bg-black/50" onClick={() => setConsoleOpen(false)} />
          <div className="fixed inset-y-0 right-0 z-50 w-full max-w-md shadow-2xl">
            <Console
              ep={activeEp}
              token={token}
              setToken={setToken}
              baseUrl={baseUrl}
              setBaseUrl={setBaseUrl}
              onClose={() => setConsoleOpen(false)}
            />
          </div>
        </div>
      )}
    </div>
  );
}
