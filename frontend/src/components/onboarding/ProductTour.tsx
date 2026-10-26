import { useEffect, useLayoutEffect, useState, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { useLocation, useNavigate } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { useTourStore, markTourSeen } from '@/stores/tourStore';

interface TourStep {
  /** Rota onde o passo acontece (com query string, se precisar). */
  path?: string;
  /** Valor do atributo data-tour do elemento destacado. Sem alvo, o card fica centralizado. */
  target?: string;
  title: string;
  body: string;
}

const steps: TourStep[] = [
  {
    path: '/dashboard',
    title: 'Bem-vindo à GorillaPay 👋',
    body: 'Em menos de um minuto vamos mostrar as principais funções da plataforma. Aqui no Dashboard você acompanha vendas, faturamento e saldo em tempo real.',
  },
  {
    path: '/charges',
    target: 'new-charge',
    title: 'Gerar uma cobrança PIX',
    body: 'Clique em "Nova Cobrança", informe o valor e os dados do cliente. O QR Code e o PIX copia-e-cola são gerados na hora, e você acompanha o pagamento nesta lista.',
  },
  {
    path: '/products',
    target: 'create-product',
    title: 'Criar um produto',
    body: 'Cadastre seu produto para ter um checkout próprio, link de pagamento, order bumps, upsells e programa de afiliados.',
  },
  {
    path: '/withdrawals',
    target: 'withdraw',
    title: 'Sacar seu saldo',
    body: 'Aqui você vê o saldo disponível e solicita saques via PIX para sua conta. O histórico de transferências fica logo abaixo.',
  },
  {
    path: '/integrations',
    target: 'integrations',
    title: 'Integrações',
    body: 'Conecte a GorillaPay às ferramentas que você já usa: pixels de anúncio e rastreamento de vendas, automações e mensagens. Busque o app aqui ou navegue pelas categorias.',
  },
  {
    path: '/domains',
    target: 'add-domain',
    title: 'Domínios próprios',
    body: 'Use seu próprio endereço no checkout, nas páginas de venda e na área de membros. Clique em "Adicionar domínio" e siga o guia "Como configurar" para apontar o DNS.',
  },
  {
    path: '/settings?tab=api',
    target: 'new-api-key',
    title: 'Criar uma chave de API',
    body: 'Para integrar seu site ou sistema, crie uma chave de API. Ela é exibida uma única vez: copie e guarde em local seguro.',
  },
  {
    path: '/settings?tab=api',
    target: 'api-docs-link',
    title: 'Documentação da API',
    body: 'Endpoints, exemplos de requisição e webhooks ficam na documentação. É por ela que seu time técnico faz a integração.',
  },
  {
    path: '/dashboard',
    target: 'profile-menu',
    title: 'Menu do seu perfil',
    body: 'Por aqui você acessa Configurações, Integrações, Domínios, a Documentação da API, o Suporte e pode rever este tutorial quando quiser.',
  },
  {
    title: 'Tudo pronto! 🚀',
    body: 'Agora é com você: crie seu primeiro produto ou gere uma cobrança PIX. Se precisar de ajuda, fale com o Suporte pelo menu do perfil.',
  },
];

const PAD = 6;
const GAP = 14;

export default function ProductTour({ userId }: { userId?: string }) {
  const { isOpen, step, setStep, close } = useTourStore();
  const navigate = useNavigate();
  const location = useLocation();
  const [rect, setRect] = useState<DOMRect | null>(null);
  const [viewport, setViewport] = useState({ w: window.innerWidth, h: window.innerHeight });

  const current = steps[step];
  const isLast = step === steps.length - 1;

  const finish = useCallback(() => {
    if (userId) markTourSeen(userId);
    close();
  }, [userId, close]);

  const go = useCallback(
    (next: number) => {
      if (next < 0) return;
      if (next >= steps.length) return finish();
      setRect(null);
      setStep(next);
    },
    [finish, setStep]
  );

  // Leva o usuário para a página do passo.
  useEffect(() => {
    if (!isOpen || !current?.path) return;
    const [pathname, query] = current.path.split('?');
    const samePage = location.pathname === pathname && (!query || location.search === `?${query}`);
    if (!samePage) navigate(current.path);
  }, [isOpen, step]); // eslint-disable-line react-hooks/exhaustive-deps

  // Acompanha a posição do elemento destacado (a página carrega dados e o layout se mexe).
  useLayoutEffect(() => {
    if (!isOpen) return;
    let scrolled = false;
    const measure = () => {
      setViewport({ w: window.innerWidth, h: window.innerHeight });
      if (!current?.target) return setRect(null);
      const el = document.querySelector<HTMLElement>(`[data-tour="${current.target}"]`);
      const r = el?.getBoundingClientRect();
      if (!el || !r || r.width === 0 || r.height === 0) return setRect(null);
      if (!scrolled) {
        scrolled = true;
        if (r.top < 70 || r.bottom > window.innerHeight - 20) {
          el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
      }
      setRect(r);
    };
    measure();
    const timer = window.setInterval(measure, 200);
    window.addEventListener('resize', measure);
    window.addEventListener('scroll', measure, true);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('resize', measure);
      window.removeEventListener('scroll', measure, true);
    };
  }, [isOpen, step, current?.target]);

  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') finish();
      else if (e.key === 'ArrowRight') go(step + 1);
      else if (e.key === 'ArrowLeft') go(step - 1);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isOpen, step, go, finish]);

  if (!isOpen || !current) return null;

  const cardWidth = Math.min(360, viewport.w - 32);
  let cardStyle: React.CSSProperties;
  if (rect) {
    const left = Math.min(Math.max(rect.left + rect.width / 2 - cardWidth / 2, 16), viewport.w - cardWidth - 16);
    cardStyle =
      rect.bottom > viewport.h * 0.55
        ? { left, bottom: viewport.h - rect.top + PAD + GAP, width: cardWidth }
        : { left, top: rect.bottom + PAD + GAP, width: cardWidth };
  } else {
    cardStyle = { left: (viewport.w - cardWidth) / 2, top: '50%', transform: 'translateY(-50%)', width: cardWidth };
  }

  return createPortal(
    <div className="fixed inset-0 z-[200]" role="dialog" aria-modal="true" aria-label="Tutorial da plataforma">
      {/* Bloqueia cliques na página enquanto o tour está aberto */}
      <div className={rect ? 'absolute inset-0' : 'absolute inset-0 bg-black/70 backdrop-blur-[2px]'} />

      {rect && (
        <div
          className="pointer-events-none absolute rounded-xl ring-2 ring-red-500 transition-all duration-300 ease-out"
          style={{
            top: rect.top - PAD,
            left: rect.left - PAD,
            width: rect.width + PAD * 2,
            height: rect.height + PAD * 2,
            boxShadow: '0 0 0 9999px rgba(0, 0, 0, 0.7), 0 0 24px 4px rgba(239, 68, 68, 0.45)',
          }}
        />
      )}

      <AnimatePresence mode="wait">
        <motion.div
          key={step}
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -8 }}
          transition={{ duration: 0.18 }}
          className="absolute"
          style={cardStyle}
        >
          <div className="rounded-card border border-border-DEFAULT bg-surface-elevated p-5 shadow-float">
            <div className="mb-2 flex items-start justify-between gap-3">
              <h3 className="text-base font-semibold text-tx-primary">{current.title}</h3>
              <button
                onClick={finish}
                className="-mr-1 -mt-1 rounded-element p-1 text-tx-muted transition-colors hover:bg-surface-hover hover:text-tx-primary"
                aria-label="Fechar tutorial"
              >
                <XMarkIcon className="h-4 w-4" />
              </button>
            </div>
            <p className="text-sm leading-relaxed text-tx-secondary">{current.body}</p>

            <div className="mt-5 flex items-center justify-between gap-3">
              <div className="flex items-center gap-1.5">
                {steps.map((_, i) => (
                  <span
                    key={i}
                    className={`h-1.5 rounded-full transition-all ${i === step ? 'w-4 bg-red-500' : 'w-1.5 bg-tx-muted/30'}`}
                  />
                ))}
              </div>
              <div className="flex items-center gap-2">
                {step === 0 ? (
                  <button
                    onClick={finish}
                    className="rounded-element px-3 py-1.5 text-xs font-medium text-tx-muted transition-colors hover:text-tx-primary"
                  >
                    Pular
                  </button>
                ) : (
                  <button
                    onClick={() => go(step - 1)}
                    className="rounded-element px-3 py-1.5 text-xs font-medium text-tx-secondary transition-colors hover:bg-surface-hover hover:text-tx-primary"
                  >
                    Voltar
                  </button>
                )}
                <button
                  onClick={() => go(step + 1)}
                  className="rounded-element bg-red-600 px-4 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-red-500"
                >
                  {isLast ? 'Concluir' : step === 0 ? 'Começar' : 'Próximo'}
                </button>
              </div>
            </div>
          </div>
        </motion.div>
      </AnimatePresence>
    </div>,
    document.body
  );
}
