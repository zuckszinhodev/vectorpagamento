import { Link } from 'react-router-dom';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';

export default function PrivacyPage() {
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

        <h1 className="text-2xl sm:text-3xl font-bold text-white mb-2">PolÃ­tica de Privacidade</h1>
        <p className="text-sm text-gray-400 mb-6 sm:mb-10">Ãšltima atualizaÃ§Ã£o: 14 de fevereiro de 2026</p>

        <div className="space-y-5 sm:space-y-8 leading-relaxed">
          <section>
            <h2 className="text-xl font-semibold text-white mb-3">1. IntroduÃ§Ã£o</h2>
            <p>
              A Vector Pagamentos ("nÃ³s", "nosso" ou "Plataforma") estÃ¡ comprometida em proteger a sua privacidade. Esta PolÃ­tica de Privacidade descreve como coletamos, usamos, armazenamos e protegemos suas informaÃ§Ãµes pessoais quando vocÃª utiliza nossos serviÃ§os.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">2. Dados Coletados</h2>
            <p>Coletamos os seguintes tipos de informaÃ§Ãµes:</p>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.1. Dados fornecidos por vocÃª</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>Nome completo, CPF/CNPJ e data de nascimento</li>
              <li>EndereÃ§o de e-mail e nÃºmero de telefone</li>
              <li>Dados bancÃ¡rios para saques (banco, agÃªncia, conta)</li>
              <li>Documentos de identidade para verificaÃ§Ã£o KYC</li>
              <li>InformaÃ§Ãµes de produtos e conteÃºdos cadastrados</li>
            </ul>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.2. Dados coletados automaticamente</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>EndereÃ§o IP e dados de geolocalizaÃ§Ã£o aproximada</li>
              <li>Tipo de navegador e sistema operacional</li>
              <li>PÃ¡ginas visitadas e tempo de permanÃªncia</li>
              <li>Dados de uso e interaÃ§Ãµes com a Plataforma</li>
              <li>Cookies e identificadores de dispositivo</li>
            </ul>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.3. Dados de transaÃ§Ãµes</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>HistÃ³rico de compras e vendas</li>
              <li>MÃ©todos de pagamento utilizados</li>
              <li>Valores e status de transaÃ§Ãµes</li>
              <li>InformaÃ§Ãµes de chargebacks e disputas</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">3. Finalidade do Uso dos Dados</h2>
            <p>Utilizamos suas informaÃ§Ãµes pessoais para:</p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Criar e gerenciar sua conta na Plataforma</li>
              <li>Processar pagamentos e realizar saques</li>
              <li>Cumprir obrigaÃ§Ãµes legais e regulatÃ³rias (KYC/AML)</li>
              <li>Prevenir fraudes e atividades suspeitas</li>
              <li>Melhorar nossos serviÃ§os e experiÃªncia do usuÃ¡rio</li>
              <li>Enviar comunicaÃ§Ãµes sobre sua conta e transaÃ§Ãµes</li>
              <li>Gerar relatÃ³rios e analytics de vendas</li>
              <li>Fornecer suporte ao cliente</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">4. Compartilhamento de Dados</h2>
            <p>Podemos compartilhar seus dados com:</p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li><strong className="text-white">Processadores de pagamento:</strong> para efetuar transaÃ§Ãµes financeiras (adquirentes de cartÃ£o, processadores de PIX e boleto)</li>
              <li><strong className="text-white">Ã“rgÃ£os governamentais:</strong> quando exigido por lei ou regulamentaÃ§Ã£o</li>
              <li><strong className="text-white">Provedores de serviÃ§o:</strong> empresas que nos auxiliam na operaÃ§Ã£o da Plataforma (hospedagem, e-mail, analytics)</li>
              <li><strong className="text-white">Compradores:</strong> informaÃ§Ãµes bÃ¡sicas do vendedor necessÃ¡rias para a transaÃ§Ã£o</li>
            </ul>
            <p className="mt-3">
              NÃ£o vendemos, alugamos ou comercializamos seus dados pessoais para terceiros com fins de marketing.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">5. Armazenamento e SeguranÃ§a</h2>
            <p>
              Seus dados sÃ£o armazenados em servidores seguros com criptografia em trÃ¢nsito (TLS/SSL) e em repouso. Adotamos medidas tÃ©cnicas e organizacionais para proteger suas informaÃ§Ãµes contra acesso nÃ£o autorizado, alteraÃ§Ã£o, divulgaÃ§Ã£o ou destruiÃ§Ã£o.
            </p>
            <p className="mt-2">As medidas incluem:</p>
            <ul className="list-disc list-inside mt-2 space-y-1 text-gray-300">
              <li>Criptografia de dados sensÃ­veis</li>
              <li>AutenticaÃ§Ã£o com tokens JWT</li>
              <li>Monitoramento contÃ­nuo de acessos</li>
              <li>Backups regulares e redundÃ¢ncia de dados</li>
              <li>Controle de acesso baseado em funÃ§Ãµes</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">6. Cookies</h2>
            <p>
              Utilizamos cookies e tecnologias similares para manter sua sessÃ£o ativa, lembrar suas preferÃªncias e melhorar a experiÃªncia de uso. VocÃª pode configurar seu navegador para recusar cookies, mas isso pode afetar a funcionalidade da Plataforma.
            </p>
            <p className="mt-2">Tipos de cookies utilizados:</p>
            <ul className="list-disc list-inside mt-2 space-y-1 text-gray-300">
              <li><strong className="text-white">Essenciais:</strong> necessÃ¡rios para o funcionamento da Plataforma</li>
              <li><strong className="text-white">Funcionais:</strong> permitem lembrar suas preferÃªncias (tema, idioma)</li>
              <li><strong className="text-white">AnalÃ­ticos:</strong> nos ajudam a entender como a Plataforma Ã© utilizada</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">7. Seus Direitos (LGPD)</h2>
            <p>
              Em conformidade com a Lei Geral de ProteÃ§Ã£o de Dados (Lei nÂº 13.709/2018), vocÃª tem direito a:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Confirmar a existÃªncia de tratamento de seus dados</li>
              <li>Acessar seus dados pessoais armazenados</li>
              <li>Corrigir dados incompletos, inexatos ou desatualizados</li>
              <li>Solicitar a anonimizaÃ§Ã£o, bloqueio ou eliminaÃ§Ã£o de dados desnecessÃ¡rios</li>
              <li>Solicitar a portabilidade dos dados para outro fornecedor</li>
              <li>Revogar o consentimento a qualquer momento</li>
              <li>Solicitar a exclusÃ£o de dados pessoais tratados com base no consentimento</li>
            </ul>
            <p className="mt-3">
              Para exercer qualquer desses direitos, entre em contato pelo e-mail:{' '}
              <a href="mailto:privacidade@vectorpagamentos.com" className="text-green-400 hover:text-green-300 hover:underline">
                privacidade@vectorpagamentos.com
              </a>
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">8. RetenÃ§Ã£o de Dados</h2>
            <p>
              Mantemos seus dados pessoais pelo tempo necessÃ¡rio para cumprir as finalidades descritas nesta PolÃ­tica, ou conforme exigido pela legislaÃ§Ã£o aplicÃ¡vel. Dados financeiros sÃ£o mantidos por no mÃ­nimo 5 anos para fins de conformidade regulatÃ³ria.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">9. Dados de Menores</h2>
            <p>
              A Plataforma destina-se exclusivamente a pessoas com 18 anos ou mais. NÃ£o coletamos intencionalmente dados de menores de idade. Caso tome conhecimento de que um menor forneceu dados pessoais, entre em contato para que possamos removÃª-los.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">10. InteligÃªncia Artificial</h2>
            <p>
              A Vector Pagamentos utiliza serviÃ§os de inteligÃªncia artificial de terceiros para geraÃ§Ã£o de conteÃºdo (pÃ¡ginas de venda, textos e imagens). Os dados enviados a estes serviÃ§os sÃ£o limitados ao necessÃ¡rio para a geraÃ§Ã£o do conteÃºdo solicitado e nÃ£o incluem dados pessoais identificÃ¡veis dos usuÃ¡rios.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">11. TransferÃªncia Internacional de Dados</h2>
            <p>
              Alguns de nossos provedores de serviÃ§o podem estar localizados fora do Brasil. Nesses casos, garantimos que as transferÃªncias de dados sÃ£o realizadas em conformidade com a LGPD, aplicando salvaguardas adequadas como clÃ¡usulas contratuais padrÃ£o.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">12. AlteraÃ§Ãµes nesta PolÃ­tica</h2>
            <p>
              Podemos atualizar esta PolÃ­tica de Privacidade periodicamente. Notificaremos sobre mudanÃ§as significativas por e-mail ou atravÃ©s de aviso na Plataforma. Recomendamos que revise esta pÃ¡gina regularmente.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">13. Contato</h2>
            <p>
              Para dÃºvidas sobre esta PolÃ­tica de Privacidade ou sobre o tratamento de seus dados pessoais, entre em contato:
            </p>
            <div className="mt-3 p-3 sm:p-4 rounded-lg bg-gray-900 border border-gray-800">
              <p className="text-white font-medium">Vector Pagamentos - Encarregado de Dados (DPO)</p>
              <p className="mt-1">
                E-mail:{' '}
                <a href="mailto:contato@vectorpagamentos.com" className="text-green-400 hover:text-green-300 hover:underline">
                  contato@vectorpagamentos.com
                </a>
              </p>
            </div>
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


