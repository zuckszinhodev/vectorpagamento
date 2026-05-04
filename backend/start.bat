@echo off
echo ========================================
echo    GatePro Gateway - Start Backend
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

echo [3/5] Verificando PostgreSQL local (127.0.0.1:5432)...
powershell -NoProfile -Command "if (-not (Test-NetConnection -ComputerName 127.0.0.1 -Port 5432 -WarningAction SilentlyContinue).TcpTestSucceeded) { exit 1 }"
if %ERRORLEVEL% NEQ 0 (
    echo ERRO: PostgreSQL nao esta acessivel em 127.0.0.1:5432
    echo.
    echo Inicie o servico do PostgreSQL e tente novamente.
    echo Exemplo: net start postgresql-x64-16
    echo.
    pause
    exit /b 1
)

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

php artisan serve --host=127.0.0.1 --port=8000
