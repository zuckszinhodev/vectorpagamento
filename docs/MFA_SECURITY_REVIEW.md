# Revisão local de autenticação e MFA — 2026-09-30

## Correções implementadas

- O login Google agora retorna um desafio MFA para contas com MFA habilitado, sem emitir JWT antes da verificação. A audiência deve corresponder ao cliente configurado e o e-mail deve estar verificado. As chamadas Google usam o cliente HTTP do Laravel, permitindo testes sem acesso externo.
- `verifyMfa` revalida o estado da conta e do MFA, dentro de transação com bloqueio da linha do usuário. Conta bloqueada ou suspensa não conclui o login.
- O mesmo OTP não pode ser consumido novamente no login, na verificação administrativa ou na desativação do MFA. O registro usa inserção atômica em cache por 120 segundos, excedendo a janela aceita. O cache precisa ser compartilhado entre as instâncias da aplicação, como o Redis configurado por padrão; limpar/perder esse cache remove temporariamente o histórico de uso.
- Requisições para o mesmo desafio são serializadas por lock. O desafio é relido após obter o bloqueio do banco, impedindo consumo de uma leitura obsoleta.
- Desafios e dispositivos confiáveis são vinculados por HMAC ao estado da senha e do MFA. Trocar/resetar senha ou alterar o segredo MFA invalida os anteriores. Tokens de dispositivo antigos, sem essa vinculação, deixam de dispensar MFA. Nenhuma senha real foi alterada automaticamente.
- Entrada MFA malformada retorna 422.

Validação inicial após as correções: 30 testes passaram (137 assertions), incluindo os 12 testes existentes de autenticação. Foram adicionados ainda cenários de reuso entre endpoints e recuperação de senha, cuja execução final é registrada abaixo.

No servidor local, um desafio inventado retornou 401 e campos com tipos inválidos retornaram 422, ambos sem token de acesso. Google foi validado com respostas simuladas do provedor. O teste de contenção do lock é determinístico; não foi realizado teste de carga distribuída.

As seções abaixo preservam o diagnóstico **anterior às correções**.

## Escopo e execução

- Uma requisição HTTP ao endpoint local `POST http://127.0.0.1:8000/api/v1/auth/mfa/verify`, com token fictício: `401 invalid_mfa_token`, sem token de acesso.
- Testes com usuários fictícios, SQLite em memória e cache isolado; sem usar as credenciais enviadas na conversa.
- `php vendor/bin/pest tests/Feature/AuthTest.php --compact`: 12 passaram, 39 assertions.
- `php vendor/bin/pest tests/Feature/MfaSecurityTest.php --compact`: 6 passaram, 2 falharam, 43 assertions.
- Nenhuma alteração na implementação de autenticação. Os dois testes falhando registram requisitos de segurança ainda não atendidos.

## Falhas reproduzidas

### Reutilização de OTP em outro desafio — prioridade média

O mesmo código TOTP foi aceito em dois desafios de login diferentes: ambas as verificações retornaram 200. Cada desafio foi obtido com a senha válida do usuário de teste. Isso não permite entrar sem senha nem adivinhar qualquer código; permite repetir um OTP capturado enquanto estiver válido.

Local: `backend/app/Http/Controllers/Api/V1/AuthController.php`, método `verifyMfa`, chamada `verifyKey`.

Correção recomendada: registrar atomicamente o passo temporal já utilizado por usuário e rejeitar reutilização, inclusive entre desafios diferentes. O requisito de uso único é descrito no [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html).

### Conta bloqueada durante o desafio ainda conclui login — prioridade média

Após iniciar login de usuário ativo, o teste mudou seu status para `blocked`. A verificação com OTP válido retornou 200, quando deveria rejeitar com 403. `verifyMfa` não revalida o status antes da emissão do JWT.

Limite: existe middleware `CheckUserStatus` em rotas protegidas; o teste não demonstra acesso irrestrito aos recursos de uma conta bloqueada. Demonstra falha na conclusão da autenticação.

Correção recomendada: revalidar status e configuração MFA ao consumir o desafio; invalidar desafios pendentes quando o acesso for revogado.

## Achado por leitura de código

O método `googleAuth` emite JWT diretamente, inclusive quando `mfa_enabled` está ativo. Há comentário explícito de que Google OAuth pula o MFA. Não foi exercitado com token Google real. Se a política é exigir o MFA da aplicação, esse caminho alternativo não a cumpre; autenticar pelo Google não comprova por si só o uso do segundo fator exigido pela aplicação.

## Controles que passaram

- Token MFA fictício rejeitado, mesmo com `remember_device: true`.
- OTP incorreto rejeitado em desafio válido.
- Desafio expirado rejeitado.
- Orçamento de cinco tentativas já esgotado rejeitado com 429; teste inicializa o contador, não simula carga nem concorrência.
- Desafio consumido rejeitado ao ser reapresentado.
- Token de dispositivo confiável adulterado não dispensa MFA.

## Interpretação da captura

Um 401 isolado não confirma vulnerabilidade. O código de erro da resposta distingue OTP incorreto, desafio expirado e mudança de contexto. O teste HTTP usou um token fictício e não diagnostica o token específico da captura.

Ver a senha e os tokens no DevTools do próprio navegador é esperado: ele origina a requisição. Em produção, a transmissão deve usar HTTPS. O token de dispositivo é assinado; payload legível não equivale a assinatura falsificável. Ele continua sendo uma credencial sensível.

A senha e o token de dispositivo compartilhados na conversa devem ser substituídos/revogados. Trocar a senha não deve ser presumido suficiente para revogar dispositivos: os métodos de alteração/reset de senha examinados não removem os registros `trusted_device` do cache.

Esta revisão não é uma auditoria completa da plataforma e não cobre concorrência, infraestrutura publicada, todos os endpoints nem exploração real do fluxo Google.
