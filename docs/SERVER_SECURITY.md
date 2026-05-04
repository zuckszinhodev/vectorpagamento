# WazerPay - Guia de Segurança do Servidor

## Instalação Rápida

No servidor, execute:

```bash
cd /var/www/wazerpay-gateway
chmod +x server-security-setup.sh
sudo bash server-security-setup.sh
```

---

## Ferramentas Instaladas

### 1. 🔥 UFW (Firewall)
Firewall simples e eficaz. Só permite SSH, HTTP e HTTPS.

```bash
# Ver status
ufw status verbose

# Bloquear um IP manualmente
ufw deny from 1.2.3.4

# Permitir IP específico
ufw allow from 10.0.0.5 to any port 22
```

### 2. 🛡️ Fail2Ban (Anti Brute-Force)
Bloqueia automaticamente IPs que tentam ataques de força bruta.

**Jails configuradas:**
| Jail | Protege | Max Tentativas | Ban Time |
|------|---------|---------------|----------|
| sshd | SSH | 3 | 2 horas |
| nginx-http-auth | Nginx auth | 3 | 1 hora |
| nginx-limit-req | Rate limit | 10 | 1 hora |
| wazerpay-api | API auth (401/403) | 10 | 1 hora |
| portscan | Port scanning | 3 | 24 horas |

```bash
# Ver status geral
fail2ban-client status

# Ver IPs bloqueados em uma jail
fail2ban-client status sshd

# Desbloquear um IP
fail2ban-client set sshd unbanip 1.2.3.4

# Ver todos os bans
fail2ban-client banned
```

### 3. 🌐 CrowdSec (Inteligência Coletiva)
Versão moderna e gratuita do Fail2Ban com inteligência de ameaças compartilhada por toda a comunidade. Recebe blocklists em tempo real.

```bash
# Ver métricas
cscli metrics

# Ver decisões (IPs bloqueados)
cscli decisions list

# Ver alertas
cscli alerts list

# Adicionar IP na whitelist
cscli decisions delete --ip 1.2.3.4

# Bloquear IP manualmente
cscli decisions add --ip 1.2.3.4 --duration 24h --reason "manual block"

# Atualizar coleções
cscli hub update
cscli hub upgrade
```

**Console Web (grátis):** Cadastre-se em https://app.crowdsec.net e conecte seu servidor para dashboard na nuvem.

```bash
# Registrar no console CrowdSec (gratuito)
cscli console enroll SEU_TOKEN_AQUI
```

### 4. 📊 Netdata (Monitoramento em Tempo Real)
Dashboard web completo com métricas de CPU, RAM, disco, rede, nginx, etc.

**Acesso via SSH Tunnel** (seguro, sem expor porta):
```bash
# No seu computador local:
ssh -L 19999:localhost:19999 root@seu-servidor

# Depois abra no navegador:
http://localhost:19999
```

**O que mostra:**
- CPU, RAM, Disco, Rede em tempo real
- Processos e containers Docker
- Métricas do Nginx
- Alertas automáticos de anomalias

### 5. 📋 Logwatch (Relatórios Diários)
Gera relatórios diários de tudo que aconteceu no servidor.

```bash
# Ver relatório de ontem
cat /var/log/logwatch/report-$(date -d yesterday +%Y-%m-%d).log

# Gerar relatório manualmente
logwatch --range today --detail High --output stdout

# Listar relatórios
ls -la /var/log/logwatch/
```

### 6. 🔍 Auditd (Auditoria de Arquivos)
Monitora quem acessa/modifica arquivos sensíveis.

```bash
# Quem acessou o .env?
ausearch -k wazerpay_env

# Mudanças no SSH config
ausearch -k sshd_config

# Mudanças no nginx
ausearch -k nginx_config

# Comandos executados como root
ausearch -k root_commands --start today

# Mudanças de permissão
ausearch -k permission_changes --start today
```

---

## Dashboard Rápido

Execute a qualquer momento:
```bash
wazerpay-security-check
```

Mostra: Firewall, Fail2Ban, CrowdSec, conexões, recursos, Docker, logins.

---

## Rotina de Segurança Recomendada

### Diário (1 min)
```bash
wazerpay-security-check
```

### Semanal (5 min)
```bash
# Ver relatórios da semana
ls /var/log/logwatch/

# Atualizar CrowdSec
cscli hub update && cscli hub upgrade

# Ver tentativas de ataque
fail2ban-client banned
cscli alerts list --since 7d
```

### Mensal (15 min)
```bash
# Atualizar sistema
apt update && apt upgrade -y

# Verificar certificados SSL
certbot certificates

# Verificar espaço em disco
df -h

# Limpar logs antigos
journalctl --vacuum-time=30d
```

---

## Bloquear Acesso Direto (Só Cloudflare)

Para forçar que todo tráfego passe pelo Cloudflare, descomente o bloco no script ou execute:

```bash
# Permitir só IPs do Cloudflare nas portas 80/443
ufw delete allow 80/tcp
ufw delete allow 443/tcp

# Adicionar IPs do Cloudflare
for ip in 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22; do
  ufw allow from $ip to any port 80,443 proto tcp comment 'Cloudflare'
done
```

---

## Troubleshooting

```bash
# Fail2Ban não está funcionando?
systemctl status fail2ban
fail2ban-client ping
journalctl -u fail2ban -f

# CrowdSec não está funcionando?
systemctl status crowdsec
cscli metrics
journalctl -u crowdsec -f

# Netdata não abre?
systemctl status netdata
ss -tlnp | grep 19999

# Me bloqueei no Fail2Ban?
# Via console/VNC do provedor:
fail2ban-client set sshd unbanip SEU_IP

# Me bloqueei no CrowdSec?
cscli decisions delete --ip SEU_IP
```
