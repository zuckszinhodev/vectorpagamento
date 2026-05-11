import { Link } from 'react-router-dom';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';

export default function TermsPage() {
  return (
    <div className="min-h-screen bg-black text-gray-300">
      <div className="max-w-3xl mx-auto px-3 py-6 sm:px-4 sm:py-12">
        <Link
          to="/register"
          className="inline-flex items-center gap-2 text-sm text-green-400 hover:text-green-300 mb-5 sm:mb-8 transition-colors"
        >
          <ArrowLeftIcon className="h-4 w-4" />
          Voltar
        </Link>

        <h1 className="text-2xl sm:text-3xl font-bold text-white mb-2">Termos de Uso</h1>
        <p className="text-sm text-gray-400 mb-6 sm:mb-10">Ãšltima atualizaÃ§Ã£o: 14 de fevereiro de 2026</p>

        <div className="space-y-5 sm:space-y-8 leading-relaxed">
          <section>
            <h2 className="text-xl font-semibold text-white mb-3">1. AceitaÃ§Ã£o dos Termos</h2>
            <p>
              Ao acessar e utilizar a plataforma Vector Pagamentos ("Plataforma"), vocÃª concorda em cumprir e estar vinculado a estes Termos de Uso. Se vocÃª nÃ£o concordar com qualquer parte destes termos, nÃ£o deverÃ¡ utilizar a Plataforma.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">2. DescriÃ§Ã£o do ServiÃ§o</h2>
            <p>
              A Vector Pagamentos Ã© uma plataforma de gateway de pagamentos que permite a criaÃ§Ã£o de checkouts personalizados, pÃ¡ginas de venda, gestÃ£o de produtos digitais e processamento de transaÃ§Ãµes financeiras. Os serviÃ§os incluem, mas nÃ£o se limitam a:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>CriaÃ§Ã£o e personalizaÃ§Ã£o de pÃ¡ginas de checkout</li>
              <li>GeraÃ§Ã£o de pÃ¡ginas de venda com inteligÃªncia artificial</li>
              <li>Processamento de pagamentos via cartÃ£o de crÃ©dito, PIX e boleto</li>
              <li>GestÃ£o de produtos e assinaturas</li>
              <li>Ãrea de membros para conteÃºdo digital</li>
              <li>RelatÃ³rios e analytics de vendas</li>
              <li>Programa de afiliados</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">3. Cadastro e Conta</h2>
            <p>
              Para utilizar a Plataforma, vocÃª deve criar uma conta fornecendo informaÃ§Ãµes verdadeiras, precisas e completas. VocÃª Ã© responsÃ¡vel por manter a confidencialidade de sua senha e por todas as atividades realizadas em sua conta.
            </p>
            <p className="mt-2">
              VocÃª concorda em notificar imediatamente a Vector Pagamentos sobre qualquer uso nÃ£o autorizado de sua conta ou qualquer violaÃ§Ã£o de seguranÃ§a.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">4. VerificaÃ§Ã£o de Identidade (KYC)</h2>
            <p>
              Para operar na Plataforma e realizar saques, pode ser necessÃ¡rio completar o processo de verificaÃ§Ã£o de identidade (Know Your Customer - KYC). VocÃª concorda em fornecer documentos vÃ¡lidos e informaÃ§Ãµes verÃ­dicas durante este processo.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">5. Pagamentos e Taxas</h2>
            <p>
              A Vector Pagamentos cobra taxas sobre as transaÃ§Ãµes processadas atravÃ©s da Plataforma. As taxas aplicÃ¡veis serÃ£o informadas antes da ativaÃ§Ã£o da conta e podem variar de acordo com o plano contratado e o volume de transaÃ§Ãµes.
            </p>
            <p className="mt-2">
              Os valores recebidos ficam disponÃ­veis para saque apÃ³s o perÃ­odo de liquidaÃ§Ã£o estabelecido, que pode variar conforme o mÃ©todo de pagamento utilizado pelo comprador.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">6. Saques e TransferÃªncias</h2>
            <p>
              Os saques sÃ£o processados conforme a modalidade escolhida (padrÃ£o ou instantÃ¢neo) e estÃ£o sujeitos a taxas especÃ­ficas. A Vector Pagamentos reserva-se o direito de reter valores em caso de suspeita de fraude, chargebacks ou disputas.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">7. Produtos e ConteÃºdo</h2>
            <p>
              VocÃª Ã© inteiramente responsÃ¡vel pelos produtos e conteÃºdos comercializados atravÃ©s da Plataforma. Ã‰ expressamente proibido vender:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Produtos ilegais ou que violem a legislaÃ§Ã£o brasileira</li>
              <li>Material que infrinja direitos autorais ou propriedade intelectual de terceiros</li>
              <li>ConteÃºdo fraudulento, enganoso ou que faÃ§a promessas falsas</li>
              <li>Produtos que promovam atividades ilegais</li>
              <li>Material pornogrÃ¡fico envolvendo menores</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">8. Propriedade Intelectual</h2>
            <p>
              Todo o conteÃºdo da Plataforma, incluindo design, cÃ³digo, logotipos e marcas, Ã© de propriedade da Vector Pagamentos. O conteÃºdo gerado pela IA (pÃ¡ginas de venda, textos) Ã© licenciado para uso do usuÃ¡rio que o gerou.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">9. Chargebacks e Disputas</h2>
            <p>
              Em caso de chargebacks ou disputas de pagamento, a Vector Pagamentos poderÃ¡ debitar o valor correspondente do saldo do vendedor. O vendedor Ã© responsÃ¡vel por manter documentaÃ§Ã£o adequada para contestar chargebacks indevidos.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">10. SuspensÃ£o e Encerramento</h2>
            <p>
              A Vector Pagamentos reserva-se o direito de suspender ou encerrar contas que violem estes Termos de Uso, pratiquem fraudes, ou realizem atividades que possam prejudicar a Plataforma ou outros usuÃ¡rios.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">11. LimitaÃ§Ã£o de Responsabilidade</h2>
            <p>
              A Vector Pagamentos nÃ£o se responsabiliza por danos indiretos, incidentais ou consequenciais resultantes do uso da Plataforma. Nossa responsabilidade total em qualquer caso estarÃ¡ limitada ao valor das taxas pagas pelo usuÃ¡rio nos Ãºltimos 12 meses.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">12. AlteraÃ§Ãµes nos Termos</h2>
            <p>
              A Vector Pagamentos pode alterar estes Termos de Uso a qualquer momento, notificando os usuÃ¡rios por e-mail ou atravÃ©s da Plataforma. O uso continuado apÃ³s as alteraÃ§Ãµes implica na aceitaÃ§Ã£o dos novos termos.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">13. LegislaÃ§Ã£o AplicÃ¡vel</h2>
            <p>
              Estes Termos de Uso sÃ£o regidos pelas leis da RepÃºblica Federativa do Brasil. Qualquer disputa serÃ¡ submetida ao foro da comarca da sede da Vector Pagamentos.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">14. Contato</h2>
            <p>
              Para dÃºvidas sobre estes Termos de Uso, entre em contato conosco pelo e-mail:{' '}
              <a href="mailto:contato@vectorpagamentos.com" className="text-green-400 hover:text-green-300 hover:underline">
                contato@vectorpagamentos.com
              </a>
            </p>
          </section>
        </div>

        <div className="mt-8 pt-6 sm:mt-12 sm:pt-8 border-t border-gray-800">
          <p className="text-sm text-gray-400 text-center">
            Â© 2026 Vector Pagamentos. Todos os direitos reservados.
          </p>
        </div>
      </div>
    </div>
  );
}


