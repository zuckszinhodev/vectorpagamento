import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  SwatchIcon,
  ChatBubbleBottomCenterTextIcon,
  CreditCardIcon,
  Squares2X2Icon,
  UserIcon,
  ArrowRightOnRectangleIcon,
  MagnifyingGlassIcon,
  GlobeAltIcon,
  TicketIcon,
  ArrowTrendingUpIcon,
  ChevronDownIcon,
  ArrowTopRightOnSquareIcon,
  DevicePhoneMobileIcon,
} from '@heroicons/react/24/outline';

/**
 * Aba de configurações do Checkout Builder.
 *
 * Só expõe o que o checkout realmente consome. O que mora em outra tela
 * (cupons, domínios) aparece como atalho, e não como campo duplicado — dois
 * lugares para a mesma configuração é o que gera dúvida sobre qual vale.
 */
export interface CheckoutExtraSettings {
  texts?: {
    ctaLabel?: string;
    ctaLoadingLabel?: string;
    summaryTitle?: string;
    securityNote?: string;
  };
  form?: {
    collectCpf?: boolean;
    collectPhone?: boolean;
    collectAddress?: boolean;
  };
  postPayment?: {
    type?: 'default' | 'url';
    url?: string;
  };
  seo?: {
    title?: string;
    description?: string;
  };
  mobile?: {
    stickyCta?: boolean;
  };
}

function Section({
  icon: Icon,
  title,
  description,
  children,
  defaultOpen = false,
}: {
  icon: any;
  title: string;
  description: string;
  children: React.ReactNode;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div className="overflow-hidden rounded-xl border border-white/10 bg-black/20">
      <button
        onClick={() => setOpen(!open)}
        className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-white/5"
      >
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10">
          <Icon className="h-4 w-4 text-emerald-400" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-medium text-white">{title}</span>
          <span className="block truncate text-[11px] text-white/55">{description}</span>
        </span>
        <ChevronDownIcon
          className={`h-4 w-4 shrink-0 text-white/55 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && <div className="space-y-3 border-t border-white/10 px-4 py-4">{children}</div>}
    </div>
  );
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="mb-1.5 block text-xs font-medium text-white/55">{label}</label>
      {children}
      {hint && <p className="mt-1 text-[11px] text-white/45">{hint}</p>}
    </div>
  );
}

function Toggle({
  label,
  hint,
  checked,
  onChange,
}: {
  label: string;
  hint?: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <label className="flex cursor-pointer items-start gap-3">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="mt-0.5 accent-emerald-500"
      />
      <span className="min-w-0">
        <span className="block text-sm text-white/85">{label}</span>
        {hint && <span className="mt-0.5 block text-[11px] text-white/55">{hint}</span>}
      </span>
    </label>
  );
}

/** Atalho para configurações que vivem em outra tela do painel. */
function Shortcut({ to, label }: { to: string; label: string }) {
  return (
    <Link
      to={to}
      target="_blank"
      className="flex items-center justify-between gap-2 rounded-lg border border-white/10 px-3 py-2.5 text-sm text-white/85 transition-colors hover:bg-white/5 hover:text-white"
    >
      {label}
      <ArrowTopRightOnSquareIcon className="h-4 w-4 shrink-0 text-white/55" />
    </Link>
  );
}

const input = 'w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white outline-none focus:border-emerald-500';

export default function CheckoutSettingsPanel({
  settings,
  onChange,
}: {
  settings: any;
  onChange: (patch: any) => void;
}) {
  const theme = settings.theme ?? {};
  const payment = settings.payment ?? {};
  const extra: CheckoutExtraSettings = settings.extra ?? {};

  const setTheme = (patch: any) => onChange({ theme: { ...theme, ...patch } });
  const setPayment = (patch: any) => onChange({ payment: { ...payment, ...patch } });
  const setExtra = (key: keyof CheckoutExtraSettings, patch: any) =>
    onChange({ extra: { ...extra, [key]: { ...(extra[key] ?? {}), ...patch } } });

  const methods: string[] = payment.methods ?? ['pix'];
  const toggleMethod = (method: string) => {
    const next = methods.includes(method) ? methods.filter((m) => m !== method) : [...methods, method];
    setPayment({ methods: next.length ? next : ['pix'] });
  };

  return (
    <div className="space-y-2.5">
      {/* ── Layout ────────────────────────────── */}
      <Section icon={Squares2X2Icon} title="Layout do checkout" description="Uma página ou duas colunas">
        <div className="grid grid-cols-1 gap-2">
          {[
            { value: 'modern-split', label: 'Duas colunas', hint: 'Formulário à esquerda, resumo fixo à direita' },
            { value: 'classic', label: 'Página única', hint: 'Tudo em coluna só, mais direto no mobile' },
          ].map((option) => {
            const active = (theme.template || 'modern-split') === option.value;
            return (
              <button
                key={option.value}
                onClick={() => setTheme({ template: option.value })}
                className={`rounded-lg border p-3 text-left transition-colors ${
                  active ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-white/10 hover:bg-white/5'
                }`}
              >
                <span className="block text-sm font-medium text-white">{option.label}</span>
                <span className="mt-0.5 block text-[11px] text-white/55">{option.hint}</span>
              </button>
            );
          })}
        </div>
      </Section>

      {/* ── Pagamento ─────────────────────────── */}
      <Section icon={CreditCardIcon} title="Pagamento e parcelamento" description="Métodos aceitos e condições">
        <Field label="Métodos aceitos">
          <div className="space-y-2">
            {[
              { id: 'pix', label: 'PIX' },
              { id: 'credit_card', label: 'Cartão de crédito' },
              { id: 'boleto', label: 'Boleto' },
            ].map((method) => (
              <Toggle
                key={method.id}
                label={method.label}
                checked={methods.includes(method.id)}
                onChange={() => toggleMethod(method.id)}
              />
            ))}
          </div>
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Parcelas máximas">
            <select
              value={payment.maxInstallments ?? 12}
              onChange={(e) => setPayment({ maxInstallments: Number(e.target.value) })}
              className={input}
            >
              {[1, 2, 3, 4, 6, 10, 12].map((n) => (
                <option key={n} value={n}>{n}x</option>
              ))}
            </select>
          </Field>

          <Field label={`Desconto no PIX — ${payment.pixDiscount ?? 0}%`}>
            <input
              type="range"
              min={0}
              max={30}
              value={payment.pixDiscount ?? 0}
              onChange={(e) => setPayment({ pixDiscount: Number(e.target.value) })}
              className="w-full accent-emerald-500"
            />
          </Field>
        </div>

        <p className="rounded-lg bg-amber-500/10 px-3 py-2 text-[11px] leading-relaxed text-amber-400">
          Só aparecem no checkout os métodos que a adquirente processa. Hoje, apenas PIX está ativo no sistema.
        </p>
      </Section>

      {/* ── Campos do formulário ──────────────── */}
      <Section icon={UserIcon} title="Campos do formulário" description="O que pedir ao cliente">
        <Toggle
          label="Telefone"
          hint="Necessário para o bot de WhatsApp e recuperação de venda"
          checked={extra.form?.collectPhone !== false}
          onChange={(v) => setExtra('form', { collectPhone: v })}
        />
        <Toggle
          label="CPF"
          hint="Obrigatório para emissão de nota e antifraude"
          checked={extra.form?.collectCpf !== false}
          onChange={(v) => setExtra('form', { collectCpf: v })}
        />
        <Toggle
          label="Endereço"
          hint="Produto físico exige endereço mesmo com isto desligado"
          checked={extra.form?.collectAddress === true}
          onChange={(v) => setExtra('form', { collectAddress: v })}
        />
        <p className="rounded-lg bg-white/5 px-3 py-2 text-[11px] leading-relaxed text-white/55">
          Cada campo a mais reduz a conversão. Peça só o que você realmente usa.
        </p>
      </Section>

      {/* ── Textos e CTAs ─────────────────────── */}
      <Section icon={ChatBubbleBottomCenterTextIcon} title="Textos e chamadas" description="Botão de compra e avisos">
        <Field label="Texto do botão de compra" hint="Deixe vazio para usar “Finalizar compra • valor”.">
          <input
            value={extra.texts?.ctaLabel ?? ''}
            onChange={(e) => setExtra('texts', { ctaLabel: e.target.value })}
            placeholder="Quero garantir o meu"
            className={input}
          />
        </Field>

        <Field label="Texto enquanto processa">
          <input
            value={extra.texts?.ctaLoadingLabel ?? ''}
            onChange={(e) => setExtra('texts', { ctaLoadingLabel: e.target.value })}
            placeholder="Processando..."
            className={input}
          />
        </Field>

        <Field label="Título do resumo">
          <input
            value={extra.texts?.summaryTitle ?? ''}
            onChange={(e) => setExtra('texts', { summaryTitle: e.target.value })}
            placeholder="Resumo do pedido"
            className={input}
          />
        </Field>

        <Field label="Aviso de segurança sob o botão">
          <input
            value={extra.texts?.securityNote ?? ''}
            onChange={(e) => setExtra('texts', { securityNote: e.target.value })}
            placeholder="Pagamento seguro com criptografia SSL"
            className={input}
          />
        </Field>
      </Section>

      {/* ── Mobile ────────────────────────────── */}
      <Section icon={DevicePhoneMobileIcon} title="Experiência no celular" description="Onde a maior parte compra">
        <Toggle
          label="Botão de compra fixo no rodapé"
          hint="Mantém o valor e o botão sempre visíveis enquanto o cliente rola"
          checked={extra.mobile?.stickyCta === true}
          onChange={(v) => setExtra('mobile', { stickyCta: v })}
        />
      </Section>

      {/* ── Pós-pagamento ─────────────────────── */}
      <Section icon={ArrowRightOnRectangleIcon} title="Depois do pagamento" description="Para onde o cliente vai">
        <div className="grid grid-cols-1 gap-2">
          {[
            { value: 'default', label: 'Tela de confirmação padrão', hint: 'Mostra o resultado e os próximos passos' },
            { value: 'url', label: 'Redirecionar para uma página', hint: 'Sua página de obrigado' },
          ].map((option) => {
            const active = (extra.postPayment?.type || 'default') === option.value;
            return (
              <button
                key={option.value}
                onClick={() => setExtra('postPayment', { type: option.value })}
                className={`rounded-lg border p-3 text-left transition-colors ${
                  active ? 'border-emerald-500/50 bg-emerald-500/10' : 'border-white/10 hover:bg-white/5'
                }`}
              >
                <span className="block text-sm font-medium text-white">{option.label}</span>
                <span className="mt-0.5 block text-[11px] text-white/55">{option.hint}</span>
              </button>
            );
          })}
        </div>

        {extra.postPayment?.type === 'url' && (
          <Field label="URL de destino" hint="Só o pagamento aprovado redireciona. PIX pendente continua na tela.">
            <input
              value={extra.postPayment?.url ?? ''}
              onChange={(e) => setExtra('postPayment', { url: e.target.value })}
              placeholder="https://seusite.com/obrigado"
              className={input}
            />
          </Field>
        )}
      </Section>

      {/* ── SEO ───────────────────────────────── */}
      <Section icon={MagnifyingGlassIcon} title="SEO e compartilhamento" description="Título e descrição do link">
        <Field label="Título da página" hint="Deixe vazio para usar o nome do produto.">
          <input
            value={extra.seo?.title ?? ''}
            onChange={(e) => setExtra('seo', { title: e.target.value })}
            className={input}
          />
        </Field>

        <Field label="Descrição" hint="Aparece na prévia ao compartilhar o link no WhatsApp.">
          <textarea
            rows={2}
            value={extra.seo?.description ?? ''}
            onChange={(e) => setExtra('seo', { description: e.target.value })}
            className={`${input} resize-y`}
          />
        </Field>

        <p className="rounded-lg bg-white/5 px-3 py-2 text-[11px] leading-relaxed text-white/55">
          Páginas de checkout não são indexadas por buscadores — isso é proposital. Estes campos valem para a
          prévia ao compartilhar o link.
        </p>
      </Section>

      {/* ── Atalhos ───────────────────────────── */}
      <Section icon={TicketIcon} title="Cupons de desconto" description="Gerenciados em tela própria">
        <Shortcut to="/coupons" label="Abrir gestão de cupons" />
        <p className="text-[11px] leading-relaxed text-white/55">
          O campo de cupom já aparece no resumo do pedido. Os códigos válidos são os cadastrados lá.
        </p>
      </Section>

      <Section icon={GlobeAltIcon} title="Domínio personalizado" description="Seu endereço no lugar do padrão">
        <Shortcut to="/domains" label="Abrir domínios" />
        <p className="text-[11px] leading-relaxed text-white/55">
          Domínios verificados e liberados para checkout passam a valer nos links dos seus produtos.
        </p>
      </Section>

      <Section icon={ArrowTrendingUpIcon} title="Upsell, order bump e recuperação" description="Configurados no produto">
        <Shortcut to="/products" label="Abrir produtos" />
        <p className="text-[11px] leading-relaxed text-white/55">
          Order bump e upsell ficam no cadastro do produto, junto do preço — é lá que a oferta é montada.
        </p>
        <Shortcut to="/integrations" label="Abrir integrações e pixels" />
        <p className="text-[11px] leading-relaxed text-white/55">
          Pixels de conversão (Meta, Google, TikTok) e recuperação de carrinho ficam em Integrações.
        </p>
      </Section>
    </div>
  );
}
