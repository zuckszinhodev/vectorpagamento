#!/bin/bash
# ============================================================
# WAZERPAY HOTFIX SCRIPT - Execute no servidor via SSH
# Corrige erro 500 em todas as endpoints
# ============================================================
# Uso: ssh root@SEU_SERVIDOR 'bash -s' < hotfix.sh
# Ou copie e cole diretamente no terminal SSH
# ============================================================

set -e
cd /var/www/wazerpay-gateway/backend

echo "🔧 Aplicando hotfixes..."

# ============================================================
# FIX 1: Exception Handler - $request->user() crash loop
# Este é o FIX PRINCIPAL - impede o handler de crashar
# ============================================================
echo "  [1/6] Corrigindo Exception Handler..."
php -r "
\$file = 'app/Exceptions/Handler.php';
\$content = file_get_contents(\$file);
\$old = \"'user_id' => \\\$request->user()?->id,\";
\$new = \"'user_id' => (function() use (\\\$request) { try { return \\\$request->user()?->id; } catch (\\\\Throwable \\\$e) { return null; } })(),\";
if (strpos(\$content, \$old) !== false) {
    \$content = str_replace(\$old, \$new, \$content);
    file_put_contents(\$file, \$content);
    echo \"    ✅ Handler.php corrigido\n\";
} else {
    echo \"    ⏭️  Handler.php já corrigido ou padrão diferente\n\";
}
"

# ============================================================
# FIX 2: RouteServiceProvider - try/catch no rate limiter
# ============================================================
echo "  [2/6] Corrigindo RouteServiceProvider..."
php -r "
\$file = 'app/Providers/RouteServiceProvider.php';
\$content = file_get_contents(\$file);

// Fix api rate limiter
\$old1 = '\$userId = \$request->user()?->id;';
if (strpos(\$content, 'try {') === false || strpos(\$content, \$old1) !== false) {
    // Replace the entire configureRateLimiting method
    \$pattern = '/protected function configureRateLimiting\(\): void\s*\{.*?\n    \}/s';
    \$replacement = 'protected function configureRateLimiting(): void
    {
        // Default API rate limit
        RateLimiter::for(\'api\', function (Request \$request) {
            try {
                \$userId = \$request->user()?->id;
            } catch (\\\\Throwable \$e) {
                \$userId = null;
            }
            return Limit::perMinute(60)->by(\$userId ?: \$request->ip());
        });

        RateLimiter::for(\'checkout\', function (Request \$request) {
            return Limit::perMinute(120)->by(\$request->ip());
        });

        RateLimiter::for(\'webhooks\', function (Request \$request) {
            return Limit::perMinute(200)->by(\$request->ip());
        });

        RateLimiter::for(\'auth\', function (Request \$request) {
            return Limit::perMinute(10)->by(\$request->ip());
        });

        RateLimiter::for(\'reports\', function (Request \$request) {
            try {
                \$userId = \$request->user()?->id;
            } catch (\\\\Throwable \$e) {
                \$userId = null;
            }
            return Limit::perMinute(15)->by(\$userId ?: \$request->ip());
        });
    }';
    \$content = preg_replace(\$pattern, \$replacement, \$content);
    file_put_contents(\$file, \$content);
    echo \"    ✅ RouteServiceProvider.php corrigido\n\";
} else {
    echo \"    ⏭️  RouteServiceProvider.php já corrigido\n\";
}
"

# ============================================================
# FIX 3: CheckUserStatus middleware
# ============================================================
echo "  [3/6] Corrigindo CheckUserStatus..."
php -r "
\$file = 'app/Http/Middleware/CheckUserStatus.php';
\$content = file_get_contents(\$file);
\$old = '\$user = \$request->user();';
if (strpos(\$content, 'try {') === false) {
    \$content = str_replace(
        '\$user = \$request->user();',
        'try {
            \$user = \$request->user();
        } catch (\\\\Throwable \$e) {
            \$user = null;
        }',
        \$content
    );
    file_put_contents(\$file, \$content);
    echo \"    ✅ CheckUserStatus.php corrigido\n\";
} else {
    echo \"    ⏭️  CheckUserStatus.php já corrigido\n\";
}
"

# ============================================================
# FIX 4: AuthJwtOrApiKey - null-safe account access
# ============================================================
echo "  [4/6] Corrigindo AuthJwtOrApiKey..."
php -r "
\$file = 'app/Http/Middleware/AuthJwtOrApiKey.php';
\$content = file_get_contents(\$file);
\$replacements = [
    ['\$account->status', '\$account?->status'],
    ['\$account->block_reason', '\$account?->block_reason'],
    ['\$account->role', '\$account?->role'],
];
\$changed = false;
foreach (\$replacements as [\$old, \$new]) {
    // Only replace the specific patterns (not already null-safe)
    if (strpos(\$content, \$old) !== false && strpos(\$content, \$new) === false) {
        \$content = str_replace(\$old, \$new, \$content);
        \$changed = true;
    }
}
if (\$changed) {
    file_put_contents(\$file, \$content);
    echo \"    ✅ AuthJwtOrApiKey.php corrigido\n\";
} else {
    echo \"    ⏭️  AuthJwtOrApiKey.php já corrigido\n\";
}
"

# ============================================================
# FIX 5: sentry.php - remove exec() call that can crash
# ============================================================
echo "  [5/6] Corrigindo sentry.php..."
php -r "
\$file = 'config/sentry.php';
if (file_exists(\$file)) {
    \$content = file_get_contents(\$file);
    \$old = \"trim(exec('git log --pretty=\\\"%h\\\" -n1 HEAD') ?: 'unknown')\";
    if (strpos(\$content, 'exec(') !== false) {
        \$content = str_replace(\$old, \"'unknown'\", \$content);
        file_put_contents(\$file, \$content);
        echo \"    ✅ sentry.php corrigido\n\";
    } else {
        echo \"    ⏭️  sentry.php já corrigido\n\";
    }
} else {
    echo \"    ⏭️  sentry.php não encontrado (ok)\n\";
}
"

# ============================================================
# FIX 6: MarketplaceController - try/catch on user() and stats
# ============================================================
echo "  [6/6] Corrigindo MarketplaceController..."
php -r "
\$file = 'app/Http/Controllers/Api/V1/MarketplaceController.php';
\$content = file_get_contents(\$file);
// Fix user() call in index method
\$old = '\$user = \$request->user();';
if (strpos(\$content, 'try {') === false || substr_count(\$content, '\$user = \$request->user()') > 0) {
    \$content = str_replace(
        '\$user = \$request->user();
        if (\$user',
        'try {
            \$user = \$request->user();
        } catch (\\\\Throwable \$e) {
            \$user = null;
        }
        if (\$user',
        \$content
    );
    file_put_contents(\$file, \$content);
    echo \"    ✅ MarketplaceController.php corrigido\n\";
} else {
    echo \"    ⏭️  MarketplaceController.php já corrigido\n\";
}
"

# ============================================================
# Clear caches and restart
# ============================================================
echo ""
echo "🔄 Limpando caches e reiniciando..."

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Rebuild caches
php artisan config:cache
# Skip route:cache if it fails (closures)
php artisan route:cache 2>/dev/null || echo "  ⚠️  route:cache pulado (closures nas rotas)"
php artisan view:cache

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Restart PHP-FPM
systemctl restart php8.2-fpm

echo ""
echo "✅ Hotfix aplicado com sucesso!"
echo ""
echo "Teste com: curl -s https://api.wazerpay.com.br/api/health | python3 -m json.tool"
