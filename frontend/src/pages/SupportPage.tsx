import { useState } from 'react';
import {
 ChatBubbleLeftRightIcon,
 BookOpenIcon,
 EnvelopeIcon,
 QuestionMarkCircleIcon,
 ChevronDownIcon,
 ArrowTopRightOnSquareIcon,
} from '@heroicons/react/24/outline';

// --------------- FAQ data ---------------
const faqItems = [
 {
 question: 'Como criar minha primeira cobrança?',
 answer:
 'Acesse o menu "Cobranças" no painel e clique em "Nova Cobrança". Preencha o valor, método de pagamento (Pix, Boleto ou Cartão), dados do cliente e clique em "Criar Cobrança". Você receberá o link de pagamento instantaneamente.',
 },
 {
 question: 'Quanto tempo leva para receber meus saques?',
 answer:
 'Saques via Pix são processados em até 1 dia útil. Saques via transferência bancária podem levar de 1 a 3 dias úteis dependendo do banco.',
 },
 {
 question: 'Como configurar webhooks?',
 answer:
 'Vá em Configurações > Webhooks e adicione a URL do seu servidor. Você pode configurar webhooks para N8N, Zapier ou qualquer endpoint HTTP. Também pode configurar na página de Integrações.',
 },
 {
 question: 'O que é o processo de KYC?',
 answer:
 'KYC (Conheça Seu Cliente) é um processo obrigatório de verificação de identidade. Envie seus documentos pessoais e comprovante de endereço em Configurações > Verificação. A aprovação leva até 48 horas.',
 },
 {
 question: 'Como funciona o checkout personalizado?',
 answer:
 'Você pode criar checkouts personalizados com sua marca em Checkouts > Novo Checkout. Defina cores, logo, campos personalizados e o produto. Depois é só compartilhar o link gerado.',
 },
 {
 question: 'Quais são as taxas da WazerPay?',
 answer:
 'As taxas variam conforme o método de pagamento: Pix tem taxa fixa por transação, Boleto tem taxa fixa, e Cartão de Crédito tem taxa percentual. Consulte a tabela completa em Configurações > Plano.',
 },
 {
 question: 'Como adicionar domínio personalizado?',
 answer:
 'Em Domínios > Adicionar Domínio, insira seu domínio e configure o registro DNS CNAME apontando para nosso servidor. A propagação pode levar até 24 horas.',
 },
 {
 question: 'Como funciona o programa de afiliados?',
 answer:
 'Você pode convidar afiliados em Membros > Afiliados. Cada afiliado recebe um link único e você define a comissão por venda. Os pagamentos de comissão são automáticos.',
 },
];

// --------------- component ---------------
export default function SupportPage() {
 const [openFaq, setOpenFaq] = useState<number | null>(null);

 return (
 <div className="space-y-4 sm:space-y-6">
 {/* Header */}
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Suporte</h1>
 <p className="mt-1 text-sm text-tx-muted">
 Tire suas dúvidas ou entre em contato com nosso time
 </p>
 </div>

 {/* Quick Contact Cards */}
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
 <a
 href="https://wa.me/5511940968290"
 target="_blank"
 rel="noopener noreferrer"
 className="bg-surface-card rounded-2xl border border-border-DEFAULT p-5 hover:border-green-300 transition-all hover:shadow-lg group"
 >
 <div className="flex items-center gap-3 mb-2">
 <div className="p-2.5 bg-status-success-subtle rounded-xl group-hover:scale-110 transition-transform">
 <ChatBubbleLeftRightIcon className="h-5 w-5 text-status-success" />
 </div>
 <h3 className="text-sm font-medium text-tx-primary">WhatsApp</h3>
 </div>
 <p className="text-xs text-tx-muted">
 Atendimento rápido via chat
 </p>
 </a>

 <a
 href="https://wazerpay.readme.io/reference"
 target="_blank"
 rel="noopener noreferrer"
 className="bg-surface-card rounded-2xl border border-border-DEFAULT p-5 hover:border-blue-300 transition-all hover:shadow-lg group"
 >
 <div className="flex items-center gap-3 mb-2">
 <div className="p-2.5 bg-accent-subtle rounded-xl group-hover:scale-110 transition-transform">
 <BookOpenIcon className="h-5 w-5 text-accent-primary" />
 </div>
 <h3 className="text-sm font-medium text-tx-primary">Documentação</h3>
 </div>
 <p className="text-xs text-tx-muted flex items-center gap-1">
 Guias e tutoriais completos
 <ArrowTopRightOnSquareIcon className="h-3 w-3" />
 </p>
 </a>
 </div>

 {/* FAQ Section */}
 <div>
 <h2 className="text-lg font-semibold text-tx-primary mb-4 flex items-center gap-2">
 <QuestionMarkCircleIcon className="h-5 w-5 text-accent-primary" />
 Perguntas Frequentes
 </h2>

 <div className="bg-surface-card rounded-2xl border border-border-DEFAULT overflow-hidden divide-y divide-border-DEFAULT">
 {faqItems.map((item, idx) => (
 <div key={idx}>
 <button
 onClick={() => setOpenFaq(openFaq === idx ? null : idx)}
 className="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-surface-page/50 transition-colors"
 >
 <span className="text-sm font-medium text-tx-primary pr-4">
 {item.question}
 </span>
 <ChevronDownIcon
 className={`h-4 w-4 text-tx-muted shrink-0 transition-transform duration-200 ${
 openFaq === idx ? 'rotate-180' : ''
 }`}
 />
 </button>
 {openFaq === idx && (
 <div className="px-5 pb-4 -mt-1">
 <p className="text-sm text-tx-secondary leading-relaxed">
 {item.answer}
 </p>
 </div>
 )}
 </div>
 ))}
 </div>
 </div>

 </div>
 );
}
