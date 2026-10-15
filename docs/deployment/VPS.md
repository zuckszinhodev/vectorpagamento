# VPS de produção

Instalação realizada em 15/10/2026.

- Sistema: http://179.199.152.114
- API: http://179.199.152.114/api/v1
- Saúde da API, banco e cache: http://179.199.152.114/api/health
- Código: `/var/www/vector`
- Ambiente privado: `/var/www/vector/backend/.env`

O acesso inicial usa HTTP pelo IP. O administrador deve ativar o 2FA em
Configurações > Segurança para acessar as telas administrativas.

## Serviços

Nginx, PHP 8.5 FPM, PostgreSQL e Redis são serviços nativos do Ubuntu.
PostgreSQL e Redis escutam somente em localhost. Cache, sessões e filas usam
Redis, com persistência AOF habilitada.

Os seguintes serviços iniciam automaticamente:

```sh
systemctl status nginx php8.5-fpm postgresql redis-server
systemctl status vector-queue@1 vector-queue@2 vector-withdrawals vector-scheduler
journalctl -u vector-queue@1 -u vector-queue@2 -u vector-withdrawals -u vector-scheduler
```

O tempo de reserva dos jobs Redis é de 360 segundos, superior ao maior timeout
dos jobs existentes (300 segundos). A tarefa de limpeza do Telescope só é
registrada quando o pacote estiver instalado.

## Dados iniciais

Foram importados somente perfis de permissão, configurações gerais, adquirentes
e regras/perfis globais de roteamento. Usuários antigos, contas de clientes,
cobranças, saques, transações e uploads não foram importados. Foi criada uma
única conta administrativa nova com saldo zero.

Credenciais das adquirentes precisam ser revisadas: a aplicação local também
não conseguia ler as credenciais salvas. Nenhum pagamento real foi executado
como teste. SMTP não estava configurado; o envio de e-mails está em modo log.

## Backups

`vector-backup.timer` executa diariamente às 06:00 UTC (03:00 em São Paulo).
Os arquivos ficam em `/var/backups/vector`, com acesso restrito a root e retenção
de sete dias. Incluem dump PostgreSQL, `.env` e `storage/app`.

```sh
systemctl start vector-backup.service
systemctl list-timers vector-backup.timer
```

Esses backups estão na própria VPS. A chave da aplicação deve acompanhar o
banco em uma restauração, pois protege as configurações criptografadas.

## Após atualizar o código

Preservar `.env` e `storage`. Instalar dependências usando os arquivos lock,
compilar o frontend e executar migrações. A compilação do frontend usa
`VITE_API_URL=http://179.199.152.114/api/v1` em `.env.production`.

```sh
cd /var/www/vector/backend
runuser -u www-data -- php artisan migrate --force
runuser -u www-data -- php artisan config:cache
runuser -u www-data -- php artisan view:cache
systemctl restart php8.5-fpm vector-queue@1 vector-queue@2 vector-withdrawals vector-scheduler
```

Não executar seeders de demonstração em produção.
