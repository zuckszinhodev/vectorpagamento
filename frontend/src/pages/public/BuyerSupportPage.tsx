import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useThemeStore } from '@/stores/themeStore';
import {
  ShieldCheckIcon,
  BuildingStorefrontIcon,
  CreditCardIcon,
  ChevronDownIcon,
  MagnifyingGlassIcon,
  EnvelopeIcon,
  ClockIcon,
  DocumentTextIcon,
  LockClosedIcon,
  CheckCircleIcon,
  XMarkIcon,
  TruckIcon,
  ArrowUturnLeftIcon,
  PhotoIcon,
  TicketIcon,
  ArrowRightIcon,
} from '@heroicons/react/24/outline';

type FaqTag = 'Pagamento' | 'Entrega' | 'Reembolso' | 'Segurança';

interface Faq {
  q: string;
  a: string;
  tag: FaqTag;
}

/** Cada categoria tem cor e ícone próprios — dá para achar pela cor, sem ler. */
const TAG_META: Record<FaqTag, { icon: any; chip: string; ring: string; iconBg: string; dot: string }> = {
  Pagamento: {
    icon: CreditCardIcon,
    chip: 'bg-blue-50 text-blue-700',
    ring: 'hover:border-blue-200 open:border-blue-200',
    iconBg: 'bg-blue-50 text-blue-600',
    dot: 'bg-blue-500',
  },
  Entrega: {
    icon: TruckIcon,
    chip: 'bg-amber-50 text-amber-700',
    ring: 'hover:border-amber-200 open:border-amber-200',
    iconBg: 'bg-amber-50 text-amber-600',
    dot: 'bg-amber-500',
  },
  Reembolso: {
    icon: ArrowUturnLeftIcon,
    chip: 'bg-violet-50 text-violet-700',
    ring: 'hover:border-violet-200 open:border-violet-200',
    iconBg: 'bg-violet-50 text-violet-600',
    dot: 'bg-violet-500',
  },
  Segurança: {
    icon: ShieldCheckIcon,
    chip: 'bg-emerald-50 text-emerald-700',
    ring: 'hover:border-emerald-200 open:border-emerald-200',
    iconBg: 'bg-emerald-50 text-emerald-600',
    dot: 'bg-emerald-500',
  },
};

/** Limites espelham a validação do backend. */
const MAX_FILES = 3;
const MAX_FILE_BYTES = 5 * 1024 * 1024;

const FAQS: Faq[] = [
  {
    tag: 'Pagamento',
    q: 'Paguei com PIX e o pedido não confirmou. E agora?',
    a: 'A confirmação costuma levar poucos segundos, mas pode demorar alguns minutos em horários de pico. Se passar de 30 minutos com o valor debitado, guarde o comprovante e procure o vendedor — ele enxerga o pagamento no painel dele.',
  },
  {
    tag: 'Pagamento',
    q: 'O código PIX expirou antes de eu pagar.',
    a: 'Códigos PIX têm validade curta por segurança. Abra o link de compra novamente e gere um novo código. Se você chegou a pagar um código expirado, o valor é devolvido automaticamente pelo seu banco.',
  },
  {
    tag: 'Entrega',
    q: 'Paguei e não recebi o acesso ao produto.',
    a: 'A entrega é feita pelo vendedor, não pela processadora de pagamento. Confira também a caixa de spam do e-mail usado na compra. Se não chegar, entre em contato com o vendedor pelos canais informados por ele.',
  },
  {
    tag: 'Entrega',
    q: 'Comprei um produto físico. Como acompanho a entrega?',
    a: 'O código de rastreio é enviado pelo vendedor assim que o pedido é despachado. O prazo mostrado no checkout é uma estimativa da transportadora e começa a contar após a postagem.',
  },
  {
    tag: 'Reembolso',
    q: 'Quero cancelar ou pedir reembolso.',
    a: 'O prazo e as condições de reembolso são definidos pelo vendedor e costumam aparecer na página de compra. O pedido deve ser feito diretamente a ele, que solicita o estorno. Depois de aprovado, o valor leva alguns dias úteis para voltar, conforme o meio de pagamento.',
  },
  {
    tag: 'Segurança',
    q: 'Não reconheço uma cobrança com este nome na fatura.',
    a: 'Compras processadas aqui podem aparecer na fatura com o nome do vendedor ou o da processadora. Verifique se alguém da sua casa fez a compra. Se ainda assim não reconhecer, fale com o seu banco e conteste.',
  },
  {
    tag: 'Segurança',
    q: 'Meus dados de pagamento ficam guardados?',
    a: 'Não armazenamos os dados completos do seu cartão. As informações trafegam criptografadas e o processamento acontece em ambiente certificado.',
  },
];

export default function BuyerSupportPage() {
  // O store tem 'VectorPagamentos' fixo como valor inicial, então numa primeira
  // visita a página pintaria a marca errada até a busca do App responder. Aqui
  // ela é buscada direto e o store fica só como valor imediato.
  const storeName = useThemeStore((s) => s.platformName);
  const storeLogo = useThemeStore((s) => s.logoUrl);
  const [search, setSearch] = useState('');
  const [ticketOpen, setTicketOpen] = useState(false);
  const [lookupOpen, setLookupOpen] = useState(false);

  const { data: branding } = useQuery({
    queryKey: ['branding'],
    queryFn: async () => (await api.get('/branding')).data?.data,
    staleTime: 5 * 60 * 1000,
  });

  const platformName = branding?.platform_name || storeName;
  const logoUrl: string = branding?.logo_url || storeLogo || '';
  const brandColor: string = branding?.primary_color || '#ef4444';
  const supportEmail: string = branding?.support_email || '';

  const results = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return FAQS;
    return FAQS.filter((f) => `${f.q} ${f.a} ${f.tag}`.toLowerCase().includes(term));
  }, [search]);

  return (
    <div className="min-h-screen bg-[#f8fafc] text-gray-900">
      {/* ── Topo ───────────────────────────────── */}
      <header className="border-b border-black/5 bg-white">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-4 sm:px-6">
          {logoUrl ? (
            <img src={logoUrl} alt={platformName} className="h-9 w-auto max-w-[160px] object-contain object-left" />
          ) : (
            <span className="text-base font-bold">{platformName}</span>
          )}
          <span className="h-6 w-px bg-gray-200" aria-hidden="true" />
          <span className="text-sm font-medium text-gray-500">Suporte ao cliente</span>
          <button onClick={() => setLookupOpen(true)} className="ml-auto hidden items-center gap-2 rounded-lg border border-gray-200 px-3.5 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 sm:flex">
            <TicketIcon className="h-4 w-4" />
            Acompanhar chamado
          </button>
        </div>
      </header>

      {/* ── Hero com busca ─────────────────────── */}
      <section className="relative overflow-hidden bg-gray-950 text-white">
        <div className="absolute inset-0 opacity-20" style={{ background: `radial-gradient(circle at 50% 0%, ${brandColor}, transparent 58%)` }} />
        <div className="relative mx-auto max-w-3xl px-4 py-14 text-center sm:py-20">
          <h1 className="text-3xl font-bold tracking-tight sm:text-5xl">Como podemos ajudar?</h1>
          <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-white/60 sm:text-base">
            Respostas para as dúvidas mais comuns sobre compras processadas pela {platformName}.
          </p>

          <div className="relative mx-auto mt-7 max-w-lg">
            <MagnifyingGlassIcon className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Busque por PIX, reembolso, entrega..."
              className="w-full rounded-2xl border border-white/10 bg-white py-4 pl-12 pr-4 text-base text-gray-900 shadow-2xl shadow-black/30 outline-none placeholder:text-gray-400 focus:ring-4 focus:ring-white/10"
            />
          </div>
          <div className="mt-5 flex flex-col justify-center gap-2 sm:flex-row">
            <button onClick={() => setTicketOpen(true)} className="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white transition-opacity hover:opacity-90" style={{ backgroundColor: brandColor }}>
              Abrir um chamado <ArrowRightIcon className="h-4 w-4" />
            </button>
            <button onClick={() => setLookupOpen(true)} className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10 sm:hidden">
              <TicketIcon className="h-4 w-4" /> Acompanhar chamado
            </button>
          </div>
        </div>
      </section>

      <main className="mx-auto max-w-5xl px-4 py-10 sm:py-12">
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
          {/* ── Conteúdo ─────────────────────── */}
          <div className="min-w-0">
            {/* Quem resolve o quê: a confusão que mais gera atrito */}
            <section className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-black/5 bg-white p-5 shadow-sm">
                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50">
                  <BuildingStorefrontIcon className="h-5 w-5 text-emerald-600" />
                </span>
                <h2 className="mt-3 text-sm font-semibold">Produto e entrega</h2>
                <p className="mt-1.5 text-sm leading-relaxed text-gray-500">
                  Acesso, conteúdo, envio, troca e reembolso são responsabilidade do{' '}
                  <strong className="font-semibold text-gray-700">vendedor</strong>.
                </p>
              </div>

              <div className="rounded-xl border border-black/5 bg-white p-5 shadow-sm">
                <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50">
                  <CreditCardIcon className="h-5 w-5 text-blue-600" />
                </span>
                <h2 className="mt-3 text-sm font-semibold">Cobrança e pagamento</h2>
                <p className="mt-1.5 text-sm leading-relaxed text-gray-500">
                  Erro ao finalizar, cobrança duplicada ou valor divergente são tratados pela{' '}
                  <strong className="font-semibold text-gray-700">{platformName}</strong>.
                </p>
              </div>
            </section>

            {/* FAQ */}
            <section className="mt-8">
              <div className="mb-3 flex items-baseline justify-between gap-3">
                <h2 className="text-base font-semibold">Perguntas frequentes</h2>
                {search && (
                  <span className="text-xs text-gray-400">
                    {results.length} {results.length === 1 ? 'resultado' : 'resultados'}
                  </span>
                )}
              </div>

              {results.length === 0 ? (
                <div className="rounded-xl border border-dashed border-gray-200 bg-white p-10 text-center">
                  <p className="text-sm text-gray-500">Nenhuma resposta encontrada para "{search}".</p>
                  <p className="mt-1 text-xs text-gray-400">Tente outra palavra ou fale com o suporte ao lado.</p>
                </div>
              ) : (
                <div className="space-y-2.5">
                  {results.map((faq) => {
                    const meta = TAG_META[faq.tag];
                    return (
                      <details
                        key={faq.q}
                        className={`group overflow-hidden rounded-xl border border-black/5 bg-white shadow-sm transition-all duration-200 hover:shadow-md open:shadow-md ${meta.ring}`}
                      >
                        <summary className="flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 sm:px-5">
                          {/* Ícone da categoria: identificação imediata, sem ler o rótulo */}
                          <span
                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-transform duration-200 group-hover:scale-105 ${meta.iconBg}`}
                          >
                            <meta.icon className="h-4 w-4" />
                          </span>

                          <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium leading-snug text-gray-900">{faq.q}</span>
                            <span
                              className={`mt-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${meta.chip}`}
                            >
                              {faq.tag}
                            </span>
                          </span>

                          <ChevronDownIcon className="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-180" />
                        </summary>

                        {/* Faixa colorida à esquerda amarra a resposta à categoria */}
                        <div className="flex border-t border-black/5">
                          <span className={`w-1 shrink-0 ${meta.dot}`} />
                          <p className="px-4 py-4 text-sm leading-relaxed text-gray-500 sm:px-5">{faq.a}</p>
                        </div>
                      </details>
                    );
                  })}
                </div>
              )}
            </section>
          </div>

          {/* ── Contato ──────────────────────── */}
          <aside className="lg:sticky lg:top-8 lg:self-start">
            <div className="rounded-xl border border-black/5 bg-white p-5 shadow-sm">
              <h2 className="text-sm font-semibold">Não resolveu?</h2>
              <p className="mt-1.5 text-sm leading-relaxed text-gray-500">
                Fale com o time de pagamentos da {platformName}.
              </p>

              <button
                onClick={() => setTicketOpen(true)}
                className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
              >
                <EnvelopeIcon className="h-4 w-4" />
                Abrir chamado
              </button>

              {supportEmail && (
                <a
                  href={`mailto:${supportEmail}?subject=${encodeURIComponent('Ajuda com uma compra')}`}
                  className="mt-2 block text-center text-xs text-gray-400 underline-offset-2 hover:text-gray-600 hover:underline"
                >
                  ou envie um e-mail
                </a>
              )}

              <div className="mt-5 space-y-3 border-t border-black/5 pt-4">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                  Tenha em mãos
                </p>
                {[
                  { icon: EnvelopeIcon, text: 'E-mail usado na compra' },
                  { icon: ClockIcon, text: 'Data e valor do pagamento' },
                  { icon: DocumentTextIcon, text: 'Comprovante, se houver' },
                ].map((item) => (
                  <div key={item.text} className="flex items-center gap-2.5">
                    <item.icon className="h-4 w-4 shrink-0 text-gray-400" />
                    <span className="text-xs text-gray-600">{item.text}</span>
                  </div>
                ))}
                <p className="pt-1 text-[11px] leading-relaxed text-gray-400">
                  Com esses dados o atendimento localiza seu pedido bem mais rápido.
                </p>
              </div>
            </div>

            <div className="mt-3 flex items-start gap-2.5 rounded-xl border border-black/5 bg-white p-4 shadow-sm">
              <LockClosedIcon className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
              <p className="text-[11px] leading-relaxed text-gray-500">
                Nunca pedimos senha, código de cartão ou CVV por e-mail, telefone ou mensagem.
              </p>
            </div>
          </aside>
        </div>
      </main>

      <footer className="border-t border-black/5 bg-white">
        <div className="mx-auto max-w-5xl px-4 py-6 text-center">
          <p className="text-xs text-gray-400">
            {platformName} é a processadora de pagamentos. A responsabilidade pelo produto é do vendedor.
          </p>
        </div>
      </footer>

      {ticketOpen && <TicketDialog onClose={() => setTicketOpen(false)} />}
      {lookupOpen && <TicketLookupDialog onClose={() => setLookupOpen(false)} />}
    </div>
  );
}

type PublicTicket = {
  protocol: string;
  subject: string;
  status: string;
  message: string;
  admin_reply?: string | null;
  created_at?: string | null;
};

function TicketLookupDialog({ onClose }: { onClose: () => void }) {
  const [protocol, setProtocol] = useState('');
  const [email, setEmail] = useState('');
  const [ticket, setTicket] = useState<PublicTicket | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const field = 'w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition-shadow placeholder:text-gray-400 focus:border-red-400 focus:ring-4 focus:ring-red-500/10';

  const lookup = async () => {
    if (!protocol.trim() || !email.trim()) {
      setError('Informe o protocolo e o e-mail usado no chamado.');
      return;
    }
    setLoading(true);
    setError('');
    setTicket(null);
    try {
      const response = await api.post(`/buyer-support/tickets/${encodeURIComponent(protocol.trim().toUpperCase())}`, { email: email.trim() });
      setTicket(response.data.data);
    } catch (requestError: any) {
      setError(requestError.response?.data?.error?.message || 'Não foi possível localizar o chamado.');
    } finally {
      setLoading(false);
    }
  };

  const statusLabel: Record<string, string> = {
    open: 'Recebido', pending: 'Em atendimento', resolved: 'Resolvido', closed: 'Encerrado',
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-gray-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Acompanhar chamado">
      <div className="flex min-h-full items-start justify-center sm:items-center">
        <div className="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-gray-100 px-6 py-5">
            <div>
              <h2 className="text-lg font-bold text-gray-900">Acompanhar chamado</h2>
              <p className="mt-0.5 text-xs text-gray-500">Consulte o andamento com seus dados de acesso.</p>
            </div>
            <button onClick={onClose} className="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Fechar">
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>

          <div className="space-y-3 p-6">
            <input value={protocol} onChange={(e) => setProtocol(e.target.value)} className={`${field} uppercase`} placeholder="Protocolo (ex.: SUP-...)" />
            <input value={email} onChange={(e) => setEmail(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && void lookup()} type="email" className={field} placeholder="E-mail informado no chamado" />
            <button onClick={lookup} disabled={loading} className="w-full rounded-xl bg-gray-950 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-gray-800 disabled:opacity-50">
              {loading ? 'Consultando...' : 'Consultar chamado'}
            </button>
            {error && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

            {ticket && (
              <div className="mt-5 overflow-hidden rounded-xl border border-gray-200">
                <div className="flex items-center justify-between gap-3 bg-gray-50 px-4 py-3">
                  <span className="font-mono text-xs font-bold text-gray-700">{ticket.protocol}</span>
                  <span className="rounded-full bg-blue-100 px-2.5 py-1 text-[11px] font-bold text-blue-700">{statusLabel[ticket.status] || ticket.status}</span>
                </div>
                <div className="space-y-4 p-4">
                  <div>
                    <p className="text-sm font-bold text-gray-900">{ticket.subject}</p>
                    {ticket.created_at && <p className="mt-1 text-xs text-gray-400">Aberto em {new Date(ticket.created_at).toLocaleDateString('pt-BR')}</p>}
                  </div>
                  <div className="rounded-lg bg-gray-50 p-3 text-sm leading-relaxed text-gray-600">{ticket.message}</div>
                  {ticket.admin_reply ? (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                      <p className="text-xs font-bold uppercase tracking-wide text-emerald-700">Resposta do suporte</p>
                      <p className="mt-1.5 text-sm leading-relaxed text-emerald-900">{ticket.admin_reply}</p>
                    </div>
                  ) : (
                    <p className="text-xs text-gray-500">Seu chamado foi recebido e ainda aguarda resposta.</p>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

// ============================================================
//  ABERTURA DE CHAMADO
// ============================================================
function TicketDialog({ onClose }: { onClose: () => void }) {
  const [form, setForm] = useState({
    name: '',
    email: '',
    subject: '',
    category: 'pagamento',
    message: '',
    order_reference: '',
    website: '', // honeypot
  });
  const [files, setFiles] = useState<File[]>([]);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [protocol, setProtocol] = useState<string | null>(null);

  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
  const field = 'w-full rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm outline-none transition-shadow focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10';

  const addFiles = (list: FileList | null) => {
    if (!list) return;
    const incoming = Array.from(list);

    const tooBig = incoming.find((f) => f.size > MAX_FILE_BYTES);
    if (tooBig) {
      setError(`"${tooBig.name}" passa de 5MB.`);
      return;
    }
    if (files.length + incoming.length > MAX_FILES) {
      setError(`Envie no máximo ${MAX_FILES} imagens.`);
      return;
    }

    setError(null);
    setFiles((current) => [...current, ...incoming]);
  };

  const submit = async () => {
    setSending(true);
    setError(null);
    try {
      // multipart por causa dos anexos; sem arquivo o payload segue igual.
      const payload = new FormData();
      Object.entries(form).forEach(([key, value]) => payload.append(key, value));
      files.forEach((file) => payload.append('attachments[]', file));

      const response = await api.post('/buyer-support/tickets', payload, {
        headers: { 'Content-Type': undefined },
      });
      setProtocol(response.data?.data?.protocol ?? null);
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      setError(
        errors ? String(Object.values(errors)[0]) : err?.response?.data?.error?.message || 'Não consegui enviar agora.',
      );
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4 backdrop-blur-sm">
      <div className="flex min-h-full items-start justify-center sm:items-center">
        <div className="w-full max-w-lg rounded-2xl bg-white shadow-xl">
          {protocol ? (
            <div className="p-8 text-center">
              <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50">
                <CheckCircleIcon className="h-7 w-7 text-emerald-600" />
              </span>
              <h2 className="mt-5 text-lg font-semibold text-gray-900">Chamado registrado</h2>
              <p className="mt-2 text-sm leading-relaxed text-gray-500">
                Guarde o protocolo abaixo. Responderemos no e-mail informado.
              </p>
              <p className="mt-4 rounded-lg bg-gray-50 px-4 py-3 font-mono text-lg font-bold tracking-wider text-gray-900">
                {protocol}
              </p>
              <button
                onClick={onClose}
                className="mt-6 w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800"
              >
                Fechar
              </button>
            </div>
          ) : (
            <>
              <div className="flex items-center justify-between border-b border-black/5 px-6 py-4">
                <h2 className="text-base font-semibold text-gray-900">Abrir chamado</h2>
                <button onClick={onClose} className="text-gray-400 transition-colors hover:text-gray-600">
                  <XMarkIcon className="h-5 w-5" />
                </button>
              </div>

              <div className="space-y-3 px-6 py-5">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="Seu nome*" className={field} />
                  <input value={form.email} onChange={(e) => set({ email: e.target.value })} type="email" placeholder="E-mail da compra*" className={field} />
                </div>

                <select value={form.category} onChange={(e) => set({ category: e.target.value })} className={field}>
                  <option value="pagamento">Cobrança e pagamento</option>
                  <option value="entrega">Produto e entrega</option>
                  <option value="reembolso">Reembolso</option>
                  <option value="seguranca">Não reconheço a compra</option>
                  <option value="outro">Outro assunto</option>
                </select>

                <input value={form.subject} onChange={(e) => set({ subject: e.target.value })} placeholder="Assunto*" className={field} />
                <input value={form.order_reference} onChange={(e) => set({ order_reference: e.target.value })} placeholder="Nº do pedido ou data do pagamento (opcional)" className={field} />
                <textarea value={form.message} onChange={(e) => set({ message: e.target.value })} rows={4} placeholder="Descreva o que aconteceu*" className={`${field} resize-y`} />

                {/* Anexos */}
                <div>
                  <div className="flex flex-wrap gap-2">
                    {files.map((file, i) => (
                      <div key={i} className="group relative">
                        <img
                          src={URL.createObjectURL(file)}
                          alt=""
                          className="h-16 w-16 rounded-lg border border-gray-200 object-cover"
                        />
                        <button
                          type="button"
                          onClick={() => setFiles((current) => current.filter((_, j) => j !== i))}
                          className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-gray-900 text-white shadow-sm"
                          aria-label={`Remover ${file.name}`}
                        >
                          <XMarkIcon className="h-3 w-3" />
                        </button>
                      </div>
                    ))}

                    {files.length < MAX_FILES && (
                      <label className="flex h-16 w-16 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 text-gray-400 transition-colors hover:border-emerald-400 hover:text-emerald-500">
                        <PhotoIcon className="h-5 w-5" />
                        <span className="text-[10px] font-medium">Print</span>
                        <input
                          type="file"
                          accept="image/png,image/jpeg,image/webp"
                          multiple
                          className="hidden"
                          onChange={(e) => {
                            addFiles(e.target.files);
                            e.target.value = '';
                          }}
                        />
                      </label>
                    )}
                  </div>
                  <p className="mt-1.5 text-[11px] text-gray-400">
                    Até {MAX_FILES} imagens, 5MB cada. JPG, PNG ou WEBP.
                  </p>
                </div>

                {/* Honeypot: invisível para gente, irresistível para robô. */}
                <input
                  value={form.website}
                  onChange={(e) => set({ website: e.target.value })}
                  tabIndex={-1}
                  autoComplete="off"
                  aria-hidden
                  className="hidden"
                />

                {error && <p className="text-xs font-medium text-red-500">{error}</p>}

                <p className="text-[11px] leading-relaxed text-gray-400">
                  Nunca pedimos senha, código de cartão ou CVV. Não inclua esses dados aqui.
                </p>
              </div>

              <div className="flex gap-3 border-t border-black/5 px-6 py-4">
                <button onClick={onClose} className="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                  Cancelar
                </button>
                <button
                  onClick={submit}
                  disabled={sending}
                  className="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                >
                  {sending ? 'Enviando...' : 'Enviar chamado'}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
