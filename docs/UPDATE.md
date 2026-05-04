DEPLOY COMPLETO
cd /var/www/wazerpay-gateway \
	&& git fetch --all \
	&& git reset --hard origin/main \
	&& cd backend \
	&& rm -rf vendor \
	&& composer install --optimize-autoloader --no-dev \
	&& php artisan migrate --force \
	&& php artisan config:cache \
	&& php artisan route:cache \
	&& php artisan view:cache \
	&& mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
	&& chown -R www-data:www-data storage bootstrap/cache \
	&& chmod -R 775 storage bootstrap/cache \
	&& systemctl restart php8.2-fpm \
	&& systemctl restart wazerpay-worker \
	&& systemctl restart wazerpay-scheduler \
	&& cd ../frontend \
	&& chown -R root:root . \
	&& chmod -R 755 . \
	&& rm -rf node_modules/.cache \
	&& npm install \
	&& chmod -R 755 node_modules/.bin \
	&& npm run build \
	&& chown -R www-data:www-data dist \
	&& chmod -R 755 dist \
	&& nginx -t \
	&& systemctl restart nginx \
	&& echo "Atualizacao completa"

UPDATE BACKEND
cd /var/www/wazerpay-gateway/backend \
	&& rm -rf vendor \
	&& composer install --optimize-autoloader --no-dev \
	&& php artisan config:cache \
	&& php artisan route:cache \
	&& systemctl restart php8.2-fpm \
	&& systemctl restart wazerpay-worker \
	&& systemctl restart wazerpay-scheduler

UPDATE FRONTEND
cd /var/www/wazerpay-gateway \
	&& git fetch --all \
	&& git reset --hard origin/main \
	&& cd frontend \
	&& rm -rf node_modules/.cache \
	&& npm install \
	&& npm run build \
	&& chown -R www-data:www-data dist \
	&& chmod -R 755 dist \
	&& echo "Frontend atualizado"

IMPORTANTE
Worker precisa incluir a fila de saques:
php artisan queue:work --queue=withdrawals,default,notifications,utmify,n8n-webhooks --sleep=3 --tries=3 --max-time=3600

Scheduler precisa ficar ativo continuamente:
php artisan schedule:work

CORRECAO PARA ERRO: Unit wazerpay-scheduler.service not found

1) Verifique se o unit existe:
systemctl list-unit-files | grep -E "wazerpay|scheduler|schedule"

2) Se nao existir, crie o service:
cat > /etc/systemd/system/wazerpay-scheduler.service << 'EOF'
[Unit]
Description=WazerPay Laravel Scheduler
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/wazerpay-gateway/backend
ExecStart=/usr/bin/php /var/www/wazerpay-gateway/backend/artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

3) Recarregue e habilite:
systemctl daemon-reload
systemctl enable --now wazerpay-scheduler

4) Valide:
systemctl status wazerpay-scheduler --no-pager
journalctl -u wazerpay-scheduler -n 100 --no-pager

OBS
Se o caminho do PHP for diferente, ajuste o ExecStart:
which php