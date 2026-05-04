@echo off
REM GatePro Gateway - Backend Installation Script for Windows
REM This script automates the backend setup process

echo ================================================
echo  GatePro Gateway Backend Installation
echo ================================================
echo.

REM Check if composer is installed
where composer >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Composer not found. Please install Composer first.
    echo Visit: https://getcomposer.org/download/
    pause
    exit /b 1
)

REM Check if PHP is installed
where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] PHP not found. Please install PHP 8.2 or higher.
    pause
    exit /b 1
)

echo [OK] PHP and Composer found
echo.

REM Install Composer dependencies
echo [Step 1/12] Installing Composer dependencies...
call composer install --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-req=php
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Failed to install Composer dependencies
    pause
    exit /b 1
)
echo [OK] Composer dependencies installed
echo.

REM Create .env file
if not exist .env (
    echo [Step 2/12] Creating .env file...
    copy .env.example .env
    echo [OK] .env file created
) else (
    echo [Step 2/12] .env file already exists, skipping...
)
echo.

REM Generate application key
echo [Step 3/12] Generating application key...
php artisan key:generate --force
echo.

REM Install JWT package
echo [Step 4/12] Installing JWT authentication...
call composer require tymon/jwt-auth --no-interaction
php artisan jwt:secret --force
echo.

REM Install Horizon
echo [Step 5/12] Installing Laravel Horizon...
call composer require laravel/horizon --no-interaction
php artisan horizon:install
echo.

REM Install Redis client
echo [Step 6/12] Installing Redis client...
call composer require predis/predis --no-interaction
echo.

REM Install Stripe SDK
echo [Step 7/12] Installing Stripe SDK...
call composer require stripe/stripe-php --no-interaction
echo.

REM Publish JWT configuration
echo [Step 8/12] Publishing JWT configuration...
php artisan vendor:publish --provider="Tymon\JWTAuth\Providers\LaravelServiceProvider" --force
echo.

REM Database configuration
echo [Step 9/12] Database configuration
echo.
echo Please edit your .env file and configure:
echo - DB_CONNECTION (default: pgsql)
echo - DB_HOST (default: 127.0.0.1)
echo - DB_PORT (default: 5432)
echo - DB_DATABASE (default: gatepro_dev)
echo - DB_USERNAME (default: postgres)
echo - DB_PASSWORD (default: postgres)
echo - DB_SSLMODE (default: disable)
echo.
echo Press any key after configuring database settings...
pause >nul
echo.

REM Run migrations
echo [Step 10/12] Running database migrations...
php artisan migrate --force
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Failed to run migrations
    echo Please check your database configuration in .env
    pause
    exit /b 1
)
echo [OK] Migrations completed
echo.

REM Run seeders
echo [Step 11/12] Seeding database...
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=AcquirerSeeder --force
php artisan db:seed --class=CategorySeeder --force
echo [OK] Database seeded
echo.

REM Clear caches
echo [Step 12/12] Clearing caches...
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo [OK] Caches cleared
echo.

REM Create storage link
php artisan storage:link

echo.
echo ================================================
echo  Installation Complete!
echo ================================================
echo.
echo Next Steps:
echo.
echo 1. Configure payment gateways in .env:
echo    - STRIPE_SECRET_KEY
echo    - PAGARME_SECRET_KEY
echo    - MERCADOPAGO_ACCESS_TOKEN
echo.
echo 2. Start development server:
echo    php artisan serve
echo.
echo 3. Start Laravel Horizon:
echo    php artisan horizon
echo.
echo 4. Access Horizon dashboard:
echo    http://localhost:8000/horizon
echo.
echo 5. Test the API:
echo    POST http://localhost:8000/api/v1/auth/register
echo.
echo Read SETUP.md for detailed documentation
echo.
echo Happy coding!
echo.
pause
