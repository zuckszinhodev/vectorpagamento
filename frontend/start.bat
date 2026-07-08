@echo off
echo ========================================
echo   Vector Pagamentos Gateway - Start Frontend
echo ========================================
echo.

cd /d "%~dp0"

echo [1/3] Verificando configuracao...
if not exist ".env" (
    echo AVISO: Arquivo .env nao encontrado!
    echo Usando configuracao padrao...
    echo.
)

echo [2/3] Verificando dependencias...
echo Sincronizando dependencias...
call npm install
if %ERRORLEVEL% NEQ 0 (
    echo ERRO: Falha ao instalar dependencias do frontend.
    pause
    exit /b 1
)

echo [3/3] Iniciando servidor Vite...
echo.
echo Frontend rodando em: http://localhost:3000
echo.
echo Pressione Ctrl+C para parar o servidor
echo.

call npm run dev
