#!/bin/sh
set -e

cd /var/www/html

echo "🚀 Starting GatePro Backend..."

# Wait for database
if [ "$DB_CONNECTION" = "mysql" ]; then
    echo "⏳ Waiting for MySQL..."
    while ! nc -z "$DB_HOST" "${DB_PORT:-3306}" 2>/dev/null; do
        sleep 1
    done
    echo "✅ MySQL is ready"
elif [ "$DB_CONNECTION" = "pgsql" ]; then
    echo "⏳ Connecting to PostgreSQL (Supabase)..."
    while ! nc -z "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; do
        sleep 1
    done
    echo "✅ PostgreSQL is ready"
fi

# Create SQLite database if needed
if [ "$DB_CONNECTION" = "sqlite" ]; then
    DB_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    if [ ! -f "$DB_PATH" ]; then
        echo "📦 Creating SQLite database..."
        touch "$DB_PATH"
        chown www-data:www-data "$DB_PATH"
    fi
fi

# Generate app key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force
fi

# Run migrations
echo "📦 Running migrations..."
php artisan migrate --force

# Cache config for production
if [ "$APP_ENV" = "production" ]; then
    echo "⚡ Caching configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Create storage link
php artisan storage:link 2>/dev/null || true

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache database

echo "✅ GatePro Backend ready!"

exec "$@"
