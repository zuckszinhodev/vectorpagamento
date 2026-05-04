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
        <p className="text-sm text-gray-400 mb-6 sm:mb-10">Última atualização: 14 de fevereiro de 2026</p>

        <div className="space-y-5 sm:space-y-8 leading-relaxed">
          <section>
            <h2 className="text-xl font-semibold text-white mb-3">1. Aceitação dos Termos</h2>
            <p>
              Ao acessar e utilizar a plataforma GatePro ("Plataforma"), você concorda em cumprir e estar vinculado a estes Termos de Uso. Se você não concordar com qualquer parte destes termos, não deverá utilizar a Plataforma.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">2. Descrição do Serviço</h2>
            <p>
              A GatePro é uma plataforma de gateway de pagamentos que permite a criação de checkouts personalizados, páginas de venda, gestão de produtos digitais e processamento de transações financeiras. Os serviços incluem, mas não se limitam a:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Criação e personalização de páginas de checkout</li>
              <li>Geração de páginas de venda com inteligência artificial</li>
              <li>Processamento de pagamentos via cartão de crédito, PIX e boleto</li>
              <li>Gestão de produtos e assinaturas</li>
              <li>Área de membros para conteúdo digital</li>
              <li>Relatórios e analytics de vendas</li>
              <li>Programa de afiliados</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">3. Cadastro e Conta</h2>
            <p>
              Para utilizar a Plataforma, você deve criar uma conta fornecendo informações verdadeiras, precisas e completas. Você é responsável por manter a confidencialidade de sua senha e por todas as atividades realizadas em sua conta.
            </p>
            <p className="mt-2">
              Você concorda em notificar imediatamente a GatePro sobre qualquer uso não autorizado de sua conta ou qualquer violação de segurança.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">4. Verificação de Identidade (KYC)</h2>
            <p>
              Para operar na Plataforma e realizar saques, pode ser necessário completar o processo de verificação de identidade (Know Your Customer - KYC). Você concorda em fornecer documentos válidos e informações verídicas durante este processo.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">5. Pagamentos e Taxas</h2>
            <p>
              A GatePro cobra taxas sobre as transações processadas através da Plataforma. As taxas aplicáveis serão informadas antes da ativação da conta e podem variar de acordo com o plano contratado e o volume de transações.
            </p>
            <p className="mt-2">
              Os valores recebidos ficam disponíveis para saque após o período de liquidação estabelecido, que pode variar conforme o método de pagamento utilizado pelo comprador.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">6. Saques e Transferências</h2>
            <p>
              Os saques são processados conforme a modalidade escolhida (padrão ou instantâneo) e estão sujeitos a taxas específicas. A GatePro reserva-se o direito de reter valores em caso de suspeita de fraude, chargebacks ou disputas.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">7. Produtos e Conteúdo</h2>
            <p>
              Você é inteiramente responsável pelos produtos e conteúdos comercializados através da Plataforma. É expressamente proibido vender:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Produtos ilegais ou que violem a legislação brasileira</li>
              <li>Material que infrinja direitos autorais ou propriedade intelectual de terceiros</li>
              <li>Conteúdo fraudulento, enganoso ou que faça promessas falsas</li>
              <li>Produtos que promovam atividades ilegais</li>
              <li>Material pornográfico envolvendo menores</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">8. Propriedade Intelectual</h2>
            <p>
              Todo o conteúdo da Plataforma, incluindo design, código, logotipos e marcas, é de propriedade da GatePro. O conteúdo gerado pela IA (páginas de venda, textos) é licenciado para uso do usuário que o gerou.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">9. Chargebacks e Disputas</h2>
            <p>
              Em caso de chargebacks ou disputas de pagamento, a GatePro poderá debitar o valor correspondente do saldo do vendedor. O vendedor é responsável por manter documentação adequada para contestar chargebacks indevidos.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">10. Suspensão e Encerramento</h2>
            <p>
              A GatePro reserva-se o direito de suspender ou encerrar contas que violem estes Termos de Uso, pratiquem fraudes, ou realizem atividades que possam prejudicar a Plataforma ou outros usuários.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">11. Limitação de Responsabilidade</h2>
            <p>
              A GatePro não se responsabiliza por danos indiretos, incidentais ou consequenciais resultantes do uso da Plataforma. Nossa responsabilidade total em qualquer caso estará limitada ao valor das taxas pagas pelo usuário nos últimos 12 meses.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">12. Alterações nos Termos</h2>
            <p>
              A GatePro pode alterar estes Termos de Uso a qualquer momento, notificando os usuários por e-mail ou através da Plataforma. O uso continuado após as alterações implica na aceitação dos novos termos.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">13. Legislação Aplicável</h2>
            <p>
              Estes Termos de Uso são regidos pelas leis da República Federativa do Brasil. Qualquer disputa será submetida ao foro da comarca da sede da GatePro.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">14. Contato</h2>
            <p>
              Para dúvidas sobre estes Termos de Uso, entre em contato conosco pelo e-mail:{' '}
              <a href="mailto:contato@wazerpay.com" className="text-green-400 hover:text-green-300 hover:underline">
                contato@wazerpay.com
              </a>
            </p>
          </section>
        </div>

        <div className="mt-8 pt-6 sm:mt-12 sm:pt-8 border-t border-gray-800">
          <p className="text-sm text-gray-400 text-center">
            © 2026 GatePro. Todos os direitos reservados.
          </p>
        </div>
      </div>
    </div>
  );
}


