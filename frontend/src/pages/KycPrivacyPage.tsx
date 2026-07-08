import { Link } from 'react-router-dom';

export default function KycPrivacyPage() {
  return (
    <div className="min-h-screen bg-white px-5 py-8 text-gray-900">
      <main className="mx-auto max-w-3xl">
        <Link to="/kyc" className="text-sm font-medium text-emerald-700 hover:text-emerald-800">
          Voltar ao KYC
        </Link>

        <header className="mt-8 border-b border-gray-200 pb-6">
          <h1 className="text-3xl font-semibold tracking-tight">Politica de Privacidade do KYC</h1>
          <p className="mt-3 text-sm leading-6 text-gray-600">
            Esta politica explica como a Vector Pagamentos coleta, utiliza, protege e armazena
            os dados enviados durante o processo de verificacao de identidade.
          </p>
          <p className="mt-2 text-xs text-gray-500">Ultima atualizacao: 02 de julho de 2026</p>
        </header>

        <section className="space-y-8 py-8 text-sm leading-7 text-gray-700">
          <article>
            <h2 className="text-lg font-semibold text-gray-950">1. Objetivo do KYC</h2>
            <p className="mt-2">
              O processo de KYC, sigla para Know Your Customer, e utilizado para confirmar a
              identidade do usuario, reduzir riscos de fraude, proteger a conta, validar dados
              cadastrais e permitir o uso seguro dos recursos financeiros da plataforma.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">2. Dados coletados</h2>
            <p className="mt-2">
              Durante o processo de verificacao, podemos solicitar dados pessoais e documentos
              necessarios para confirmar a identidade do titular da conta ou do representante legal
              da empresa.
            </p>
            <ul className="mt-3 list-disc space-y-1 pl-5">
              <li>Nome completo, CPF, CNPJ, data de nascimento e telefone.</li>
              <li>Endereco informado no cadastro.</li>
              <li>Documento de identidade, como RG ou CNH, frente e verso.</li>
              <li>Documentos empresariais, quando o cadastro for de pessoa juridica.</li>
              <li>Selfie facial capturada pelo celular para validacao de identidade.</li>
              <li>Informacoes tecnicas basicas, como data, horario e IP do envio.</li>
            </ul>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">3. Como os dados sao usados</h2>
            <p className="mt-2">
              Os dados enviados no KYC sao utilizados exclusivamente para verificacao de identidade,
              analise cadastral, prevencao a fraude, seguranca da conta e cumprimento de controles
              internos da plataforma.
            </p>
            <p className="mt-2">
              A Vector Pagamentos nao utiliza os documentos de KYC para publicidade, venda de dados,
              enriquecimento comercial de base ou qualquer finalidade que nao esteja relacionada a
              seguranca, verificacao ou operacao da conta.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">4. Criptografia e seguranca</h2>
            <p className="mt-2">
              Os dados enviados durante o KYC sao transmitidos por conexao segura e armazenados em
              nosso sistema com mecanismos de protecao e criptografia. O acesso aos documentos e
              restrito a pessoas autorizadas e somente quando necessario para analise, suporte,
              auditoria operacional ou prevencao a fraude.
            </p>
            <p className="mt-2">
              Tambem adotamos controles internos para reduzir acesso indevido, registrar eventos
              relevantes e proteger as informacoes contra uso nao autorizado.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">5. Prazo de armazenamento</h2>
            <p className="mt-2">
              Os documentos, a selfie facial e os arquivos enviados para o KYC sao armazenados em
              nosso sistema por ate 10 dias, contados a partir do envio ou da conclusao da analise,
              conforme a necessidade operacional.
            </p>
            <p className="mt-2">
              Apos esse periodo, os arquivos podem ser removidos ou anonimizados, salvo quando a
              manutencao for necessaria para cumprimento de obrigacao legal, ordem de autoridade
              competente, investigacao de fraude, defesa de direitos ou exigencia regulatoria.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">6. Compartilhamento de dados</h2>
            <p className="mt-2">
              Os dados de KYC podem ser compartilhados apenas quando necessario para validacao de
              identidade, prevencao a fraude, cumprimento de obrigacoes legais ou atendimento de
              solicitacoes de autoridades competentes.
            </p>
            <p className="mt-2">
              Quando houver uso de fornecedores tecnicos para armazenamento, seguranca ou validacao,
              o tratamento devera seguir regras de confidencialidade e protecao de dados.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">7. Responsabilidade do usuario</h2>
            <p className="mt-2">
              O usuario deve enviar documentos verdadeiros, legiveis, atualizados e pertencentes ao
              titular da conta ou ao representante legal autorizado. Informacoes inconsistentes,
              ilegiveis ou divergentes podem causar rejeicao do KYC ou solicitar nova verificacao.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">8. Direitos do titular</h2>
            <p className="mt-2">
              O titular pode solicitar informacoes sobre o tratamento dos seus dados, correcao de
              dados incorretos, esclarecimentos sobre armazenamento e, quando aplicavel, exclusao ou
              revisao do tratamento, respeitando obrigacoes legais e necessidades de seguranca.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">9. Ciencia e consentimento</h2>
            <p className="mt-2">
              Ao continuar com o processo de KYC, o usuario declara estar ciente de que seus dados
              cadastrais, documentos e selfie facial serao criptografados e armazenados em nosso
              sistema por ate 10 dias para verificacao de identidade, seguranca da conta e prevencao
              a fraude.
            </p>
          </article>

          <article>
            <h2 className="text-lg font-semibold text-gray-950">10. Contato</h2>
            <p className="mt-2">
              Em caso de duvidas sobre esta politica ou sobre o tratamento de dados no KYC, entre em
              contato com o suporte da Vector Pagamentos pelos canais oficiais da plataforma.
            </p>
          </article>
        </section>
      </main>
    </div>
  );
}
