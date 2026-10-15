import {
  ShoppingCartIcon,
  AcademicCapIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  ChevronDownIcon,
} from '@heroicons/react/24/outline';

const CNAME_TARGET = 'checkout.vectorpagamentos.com.br';

function Step({ number, title, children }: { number: number; title: string; children: React.ReactNode }) {
  return (
    <div className="relative pl-11">
      {/* Trilho vertical ligando os passos */}
      <span className="absolute left-3.5 top-8 bottom-0 w-px bg-border-DEFAULT" aria-hidden />
      <span className="absolute left-0 top-0 flex h-7 w-7 items-center justify-center rounded-full bg-accent-subtle text-accent-primary text-xs font-bold">
        {number}
      </span>
      <h3 className="text-sm font-semibold text-tx-primary leading-7">{title}</h3>
      <div className="mt-2 space-y-3 pb-8">{children}</div>
    </div>
  );
}

function Provider({ name, badge, children }: { name: string; badge: React.ReactNode; children: React.ReactNode }) {
  return (
    <details className="group rounded-element border border-border-DEFAULT overflow-hidden">
      <summary className="flex items-center gap-3 px-4 py-2.5 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors">
        {badge}
        <span className="text-sm font-medium text-tx-primary">{name}</span>
        <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto group-open:rotate-180 transition-transform" />
      </summary>
      <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page">{children}</div>
    </details>
  );
}

function Faq({ question, children }: { question: string; children: React.ReactNode }) {
  return (
    <details className="group rounded-element border border-border-DEFAULT overflow-hidden">
      <summary className="flex items-center gap-3 px-4 py-2.5 cursor-pointer bg-surface-card hover:bg-surface-hover transition-colors text-sm font-medium text-tx-primary">
        {question}
        <ChevronDownIcon className="h-4 w-4 text-tx-muted ml-auto shrink-0 group-open:rotate-180 transition-transform" />
      </summary>
      <div className="px-4 py-3 border-t border-border-DEFAULT bg-surface-page text-xs text-tx-secondary leading-relaxed">
        {children}
      </div>
    </details>
  );
}

const code = 'px-1.5 py-0.5 rounded bg-surface-hover text-tx-primary font-mono text-[11px]';
const list = 'list-decimal list-inside space-y-1.5 text-xs text-tx-secondary leading-relaxed';

export default function DomainSetupGuide({ platformName }: { platformName: string }) {
  return (
    <div className="space-y-2">
      <Step number={1} title="Escolha seu domínio ou subdomínio">
        <p className="text-sm text-tx-secondary">
          Você pode usar um <strong className="text-tx-primary">domínio raiz</strong> (<code className={code}>meusite.com.br</code>)
          ou um <strong className="text-tx-primary">subdomínio</strong> (<code className={code}>pay.meusite.com.br</code>).
        </p>
        <div className="rounded-element bg-surface-page border border-border-DEFAULT p-3">
          <p className="text-[10px] font-semibold text-tx-muted uppercase tracking-wider mb-2">Exemplos comuns</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5">
            {[
              { icon: ShoppingCartIcon, host: 'pay.meusite.com.br', use: 'Checkout', tone: 'text-emerald-400' },
              { icon: ShoppingCartIcon, host: 'comprar.meusite.com.br', use: 'Checkout', tone: 'text-emerald-400' },
              { icon: AcademicCapIcon, host: 'alunos.meusite.com.br', use: 'Membros', tone: 'text-indigo-400' },
              { icon: AcademicCapIcon, host: 'cursos.meusite.com.br', use: 'Membros', tone: 'text-indigo-400' },
            ].map(item => (
              <div key={item.host} className="flex items-center gap-2 min-w-0">
                <item.icon className={`h-4 w-4 shrink-0 ${item.tone}`} />
                <code className="text-[11px] font-mono text-tx-primary truncate">{item.host}</code>
                <span className="text-[11px] text-tx-muted shrink-0">— {item.use}</span>
              </div>
            ))}
          </div>
        </div>
      </Step>

      <Step number={2} title={`Adicione o domínio na ${platformName}`}>
        <p className="text-sm text-tx-secondary">
          Use o botão <strong className="text-tx-primary">Adicionar domínio</strong>, informe o endereço e escolha se ele
          serve o Checkout, a Área de Membros ou os dois. Os registros DNS são gerados na hora.
        </p>
      </Step>

      <Step number={3} title="Configure os registros DNS">
        <p className="text-sm text-tx-secondary">
          No painel de DNS do seu provedor, adicione <strong className="text-tx-primary">2 registros</strong>:
        </p>

        <div className="overflow-x-auto rounded-element border border-border-DEFAULT">
          <table className="w-full text-left min-w-[420px]">
            <thead className="bg-surface-hover">
              <tr>
                {['Tipo', 'Nome / Host', 'Valor / Destino'].map(h => (
                  <th key={h} className="px-3 py-2 text-[10px] font-semibold text-tx-muted uppercase tracking-wider">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT bg-surface-card">
              <tr>
                <td className="px-3 py-2.5">
                  <span className="inline-flex px-2 py-0.5 rounded text-[10px] font-bold bg-blue-500/10 text-blue-400">CNAME</span>
                </td>
                <td className="px-3 py-2.5 font-mono text-[11px] text-tx-primary">seu-subdominio</td>
                <td className="px-3 py-2.5 font-mono text-[11px] text-tx-primary break-all">{CNAME_TARGET}</td>
              </tr>
              <tr>
                <td className="px-3 py-2.5">
                  <span className="inline-flex px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/10 text-emerald-400">TXT</span>
                </td>
                <td className="px-3 py-2.5 font-mono text-[11px] text-tx-primary break-all">
                  _vectorpagamentos-verification.seudominio.com
                </td>
                <td className="px-3 py-2.5 font-mono text-[11px] text-tx-primary break-all">vectorpagamentos-verify=SEU_TOKEN</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className="flex items-start gap-2 rounded-element bg-status-warning-subtle border border-status-warning/30 p-3">
          <ExclamationTriangleIcon className="h-4 w-4 text-status-warning shrink-0 mt-0.5" />
          <p className="text-xs text-status-warning leading-relaxed">
            Os valores exatos aparecem depois que você adiciona o domínio. A tabela acima é só um exemplo.
          </p>
        </div>

        <div className="space-y-2">
          <p className="text-[10px] font-semibold text-tx-muted uppercase tracking-wider">Guia por provedor</p>

          <Provider
            name="Cloudflare"
            badge={<img src="https://cdn.simpleicons.org/cloudflare/F38020" alt="" className="h-5 w-5" />}
          >
            <ol className={list}>
              <li>Acesse <strong className="text-tx-primary">dash.cloudflare.com</strong> e selecione seu domínio</li>
              <li>Vá em <strong className="text-tx-primary">DNS → Records → Add Record</strong></li>
              <li>Adicione o <strong className="text-tx-primary">CNAME</strong>: nome = seu subdomínio, destino = <code className={code}>{CNAME_TARGET}</code></li>
              <li><strong className="text-tx-primary">Ative o proxy (nuvem laranja)</strong> — é o que dá SSL automático</li>
              <li>Adicione o <strong className="text-tx-primary">TXT</strong> com o host e token mostrados aqui</li>
              <li>Aguarde 1–5 minutos e clique em <strong className="text-tx-primary">Verificar</strong></li>
            </ol>
            <div className="mt-2.5 flex items-start gap-2 rounded-element bg-emerald-500/10 border border-emerald-500/25 p-2.5">
              <CheckCircleIcon className="h-4 w-4 text-emerald-400 shrink-0 mt-0.5" />
              <p className="text-[11px] text-emerald-400 leading-relaxed">
                Provedor recomendado: entrega SSL e CDN de graça. O proxy precisa estar ativo.
              </p>
            </div>
          </Provider>

          <Provider
            name="Hostinger"
            badge={<span className="flex h-5 w-5 items-center justify-center rounded bg-indigo-500/15 text-indigo-400 text-[10px] font-bold">H</span>}
          >
            <ol className={list}>
              <li>No <strong className="text-tx-primary">hPanel</strong>, vá em <strong className="text-tx-primary">Domínios → Zona DNS</strong></li>
              <li>Em "Gerenciar registros DNS", clique em <strong className="text-tx-primary">Adicionar registro</strong></li>
              <li>Selecione <strong className="text-tx-primary">CNAME</strong> e preencha nome e destino</li>
              <li>Adicione outro registro <strong className="text-tx-primary">TXT</strong> com o token</li>
              <li>Salve e aguarde até 15 minutos</li>
              <li>Volte aqui e clique em <strong className="text-tx-primary">Verificar</strong></li>
            </ol>
          </Provider>

          <Provider
            name="GoDaddy"
            badge={<span className="flex h-5 w-5 items-center justify-center rounded bg-surface-hover text-tx-primary text-[10px] font-bold">G</span>}
          >
            <ol className={list}>
              <li>Acesse <strong className="text-tx-primary">Meus Produtos → DNS</strong> no domínio desejado</li>
              <li>Clique em <strong className="text-tx-primary">Adicionar</strong> e escolha o tipo <strong className="text-tx-primary">CNAME</strong></li>
              <li>Host = seu subdomínio, Aponta para = <code className={code}>{CNAME_TARGET}</code></li>
              <li>Repita com o tipo <strong className="text-tx-primary">TXT</strong> para o token</li>
              <li>Salve e aguarde a propagação</li>
            </ol>
          </Provider>

          <Provider
            name="Outro provedor"
            badge={<span className="flex h-5 w-5 items-center justify-center rounded bg-surface-hover text-tx-muted text-[10px] font-bold">?</span>}
          >
            <p className="text-xs text-tx-secondary mb-2">O processo é o mesmo em qualquer painel de DNS:</p>
            <ol className={list}>
              <li>Abra a seção <strong className="text-tx-primary">Registros DNS</strong> ou <strong className="text-tx-primary">Zona DNS</strong></li>
              <li>Crie um <strong className="text-tx-primary">CNAME</strong> apontando para <code className={code}>{CNAME_TARGET}</code></li>
              <li>Crie um <strong className="text-tx-primary">TXT</strong> com o token de verificação</li>
              <li>Salve e aguarde a propagação (minutos a 48h)</li>
            </ol>
          </Provider>
        </div>
      </Step>

      <Step number={4} title="Verifique o domínio">
        <p className="text-sm text-tx-secondary">
          Volte aqui e clique em <strong className="text-tx-primary">Verificar</strong>. O sistema consulta seus registros
          DNS na hora e precisa encontrar os dois:
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {[
            { title: 'CNAME válido', text: `Seu domínio aponta para a ${platformName}` },
            { title: 'TXT verificado', text: 'A propriedade do domínio foi confirmada' },
          ].map(item => (
            <div key={item.title} className="flex items-start gap-2 p-3 rounded-element bg-emerald-500/10 border border-emerald-500/25">
              <CheckCircleIcon className="h-4 w-4 text-emerald-400 shrink-0 mt-0.5" />
              <div>
                <p className="text-xs font-semibold text-emerald-400">{item.title}</p>
                <p className="text-[11px] text-tx-secondary mt-0.5">{item.text}</p>
              </div>
            </div>
          ))}
        </div>
      </Step>

      <Step number={5} title="Pronto — domínio ativo">
        <p className="text-sm text-tx-secondary">
          O status passa a <strong className="text-tx-primary">Verificado</strong> e o domínio fica disponível nos serviços
          que você marcou.
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div className="flex items-center gap-2 p-2.5 rounded-element bg-emerald-500/10 border border-emerald-500/25">
            <ShoppingCartIcon className="h-4 w-4 text-emerald-400 shrink-0" />
            <span className="text-[11px] text-tx-secondary">
              <strong className="text-emerald-400">Checkout:</strong> seus clientes pagam pelo seu domínio
            </span>
          </div>
          <div className="flex items-center gap-2 p-2.5 rounded-element bg-indigo-500/10 border border-indigo-500/25">
            <AcademicCapIcon className="h-4 w-4 text-indigo-400 shrink-0" />
            <span className="text-[11px] text-tx-secondary">
              <strong className="text-indigo-400">Membros:</strong> selecione na configuração da Área de Membros
            </span>
          </div>
        </div>
      </Step>

      <div className="border-t border-border-DEFAULT pt-5 space-y-2">
        <h3 className="text-sm font-semibold text-tx-primary mb-3">Perguntas frequentes</h3>

        <Faq question="Quanto tempo leva a propagação DNS?">
          Com <strong className="text-tx-primary">Cloudflare</strong>: 1 a 5 minutos. Outros provedores: de 15 minutos a 48
          horas. Na maioria dos casos, menos de 1 hora.
        </Faq>

        <Faq question="Preciso de SSL (HTTPS)?">
          Sim, e ele vem automático pelo <strong className="text-tx-primary">Cloudflare</strong>. Com o proxy ativado
          (nuvem laranja), o HTTPS funciona sem configuração extra.
        </Faq>

        <Faq question="Posso usar o mesmo domínio no Checkout e na Área de Membros?">
          Pode — marque as duas opções. Ainda assim recomendamos subdomínios separados, como{' '}
          <code className={code}>pay.meusite.com</code> e <code className={code}>alunos.meusite.com</code>.
        </Faq>

        <Faq question="A verificação falhou. E agora?">
          Confira se o CNAME aponta exatamente para <code className={code}>{CNAME_TARGET}</code> e se o TXT foi criado com
          o host e valor mostrados no domínio. Depois aguarde a propagação e tente de novo — use{' '}
          <a href="https://dnschecker.org" target="_blank" rel="noopener noreferrer" className="text-accent-primary underline">
            dnschecker.org
          </a>{' '}
          para conferir se já propagou.
        </Faq>

        <Faq question="Posso usar um domínio raiz, sem subdomínio?">
          Tecnicamente sim, mas <strong className="text-tx-primary">não recomendamos</strong>: nem todo provedor aceita
          CNAME na raiz. Prefira <code className={code}>pay.meusite.com.br</code>. No Cloudflare a raiz funciona graças ao
          CNAME flattening.
        </Faq>
      </div>
    </div>
  );
}
