#!/bin/sh
set -e

cd /var/www/html

echo "ðŸš€ Starting Vector Pagamentos Backend..."

# Wait for database
if [ "$DB_CONNECTION" = "mysql" ]; then
    echo "â³ Waiting for MySQL..."
    while ! nc -z "$DB_HOST" "${DB_PORT:-3306}" 2>/dev/null; do
        sleep 1
    done
    echo "âœ… MySQL is ready"
elif [ "$DB_CONNECTION" = "pgsql" ]; then
    echo "â³ Connecting to PostgreSQL (Supabase)..."
    while ! nc -z "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; do
        sleep 1
    done
    echo "âœ… PostgreSQL is ready"
fi

# Create SQLite database if needed
if [ "$DB_CONNECTION" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    if [ ! -f "$DB_PATH" ]; then
        echo "ðŸ“¦ Creating SQLite database..."
        touch "$DB_PATH"
        chown www-data:www-data "$DB_PATH"
    fi
fi

# Generate app key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "ðŸ”‘ Generating application key..."
    php artisan key:generate --force
fi

# Run migrations
echo "ðŸ“¦ Running migrations..."
php artisan migrate --force

# Cache config for production
if [ "$APP_ENV" = "production" ]; then
    echo "âš¡ Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Create storage link
php artisan storage:link 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache database

echo "âœ… Vector Pagamentos Backend ready!"

exec "$@"
