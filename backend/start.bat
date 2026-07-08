@echo off
echo ========================================
echo    Vector Pagamentos - Start Backend
echo ========================================
echo.

cd /d "%~dp0"

echo [1/3] Verificando configuracao...
if not exist ".env" (
    echo ERRO: Arquivo .env nao encontrado!
    echo Execute: copy .env.example .env
    pause
    exit /b 1
)

echo [2/3] Verificando dependencias...
if not exist "vendor" (
    echo Instalando dependencias...
    composer install --no-interaction --prefer-dist --ignore-platform-req=php
)

for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_CONNECTION=" .env') do set DB_CONNECTION=%%B
if "%DB_CONNECTION%"=="" set DB_CONNECTION=pgsql

if /I "%DB_CONNECTION%"=="sqlite" goto sqlite_check
goto pgsql_check

:sqlite_check
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_DATABASE=" .env') do set DB_DATABASE=%%B
if "%DB_DATABASE%"=="" set DB_DATABASE=database\database.sqlite
echo [3/5] Verificando SQLite: %DB_DATABASE%
if not exist "%DB_DATABASE%" (
    powershell -NoProfile -Command "New-Item -ItemType File -Force -Path '%DB_DATABASE%' | Out-Null"
)
goto db_check_done

:pgsql_check
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_HOST=" .env') do set DB_HOST=%%B
for /f "tokens=1,* delims==" %%A in ('findstr /b "DB_PORT=" .env') do set DB_PORT=%%B
if "%DB_HOST%"=="" set DB_HOST=127.0.0.1
if "%DB_PORT%"=="" set DB_PORT=5432

echo [3/5] Verificando PostgreSQL: %DB_HOST%:%DB_PORT%
powershell -NoProfile -Command "if (-not (Test-NetConnection -ComputerName '%DB_HOST%' -Port %DB_PORT% -WarningAction SilentlyContinue).TcpTestSucceeded) { exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo ERRO: PostgreSQL nao esta acessivel em %DB_HOST%:%DB_PORT%
    echo.
    echo Se estiver usando Supabase, use a Connection String do Connection Pooler.
    echo No painel Supabase: Project Settings ^> Database ^> Connection Pooling.
    echo Copie host, porta, usuario e senha para DB_HOST, DB_PORT, DB_USERNAME e DB_PASSWORD.
    echo.
    echo Observacao: alguns hosts diretos db.*.supabase.co resolvem apenas IPv6 e podem falhar no Windows/rede atual.
    echo.
    pause
    exit /b 1
)

:db_check_done

echo [4/5] Aplicando migrations...
php artisan migrate --force
if %ERRORLEVEL% NEQ 0 (
    echo ERRO: Falha ao executar migrations.
    echo Verifique as credenciais DB_* no arquivo .env
    pause
    exit /b 1
)

echo [5/5] Iniciando servidor Laravel...
echo.
echo Backend rodando em: http://127.0.0.1:8000
echo API disponivel em: http://127.0.0.1:8000/api/v1
echo.
echo Pressione Ctrl+C para parar o servidor
echo.

if "%SKIP_SERVE%"=="1" exit /b 0

php artisan serve --host=127.0.0.1 --port=8000
