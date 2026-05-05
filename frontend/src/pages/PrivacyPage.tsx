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

        <h1 className="text-2xl sm:text-3xl font-bold text-white mb-2">Política de Privacidade</h1>
        <p className="text-sm text-gray-400 mb-6 sm:mb-10">Última atualização: 14 de fevereiro de 2026</p>

        <div className="space-y-5 sm:space-y-8 leading-relaxed">
          <section>
            <h2 className="text-xl font-semibold text-white mb-3">1. Introdução</h2>
            <p>
              A Vector Pagamentos ("nós", "nosso" ou "Plataforma") está comprometida em proteger a sua privacidade. Esta Política de Privacidade descreve como coletamos, usamos, armazenamos e protegemos suas informações pessoais quando você utiliza nossos serviços.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">2. Dados Coletados</h2>
            <p>Coletamos os seguintes tipos de informações:</p>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.1. Dados fornecidos por você</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>Nome completo, CPF/CNPJ e data de nascimento</li>
              <li>Endereço de e-mail e número de telefone</li>
              <li>Dados bancários para saques (banco, agência, conta)</li>
              <li>Documentos de identidade para verificação KYC</li>
              <li>Informações de produtos e conteúdos cadastrados</li>
            </ul>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.2. Dados coletados automaticamente</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>Endereço IP e dados de geolocalização aproximada</li>
              <li>Tipo de navegador e sistema operacional</li>
              <li>Páginas visitadas e tempo de permanência</li>
              <li>Dados de uso e interações com a Plataforma</li>
              <li>Cookies e identificadores de dispositivo</li>
            </ul>

            <h3 className="text-lg font-medium text-white mt-4 mb-2">2.3. Dados de transações</h3>
            <ul className="list-disc list-inside space-y-1 text-gray-300">
              <li>Histórico de compras e vendas</li>
              <li>Métodos de pagamento utilizados</li>
              <li>Valores e status de transações</li>
              <li>Informações de chargebacks e disputas</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">3. Finalidade do Uso dos Dados</h2>
            <p>Utilizamos suas informações pessoais para:</p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Criar e gerenciar sua conta na Plataforma</li>
              <li>Processar pagamentos e realizar saques</li>
              <li>Cumprir obrigações legais e regulatórias (KYC/AML)</li>
              <li>Prevenir fraudes e atividades suspeitas</li>
              <li>Melhorar nossos serviços e experiência do usuário</li>
              <li>Enviar comunicações sobre sua conta e transações</li>
              <li>Gerar relatórios e analytics de vendas</li>
              <li>Fornecer suporte ao cliente</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">4. Compartilhamento de Dados</h2>
            <p>Podemos compartilhar seus dados com:</p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li><strong className="text-white">Processadores de pagamento:</strong> para efetuar transações financeiras (adquirentes de cartão, processadores de PIX e boleto)</li>
              <li><strong className="text-white">Órgãos governamentais:</strong> quando exigido por lei ou regulamentação</li>
              <li><strong className="text-white">Provedores de serviço:</strong> empresas que nos auxiliam na operação da Plataforma (hospedagem, e-mail, analytics)</li>
              <li><strong className="text-white">Compradores:</strong> informações básicas do vendedor necessárias para a transação</li>
            </ul>
            <p className="mt-3">
              Não vendemos, alugamos ou comercializamos seus dados pessoais para terceiros com fins de marketing.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">5. Armazenamento e Segurança</h2>
            <p>
              Seus dados são armazenados em servidores seguros com criptografia em trânsito (TLS/SSL) e em repouso. Adotamos medidas técnicas e organizacionais para proteger suas informações contra acesso não autorizado, alteração, divulgação ou destruição.
            </p>
            <p className="mt-2">As medidas incluem:</p>
            <ul className="list-disc list-inside mt-2 space-y-1 text-gray-300">
              <li>Criptografia de dados sensíveis</li>
              <li>Autenticação com tokens JWT</li>
              <li>Monitoramento contínuo de acessos</li>
              <li>Backups regulares e redundância de dados</li>
              <li>Controle de acesso baseado em funções</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">6. Cookies</h2>
            <p>
              Utilizamos cookies e tecnologias similares para manter sua sessão ativa, lembrar suas preferências e melhorar a experiência de uso. Você pode configurar seu navegador para recusar cookies, mas isso pode afetar a funcionalidade da Plataforma.
            </p>
            <p className="mt-2">Tipos de cookies utilizados:</p>
            <ul className="list-disc list-inside mt-2 space-y-1 text-gray-300">
              <li><strong className="text-white">Essenciais:</strong> necessários para o funcionamento da Plataforma</li>
              <li><strong className="text-white">Funcionais:</strong> permitem lembrar suas preferências (tema, idioma)</li>
              <li><strong className="text-white">Analíticos:</strong> nos ajudam a entender como a Plataforma é utilizada</li>
            </ul>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">7. Seus Direitos (LGPD)</h2>
            <p>
              Em conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018), você tem direito a:
            </p>
            <ul className="list-disc list-inside mt-3 space-y-1 text-gray-300">
              <li>Confirmar a existência de tratamento de seus dados</li>
              <li>Acessar seus dados pessoais armazenados</li>
              <li>Corrigir dados incompletos, inexatos ou desatualizados</li>
              <li>Solicitar a anonimização, bloqueio ou eliminação de dados desnecessários</li>
              <li>Solicitar a portabilidade dos dados para outro fornecedor</li>
              <li>Revogar o consentimento a qualquer momento</li>
              <li>Solicitar a exclusão de dados pessoais tratados com base no consentimento</li>
            </ul>
            <p className="mt-3">
              Para exercer qualquer desses direitos, entre em contato pelo e-mail:{' '}
              <a href="mailto:privacidade@wazerpay.com" className="text-green-400 hover:text-green-300 hover:underline">
                privacidade@wazerpay.com
              </a>
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">8. Retenção de Dados</h2>
            <p>
              Mantemos seus dados pessoais pelo tempo necessário para cumprir as finalidades descritas nesta Política, ou conforme exigido pela legislação aplicável. Dados financeiros são mantidos por no mínimo 5 anos para fins de conformidade regulatória.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">9. Dados de Menores</h2>
            <p>
              A Plataforma destina-se exclusivamente a pessoas com 18 anos ou mais. Não coletamos intencionalmente dados de menores de idade. Caso tome conhecimento de que um menor forneceu dados pessoais, entre em contato para que possamos removê-los.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">10. Inteligência Artificial</h2>
            <p>
              A Vector Pagamentos utiliza serviços de inteligência artificial de terceiros para geração de conteúdo (páginas de venda, textos e imagens). Os dados enviados a estes serviços são limitados ao necessário para a geração do conteúdo solicitado e não incluem dados pessoais identificáveis dos usuários.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">11. Transferência Internacional de Dados</h2>
            <p>
              Alguns de nossos provedores de serviço podem estar localizados fora do Brasil. Nesses casos, garantimos que as transferências de dados são realizadas em conformidade com a LGPD, aplicando salvaguardas adequadas como cláusulas contratuais padrão.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">12. Alterações nesta Política</h2>
            <p>
              Podemos atualizar esta Política de Privacidade periodicamente. Notificaremos sobre mudanças significativas por e-mail ou através de aviso na Plataforma. Recomendamos que revise esta página regularmente.
            </p>
          </section>

          <section>
            <h2 className="text-xl font-semibold text-white mb-3">13. Contato</h2>
            <p>
              Para dúvidas sobre esta Política de Privacidade ou sobre o tratamento de seus dados pessoais, entre em contato:
            </p>
            <div className="mt-3 p-3 sm:p-4 rounded-lg bg-gray-900 border border-gray-800">
              <p className="text-white font-medium">Vector Pagamentos - Encarregado de Dados (DPO)</p>
              <p className="mt-1">
                E-mail:{' '}
                <a href="mailto:contato@wazerpay.com.br" className="text-green-400 hover:text-green-300 hover:underline">
                  contato@wazerpay.com.br
                </a>
              </p>
            </div>
          </section>
        </div>

        <div className="mt-8 pt-6 sm:mt-12 sm:pt-8 border-t border-gray-800">
          <p className="text-sm text-gray-400 text-center">
            © 2026 Vector Pagamentos. Todos os direitos reservados.
          </p>
        </div>
      </div>
    </div>
  );
}


